<?php

namespace Database\Factories;

use App\Enums\ClientOrigin;
use App\Enums\EntryStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JournalEntry>
 *
 * NOTE: prefer posting through LedgerService in tests — it keeps cached
 * balances equal to the ledger. This factory is for shape-only needs.
 */
class JournalEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'reference' => (string) Str::ulid(),
            'origin' => ClientOrigin::System,
            'type' => TransactionType::Adjustment,
            'status' => EntryStatus::Completed,
            'payment_method' => PaymentMethod::Internal,
            'recorded_at' => now(),
            'posted_at' => now(),
        ];
    }
}
