<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\WithdrawalStatus;
use App\Filament\Pages\RequestWithdrawal;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1000);

    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();
});

it('is only accessible to field agents', function (): void {
    expect(RequestWithdrawal::canAccess())->toBeTrue();

    $this->actingAs($this->manager);
    expect(RequestWithdrawal::canAccess())->toBeFalse();
});

it("submits a withdrawal request for one of the agent's accounts", function (): void {
    livewire(RequestWithdrawal::class)
        ->callAction('requestWithdrawal', data: [
            'savings_account_id' => $this->account->id,
            'amount' => '3.00',
            'reason' => 'Customer needs cash for a medical bill',
        ])
        ->assertHasNoActionErrors();

    $request = WithdrawalRequest::where('savings_account_id', $this->account->id)->first();

    expect($request)->not->toBeNull()
        ->and($request->amount)->toBe(300)
        ->and($request->status)->toBe(WithdrawalStatus::Pending)
        ->and($request->requested_by)->toBe($this->agent->id);
});

it("cannot request a withdrawal for another agent's account", function (): void {
    $otherAgent = User::factory()->fieldAgent($this->branch)->create();
    $this->account->update(['agent_id' => $otherAgent->id]);

    livewire(RequestWithdrawal::class)
        ->callAction('requestWithdrawal', data: [
            'savings_account_id' => $this->account->id,
            'amount' => '3.00',
        ])
        ->assertHasActionErrors();

    expect(WithdrawalRequest::where('savings_account_id', $this->account->id)->exists())->toBeFalse();
});
