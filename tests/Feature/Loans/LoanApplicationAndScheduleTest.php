<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\LoanApplicationDetails;
use App\Actions\Loans\RecalculateRepaymentScheduleAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Enums\InstallmentStatus;
use App\Enums\LoanFrequency;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
    $this->guarantor = Customer::factory()->forBranch($this->branch)->create();
    $this->product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'repayment_frequency' => 'weekly',
        'term_period_count' => 4,
        'origination_fee_amount' => 10_00,
    ]);
});

function disbursedLoan(User $agent, User $manager, Customer $customer, LoanProduct $product, ?LoanApplicationDetails $details = null): Loan
{
    $loan = app(ApplyForLoanAction::class)->execute($agent, $customer, $product, 400_00, details: $details);
    app(ApproveLoanAction::class)->execute($loan, $manager);

    return app(DisburseLoanAction::class)->execute($loan->fresh(), $manager);
}

it('stores overrides, itemised charges, collateral and guarantors from an application', function (): void {
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->product, 400_00, details: LoanApplicationDetails::fromArray([
        'term_period_count' => 8,
        'interest_rate_bps' => 250,
        'purpose' => 'Restock shop',
        'charges' => [['name' => 'Processing fee', 'amount' => 10_00], ['name' => 'Insurance', 'amount' => 5_00]],
        'collaterals' => [['type' => 'Vehicle', 'description' => 'Motorbike', 'estimated_value' => 3000_00]],
        'guarantors' => [['name' => 'Kwame Asare', 'phone' => '0244000000', 'customer_id' => $this->guarantor->id]],
    ]));

    expect($loan->fresh())
        ->term_period_count->toBe(8)
        ->interest_rate_bps->toBe(250)
        ->origination_fee_amount->toBe(15_00)
        ->purpose->toBe('Restock shop')
        ->guarantor_name->toBe('Kwame Asare')
        ->and($loan->charges)->toHaveCount(2)
        ->and($loan->collaterals->first()->estimated_value)->toBe(3000_00)
        ->and($loan->guarantors->first()->customer_id)->toBe($this->guarantor->id);
});

it('snapshots the product fee as a charge when none are itemised', function (): void {
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->product, 400_00);

    expect($loan->charges()->pluck('amount')->all())->toBe([10_00])
        ->and($loan->origination_fee_amount)->toBe(10_00);
});

it('rejects term overrides from a customer and self-guarantees', function (): void {
    $customerUser = User::factory()->customerUser($this->branch->company)->create();

    expect(fn () => app(ApplyForLoanAction::class)->execute($customerUser, $this->customer, $this->product, 400_00, details: new LoanApplicationDetails(termPeriodCount: 12)))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->product, 400_00, details: LoanApplicationDetails::fromArray([
            'guarantors' => [['name' => 'Me', 'customer_id' => $this->customer->id]],
        ])))->toThrow(ValidationException::class);
});

it('schedules the first installment on the chosen first repayment date', function (): void {
    $firstDate = today()->addDays(10);

    $loan = disbursedLoan($this->agent, $this->manager, $this->customer, $this->product, new LoanApplicationDetails(firstRepaymentDate: $firstDate));

    expect($loan->installments->first()->due_date->toDateString())->toBe($firstDate->toDateString())
        ->and($loan->installments->last()->due_date->toDateString())->toBe($firstDate->copy()->addWeeks(3)->toDateString());
});

it('re-dates unpaid installments from a new date and leaves paid ones alone', function (): void {
    $loan = disbursedLoan($this->agent, $this->manager, $this->customer, $this->product);
    $firstInstallment = $loan->installments->first();
    app(RecordLoanRepaymentAction::class)->execute($loan, $firstInstallment->principal_due + $firstInstallment->interest_due, $this->agent);
    $paidDate = $firstInstallment->due_date->toDateString();

    $newStart = today()->addMonth();
    $result = app(RecalculateRepaymentScheduleAction::class)->execute($loan->fresh(), $this->manager, $newStart, 'Customer travelled');

    $installments = $loan->installments()->get();
    expect($result['rescheduled'])->toBe(3)
        ->and($installments[0]->due_date->toDateString())->toBe($paidDate)
        ->and($installments[1]->due_date->toDateString())->toBe($newStart->toDateString())
        ->and($installments[3]->due_date->toDateString())->toBe($newStart->copy()->addWeeks(2)->toDateString())
        ->and($loan->activities()->where('event', 'schedule_recalculated')->exists())->toBeTrue();
});

it('repairs corrupted dates from the loan\'s own rule and un-flags rescheduled arrears', function (): void {
    $loan = disbursedLoan($this->agent, $this->manager, $this->customer, $this->product);
    $loan->installments()->update(['due_date' => '2020-01-01', 'status' => InstallmentStatus::Overdue]);

    app(RecalculateRepaymentScheduleAction::class)->execute($loan->fresh(), $this->manager);

    $installments = $loan->installments()->get();
    expect($installments->pluck('due_date')->map->toDateString()->all())->toBe([
        $loan->disbursed_at->copy()->startOfDay()->addWeek()->toDateString(),
        $loan->disbursed_at->copy()->startOfDay()->addWeeks(2)->toDateString(),
        $loan->disbursed_at->copy()->startOfDay()->addWeeks(3)->toDateString(),
        $loan->disbursed_at->copy()->startOfDay()->addWeeks(4)->toDateString(),
    ])->and($installments->pluck('status')->unique()->all())->toBe([InstallmentStatus::Pending]);
});

it('recalculates a group member loan and moves its start date', function (): void {
    $group = LoanGroup::factory()->create(['branch_id' => $this->branch->id]);
    app(AddLoanGroupMemberAction::class)->execute($group, $this->customer);
    $groupLoan = app(IssueGroupMemberLoanAction::class)->execute($this->manager, $group, $this->customer, 300_00, 0, 100_00, LoanFrequency::Weekly, Carbon::today());
    $groupLoan = app(ActivateGroupLoanAction::class)->execute($groupLoan, $this->manager);

    app(RecalculateRepaymentScheduleAction::class)->execute($groupLoan, $this->manager, today()->addWeeks(2));

    expect($groupLoan->fresh()->start_date->toDateString())->toBe(today()->addWeeks(2)->toDateString())
        ->and($groupLoan->installments()->get()->last()->due_date->toDateString())->toBe(today()->addWeeks(4)->toDateString());
});

it('only lets managers recalculate a running loan', function (): void {
    $loan = disbursedLoan($this->agent, $this->manager, $this->customer, $this->product);

    app(RecalculateRepaymentScheduleAction::class)->execute($loan, $this->agent);
})->throws(ValidationException::class);

it('recalculates over the API and through sync', function (): void {
    $loan = disbursedLoan($this->agent, $this->manager, $this->customer, $this->product);
    $date = today()->addWeeks(3)->toDateString();

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/loans/{$loan->id}/recalculate-schedule", ['first_due_date' => $date])
        ->assertForbidden();

    $this->actingAs($this->manager, 'sanctum')
        ->postJson("/api/v1/loans/{$loan->id}/recalculate-schedule", ['first_due_date' => $date])
        ->assertOk()
        ->assertJsonPath('recalculation.first_due_date', $date)
        ->assertJsonPath('data.installments.0.due_date', fn ($due) => str_starts_with((string) $due, $date));

    $this->actingAs($this->manager, 'sanctum')
        ->postJson('/api/v1/sync/batch', ['ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'loan.schedule.recalculate',
            'payload' => ['loan_id' => $loan->id],
            'recorded_at' => now()->toISOString(),
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'applied');

    expect($loan->installments()->first()->due_date->toDateString())->toBe($loan->disbursed_at->copy()->startOfDay()->addWeek()->toDateString());
});

it('accepts application details over the API and shows them back', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/loans', [
        'customer_id' => $this->customer->id,
        'loan_product_id' => $this->product->id,
        'amount' => 400_00,
        'first_repayment_date' => today()->addWeek()->toDateString(),
        'collaterals' => [['type' => 'Land', 'description' => 'Plot at Kasoa', 'estimated_value' => 10_000_00]],
        'guarantors' => [['name' => 'Efua Mensah', 'phone' => '0201234567']],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.collaterals.0.description', 'Plot at Kasoa')
        ->assertJsonPath('data.guarantors.0.name', 'Efua Mensah')
        ->assertJsonPath('data.guarantor_name', 'Efua Mensah')
        ->assertJsonPath('data.charges.0.amount', 10_00);
});

it('calculates a what-if schedule', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/loans/calculator', [
            'principal_amount' => 1000_00,
            'interest_rate_bps' => 600,
            'interest_method' => 'flat',
            'term_period_count' => 6,
            'repayment_frequency' => 'weekly',
            'disbursement_date' => '2026-07-18',
        ])
        ->assertOk()
        ->assertJsonPath('data.total_interest', 360_00)
        ->assertJsonPath('data.first_due_date', '2026-07-25')
        ->assertJsonCount(6, 'data.installments');
});

it('applies through the Filament wizard with collateral and a guarantor, then shows the loan page', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CreateLoan::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'loan_product_id' => $this->product->id,
            'amount' => '400',
        ])
        ->fillForm([
            'term_period_count' => 10,
            'collaterals' => [['type' => 'Equipment', 'description' => 'Sewing machine', 'estimated_value' => '800']],
            'guarantors' => [['name' => 'Yaw Boateng', 'phone' => '0277000000']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::where('customer_id', $this->customer->id)->firstOrFail();
    expect($loan)
        ->term_period_count->toBe(10)
        ->origination_fee_amount->toBe(10_00)
        ->and($loan->collaterals->first()->estimated_value)->toBe(800_00)
        ->and($loan->guarantors->first()->name)->toBe('Yaw Boateng');

    livewire(ViewLoan::class, ['record' => $loan->getKey()])
        ->assertOk()
        ->assertSee('Loan balance')
        ->assertSee($loan->loan_number);
});
