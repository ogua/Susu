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
 * Closes a fully-repaid group loan. Any security deposit still held at this
 * point has no balance left to offset, so it is refunded to the member in
 * cash (Dr deposit liability / Cr branch cash). Called automatically by
 * RecordGroupLoanRepaymentAction and ApplyGroupLoanDepositAction when the
 * outstanding balance reaches zero.
 */
class CloseGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(GroupLoan $groupLoan, User $closedBy, ClientOrigin $origin = ClientOrigin::Web): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Only an active group loan can be closed.']);
        }
        if ($groupLoan->outstanding_balance > 0) {
            throw ValidationException::withMessages(['status' => 'This group loan still has an outstanding balance.']);
        }

        return DB::transaction(function () use ($groupLoan, $closedBy, $origin): GroupLoan {
            $this->refundHeldDeposit($groupLoan, $closedBy, $origin);

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::Closed,
                'closed_at' => now(),
            ])->save();

            return $groupLoan->fresh();
        });
    }

    /** Refund a still-held deposit in cash. No-op if the deposit was already settled (applied/seized). */
    public function refundHeldDeposit(GroupLoan $groupLoan, User $recordedBy, ClientOrigin $origin = ClientOrigin::Web): void
    {
        if ($groupLoan->deposit_status !== DepositStatus::Held || $groupLoan->security_deposit_amount <= 0) {
            return;
        }

        $amount = $groupLoan->security_deposit_amount;

        $entry = $this->ledger->post(new EntryData(
            company: $groupLoan->company,
            type: TransactionType::GroupLoanDepositRefunded,
            lines: [
                ['account' => $groupLoan->depositLiabilityAccount, 'debit' => $amount],
                ['account' => $this->chart->branchCash($groupLoan->branch), 'credit' => $amount],
            ],
            branch: $groupLoan->branch,
            paymentMethod: PaymentMethod::Cash,
            origin: $origin,
            recordedBy: $recordedBy,
            recordedAt: now(),
            description: "Group loan deposit refund {$groupLoan->loan_number}",
            meta: [
                'customer_id' => $groupLoan->customer_id,
                'group_loan_id' => $groupLoan->id,
                'amount' => $amount,
            ],
        ));

        GroupLoanDeposit::create([
            'group_loan_id' => $groupLoan->id,
            'journal_entry_id' => $entry->id,
            'recorded_by' => $recordedBy->id,
            'amount' => $amount,
            'type' => 'refunded',
            'recorded_at' => now(),
        ]);

        $groupLoan->forceFill(['deposit_status' => DepositStatus::Settled])->save();
    }
}
