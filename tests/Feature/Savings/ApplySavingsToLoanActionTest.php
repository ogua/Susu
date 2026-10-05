<?php

use App\Actions\Loans\ApplySavingsToLoanAction;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->savingsAccount = SavingsAccount::factory()->funded(500_00)->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
    ]);

    $this->receivableAccount = LedgerAccount::factory()->create(['company_id' => $this->branch->company_id]);
});

it('debits savings and credits the receivable account for the applied amount', function (): void {
    $entry = app(ApplySavingsToLoanAction::class)->execute(
        savingsAccount: $this->savingsAccount,
        receivableAccount: $this->receivableAccount,
        amount: 200_00,
        appliedBy: $this->manager,
        transactionType: TransactionType::SavingsAppliedToLoanWriteOff,
        description: 'Test apply',
    );

    // The factory sets SavingsAccount.balance (500_00) directly with no
    // backing journal line, so its ledger account starts at 0 rather than
    // 500_00 — both ledger accounts below started at 0 with no prior lines,
    // so a Dr/Cr of 200_00 moves each by exactly that much in its normal
    // direction (Liability debited = down; Asset credited = down).
    expect($entry->type)->toBe(TransactionType::SavingsAppliedToLoanWriteOff)
        ->and($this->savingsAccount->fresh()->balance)->toBe(300_00)
        ->and($this->savingsAccount->ledgerAccount->refresh()->balance)->toBe(300_00)
        ->and($this->receivableAccount->refresh()->balance)->toBe(-200_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('rejects an amount greater than the savings account balance', function (): void {
    expect(fn () => app(ApplySavingsToLoanAction::class)->execute(
        savingsAccount: $this->savingsAccount,
        receivableAccount: $this->receivableAccount,
        amount: 600_00,
        appliedBy: $this->manager,
        transactionType: TransactionType::SavingsAppliedToLoanWriteOff,
        description: 'Test apply',
    ))->toThrow(ValidationException::class);
});

it('rejects a zero or negative amount', function (): void {
    expect(fn () => app(ApplySavingsToLoanAction::class)->execute(
        savingsAccount: $this->savingsAccount,
        receivableAccount: $this->receivableAccount,
        amount: 0,
        appliedBy: $this->manager,
        transactionType: TransactionType::SavingsAppliedToLoanWriteOff,
        description: 'Test apply',
    ))->toThrow(ValidationException::class);
});
