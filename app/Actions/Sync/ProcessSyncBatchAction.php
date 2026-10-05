<?php

namespace App\Actions\Sync;

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SubmitAgentDailySummaryAction;
use App\Actions\Customers\CreateCustomerAction;
use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\CancelGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Actions\Groups\RecordGroupContributionAction;
use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\LoanApplicationDetails;
use App\Actions\Loans\RecalculateRepaymentScheduleAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Actions\Loans\RestructureLoanAction;
use App\Actions\Loans\TopUpLoanAction;
use App\Actions\Loans\WriteOffLoanAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\LoanFrequency;
use App\Enums\SyncOpType;
use App\Http\Requests\Api\V1\ActivateGroupLoanRequest;
use App\Http\Requests\Api\V1\ApproveLoanRequest;
use App\Http\Requests\Api\V1\CancelGroupLoanRequest;
use App\Http\Requests\Api\V1\DisburseLoanRequest;
use App\Http\Requests\Api\V1\IssueGroupMemberLoanRequest;
use App\Http\Requests\Api\V1\RecalculateScheduleRequest;
use App\Http\Requests\Api\V1\RecordGroupLoanDepositRequest;
use App\Http\Requests\Api\V1\RecordGroupLoanRepaymentRequest;
use App\Http\Requests\Api\V1\RecordLoanRepaymentRequest;
use App\Http\Requests\Api\V1\RejectLoanRequest;
use App\Http\Requests\Api\V1\RestructureLoanRequest;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\StoreGroupContributionRequest;
use App\Http\Requests\Api\V1\StoreLoanApplicationRequest;
use App\Http\Requests\Api\V1\StoreLocationPingsRequest;
use App\Http\Requests\Api\V1\StoreSavingsAccountRequest;
use App\Http\Requests\Api\V1\SubmitDailySummaryRequest;
use App\Http\Requests\Api\V1\TopUpLoanRequest;
use App\Http\Requests\Api\V1\WriteOffGroupLoanRequest;
use App\Http\Requests\Api\V1\WriteOffLoanRequest;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\GroupMember;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SyncOp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies a batch of offline operations, one result per op — never
 * all-or-nothing (AD-3). Two idempotency layers: sync_ops.op_id (this class)
 * and client_reference on the produced records (the actions), so replays are
 * safe even across transport retries.
 */
class ProcessSyncBatchAction
{
    public function __construct(
        private CreateCustomerAction $createCustomer,
        private OpenSavingsAccountAction $openAccount,
        private RecordCollectionAction $recordCollection,
        private SubmitAgentDailySummaryAction $submitSummary,
        private RecordLocationPingsAction $recordPings,
        private ApplyForLoanAction $applyForLoan,
        private RecordLoanRepaymentAction $recordLoanRepayment,
        private ApproveLoanAction $approveLoan,
        private RejectLoanAction $rejectLoan,
        private DisburseLoanAction $disburseLoan,
        private RecordGroupContributionAction $recordGroupContribution,
        private IssueGroupMemberLoanAction $issueGroupMemberLoan,
        private RecordGroupLoanDepositAction $recordGroupLoanDeposit,
        private ActivateGroupLoanAction $activateGroupLoan,
        private RecordGroupLoanRepaymentAction $recordGroupLoanRepayment,
        private RestructureLoanAction $restructureLoan,
        private TopUpLoanAction $topUpLoan,
        private WriteOffLoanAction $writeOffLoan,
        private WriteOffGroupLoanAction $writeOffGroupLoan,
        private CancelGroupLoanAction $cancelGroupLoan,
        private RecalculateRepaymentScheduleAction $recalculateSchedule,
    ) {}

    /**
     * @param  array<int, array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}>  $ops
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $actor, ClientOrigin $origin, array $ops): array
    {
        $allowed = $actor->getRoleNames()
            ->flatMap(fn (string $role): array => SyncOpType::allowedFor($role))
            ->unique(fn (SyncOpType $type): string => $type->value)
            ->values()
            ->all();
        $results = [];

        foreach ($ops as $op) {
            $results[] = $this->applyOne($actor, $origin, $op, $allowed);
        }

        return $results;
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<int, SyncOpType>  $allowed
     * @return array<string, mixed>
     */
    private function applyOne(User $actor, ClientOrigin $origin, array $op, array $allowed): array
    {
        $opId = $op['op_id'];
        $opType = SyncOpType::from($op['op_type']);

        $existing = SyncOp::where('op_id', $opId)->first();
        if ($existing !== null) {
            return $this->duplicateOf($actor, $existing);
        }

        if (! in_array($opType, $allowed, true)) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => ['This operation is not allowed for your role.'],
            ]);
        }

        try {
            // The sync_ops row is claimed before the op runs, inside the same
            // transaction: a concurrent submit of the same op_id blocks on the
            // unique index and then sees this op as a duplicate instead of
            // applying it a second time. Any failure rolls the claim back.
            return DB::transaction(function () use ($actor, $origin, $opType, $op): array {
                $claim = $this->claim($actor, $origin, $op);
                $result = $this->dispatch($actor, $origin, $opType, $op);
                $claim->update(['result' => $result]);

                return ['op_id' => $op['op_id'], 'status' => 'applied', 'result' => $result];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = SyncOp::where('op_id', $opId)->first();

            return $existing !== null
                ? $this->duplicateOf($actor, $existing)
                : $this->retryable($opId, 'This operation is already being applied. Try again shortly.');
        } catch (ValidationException $e) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => collect($e->errors())->flatten()->all(),
            ]);
        } catch (ModelNotFoundException) {
            // Usually a record this op depends on (a customer, an account)
            // has not reached the server yet, so the op stays retryable.
            return $this->retryable($opId, 'A record this operation refers to was not found.');
        } catch (Throwable $e) {
            report($e);

            // Unexpected/transient failures (deadlocks, timeouts) are not
            // stored, so the client can resend the same op_id.
            return $this->retryable($opId, 'The server could not apply this operation.');
        }
    }

    /**
     * Company + branch scope for every record an op refers to: staff can only
     * act on branches they belong to, same as the Filament tenant scope.
     *
     * @param  class-string<Model>  $model
     */
    private function scoped(string $model, User $actor): Builder
    {
        return $model::query()
            ->where('company_id', $actor->company_id)
            ->whereIn('branch_id', $actor->accessibleBranchIds());
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicateOf(User $actor, SyncOp $existing): array
    {
        if ($existing->company_id !== $actor->company_id) {
            return [
                'op_id' => $existing->op_id,
                'status' => 'rejected',
                'result' => ['errors' => ['This operation id is already in use.']],
            ];
        }

        return ['op_id' => $existing->op_id, 'status' => 'duplicate', 'result' => $existing->result];
    }

    /**
     * A rejection that is not stored: resending the same op_id re-runs it.
     *
     * @return array<string, mixed>
     */
    private function retryable(string $opId, string $message): array
    {
        return [
            'op_id' => $opId,
            'status' => 'rejected',
            'retryable' => true,
            'result' => ['errors' => [$message]],
        ];
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @return array<string, mixed>
     */
    private function dispatch(User $actor, ClientOrigin $origin, SyncOpType $opType, array $op): array
    {
        $payload = $this->validatePayload($opType, $op['payload']);
        $recordedAt = Carbon::parse($op['recorded_at']);

        return match ($opType) {
            SyncOpType::RegisterCustomer => $this->applyRegisterCustomer($actor, $payload, $op['op_id']),
            SyncOpType::OpenSavingsAccount => $this->applyOpenAccount($actor, $payload, $op['op_id']),
            SyncOpType::RecordCollection => $this->applyCollection($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::SubmitDailySummary => $this->applySummary($actor, $payload, $op['op_id'], $recordedAt),
            SyncOpType::RecordLocationPings => ['stored' => $this->recordPings->execute($actor, $payload['pings'])],
            SyncOpType::ApplyForLoan => $this->applyLoanApplication($actor, $payload, $op['op_id']),
            SyncOpType::RecordLoanRepayment => $this->applyLoanRepayment($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::ApproveLoan => $this->applyApproveLoan($actor, $payload),
            SyncOpType::RejectLoan => $this->applyRejectLoan($actor, $payload),
            SyncOpType::DisburseLoan => $this->applyDisburseLoan($actor, $payload),
            SyncOpType::RecordGroupContribution => $this->applyGroupContribution($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::IssueGroupMemberLoan => $this->applyIssueGroupMemberLoan($actor, $payload, $op['op_id']),
            SyncOpType::RecordGroupLoanDeposit => $this->applyGroupLoanDeposit($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::ActivateGroupLoan => $this->applyActivateGroupLoan($actor, $origin, $payload),
            SyncOpType::RecordGroupLoanRepayment => $this->applyGroupLoanRepayment($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::RestructureLoan => $this->applyRestructureLoan($actor, $payload, $op['op_id']),
            SyncOpType::TopUpLoan => $this->applyTopUpLoan($actor, $payload, $op['op_id']),
            SyncOpType::WriteOffLoan => $this->applyWriteOffLoan($actor, $payload),
            SyncOpType::WriteOffGroupLoan => $this->applyWriteOffGroupLoan($actor, $origin, $payload),
            SyncOpType::CancelGroupLoan => $this->applyCancelGroupLoan($actor, $payload),
            SyncOpType::RecalculateLoanSchedule => $this->applyRecalculateSchedule($actor, $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']), $payload),
            SyncOpType::RecalculateGroupLoanSchedule => $this->applyRecalculateSchedule($actor, $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']), $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(SyncOpType $opType, array $payload): array
    {
        $rules = match ($opType) {
            SyncOpType::RegisterCustomer => StoreCustomerRequest::payloadRules(),
            SyncOpType::OpenSavingsAccount => StoreSavingsAccountRequest::payloadRules(),
            SyncOpType::RecordCollection => StoreCollectionRequest::payloadRules(),
            SyncOpType::SubmitDailySummary => SubmitDailySummaryRequest::payloadRules(),
            SyncOpType::RecordLocationPings => StoreLocationPingsRequest::payloadRules(),
            SyncOpType::ApplyForLoan => StoreLoanApplicationRequest::payloadRules(),
            SyncOpType::RecordLoanRepayment => RecordLoanRepaymentRequest::payloadRules(),
            SyncOpType::ApproveLoan => ApproveLoanRequest::payloadRules(),
            SyncOpType::RejectLoan => RejectLoanRequest::payloadRules(),
            SyncOpType::DisburseLoan => DisburseLoanRequest::payloadRules(),
            SyncOpType::RecordGroupContribution => StoreGroupContributionRequest::payloadRules(),
            SyncOpType::IssueGroupMemberLoan => IssueGroupMemberLoanRequest::payloadRules(),
            SyncOpType::RecordGroupLoanDeposit => RecordGroupLoanDepositRequest::payloadRules(),
            SyncOpType::ActivateGroupLoan => ActivateGroupLoanRequest::payloadRules(),
            SyncOpType::RecordGroupLoanRepayment => RecordGroupLoanRepaymentRequest::payloadRules(),
            SyncOpType::RestructureLoan => RestructureLoanRequest::payloadRules(),
            SyncOpType::TopUpLoan => TopUpLoanRequest::payloadRules(),
            SyncOpType::WriteOffLoan => WriteOffLoanRequest::payloadRules(),
            SyncOpType::WriteOffGroupLoan => WriteOffGroupLoanRequest::payloadRules(),
            SyncOpType::CancelGroupLoan => CancelGroupLoanRequest::payloadRules(),
            SyncOpType::RecalculateLoanSchedule => RecalculateScheduleRequest::payloadRules(),
            SyncOpType::RecalculateGroupLoanSchedule => RecalculateScheduleRequest::groupPayloadRules(),
        };

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRegisterCustomer(User $actor, array $payload, string $opId): array
    {
        $customer = $this->createCustomer->execute(
            $actor,
            $actor->branch,
            collect($payload)->except('client_reference')->all(),
            $payload['client_reference'] ?? $opId,
        );

        return ['customer_id' => $customer->id, 'customer_code' => $customer->customer_code];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyOpenAccount(User $actor, array $payload, string $opId): array
    {
        $customer = $this->scoped(Customer::class, $actor)->findOrFail($payload['customer_id']);
        $product = SavingsProduct::where('company_id', $actor->company_id)->findOrFail($payload['savings_product_id']);

        $account = $this->openAccount->execute(
            customer: $customer,
            product: $product,
            agent: $actor,
            contributionAmount: isset($payload['contribution_amount']) ? (int) $payload['contribution_amount'] : null,
            clientReference: $payload['client_reference'] ?? $opId,
            targetAmount: isset($payload['target_amount']) ? (int) $payload['target_amount'] : null,
            maturesAt: isset($payload['matures_at']) ? Carbon::parse($payload['matures_at']) : null,
        );

        return ['account_id' => $account->id, 'account_number' => $account->account_number];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyCollection(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $account = $this->scoped(SavingsAccount::class, $actor)
            ->findOrFail($payload['savings_account_id']);

        $result = $this->recordCollection->execute(
            agent: $actor,
            account: $account,
            amount: (int) $payload['amount'],
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
            latitude: isset($payload['latitude']) ? (float) $payload['latitude'] : null,
            longitude: isset($payload['longitude']) ? (float) $payload['longitude'] : null,
        );

        return [
            'entry_id' => $result->entry->id,
            'reference' => $result->entry->reference,
            'balance' => $result->account->balance,
            'commission' => $result->commissionAmount,
            'was_duplicate' => $result->duplicate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applySummary(User $actor, array $payload, string $opId, Carbon $recordedAt): array
    {
        $summary = $this->submitSummary->execute(
            agent: $actor,
            declaredCash: (int) $payload['declared_cash'],
            date: isset($payload['summary_date']) ? Carbon::parse($payload['summary_date']) : $recordedAt,
            notes: $payload['notes'] ?? null,
            clientReference: $payload['client_reference'] ?? $opId,
        );

        return [
            'summary_id' => $summary->id,
            'expected_cash' => $summary->expected_cash,
            'variance' => $summary->variance,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLoanApplication(User $actor, array $payload, string $opId): array
    {
        // Sync ops are staff-only (SyncBatchRequest), so customer_id is always
        // required here even though the direct API lets a customer omit it
        // and apply for themselves instead.
        $customer = $this->scoped(Customer::class, $actor)->findOrFail($payload['customer_id']);
        $product = LoanProduct::where('company_id', $actor->company_id)->findOrFail($payload['loan_product_id']);
        $savingsAccount = isset($payload['savings_account_id'])
            ? $this->scoped(SavingsAccount::class, $actor)->find($payload['savings_account_id'])
            : null;

        $loan = $this->applyForLoan->execute(
            submittedBy: $actor,
            customer: $customer,
            product: $product,
            requestedAmount: (int) $payload['amount'],
            savingsAccount: $savingsAccount,
            guarantorName: $payload['guarantor_name'] ?? null,
            guarantorPhone: $payload['guarantor_phone'] ?? null,
            notes: $payload['notes'] ?? null,
            clientReference: $payload['client_reference'] ?? $opId,
            details: LoanApplicationDetails::fromArray($payload),
        );

        return ['loan_id' => $loan->id, 'loan_number' => $loan->loan_number, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLoanRepayment(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);

        $result = $this->recordLoanRepayment->execute(
            loan: $loan,
            amount: (int) $payload['amount'],
            recordedBy: $actor,
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
        );

        return [
            'entry_id' => $result->entry->id,
            'outstanding_balance' => $result->loan->outstanding_balance,
            'loan_status' => $result->loan->status->value,
            'was_duplicate' => $result->duplicate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyApproveLoan(User $actor, array $payload): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);
        $loan = $this->approveLoan->execute($loan, $actor);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRejectLoan(User $actor, array $payload): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);
        $loan = $this->rejectLoan->execute($loan, $actor, $payload['reason']);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDisburseLoan(User $actor, array $payload): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);
        $loan = $this->disburseLoan->execute($loan, $actor);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value, 'outstanding_balance' => $loan->outstanding_balance];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGroupContribution(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $member = GroupMember::whereHas('group', fn ($query) => $query
            ->where('company_id', $actor->company_id)
            ->whereIn('branch_id', $actor->accessibleBranchIds()))
            ->findOrFail($payload['group_member_id']);

        $contribution = $this->recordGroupContribution->execute(
            recordedBy: $actor,
            member: $member,
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
        );

        return [
            'contribution_id' => $contribution->id,
            'amount' => $contribution->amount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyIssueGroupMemberLoan(User $actor, array $payload, string $opId): array
    {
        $loanGroup = $this->scoped(LoanGroup::class, $actor)->findOrFail($payload['loan_group_id']);
        $customer = $this->scoped(Customer::class, $actor)->findOrFail($payload['customer_id']);

        $groupLoan = $this->issueGroupMemberLoan->execute(
            issuedBy: $actor,
            loanGroup: $loanGroup,
            customer: $customer,
            principal: (int) $payload['principal_amount'],
            securityDeposit: (int) $payload['security_deposit_amount'],
            periodicAmount: (int) $payload['periodic_amount'],
            frequency: LoanFrequency::from($payload['repayment_frequency']),
            startDate: Carbon::parse($payload['start_date']),
            notes: $payload['notes'] ?? null,
            clientReference: $payload['client_reference'] ?? $opId,
        );

        return ['group_loan_id' => $groupLoan->id, 'loan_number' => $groupLoan->loan_number, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGroupLoanDeposit(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $groupLoan = $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']);
        $savingsAccount = $this->scoped(SavingsAccount::class, $actor)->findOrFail($payload['savings_account_id']);

        $result = $this->recordGroupLoanDeposit->execute(
            groupLoan: $groupLoan,
            savingsAccount: $savingsAccount,
            amount: (int) $payload['amount'],
            recordedBy: $actor,
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
        );

        return [
            'entry_id' => $result->entry?->id,
            'deposit_status' => $result->groupLoan->deposit_status->value,
            'was_duplicate' => $result->duplicate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyActivateGroupLoan(User $actor, ClientOrigin $origin, array $payload): array
    {
        $groupLoan = $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']);
        $groupLoan = $this->activateGroupLoan->execute($groupLoan, $actor, $origin);

        return [
            'group_loan_id' => $groupLoan->id,
            'status' => $groupLoan->status->value,
            'total_periods' => $groupLoan->total_periods,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGroupLoanRepayment(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $groupLoan = $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']);

        $result = $this->recordGroupLoanRepayment->execute(
            groupLoan: $groupLoan,
            amount: (int) $payload['amount'],
            recordedBy: $actor,
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
        );

        return [
            'entry_id' => $result->entry->id,
            'outstanding_balance' => $result->groupLoan->outstanding_balance,
            'group_loan_status' => $result->groupLoan->status->value,
            'was_duplicate' => $result->duplicate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRestructureLoan(User $actor, array $payload, string $opId): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);
        $newProduct = LoanProduct::where('company_id', $actor->company_id)->findOrFail($payload['loan_product_id']);

        $newLoan = $this->restructureLoan->execute(
            $loan,
            $actor,
            $newProduct,
            $payload['reason'],
            $payload['client_reference'] ?? $opId,
        );

        return ['loan_id' => $newLoan->id, 'loan_number' => $newLoan->loan_number, 'status' => $newLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyTopUpLoan(User $actor, array $payload, string $opId): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);

        $newLoan = $this->topUpLoan->execute(
            $loan,
            $actor,
            (int) $payload['amount'],
            $payload['reason'],
            $payload['client_reference'] ?? $opId,
        );

        return ['loan_id' => $newLoan->id, 'loan_number' => $newLoan->loan_number, 'status' => $newLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyWriteOffLoan(User $actor, array $payload): array
    {
        $loan = $this->scoped(Loan::class, $actor)->findOrFail($payload['loan_id']);
        $savingsAccount = isset($payload['savings_account_id'])
            ? $this->scoped(SavingsAccount::class, $actor)->findOrFail($payload['savings_account_id'])
            : null;

        $loan = $this->writeOffLoan->execute(
            $loan,
            $actor,
            $payload['reason'],
            savingsAccount: $savingsAccount,
            savingsAmountApplied: (int) ($payload['savings_amount_applied'] ?? 0),
        );

        return ['loan_id' => $loan->id, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyWriteOffGroupLoan(User $actor, ClientOrigin $origin, array $payload): array
    {
        $groupLoan = $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']);
        $savingsAccount = isset($payload['savings_account_id'])
            ? $this->scoped(SavingsAccount::class, $actor)->findOrFail($payload['savings_account_id'])
            : null;

        $groupLoan = $this->writeOffGroupLoan->execute(
            $groupLoan,
            $actor,
            $payload['reason'],
            savingsAccount: $savingsAccount,
            savingsAmountApplied: (int) ($payload['savings_amount_applied'] ?? 0),
            origin: $origin,
        );

        return ['group_loan_id' => $groupLoan->id, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyCancelGroupLoan(User $actor, array $payload): array
    {
        $groupLoan = $this->scoped(GroupLoan::class, $actor)->findOrFail($payload['group_loan_id']);

        $groupLoan = $this->cancelGroupLoan->execute($groupLoan, $actor, $payload['reason'] ?? null);

        return ['group_loan_id' => $groupLoan->id, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRecalculateSchedule(User $actor, Loan|GroupLoan $loan, array $payload): array
    {
        $result = $this->recalculateSchedule->execute(
            $loan,
            $actor,
            isset($payload['first_due_date']) ? Carbon::parse($payload['first_due_date']) : null,
            $payload['reason'] ?? null,
        );

        return [$loan instanceof GroupLoan ? 'group_loan_id' : 'loan_id' => $loan->id, ...$result];
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(User $actor, ClientOrigin $origin, array $op, string $status, array $result): array
    {
        try {
            $this->claim($actor, $origin, $op, $status, $result);
        } catch (UniqueConstraintViolationException) {
            $existing = SyncOp::where('op_id', $op['op_id'])->first();
            if ($existing !== null) {
                return $this->duplicateOf($actor, $existing);
            }
        }

        return ['op_id' => $op['op_id'], 'status' => $status, 'result' => $result];
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<string, mixed>|null  $result
     */
    private function claim(User $actor, ClientOrigin $origin, array $op, string $status = 'applied', ?array $result = null): SyncOp
    {
        return SyncOp::create([
            'op_id' => $op['op_id'],
            'company_id' => $actor->company_id,
            'actor_id' => $actor->id,
            'origin' => $origin,
            'op_type' => $op['op_type'],
            'status' => $status,
            'result' => $result,
            'recorded_at' => Carbon::parse($op['recorded_at']),
        ]);
    }
}
