<?php

use App\Models\Branch;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('includes the company-wide active product catalogue in bootstrap', function (): void {
    $active = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'is_active' => true,
    ]);
    $inactive = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'is_active' => false,
    ]);
    // Another company's product must never leak into this agent's catalogue.
    $otherCompanyProduct = SavingsProduct::factory()->create(['is_active' => true]);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/sync/bootstrap');

    $response->assertOk();
    $ids = collect($response->json('products'))->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id)
        ->and($ids)->not->toContain($otherCompanyProduct->id);
});
