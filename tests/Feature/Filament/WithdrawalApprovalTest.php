<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\WithdrawalStatus;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $customerUser = app(ProvisionCustomerLoginAction::class)->execute($customer, 'password');
    $this->request = app(RequestWithdrawalAction::class)->execute(
        $customerUser, $this->account->fresh(), 500
    );

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();
});

it('approves and pays a withdrawal from the table actions', function (): void {
    livewire(ListWithdrawalRequests::class)
        ->callAction(TestAction::make('approve')->table($this->request))
        ->assertHasNoActionErrors();

    expect($this->request->refresh()->status)->toBe(WithdrawalStatus::Approved);

    livewire(ListWithdrawalRequests::class)
        ->callAction(TestAction::make('pay')->table($this->request->fresh()))
        ->assertHasNoActionErrors();

    expect($this->request->refresh()->status)->toBe(WithdrawalStatus::Paid);
});

it('denies a field agent from approving withdrawals', function (): void {
    $this->actingAs($this->agent);

    livewire(ListWithdrawalRequests::class)
        ->assertActionHidden(TestAction::make('approve')->table($this->request));
});
