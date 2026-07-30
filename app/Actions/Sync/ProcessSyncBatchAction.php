<?php

namespace App\Actions\Sync;

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SubmitAgentDailySummaryAction;
use App\Actions\Customers\CreateCustomerAction;
use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\DisburseGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\RejectGroupLoanAction;
use App\Actions\GroupLoans\RestructureGroupLoanAction;
use App\Actions\GroupLoans\TopUpGroupLoanAction;
use App\Actions\Groups\RecordGroupContributionAction;
use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Actions\Loans\RestructureLoanAction;
use App\Actions\Loans\TopUpLoanAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\SyncOpType;
use App\Http\Requests\Api\V1\ApproveGroupLoanRequest;
use App\Http\Requests\Api\V1\ApproveLoanRequest;
use App\Http\Requests\Api\V1\DisburseGroupLoanRequest;
use App\Http\Requests\Api\V1\DisburseLoanRequest;
use App\Http\Requests\Api\V1\RecordGroupLoanRepaymentRequest;
use App\Http\Requests\Api\V1\RecordLoanRepaymentRequest;
use App\Http\Requests\Api\V1\RejectGroupLoanRequest;
use App\Http\Requests\Api\V1\RejectLoanRequest;
use App\Http\Requests\Api\V1\RestructureGroupLoanRequest;
use App\Http\Requests\Api\V1\RestructureLoanRequest;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\StoreGroupContributionRequest;
use App\Http\Requests\Api\V1\StoreGroupLoanApplicationRequest;
use App\Http\Requests\Api\V1\StoreLoanApplicationRequest;
use App\Http\Requests\Api\V1\StoreLocationPingsRequest;
use App\Http\Requests\Api\V1\StoreSavingsAccountRequest;
use App\Http\Requests\Api\V1\SubmitDailySummaryRequest;
use App\Http\Requests\Api\V1\TopUpGroupLoanRequest;
use App\Http\Requests\Api\V1\TopUpLoanRequest;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupMember;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SyncOp;
use App\Models\User;
use Carbon\Carbon;
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
        private ApplyForGroupLoanAction $applyForGroupLoan,
        private RecordGroupLoanRepaymentAction $recordGroupLoanRepayment,
        private ApproveGroupLoanAction $approveGroupLoan,
        private RejectGroupLoanAction $rejectGroupLoan,
        private DisburseGroupLoanAction $disburseGroupLoan,
        private RestructureLoanAction $restructureLoan,
        private TopUpLoanAction $topUpLoan,
        private RestructureGroupLoanAction $restructureGroupLoan,
        private TopUpGroupLoanAction $topUpGroupLoan,
    ) {}

    /**
     * @param  array<int, array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}>  $ops
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $actor, ClientOrigin $origin, array $ops): array
    {
        $role = $actor->getRoleNames()->first() ?? 'none';
        $allowed = SyncOpType::allowedFor($role);
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
            return ['op_id' => $opId, 'status' => 'duplicate', 'result' => $existing->result];
        }

        if (! in_array($opType, $allowed, true)) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => ['This operation is not allowed for your role.'],
            ]);
        }

        try {
            $result = $this->dispatch($actor, $origin, $opType, $op);

            return $this->record($actor, $origin, $op, 'applied', $result);
        } catch (ValidationException $e) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => collect($e->errors())->flatten()->all(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => ['The server could not apply this operation.'],
            ]);
        }
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
            SyncOpType::ApplyForGroupLoan => $this->applyGroupLoanApplication($actor, $payload, $op['op_id']),
            SyncOpType::RecordGroupLoanRepayment => $this->applyGroupLoanRepayment($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::ApproveGroupLoan => $this->applyApproveGroupLoan($actor, $payload),
            SyncOpType::RejectGroupLoan => $this->applyRejectGroupLoan($actor, $payload),
            SyncOpType::DisburseGroupLoan => $this->applyDisburseGroupLoan($actor, $payload),
            SyncOpType::RestructureLoan => $this->applyRestructureLoan($actor, $payload, $op['op_id']),
            SyncOpType::TopUpLoan => $this->applyTopUpLoan($actor, $payload, $op['op_id']),
            SyncOpType::RestructureGroupLoan => $this->applyRestructureGroupLoan($actor, $payload, $op['op_id']),
            SyncOpType::TopUpGroupLoan => $this->applyTopUpGroupLoan($actor, $payload, $op['op_id']),
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
            SyncOpType::ApplyForGroupLoan => StoreGroupLoanApplicationRequest::payloadRules(),
            SyncOpType::RecordGroupLoanRepayment => RecordGroupLoanRepaymentRequest::payloadRules(),
            SyncOpType::ApproveGroupLoan => ApproveGroupLoanRequest::payloadRules(),
            SyncOpType::RejectGroupLoan => RejectGroupLoanRequest::payloadRules(),
            SyncOpType::DisburseGroupLoan => DisburseGroupLoanRequest::payloadRules(),
            SyncOpType::RestructureLoan => RestructureLoanRequest::payloadRules(),
            SyncOpType::TopUpLoan => TopUpLoanRequest::payloadRules(),
            SyncOpType::RestructureGroupLoan => RestructureGroupLoanRequest::payloadRules(),
            SyncOpType::TopUpGroupLoan => TopUpGroupLoanRequest::payloadRules(),
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
        $customer = Customer::where('company_id', $actor->company_id)->findOrFail($payload['customer_id']);
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
        $account = SavingsAccount::where('company_id', $actor->company_id)
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
        $customer = Customer::where('company_id', $actor->company_id)->findOrFail($payload['customer_id']);
        $product = LoanProduct::where('company_id', $actor->company_id)->findOrFail($payload['loan_product_id']);
        $savingsAccount = isset($payload['savings_account_id'])
            ? SavingsAccount::where('company_id', $actor->company_id)->find($payload['savings_account_id'])
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
        );

        return ['loan_id' => $loan->id, 'loan_number' => $loan->loan_number, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLoanRepayment(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);

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
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);
        $loan = $this->approveLoan->execute($loan, $actor);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRejectLoan(User $actor, array $payload): array
    {
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);
        $loan = $this->rejectLoan->execute($loan, $actor, $payload['reason']);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDisburseLoan(User $actor, array $payload): array
    {
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);
        $loan = $this->disburseLoan->execute($loan, $actor);

        return ['loan_id' => $loan->id, 'status' => $loan->status->value, 'outstanding_balance' => $loan->outstanding_balance];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGroupContribution(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $member = GroupMember::whereHas('group', fn ($query) => $query->where('company_id', $actor->company_id))
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
    private function applyGroupLoanApplication(User $actor, array $payload, string $opId): array
    {
        $loanGroup = LoanGroup::where('company_id', $actor->company_id)->findOrFail($payload['loan_group_id']);
        $product = LoanProduct::where('company_id', $actor->company_id)->findOrFail($payload['loan_product_id']);

        $groupLoan = $this->applyForGroupLoan->execute(
            submittedBy: $actor,
            loanGroup: $loanGroup,
            product: $product,
            requestedAmount: (int) $payload['amount'],
            notes: $payload['notes'] ?? null,
            clientReference: $payload['client_reference'] ?? $opId,
        );

        return ['group_loan_id' => $groupLoan->id, 'loan_number' => $groupLoan->loan_number, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyGroupLoanRepayment(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);
        $borrower = GroupLoanBorrower::where('group_loan_id', $groupLoan->id)->findOrFail($payload['group_loan_borrower_id']);

        $result = $this->recordGroupLoanRepayment->execute(
            borrower: $borrower,
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
    private function applyApproveGroupLoan(User $actor, array $payload): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);
        $groupLoan = $this->approveGroupLoan->execute($groupLoan, $actor);

        return ['group_loan_id' => $groupLoan->id, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRejectGroupLoan(User $actor, array $payload): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);
        $groupLoan = $this->rejectGroupLoan->execute($groupLoan, $actor, $payload['reason']);

        return ['group_loan_id' => $groupLoan->id, 'status' => $groupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDisburseGroupLoan(User $actor, array $payload): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);
        $groupLoan = $this->disburseGroupLoan->execute($groupLoan, $actor);

        return ['group_loan_id' => $groupLoan->id, 'status' => $groupLoan->status->value, 'outstanding_balance' => $groupLoan->outstanding_balance];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRestructureLoan(User $actor, array $payload, string $opId): array
    {
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);
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
        $loan = Loan::where('company_id', $actor->company_id)->findOrFail($payload['loan_id']);

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
    private function applyRestructureGroupLoan(User $actor, array $payload, string $opId): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);
        $newProduct = LoanProduct::where('company_id', $actor->company_id)->findOrFail($payload['loan_product_id']);

        $newGroupLoan = $this->restructureGroupLoan->execute(
            $groupLoan,
            $actor,
            $newProduct,
            $payload['reason'],
            $payload['client_reference'] ?? $opId,
        );

        return ['group_loan_id' => $newGroupLoan->id, 'loan_number' => $newGroupLoan->loan_number, 'status' => $newGroupLoan->status->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyTopUpGroupLoan(User $actor, array $payload, string $opId): array
    {
        $groupLoan = GroupLoan::where('company_id', $actor->company_id)->findOrFail($payload['group_loan_id']);

        $newGroupLoan = $this->topUpGroupLoan->execute(
            $groupLoan,
            $actor,
            (int) $payload['amount'],
            $payload['reason'],
            $payload['client_reference'] ?? $opId,
        );

        return ['group_loan_id' => $newGroupLoan->id, 'loan_number' => $newGroupLoan->loan_number, 'status' => $newGroupLoan->status->value];
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(User $actor, ClientOrigin $origin, array $op, string $status, array $result): array
    {
        SyncOp::create([
            'op_id' => $op['op_id'],
            'company_id' => $actor->company_id,
            'actor_id' => $actor->id,
            'origin' => $origin,
            'op_type' => $op['op_type'],
            'status' => $status,
            'result' => $result,
            'recorded_at' => Carbon::parse($op['recorded_at']),
        ]);

        return ['op_id' => $op['op_id'], 'status' => $status, 'result' => $result];
    }
}
