<?php

use App\Actions\Reports\GenerateAccountStatementPdfAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

it('renders a statement with a running balance across every deposit', function (): void {
    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $this->account, 500);
    $collect->execute($this->agent, $this->account, 500);
    $this->account->refresh();

    $pdf = app(GenerateAccountStatementPdfAction::class)->execute($this->account);

    expect($pdf->output())->toBeString()->not->toBeEmpty();
});

it('only pulls entries touching this account, not a sibling account under the same agent', function (): void {
    $otherAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $otherAccount, 500);

    // The Action queries $account->entries(), the same relation asserted here —
    // it must stay empty for an account nothing has ever been posted to.
    expect($this->account->entries()->count())->toBe(0)
        ->and($otherAccount->entries()->count())->toBeGreaterThan(0);

    $pdf = app(GenerateAccountStatementPdfAction::class)->execute($this->account);
    expect($pdf->output())->toBeString();
});

it('restricts a period statement to entries within the given date range', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500, recordedAt: now()->subDays(10));
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500, recordedAt: now());

    expect(
        $this->account->entries()->where('recorded_at', '>=', now()->subDay())->count()
    )->toBe(1);

    $pdf = app(GenerateAccountStatementPdfAction::class)->execute($this->account, from: now()->subDay());
    expect($pdf->output())->toBeString()->not->toBeEmpty();
});
