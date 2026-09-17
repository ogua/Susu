<?php

namespace App\Actions\GroupLoans;

use App\Enums\AccountStatus;
use App\Enums\ClientOrigin;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\LedgerAccount;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a member paying in their agreed security deposit, crediting it
 * straight into a savings account the member already holds — it becomes
 * ordinary savings, indistinguishable from any other deposit once posted.
 * The loan can be activated once the deposit is held. Idempotent on
 * client_reference.
 */
class RecordGroupLoanDepositAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        SavingsAccount $savingsAccount,
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
        if ($savingsAccount->customer_id !== $groupLoan->customer_id) {
            throw ValidationException::withMessages(['savings_account_id' => 'This savings account does not belong to the borrower.']);
        }
        if ($savingsAccount->status === AccountStatus::Closed) {
            throw ValidationException::withMessages(['savings_account_id' => 'This savings account is closed.']);
        }

        return DB::transaction(function () use ($groupLoan, $savingsAccount, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): GroupLoanDepositResult {
            /** @var SavingsAccount $lockedAccount */
            $lockedAccount = SavingsAccount::whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();

            $entry = $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanDepositHeld,
                lines: [
                    ['account' => $this->cashAccount($groupLoan, $paymentMethod), 'debit' => $amount],
                    ['account' => $lockedAccount->ledgerAccount, 'credit' => $amount],
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
                    'savings_account_id' => $lockedAccount->id,
                    'amount' => $amount,
                ],
            ));

            $lockedAccount->forceFill(['balance' => $lockedAccount->balance + $amount])->save();

            $deposit = GroupLoanDeposit::create([
                'group_loan_id' => $groupLoan->id,
                'savings_account_id' => $lockedAccount->id,
                'journal_entry_id' => $entry->id,
                'recorded_by' => $recordedBy->id,
                'amount' => $amount,
                'type' => 'held',
                'recorded_at' => $recordedAt ?? now(),
                'client_reference' => $clientReference,
            ]);

            $groupLoan->forceFill([
                'deposit_status' => DepositStatus::Held,
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
