<?php

use App\Actions\Company\CreateBranchAction;
use App\Actions\Company\CreateCompanyAdminAction;
use App\Actions\Company\ProvisionStarterProductsAction;
use App\Filament\SuperAdmin\Resources\Companies\Pages\CreateCompany;
use App\Filament\SuperAdmin\Resources\Companies\Pages\EditCompany;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ListCompanies;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ViewCompany;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\BranchesRelationManager;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\StaffRelationManager;
use App\Filament\SuperAdmin\Resources\Users\Pages\CreateUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();
});

/**
 * @return array<string, mixed>
 */
function onboardingFormData(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Tamale Savings',
        'slug' => 'tamale-savings',
        'domain_alias' => 'tamale.susuapp.test',
        'contact_email' => 'info@tamale.test',
        'contact_phone' => '+233244111222',
        'is_active' => true,
        'branch' => [
            'name' => 'Tamale Main',
            'slug' => 'tamale-main',
            'code' => 'TM',
        ],
        'admin' => [
            'name' => 'Abena Mensah',
            'email' => 'abena@tamale.test',
            'phone' => '+233244333444',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ],
    ], $overrides);
}

it('onboards a company with its first branch and a company admin who can enter the admin panel', function (): void {
    livewire(CreateCompany::class)
        ->fillForm(onboardingFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $company = Company::where('slug', 'tamale-savings')->firstOrFail();
    $branch = Branch::where('company_id', $company->id)->firstOrFail();
    $admin = User::where('email', 'abena@tamale.test')->firstOrFail();

    expect($branch->slug)->toBe('tamale-main')
        ->and($admin->company_id)->toBe($company->id)
        ->and($admin->branch_id)->toBe($branch->id)
        ->and($admin->hasRole('company_admin'))->toBeTrue()
        ->and($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($admin->canAccessTenant($branch))->toBeTrue();
});

it('creates nothing when the admin email is already taken', function (): void {
    User::factory()->create(['email' => 'abena@tamale.test']);

    livewire(CreateCompany::class)
        ->fillForm(onboardingFormData())
        ->call('create')
        ->assertHasFormErrors(['admin.email' => 'unique']);

    expect(Company::where('slug', 'tamale-savings')->exists())->toBeFalse();
});

it('requires the admin password to be confirmed', function (): void {
    livewire(CreateCompany::class)
        ->fillForm(onboardingFormData(['admin' => ['password_confirmation' => 'different']]))
        ->call('create')
        ->assertHasFormErrors(['admin.password']);
});

it('grants existing company admins access to a branch added later', function (): void {
    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();
    $admin = User::factory()->companyAdmin($company)->create();

    livewire(BranchesRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->callTableAction('create', data: ['name' => 'Second Branch', 'slug' => 'second'])
        ->assertHasNoTableActionErrors();

    $second = Branch::where('company_id', $company->id)->where('slug', 'second')->firstOrFail();

    expect($admin->canAccessTenant($second))->toBeTrue();
});

it('adds a company admin from the company page', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction('addCompanyAdmin', [
            'name' => 'Kofi Boateng',
            'email' => 'kofi@example.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $admin = User::where('email', 'kofi@example.test')->firstOrFail();

    expect($admin->hasRole('company_admin'))->toBeTrue()
        ->and($admin->canAccessTenant($branch))->toBeTrue();
});

it('hides "add company admin" until the company has a branch', function (): void {
    $company = Company::factory()->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden('addCompanyAdmin')
        ->assertActionVisible('addBranch');
});

it('refuses to create a company admin for a company without branches', function (): void {
    $company = Company::factory()->create();

    app(CreateCompanyAdminAction::class)->execute($company, [
        'name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-pass',
    ]);
})->throws(ValidationException::class);

it('shows the company page with onboarding status and staff', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $admin = User::factory()->companyAdmin($company)->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $customer = User::factory()->customerUser($company)->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertOk()
        ->assertSee('Active company admins');

    livewire(StaffRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->assertCanSeeTableRecords([$admin, $agent])
        ->assertCanNotSeeTableRecords([$customer]);
});

it('filters companies whose onboarding is incomplete', function (): void {
    $complete = Company::factory()->create();
    Branch::factory()->for($complete)->create();
    User::factory()->companyAdmin($complete)->create();

    $noBranch = Company::factory()->create();
    $noAdmin = Company::factory()->create();
    Branch::factory()->for($noAdmin)->create();

    livewire(ListCompanies::class)
        ->filterTable('incomplete_onboarding')
        ->assertCanSeeTableRecords([$noBranch, $noAdmin])
        ->assertCanNotSeeTableRecords([$complete]);
});

it('gives a company admin created on the users page every branch of their company', function (): void {
    $company = Company::factory()->create();
    $first = Branch::factory()->for($company)->create();
    $second = Branch::factory()->for($company)->create();
    $role = Role::where('name', 'company_admin')->firstOrFail();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Ama Owusu',
            'email' => 'ama@example.test',
            'password' => 'secret-pass',
            'company_id' => $company->id,
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $admin = User::where('email', 'ama@example.test')->firstOrFail();

    expect($admin->branches->pluck('id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($admin->branch_id)->not->toBeNull();
});

it('suspends a company from the table, signing its users out of the panel and the API', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $manager = User::factory()->branchManager($branch)->create();
    $manager->createToken('phone');

    livewire(ListCompanies::class)
        ->callAction(TestAction::make('toggleActive')->table($company))
        ->assertNotified();

    expect($company->refresh()->is_active)->toBeFalse()
        ->and($manager->refresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($manager->tokens()->count())->toBe(0);

    livewire(ListCompanies::class)
        ->callAction(TestAction::make('toggleActive')->table($company));

    expect($company->refresh()->is_active)->toBeTrue()
        ->and($manager->refresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('revokes API tokens when the company is deactivated from the edit form', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $agent->createToken('phone');

    livewire(EditCompany::class, ['record' => $company->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Company::class, ['id' => $company->id, 'is_active' => false]);
    expect($agent->tokens()->count())->toBe(0);
});

it('creates a branch through the action without an existing admin', function (): void {
    $company = Company::factory()->create();

    $branch = app(CreateBranchAction::class)->execute($company, ['name' => 'Solo', 'slug' => 'solo']);

    expect($branch->company_id)->toBe($company->id)
        ->and($branch->users()->count())->toBe(0);
});

it('gives an onboarded company the starter product catalogue by default', function (): void {
    livewire(CreateCompany::class)
        ->fillForm(onboardingFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $company = Company::where('slug', 'tamale-savings')->firstOrFail();

    expect($company->savingsProducts()->count())->toBe(count(ProvisionStarterProductsAction::SAVINGS_PRODUCTS))
        ->and($company->loanProducts()->count())->toBe(count(ProvisionStarterProductsAction::LOAN_PRODUCTS))
        ->and($company->savingsProducts()->where('code', 'FD-091')->value('term_days'))->toBe(91);
});

it('skips the starter products when the operator opts out', function (): void {
    livewire(CreateCompany::class)
        ->fillForm(onboardingFormData(['admin' => ['with_starter_products' => false]]))
        ->call('create')
        ->assertHasNoFormErrors();

    $company = Company::where('slug', 'tamale-savings')->firstOrFail();

    expect($company->savingsProducts()->count())->toBe(0)
        ->and($company->loanProducts()->count())->toBe(0);
});

it('only adds missing starter products and never overwrites the company\'s edits', function (): void {
    $company = Company::factory()->create();
    SavingsProduct::factory()->create(['company_id' => $company->id, 'code' => 'DS-005', 'name' => 'Our own daily susu']);

    $added = app(ProvisionStarterProductsAction::class)->execute($company);

    expect($added['savings'])->toBe(count(ProvisionStarterProductsAction::SAVINGS_PRODUCTS) - 1)
        ->and($company->savingsProducts()->where('code', 'DS-005')->value('name'))->toBe('Our own daily susu')
        ->and(app(ProvisionStarterProductsAction::class)->execute($company))->toBe(['savings' => 0, 'loans' => 0]);
});

it('adds starter products from the company page', function (): void {
    $company = Company::factory()->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction('addStarterProducts')
        ->assertNotified();

    expect($company->loanProducts()->count())->toBe(count(ProvisionStarterProductsAction::LOAN_PRODUCTS));
});
