<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanRepayment;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\GroupLoanInstallmentAllocator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a cash repayment against one member's group loan. The payment is
 * allocated across the member's installments oldest-first (pure principal —
 * no interest or penalty), the outstanding balance drops, and the loan
 * auto-closes (refunding any still-held deposit) once it reaches zero.
 * Idempotent on client_reference.
 */
class RecordGroupLoanRepaymentAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private GroupLoanInstallmentAllocator $allocator,
        private CloseGroupLoanAction $close,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        int $amount,
        User $recordedBy,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): GroupLoanRepaymentResult {
        if ($clientReference !== null) {
            $existing = GroupLoanRepayment::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return new GroupLoanRepaymentResult($existing->journalEntry, $existing->groupLoan, $existing, duplicate: true);
            }
        }

        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Only an active group loan can receive repayments.']);
        }
        if ($amount <= 0 || $amount > $groupLoan->outstanding_balance) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be positive and cannot exceed the outstanding balance.',
            ]);
        }

        return DB::transaction(function () use ($groupLoan, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): GroupLoanRepaymentResult {
            /** @var GroupLoan $locked */
            $locked = GroupLoan::whereKey($groupLoan->id)->lockForUpdate()->firstOrFail();

            $this->allocator->apply($locked, $amount);

            $entry = $this->ledger->post(new EntryData(
                company: $locked->company,
                type: TransactionType::GroupLoanRepayment,
                lines: [
                    ['account' => $this->cashAccount($locked, $paymentMethod), 'debit' => $amount],
                    ['account' => $locked->receivableAccount, 'credit' => $amount],
                ],
                branch: $locked->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $recordedAt ?? now(),
                clientReference: $clientReference,
                description: "Group loan repayment {$locked->loan_number}",
                meta: [
                    'customer_id' => $locked->customer_id,
                    'group_loan_id' => $locked->id,
                    'amount' => $amount,
                ],
            ));

            $repayment = GroupLoanRepayment::create([
                'group_loan_id' => $locked->id,
                'journal_entry_id' => $entry->id,
                'recorded_by' => $recordedBy->id,
                'amount' => $amount,
                'recorded_at' => $recordedAt ?? now(),
                'client_reference' => $clientReference,
            ]);

            $locked->forceFill([
                'outstanding_balance' => $locked->outstanding_balance - $amount,
            ])->save();

            if ($locked->outstanding_balance <= 0) {
                $this->close->execute($locked->fresh(), $recordedBy, $origin);
            }

            return new GroupLoanRepaymentResult($entry, $locked->fresh(), $repayment, duplicate: false);
        });
    }

    private function cashAccount(GroupLoan $groupLoan, PaymentMethod $method): LedgerAccount
    {
        return match ($method) {
            PaymentMethod::MobileMoney => $this->chart->momoClearing($groupLoan->company),
            default => $this->chart->branchCash($groupLoan->branch),
        };
    }
}
