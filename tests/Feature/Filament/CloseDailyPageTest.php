<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\AgentSummaryStatus;
use App\Filament\Pages\CloseDaily;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
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

    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();
});

it('is only accessible to field agents', function (): void {
    expect(CloseDaily::canAccess())->toBeTrue();

    $this->actingAs($this->manager);
    expect(CloseDaily::canAccess())->toBeFalse();
});

it('submits a day sheet from the close day action', function (): void {
    livewire(CloseDaily::class)
        ->callAction('closeDay', data: ['declared_cash' => '5.00'])
        ->assertHasNoActionErrors();

    $summary = AgentDailySummary::where('agent_id', $this->agent->id)->first();

    expect($summary->expected_cash)->toBe(500)
        ->and($summary->declared_cash)->toBe(500)
        ->and($summary->variance)->toBe(0)
        ->and($summary->status)->toBe(AgentSummaryStatus::Submitted);
});

it('reports a variance when declared cash does not match expected cash', function (): void {
    livewire(CloseDaily::class)
        ->callAction('closeDay', data: ['declared_cash' => '4.00'])
        ->assertHasNoActionErrors();

    $summary = AgentDailySummary::where('agent_id', $this->agent->id)->first();

    expect($summary->variance)->toBe(-100);
});
