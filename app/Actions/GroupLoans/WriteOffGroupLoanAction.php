<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declares a disbursed group loan's remaining shared balance uncollectible —
 * mirrors WriteOffLoanAction exactly, including crediting the receivable by
 * its actual current balance (principal only — interest/penalty are only
 * recognized as income when actually collected) rather than the full
 * outstanding_balance, which would over-credit it negative. Unlike a
 * repayment, this does NOT touch any individual GroupLoanBorrower
 * .share_outstanding row: those remain as accountability history of what
 * each member still owed at the moment the group's joint debt was written
 * off. Only GroupLoan.outstanding_balance (the legally relevant figure under
 * joint & several liability) zeroes — this is deliberate, not an oversight.
 */
class WriteOffGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(GroupLoan $groupLoan, User $writtenOffBy, string $reason): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed group loans can be written off.']);
        }
        if ($groupLoan->outstanding_balance <= 0) {
            throw ValidationException::withMessages(['status' => 'This group loan has no outstanding balance to write off.']);
        }

        return DB::transaction(function () use ($groupLoan, $writtenOffBy, $reason): GroupLoan {
            $writeOffAmount = $groupLoan->outstanding_balance;
            $principalOutstanding = $groupLoan->receivableAccount->refresh()->balance;

            if ($principalOutstanding > 0) {
                $this->ledger->post(new EntryData(
                    company: $groupLoan->company,
                    type: TransactionType::GroupLoanWriteOff,
                    lines: [
                        ['account' => $this->chart->badDebtExpense($groupLoan->company), 'debit' => $principalOutstanding],
                        ['account' => $groupLoan->receivableAccount, 'credit' => $principalOutstanding],
                    ],
                    branch: $groupLoan->branch,
                    paymentMethod: PaymentMethod::Cash,
                    origin: ClientOrigin::Web,
                    recordedBy: $writtenOffBy,
                    recordedAt: now(),
                    description: "Group loan write-off {$groupLoan->loan_number}",
                    meta: [
                        // No customer_id — a group loan has no single customer.
                        'loan_group_id' => $groupLoan->loan_group_id,
                        'group_loan_id' => $groupLoan->id,
                        'amount' => $principalOutstanding,
                    ],
                ));
            }

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::WrittenOff,
                'outstanding_balance' => 0,
                'written_off_at' => now(),
                'write_off_reason' => $reason,
                'write_off_amount' => $writeOffAmount,
                'approved_by' => $writtenOffBy->id,
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
