<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use App\Filament\Resources\SavingsProducts\Pages\CreateSavingsProduct;
use App\Filament\Resources\SavingsProducts\Pages\EditSavingsProduct;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->companyAdmin($this->branch->company)->create();

    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();
});

it('shows only the fields that apply to each product type', function (string $type, array $visible, array $hidden): void {
    $page = livewire(CreateSavingsProduct::class)->fillForm(['type' => $type]);

    foreach ($visible as $field) {
        $page->assertFormFieldVisible($field);
    }
    foreach ($hidden as $field) {
        $page->assertFormFieldHidden($field);
    }
})->with([
    'daily susu' => ['daily_susu', ['contribution_amount', 'cycle_length_days', 'commission_type'], ['early_withdrawal_penalty_bps', 'interest_rate_bps', 'par_value']],
    'target' => ['target', ['contribution_amount', 'cycle_length_days', 'commission_type', 'early_withdrawal_penalty_bps'], ['interest_rate_bps', 'par_value']],
    'fixed deposit' => ['fixed_deposit', ['contribution_amount', 'interest_rate_bps'], ['cycle_length_days', 'commission_type', 'commission_value', 'early_withdrawal_penalty_bps', 'par_value']],
    'shares' => ['shares', ['par_value'], ['contribution_amount', 'cycle_length_days', 'commission_type', 'commission_value', 'early_withdrawal_penalty_bps', 'interest_rate_bps']],
]);

it('only asks for a commission value when the commission type uses one', function (): void {
    livewire(CreateSavingsProduct::class)
        ->fillForm(['type' => 'daily_susu', 'commission_type' => 'none'])
        ->assertFormFieldHidden('commission_value')
        ->fillForm(['commission_type' => 'first_contribution_per_cycle'])
        ->assertFormFieldHidden('commission_value')
        ->fillForm(['commission_type' => 'percentage'])
        ->assertFormFieldVisible('commission_value');
});

it('creates a fixed deposit product with its interest rate and no commission', function (): void {
    livewire(CreateSavingsProduct::class)
        ->fillForm([
            'name' => '12-Month Fixed',
            'code' => 'FD-12',
            'type' => 'fixed_deposit',
            'contribution_amount' => '1000.00',
            'interest_rate_bps' => 1500,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = SavingsProduct::where('code', 'FD-12')->firstOrFail();
    expect($product->type)->toBe(SavingsProductType::FixedDeposit)
        ->and($product->contribution_amount)->toBe(1000_00)
        ->and($product->interest_rate_bps)->toBe(1500)
        ->and($product->commission_type)->toBe(CommissionType::None);
});

it('creates a shares product from its par value alone', function (): void {
    livewire(CreateSavingsProduct::class)
        ->fillForm([
            'name' => 'Share Capital',
            'code' => 'SHR',
            'type' => 'shares',
            'par_value' => '20.00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = SavingsProduct::where('code', 'SHR')->firstOrFail();
    expect($product->par_value)->toBe(20_00)
        ->and($product->contribution_amount)->toBe(0)
        ->and($product->commission_type)->toBe(CommissionType::None);
});

it('requires a par value for shares products', function (): void {
    livewire(CreateSavingsProduct::class)
        ->fillForm(['name' => 'Shares', 'code' => 'SHR', 'type' => 'shares', 'par_value' => null])
        ->call('create')
        ->assertHasFormErrors(['par_value' => 'required']);
});

it('takes the flat per-cycle commission in GHS and stores pesewas', function (): void {
    livewire(CreateSavingsProduct::class)
        ->fillForm([
            'name' => 'Flat Susu',
            'code' => 'FLAT',
            'type' => 'daily_susu',
            'contribution_amount' => '10.00',
            'cycle_length_days' => 31,
            'commission_type' => 'flat_per_cycle',
            'commission_value' => '5.00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = SavingsProduct::where('code', 'FLAT')->firstOrFail();
    expect($product->commission_value)->toBe(500);

    livewire(EditSavingsProduct::class, ['record' => $product->id])
        ->assertSchemaStateSet(['commission_value' => 5]);
});

it('clears commission when a susu product is changed to a fixed deposit', function (): void {
    $product = SavingsProduct::factory()->percentageCommission(300)->create(['company_id' => $this->branch->company_id]);

    $product->update(['type' => SavingsProductType::FixedDeposit, 'interest_rate_bps' => 1200]);

    expect($product->fresh()->commission_type)->toBe(CommissionType::None)
        ->and($product->fresh()->commission_value)->toBe(0);
});

it('rejects susu collections on a shares account', function (): void {
    $product = SavingsProduct::factory()->shares()->create(['company_id' => $this->branch->company_id]);
    $account = SavingsAccount::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'savings_product_id' => $product->id,
        'contribution_amount' => 10_00,
    ]);

    app(RecordCollectionAction::class)->execute($this->admin, $account, 10_00);
})->throws(ValidationException::class, 'Share accounts are funded by buying shares');
