<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declares an active group loan's remaining balance uncollectible. Any held
 * security deposit is seized against the outstanding balance first (Dr
 * deposit liability / Cr receivable), then only the residual is recognized as
 * bad debt (Dr bad-debt expense / Cr receivable). Manager-tier only.
 */
class WriteOffGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(GroupLoan $groupLoan, User $writtenOffBy, string $reason, ClientOrigin $origin = ClientOrigin::Web): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Only an active group loan can be written off.']);
        }
        if ($groupLoan->outstanding_balance <= 0) {
            throw ValidationException::withMessages(['status' => 'This group loan has no outstanding balance to write off.']);
        }

        return DB::transaction(function () use ($groupLoan, $writtenOffBy, $reason, $origin): GroupLoan {
            $outstanding = $groupLoan->outstanding_balance;
            $seized = 0;

            if ($groupLoan->deposit_status === DepositStatus::Held && $groupLoan->security_deposit_amount > 0) {
                $seized = min($groupLoan->security_deposit_amount, $outstanding);

                $seizeEntry = $this->ledger->post(new EntryData(
                    company: $groupLoan->company,
                    type: TransactionType::GroupLoanDepositApplied,
                    lines: [
                        ['account' => $groupLoan->depositLiabilityAccount, 'debit' => $seized],
                        ['account' => $groupLoan->receivableAccount, 'credit' => $seized],
                    ],
                    branch: $groupLoan->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: $origin,
                    recordedBy: $writtenOffBy,
                    recordedAt: now(),
                    description: "Group loan deposit seized on write-off {$groupLoan->loan_number}",
                    meta: [
                        'customer_id' => $groupLoan->customer_id,
                        'group_loan_id' => $groupLoan->id,
                        'amount' => $seized,
                    ],
                ));

                GroupLoanDeposit::create([
                    'group_loan_id' => $groupLoan->id,
                    'journal_entry_id' => $seizeEntry->id,
                    'recorded_by' => $writtenOffBy->id,
                    'amount' => $seized,
                    'type' => 'seized',
                    'recorded_at' => now(),
                ]);

                $groupLoan->forceFill(['deposit_status' => DepositStatus::Settled])->save();
            }

            $residual = $outstanding - $seized;

            if ($residual > 0) {
                $this->ledger->post(new EntryData(
                    company: $groupLoan->company,
                    type: TransactionType::GroupLoanWriteOff,
                    lines: [
                        ['account' => $this->chart->badDebtExpense($groupLoan->company), 'debit' => $residual],
                        ['account' => $groupLoan->receivableAccount, 'credit' => $residual],
                    ],
                    branch: $groupLoan->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: $origin,
                    recordedBy: $writtenOffBy,
                    recordedAt: now(),
                    description: "Group loan write-off {$groupLoan->loan_number}",
                    meta: [
                        'customer_id' => $groupLoan->customer_id,
                        'loan_group_id' => $groupLoan->loan_group_id,
                        'group_loan_id' => $groupLoan->id,
                        'amount' => $residual,
                    ],
                ));
            }

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::WrittenOff,
                'outstanding_balance' => 0,
                'written_off_at' => now(),
                'write_off_reason' => $reason,
                'write_off_amount' => $outstanding,
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
