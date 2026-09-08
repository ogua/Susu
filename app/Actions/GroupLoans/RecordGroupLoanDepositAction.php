<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a member paying in their agreed security deposit. The cash comes in
 * and is parked as a per-loan liability (money owed back to the member) — it
 * is not income and not a loan repayment. The loan can be activated once the
 * deposit is held. Idempotent on client_reference.
 */
class RecordGroupLoanDepositAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        int $amount,
        User $recordedBy,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): GroupLoanDepositResult {
        if ($clientReference !== null) {
            $existing = GroupLoanDeposit::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return new GroupLoanDepositResult($existing->journalEntry, $existing->groupLoan, $existing, duplicate: true);
            }
        }

        if ($groupLoan->status !== GroupLoanStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'A deposit can only be recorded while the loan is a draft.']);
        }
        if ($groupLoan->deposit_status !== DepositStatus::Pending) {
            throw ValidationException::withMessages(['deposit_status' => 'This loan\'s security deposit has already been settled.']);
        }
        if ($amount <= 0 || $amount !== $groupLoan->security_deposit_amount) {
            throw ValidationException::withMessages([
                'amount' => 'The deposit must be paid in full ('.$groupLoan->security_deposit_amount.' pesewas).',
            ]);
        }

        return DB::transaction(function () use ($groupLoan, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): GroupLoanDepositResult {
            $depositAccount = $this->chart->groupLoanDepositLiability($groupLoan);

            $entry = $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanDepositHeld,
                lines: [
                    ['account' => $this->cashAccount($groupLoan, $paymentMethod), 'debit' => $amount],
                    ['account' => $depositAccount, 'credit' => $amount],
                ],
                branch: $groupLoan->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $recordedAt ?? now(),
                clientReference: $clientReference,
                description: "Group loan security deposit {$groupLoan->loan_number}",
                meta: [
                    'customer_id' => $groupLoan->customer_id,
                    'group_loan_id' => $groupLoan->id,
                    'amount' => $amount,
                ],
            ));

            $deposit = GroupLoanDeposit::create([
                'group_loan_id' => $groupLoan->id,
                'journal_entry_id' => $entry->id,
                'recorded_by' => $recordedBy->id,
                'amount' => $amount,
                'type' => 'held',
                'recorded_at' => $recordedAt ?? now(),
                'client_reference' => $clientReference,
            ]);

            $groupLoan->forceFill([
                'deposit_status' => DepositStatus::Held,
                'deposit_liability_account_id' => $depositAccount->id,
            ])->save();

            return new GroupLoanDepositResult($entry, $groupLoan->fresh(), $deposit, duplicate: false);
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
