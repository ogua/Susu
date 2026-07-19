<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('registers a customer via the direct API endpoint with a legacy-shaped payload (no nested arrays)', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/customers', [
        'first_name' => 'Efua',
        'last_name' => 'Asante',
        'phone' => '0244777888',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.first_name', 'Efua')
        ->assertJsonPath('data.last_name', 'Asante');

    $customer = Customer::where('phone', '0244777888')->firstOrFail();
    expect($customer->branch_id)->toBe($this->branch->id)
        ->and($customer->client_type->value)->toBe('individual');
});

it('accepts the new eBanQR-parity fields including nested identifications and beneficiaries', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/customers', [
        'first_name' => 'Kwabena',
        'last_name' => 'Owusu',
        'phone' => '0244777999',
        'client_type' => 'individual',
        'nationality' => 'Ghanaian',
        'email' => 'kwabena@example.test',
        'identifications' => [
            ['id_type' => 'ghana_card', 'id_number' => 'GHA-111222333-4', 'issue_date' => '2026-01-01', 'is_primary' => true],
        ],
        'beneficiaries' => [
            ['name' => 'Efua Owusu', 'relationship' => 'wife', 'amount_of_legacy' => 200],
        ],
    ]);

    $response->assertCreated()->assertJsonPath('data.client_type', 'individual');

    $customer = Customer::where('phone', '0244777999')->firstOrFail();
    expect($customer->nationality)->toBe('Ghanaian')
        ->and($customer->identifications)->toHaveCount(1)
        ->and($customer->beneficiaries)->toHaveCount(1);
});

it('requires business_name/business_structure/business_start_date when client_type is business', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/customers', [
        'first_name' => 'Yaw',
        'last_name' => 'Boateng',
        'phone' => '0244778000',
        'client_type' => 'business',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['business_name', 'business_structure', 'business_start_date']);
});

it('rejects a customer registration from a role without access', function (): void {
    $customerUser = User::factory()->customerUser($this->branch->company)->create();

    $this->actingAs($customerUser, 'sanctum')->postJson('/api/v1/agent/customers', [
        'first_name' => 'Efua',
        'last_name' => 'Asante',
        'phone' => '0244777888',
    ])->assertForbidden();
});
