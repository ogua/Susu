<?php

namespace App\Services\Ledger;

use App\Enums\ClientOrigin;
use App\Enums\EntryStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Events\JournalEntryPosted;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only write path into the double-entry ledger.
 *
 * Entries are append-only: corrections are reversal entries, never edits.
 * Cached account balances are updated in the same transaction as the lines,
 * under row locks, so balance == Σ(lines) always holds.
 */
class LedgerService
{
    public function findByClientReference(string $clientReference): ?JournalEntry
    {
        return JournalEntry::where('client_reference', $clientReference)->first();
    }

    public function post(EntryData $data): JournalEntry
    {
        $this->assertBalanced($data);

        // Idempotency: an op replayed from an offline client returns the
        // entry it already produced instead of double-posting.
        if ($data->clientReference !== null) {
            $existing = $this->findByClientReference($data->clientReference);
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data): JournalEntry {
            $accountIds = collect($data->lines)
                ->map(fn (array $line) => $line['account']->id)
                ->sort() // deterministic lock order prevents deadlocks
                ->values();

            $locked = LedgerAccount::whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $entry = JournalEntry::create([
                'company_id' => $data->company->id,
                'branch_id' => $data->branch?->id,
                'reference' => (string) Str::ulid(),
                'client_reference' => $data->clientReference,
                'origin' => $data->origin,
                'type' => $data->type,
                'status' => EntryStatus::Completed,
                'payment_method' => $data->paymentMethod,
                'description' => $data->description,
                'recorded_by' => $data->recordedBy?->id,
                'recorded_at' => $data->recordedAt ?? now(),
                'posted_at' => now(),
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
                'meta' => $data->meta ?: null,
            ]);

            foreach ($data->lines as $line) {
                $debit = $line['debit'] ?? 0;
                $credit = $line['credit'] ?? 0;

                $entry->lines()->create([
                    'ledger_account_id' => $line['account']->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $line['memo'] ?? null,
                ]);

                $account = $locked[$line['account']->id];
                $delta = $account->type->normalBalance() === 'debit'
                    ? $debit - $credit
                    : $credit - $debit;
                $account->increment('balance', $delta);
            }

            event(new JournalEntryPosted($entry));

            return $entry;
        });
    }

    /**
     * Post an equal-and-opposite entry and mark the original reversed.
     */
    public function reverse(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        if ($entry->status === EntryStatus::Reversed) {
            throw new InvalidArgumentException('Entry has already been reversed.');
        }

        $entry->loadMissing('lines.account', 'company', 'branch');

        $reversal = $this->post(new EntryData(
            company: $entry->company,
            type: TransactionType::Reversal,
            lines: $entry->lines->map(fn ($line): array => [
                'account' => $line->account,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => 'Reversal: '.$reason,
            ])->all(),
            branch: $entry->branch,
            paymentMethod: PaymentMethod::Internal,
            origin: ClientOrigin::System,
            recordedBy: $reversedBy,
            description: $reason,
            meta: ['reverses' => $entry->id] + ($entry->meta ?? []),
        ));

        $reversal->update(['reversed_entry_id' => $entry->id]);
        $entry->update(['status' => EntryStatus::Reversed]);

        return $reversal;
    }

    /** Recompute a balance from the lines (integrity checks, tests). */
    public function recomputeBalance(LedgerAccount $account): int
    {
        $sums = $account->lines()
            ->selectRaw('COALESCE(SUM(debit),0) AS debits, COALESCE(SUM(credit),0) AS credits')
            ->first();

        return $account->type->normalBalance() === 'debit'
            ? (int) $sums->debits - (int) $sums->credits
            : (int) $sums->credits - (int) $sums->debits;
    }

    private function assertBalanced(EntryData $data): void
    {
        if (count($data->lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $debits = 0;
        $credits = 0;

        foreach ($data->lines as $line) {
            $debit = $line['debit'] ?? 0;
            $credit = $line['credit'] ?? 0;

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Line amounts must be non-negative.');
            }
            if (($debit > 0) === ($credit > 0)) {
                throw new InvalidArgumentException('Each line must have exactly one non-zero side.');
            }
            if ($line['account']->company_id !== $data->company->id) {
                throw new InvalidArgumentException('All accounts must belong to the posting company.');
            }

            $debits += $debit;
            $credits += $credit;
        }

        if ($debits !== $credits || $debits === 0) {
            throw new InvalidArgumentException(
                sprintf('Entry is not balanced: debits %d vs credits %d.', $debits, $credits)
            );
        }
    }
}
