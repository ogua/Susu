<?php

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();
    $this->company = Company::factory()->create();
    $this->cash = LedgerAccount::factory()->create(['company_id' => $this->company->id]);
    $this->liability = LedgerAccount::factory()->liability()->create(['company_id' => $this->company->id]);
    $this->ledger = app(LedgerService::class);
});

it('posts a balanced entry and updates cached balances by normal balance', function (): void {
    $entry = $this->ledger->post(new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 1000],
            ['account' => $this->liability, 'credit' => 1000],
        ],
    ));

    expect($entry->lines)->toHaveCount(2)
        ->and($this->cash->refresh()->balance)->toBe(1000)
        ->and($this->liability->refresh()->balance)->toBe(1000)
        ->and($this->ledger->recomputeBalance($this->cash))->toBe(1000)
        ->and($this->ledger->recomputeBalance($this->liability))->toBe(1000);
});

it('rejects unbalanced entries', function (): void {
    $this->ledger->post(new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 1000],
            ['account' => $this->liability, 'credit' => 900],
        ],
    ));
})->throws(InvalidArgumentException::class, 'not balanced');

it('rejects lines with both sides set', function (): void {
    $this->ledger->post(new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 500, 'credit' => 500],
            ['account' => $this->liability, 'credit' => 0],
        ],
    ));
})->throws(InvalidArgumentException::class);

it('is idempotent on client_reference', function (): void {
    $reference = (string) Str::uuid();
    $data = new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 700],
            ['account' => $this->liability, 'credit' => 700],
        ],
        clientReference: $reference,
    );

    $first = $this->ledger->post($data);
    $second = $this->ledger->post($data);

    expect($second->id)->toBe($first->id)
        ->and($this->cash->refresh()->balance)->toBe(700);
});

it('reverses an entry with opposite lines and marks the original', function (): void {
    $user = User::factory()->create(['company_id' => $this->company->id]);

    $entry = $this->ledger->post(new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 1200],
            ['account' => $this->liability, 'credit' => 1200],
        ],
    ));

    $reversal = $this->ledger->reverse($entry, $user, 'recorded in error');

    expect($reversal->type)->toBe(TransactionType::Reversal)
        ->and($reversal->reversed_entry_id)->toBe($entry->id)
        ->and($entry->refresh()->status)->toBe(EntryStatus::Reversed)
        ->and($this->cash->refresh()->balance)->toBe(0)
        ->and($this->liability->refresh()->balance)->toBe(0);
});

it('refuses to reverse twice', function (): void {
    $user = User::factory()->create(['company_id' => $this->company->id]);

    $entry = $this->ledger->post(new EntryData(
        company: $this->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $this->cash, 'debit' => 100],
            ['account' => $this->liability, 'credit' => 100],
        ],
    ));

    $this->ledger->reverse($entry, $user, 'first');
    $this->ledger->reverse($entry->refresh(), $user, 'second');
})->throws(InvalidArgumentException::class, 'already been reversed');
