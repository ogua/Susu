<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\LoanInstallmentAllocator;
use App\Support\AgentAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a repayment across outstanding installments oldest-first —
 * penalty, then interest, then principal within each installment (clearing
 * the punitive charge and accrued revenue before principal is considered
 * repaid). Idempotent on client_reference — a replayed op (e.g. an offline
 * sync retry) can never double-post.
 */
class RecordLoanRepaymentAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private LoanInstallmentAllocator $allocator,
    ) {}

    public function execute(
        Loan $loan,
        int $amount,
        User $recordedBy,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): LoanRepaymentResult {
        if ($clientReference !== null) {
            $existing = $this->ledger->findByClientReference($clientReference);
            if ($existing !== null) {
                return new LoanRepaymentResult($existing, $loan, duplicate: true);
            }
        }

        AgentAssignment::assertMayCollectLoan($recordedBy, $loan);

        if ($loan->status !== LoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed loans can receive repayments.']);
        }
        if ($amount <= 0 || $amount > $loan->outstanding_balance) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be positive and cannot exceed the outstanding balance.',
            ]);
        }

        return DB::transaction(function () use ($loan, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): LoanRepaymentResult {
            /** @var Loan $lockedLoan */
            $lockedLoan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            [$principalApplied, $interestApplied, $penaltyApplied] = $this->allocator->apply($lockedLoan, $amount);

            $lines = [
                ['account' => $this->cashAccount($lockedLoan, $paymentMethod), 'debit' => $amount],
            ];
            if ($principalApplied > 0) {
                $lines[] = ['account' => $lockedLoan->receivableAccount, 'credit' => $principalApplied];
            }
            if ($interestApplied > 0) {
                $lines[] = ['account' => $this->chart->loanInterestIncome($lockedLoan->company), 'credit' => $interestApplied];
            }
            if ($penaltyApplied > 0) {
                $lines[] = ['account' => $this->chart->loanPenaltyIncome($lockedLoan->company), 'credit' => $penaltyApplied];
            }

            $entry = $this->ledger->post(new EntryData(
                company: $lockedLoan->company,
                type: TransactionType::Repayment,
                lines: $lines,
                branch: $lockedLoan->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $recordedAt ?? now(),
                clientReference: $clientReference,
                description: "Loan repayment {$lockedLoan->loan_number}",
                meta: [
                    'customer_id' => $lockedLoan->customer_id,
                    'loan_id' => $lockedLoan->id,
                    'amount' => $amount,
                ],
            ));

            $newOutstanding = $lockedLoan->outstanding_balance - $amount;
            $lockedLoan->forceFill([
                'outstanding_balance' => $newOutstanding,
                'status' => $newOutstanding <= 0 ? LoanStatus::Closed : $lockedLoan->status,
                'closed_at' => $newOutstanding <= 0 ? now() : null,
            ])->save();

            return new LoanRepaymentResult($entry, $lockedLoan->fresh(), duplicate: false);
        });
    }

    private function cashAccount(Loan $loan, PaymentMethod $method): LedgerAccount
    {
        return match ($method) {
            PaymentMethod::MobileMoney => $this->chart->momoClearing($loan->company),
            default => $this->chart->branchCash($loan->branch),
        };
    }
}
