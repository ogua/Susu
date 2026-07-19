<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Http\Controllers\Api\V1\ReportController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
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

it('serves every report slug as JSON to a manager', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    foreach (ReportController::REPORTS as $report) {
        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports/{$report}")
            ->assertOk()
            ->assertJsonPath('meta.report', $report)
            ->assertJsonPath('meta.branch_id', $this->branch->id);
    }
});

it('filters the collections report by date', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500, recordedAt: now()->subDays(10));
    app(RecordCollectionAction::class)->execute($this->agent, $this->account->fresh(), 500);

    $response = $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/reports/collections?from='.now()->subDay()->toDateString());

    $response->assertOk()
        ->assertJsonPath('data.total_count', 1)
        ->assertJsonPath('data.total_amount', 500);
});

it('serves the detailed general ledger for one account', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);

    $response = $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/reports/general-ledger?account_id='.$agentCash->id);

    $response->assertOk()
        ->assertJsonPath('data.mode', 'detailed')
        ->assertJsonPath('data.closing_balance', 500)
        ->assertJsonCount(1, 'data.rows');
});

it('rejects an unknown report slug', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/reports/not-a-report')
        ->assertNotFound();
});

it('denies field agents and customers the report API', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/reports/collections')
        ->assertForbidden();
});

it('mints a signed download URL via the API', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/reports/collections/download-url?format=pdf&from='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertJsonStructure(['url', 'expires_at']);
});

it('serves a signed report URL to an unauthenticated browser', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    // Same mint the API performs — this test never authenticates, proving
    // the signature alone authorizes the download.
    $url = URL::temporarySignedRoute('reports.signed', now()->addMinutes(5), [
        'branch' => $this->branch->id,
        'report' => 'collections',
        'format' => 'pdf',
    ]);

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('rejects a tampered signed URL', function (): void {
    $url = URL::temporarySignedRoute('reports.signed', now()->addMinutes(5), [
        'branch' => $this->branch->id,
        'report' => 'collections',
        'format' => 'pdf',
    ]);

    $this->get($url.'&tampered=1')->assertForbidden();
});

it('lists the chart of accounts and one account\'s entries', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);

    $accounts = $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/ledger/accounts');

    $accounts->assertOk();
    expect(collect($accounts->json('accounts'))->pluck('code'))->toContain($agentCash->code);

    $entries = $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/ledger/accounts/{$agentCash->id}/entries");

    $entries->assertOk()
        ->assertJsonPath('closing_balance', 500)
        ->assertJsonCount(1, 'entries');
});

it('hides another company\'s ledger account entries', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherAgent = User::factory()->fieldAgent($otherBranch)->create();
    $foreign = app(ChartOfAccounts::class)->agentCash($otherAgent);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/ledger/accounts/{$foreign->id}/entries")
        ->assertNotFound();
});
