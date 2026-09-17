<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an amount from a customer's savings account straight onto a loan's
 * receivable account — a non-cash book transfer (Dr savings liability / Cr
 * receivable), no knowledge of Loan vs GroupLoan required. Used by the two
 * write-off actions to let a borrower's own savings offset what would
 * otherwise be booked as bad debt.
 */
class ApplySavingsToLoanAction
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function execute(
        SavingsAccount $savingsAccount,
        LedgerAccount $receivableAccount,
        int $amount,
        User $appliedBy,
        TransactionType $transactionType,
        string $description,
        array $meta = [],
        ClientOrigin $origin = ClientOrigin::Web,
    ): JournalEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'savings_amount_applied' => 'The amount to apply must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($savingsAccount, $receivableAccount, $amount, $appliedBy, $transactionType, $description, $meta, $origin): JournalEntry {
            /** @var SavingsAccount $locked */
            $locked = SavingsAccount::whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();

            if ($amount > $locked->balance) {
                throw ValidationException::withMessages([
                    'savings_amount_applied' => 'This savings account does not have enough balance.',
                ]);
            }

            $entry = $this->ledger->post(new EntryData(
                company: $locked->company,
                type: $transactionType,
                lines: [
                    ['account' => $locked->ledgerAccount, 'debit' => $amount],
                    ['account' => $receivableAccount, 'credit' => $amount],
                ],
                branch: $locked->branch,
                paymentMethod: PaymentMethod::Internal,
                origin: $origin,
                recordedBy: $appliedBy,
                recordedAt: now(),
                description: $description,
                meta: $meta,
            ));

            $locked->forceFill(['balance' => $locked->balance - $amount])->save();

            return $entry;
        });
    }
}
