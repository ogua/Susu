<?php

use App\Actions\Customers\DeleteCustomerAction;
use App\Actions\Savings\CloseSavingsAccountAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\AccountStatus;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\SavingsAccounts\Pages\EditSavingsAccount;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
});

function accountFor(Customer $customer, int $funded = 0): SavingsAccount
{
    $factory = $funded > 0 ? SavingsAccount::factory()->funded($funded) : SavingsAccount::factory();

    return $factory->create([
        'branch_id' => $customer->branch_id,
        'company_id' => $customer->company_id,
        'customer_id' => $customer->id,
    ])->refresh();
}

it('closes an empty account', function (): void {
    $account = accountFor($this->customer);

    app(CloseSavingsAccountAction::class)->execute($account, $this->manager);

    expect($account->refresh()->status)->toBe(AccountStatus::Closed)
        ->and($account->closed_at)->not->toBeNull();
});

it('refuses to close an account that still holds money', function (): void {
    $account = accountFor($this->customer, 1000);

    expect(fn () => app(CloseSavingsAccountAction::class)->execute($account, $this->manager))
        ->toThrow(ValidationException::class);
    expect($account->refresh()->status)->toBe(AccountStatus::Active);
});

it('refuses to close an account with a withdrawal in progress', function (): void {
    $account = accountFor($this->customer, 1000);
    app(RequestWithdrawalAction::class)->execute($this->manager, $account, 1000);
    $account->forceFill(['balance' => 0])->save();

    expect(app(CloseSavingsAccountAction::class)->blockingReason($account->refresh()))
        ->toBe('This account has a withdrawal request in progress.');
});

it('closes an account from the edit page and no longer offers delete', function (): void {
    $account = accountFor($this->customer);
    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(EditSavingsAccount::class, ['record' => $account->id])
        ->assertActionDoesNotExist(DeleteAction::class)
        ->callAction('close')
        ->assertNotified('Account closed');

    expect($account->refresh()->status)->toBe(AccountStatus::Closed);
});

it('does not let the edit form close a funded account or change its agent', function (): void {
    $account = accountFor($this->customer, 1000);
    $otherAgent = User::factory()->fieldAgent($this->branch)->create();
    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(EditSavingsAccount::class, ['record' => $account->id])
        ->fillForm(['status' => AccountStatus::Closed->value, 'agent_id' => $otherAgent->id])
        ->call('save');

    expect($account->refresh()->status)->not->toBe(AccountStatus::Closed)
        ->and($account->agent_id)->not->toBe($otherAgent->id);
});

it('blocks deleting a customer who still has an open account', function (): void {
    accountFor($this->customer);

    expect(fn () => app(DeleteCustomerAction::class)->execute($this->customer))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(EditCustomer::class, ['record' => $this->customer->id])
        ->callAction(DeleteAction::class)
        ->assertNotified('Customer not deleted');

    expect($this->customer->fresh()->trashed())->toBeFalse();
});

it('deletes a customer once everything is closed', function (): void {
    $account = accountFor($this->customer);
    app(CloseSavingsAccountAction::class)->execute($account, $this->manager);

    app(DeleteCustomerAction::class)->execute($this->customer);

    expect($this->customer->fresh()->trashed())->toBeTrue();
});
