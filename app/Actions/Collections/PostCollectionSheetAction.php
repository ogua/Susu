<?php

namespace App\Actions\Collections;

use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a filled-in collection sheet: per row, an optional loan repayment and
 * an optional savings deposit, each through the same action a single entry
 * uses (so ledger postings, schedules and agent day sheets stay identical).
 *
 * All-or-nothing: any row that fails rolls the whole sheet back and the error
 * names the row, so a sheet is never left half-posted. Optional per-posting
 * client references make a replayed sheet (e.g. from the mobile outbox) safe.
 */
class PostCollectionSheetAction
{
    public function __construct(
        private RecordLoanRepaymentAction $loanRepayment,
        private RecordGroupLoanRepaymentAction $groupLoanRepayment,
        private RecordCollectionAction $collection,
    ) {}

    /**
     * @param  list<array{loan_type?: ?string, loan_id?: ?string, repayment_amount?: ?int, repayment_reference?: ?string, savings_account_id?: ?string, deposit_amount?: ?int, deposit_reference?: ?string}>  $entries
     * @return array{repayments_count: int, repayments_total: int, deposits_count: int, deposits_total: int}
     */
    public function execute(
        User $recordedBy,
        array $entries,
        CarbonInterface $date,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
        ClientOrigin $origin = ClientOrigin::Web,
    ): array {
        $recordedAt = $date->isToday() ? now() : $date->copy()->setTimeFrom(now());
        if ($recordedAt->isFuture()) {
            throw ValidationException::withMessages(['date' => 'A collection sheet cannot be posted for a future date.']);
        }

        $totals = ['repayments_count' => 0, 'repayments_total' => 0, 'deposits_count' => 0, 'deposits_total' => 0];

        DB::transaction(function () use ($recordedBy, $entries, $recordedAt, $paymentMethod, $origin, &$totals): void {
            foreach ($entries as $index => $entry) {
                try {
                    $this->postRepayment($recordedBy, $entry, $recordedAt, $paymentMethod, $origin, $totals);
                    $this->postDeposit($recordedBy, $entry, $recordedAt, $paymentMethod, $origin, $totals);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([
                        "entries.{$index}" => 'Row '.($index + 1).': '.collect($e->errors())->flatten()->first(),
                    ]);
                }
            }
        });

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, int>  $totals
     */
    private function postRepayment(User $recordedBy, array $entry, CarbonInterface $recordedAt, PaymentMethod $paymentMethod, ClientOrigin $origin, array &$totals): void
    {
        $amount = (int) ($entry['repayment_amount'] ?? 0);
        if ($amount <= 0) {
            return;
        }

        $reference = $entry['repayment_reference'] ?? null;

        match ($entry['loan_type'] ?? null) {
            'group' => $this->groupLoanRepayment->execute(
                $this->resolveLoan(GroupLoan::class, $recordedBy, $entry['loan_id'] ?? null),
                $amount, $recordedBy, $reference, $recordedAt, $origin, $paymentMethod,
            ),
            'individual' => $this->loanRepayment->execute(
                $this->resolveLoan(Loan::class, $recordedBy, $entry['loan_id'] ?? null),
                $amount, $recordedBy, $reference, $recordedAt, $origin, $paymentMethod,
            ),
            default => throw ValidationException::withMessages(['loan_id' => 'This row has no loan to repay.']),
        };

        $totals['repayments_count']++;
        $totals['repayments_total'] += $amount;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, int>  $totals
     */
    private function postDeposit(User $recordedBy, array $entry, CarbonInterface $recordedAt, PaymentMethod $paymentMethod, ClientOrigin $origin, array &$totals): void
    {
        $amount = (int) ($entry['deposit_amount'] ?? 0);
        if ($amount <= 0) {
            return;
        }

        $account = SavingsAccount::where('company_id', $recordedBy->company_id)->find($entry['savings_account_id'] ?? null);
        if ($account === null) {
            throw ValidationException::withMessages(['savings_account_id' => 'Choose the savings account to deposit into.']);
        }

        $this->collection->execute(
            agent: $recordedBy,
            account: $account,
            amount: $amount,
            clientReference: $entry['deposit_reference'] ?? null,
            recordedAt: $recordedAt,
            origin: $origin,
            paymentMethod: $paymentMethod,
        );

        $totals['deposits_count']++;
        $totals['deposits_total'] += $amount;
    }

    /**
     * @template TLoan of GroupLoan|Loan
     *
     * @param  class-string<TLoan>  $class
     * @return TLoan
     */
    private function resolveLoan(string $class, User $user, ?string $loanId): GroupLoan|Loan
    {
        return $class::where('company_id', $user->company_id)->find($loanId)
            ?? throw ValidationException::withMessages(['loan_id' => 'Loan not found.']);
    }
}
