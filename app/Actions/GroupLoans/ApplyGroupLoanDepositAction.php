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
use App\Services\Loans\GroupLoanInstallmentAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a held security deposit against the loan's outstanding balance on
 * demand — a non-cash "repayment" (Dr deposit liability / Cr receivable),
 * allocated across installments oldest-first. Any deposit in excess of the
 * outstanding balance is refunded to the member in cash; whatever offsets the
 * balance is consumed. Closes the loan if this clears it. Idempotent on
 * client_reference.
 */
class ApplyGroupLoanDepositAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private GroupLoanInstallmentAllocator $allocator,
        private CloseGroupLoanAction $close,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        User $appliedBy,
        ?string $clientReference = null,
        ClientOrigin $origin = ClientOrigin::Web,
    ): GroupLoan {
        if ($clientReference !== null) {
            $existing = GroupLoanDeposit::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing->groupLoan;
            }
        }

        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'The deposit can only be applied while the loan is active.']);
        }
        if ($groupLoan->deposit_status !== DepositStatus::Held || $groupLoan->security_deposit_amount <= 0) {
            throw ValidationException::withMessages(['deposit_status' => 'There is no held deposit to apply.']);
        }

        return DB::transaction(function () use ($groupLoan, $appliedBy, $clientReference, $origin): GroupLoan {
            /** @var GroupLoan $locked */
            $locked = GroupLoan::whereKey($groupLoan->id)->lockForUpdate()->firstOrFail();

            $held = $locked->security_deposit_amount;
            $applied = min($held, $locked->outstanding_balance);
            $excess = $held - $applied;

            if ($applied > 0) {
                $this->allocator->apply($locked, $applied);

                $appliedEntry = $this->ledger->post(new EntryData(
                    company: $locked->company,
                    type: TransactionType::GroupLoanDepositApplied,
                    lines: [
                        ['account' => $locked->depositLiabilityAccount, 'debit' => $applied],
                        ['account' => $locked->receivableAccount, 'credit' => $applied],
                    ],
                    branch: $locked->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: $origin,
                    recordedBy: $appliedBy,
                    recordedAt: now(),
                    clientReference: $clientReference,
                    description: "Group loan deposit applied to balance {$locked->loan_number}",
                    meta: [
                        'customer_id' => $locked->customer_id,
                        'group_loan_id' => $locked->id,
                        'amount' => $applied,
                    ],
                ));

                GroupLoanDeposit::create([
                    'group_loan_id' => $locked->id,
                    'journal_entry_id' => $appliedEntry->id,
                    'recorded_by' => $appliedBy->id,
                    'amount' => $applied,
                    'type' => 'applied',
                    'recorded_at' => now(),
                    'client_reference' => $clientReference,
                ]);

                $locked->forceFill([
                    'outstanding_balance' => $locked->outstanding_balance - $applied,
                ])->save();
            }

            if ($excess > 0) {
                $excessEntry = $this->ledger->post(new EntryData(
                    company: $locked->company,
                    type: TransactionType::GroupLoanDepositRefunded,
                    lines: [
                        ['account' => $locked->depositLiabilityAccount, 'debit' => $excess],
                        ['account' => $this->chart->branchCash($locked->branch), 'credit' => $excess],
                    ],
                    branch: $locked->branch,
                    paymentMethod: PaymentMethod::Cash,
                    origin: $origin,
                    recordedBy: $appliedBy,
                    recordedAt: now(),
                    description: "Group loan deposit excess refund {$locked->loan_number}",
                    meta: [
                        'customer_id' => $locked->customer_id,
                        'group_loan_id' => $locked->id,
                        'amount' => $excess,
                    ],
                ));

                GroupLoanDeposit::create([
                    'group_loan_id' => $locked->id,
                    'journal_entry_id' => $excessEntry->id,
                    'recorded_by' => $appliedBy->id,
                    'amount' => $excess,
                    'type' => 'refunded',
                    'recorded_at' => now(),
                ]);
            }

            $locked->forceFill(['deposit_status' => DepositStatus::Settled])->save();

            if ($locked->outstanding_balance <= 0) {
                return $this->close->execute($locked->fresh(), $appliedBy, $origin);
            }

            return $locked->fresh();
        });
    }
}
