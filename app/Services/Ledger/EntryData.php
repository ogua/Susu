<?php

namespace App\Services\Ledger;

use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Everything LedgerService needs to post one balanced journal entry.
 *
 * @phpstan-type Line array{account: \App\Models\LedgerAccount, debit?: int, credit?: int, memo?: string|null}
 */
class EntryData
{
    /**
     * @param  array<int, Line>  $lines
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly Company $company,
        public readonly TransactionType $type,
        public readonly array $lines,
        public readonly ?Branch $branch = null,
        public readonly PaymentMethod $paymentMethod = PaymentMethod::Cash,
        public readonly ClientOrigin $origin = ClientOrigin::Web,
        public readonly ?User $recordedBy = null,
        public readonly ?CarbonInterface $recordedAt = null,
        public readonly ?string $clientReference = null,
        public readonly ?string $description = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly array $meta = [],
    ) {}
}
