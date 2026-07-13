<?php

use App\Enums\PaymentIntentStatus;
use App\Filament\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Resources\PaymentIntents\PaymentIntentResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
    ]);

    $this->intent = PaymentIntent::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'payable_id' => $this->account->id,
        'payable_type' => SavingsAccount::class,
        'initiated_by' => $this->agent->id,
        'status' => PaymentIntentStatus::PayOffline,
    ]);
});

it('renders the payment intents list for a branch manager, scoped to their branch', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();

    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherIntent = PaymentIntent::factory()->create(['branch_id' => $otherBranch->id, 'company_id' => $otherBranch->company_id]);

    $this->actingAs($manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListPaymentIntents::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->intent])
        ->assertCanNotSeeTableRecords([$otherIntent]);
});

it('lets a branch manager re-verify a payment intent from the table action', function (): void {
    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => $this->intent->client_reference],
        ]),
    ]);

    $manager = User::factory()->branchManager($this->branch)->create();

    $this->actingAs($manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListPaymentIntents::class)
        ->assertOk()
        ->callAction(TestAction::make('verify')->table($this->intent))
        ->assertNotified();

    expect($this->intent->refresh()->status)->toBe(PaymentIntentStatus::Success)
        ->and($this->intent->journal_entry_id)->not->toBeNull();
});

it('denies field agents access to the payment intents list', function (): void {
    expect(PaymentIntentResource::canViewAny())->toBeFalse();

    $this->actingAs($this->agent);
    expect(PaymentIntentResource::canViewAny())->toBeFalse();
});
