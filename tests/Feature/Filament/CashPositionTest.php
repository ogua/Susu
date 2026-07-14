<?php

use App\Actions\Agents\RecordAgentRemittanceAction;
use App\Enums\TransactionType;
use App\Filament\Pages\CashPosition;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $chart = app(ChartOfAccounts::class);
    $counter = LedgerAccount::factory()->liability()->create(['company_id' => $this->branch->company_id]);

    app(LedgerService::class)->post(new EntryData(
        company: $this->branch->company,
        type: TransactionType::Collection,
        lines: [
            ['account' => $chart->agentCash($this->agent), 'debit' => 500_00],
            ['account' => $counter, 'credit' => 500_00],
        ],
        branch: $this->branch,
    ));

    app(RecordAgentRemittanceAction::class)->execute($this->agent, 200_00);
});

it('shows the branch cash account and every agent cash-in-hand account for the tenant branch', function (): void {
    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(CashPosition::class)
        ->assertOk()
        ->assertSee($this->branch->name.' Cash')
        ->assertSee($this->agent->name.' Cash In Hand');

    $page = app(CashPosition::class);
    expect($page->totalCash())->toBe(500_00);
});

it('excludes cash accounts from other branches', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherManager = User::factory()->branchManager($otherBranch)->create();

    $this->actingAs($otherManager);
    bootAdminPanelWithTenant($otherBranch);

    livewire(CashPosition::class)
        ->assertOk()
        ->assertDontSee($this->agent->name.' Cash In Hand');
});

it('denies field agents access to the cash position report', function (): void {
    $this->actingAs($this->agent);

    expect(CashPosition::canAccess())->toBeFalse();
});
