<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Filament\Resources\AgentDailySummaries\Pages\ListAgentDailySummaries;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\SavingsAccounts\Pages\ListSavingsAccounts;
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
    $this->otherAgent = User::factory()->fieldAgent($this->branch)->create();
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
        'agent_id' => $this->otherAgent->id,
        'contribution_amount' => 500,
    ]);
});

it('denies a field agent from recording a collection on another agent\'s account', function (): void {
    $this->actingAs($this->agent);

    expect($this->agent->can('recordCollection', $this->account))->toBeFalse()
        ->and($this->agent->can('buyShares', $this->account))->toBeFalse();
});

it('lets a field agent record a collection on their own account', function (): void {
    $this->account->update(['agent_id' => $this->agent->id]);

    $this->actingAs($this->agent);

    expect($this->agent->can('recordCollection', $this->account))->toBeTrue();
});

it('hides the record collection action in the table for accounts the agent does not own', function (): void {
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListSavingsAccounts::class)
        ->assertActionHidden(TestAction::make('recordCollection')->table($this->account));
});

it('lets a manager record a collection on any account in the branch', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListSavingsAccounts::class)
        ->callAction(TestAction::make('recordCollection')->table($this->account), data: ['amount' => '5.00'])
        ->assertHasNoActionErrors();

    expect($this->account->fresh()->balance)->toBe(500);
});

it('denies a field agent from viewing the branch day-close list', function (): void {
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListAgentDailySummaries::class)->assertForbidden();
});

it('lets a branch manager view the branch day-close list', function (): void {
    app(RecordCollectionAction::class)->execute($this->otherAgent, $this->account, 500);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListAgentDailySummaries::class)->assertSuccessful();
});

it('denies a field agent from viewing the branch journal entries list', function (): void {
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListJournalEntries::class)->assertForbidden();
});

it('lets a branch manager view the branch journal entries list', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListJournalEntries::class)->assertSuccessful();
});
