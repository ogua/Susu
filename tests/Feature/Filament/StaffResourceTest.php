<?php

use App\Actions\Staff\CreateStaffAction;
use App\Actions\Staff\UpdateStaffAction;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Models\Branch;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
});

function bootStaffAdminPanel(Branch $branch): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();
}

it('lets a company admin create a branch manager scoped to their company', function (): void {
    $companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->actingAs($companyAdmin);
    bootStaffAdminPanel($this->branch);

    livewire(CreateStaff::class)
        ->fillForm([
            'name' => 'Ama Mensah',
            'email' => 'ama.mensah@example.com',
            'phone' => '+233241234567',
            'password' => 'password',
            'role' => 'branch_manager',
            'branch_ids' => [$this->branch->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $staff = User::where('email', 'ama.mensah@example.com')->firstOrFail();

    expect($staff->hasRole('branch_manager'))->toBeTrue()
        ->and($staff->company_id)->toBe($this->branch->company_id)
        ->and($staff->branches->pluck('id')->all())->toBe([$this->branch->id]);
});

it('lets a branch manager create a field agent within their own branch', function (): void {
    $branchManager = User::factory()->branchManager($this->branch)->create();
    $this->actingAs($branchManager);
    bootStaffAdminPanel($this->branch);

    livewire(CreateStaff::class)
        ->fillForm([
            'name' => 'Kojo Boateng',
            'email' => 'kojo.boateng@example.com',
            'password' => 'password',
            'role' => 'field_agent',
            'branch_ids' => [$this->branch->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'kojo.boateng@example.com')->firstOrFail()->hasRole('field_agent'))->toBeTrue();
});

it('denies a branch manager from creating another branch manager', function (): void {
    $branchManager = User::factory()->branchManager($this->branch)->create();

    expect(fn () => app(CreateStaffAction::class)->execute(
        actor: $branchManager,
        tenant: $this->branch,
        data: [
            'name' => 'Escalation Attempt',
            'email' => 'escalation@example.com',
            'phone' => null,
            'password' => 'password',
            'role' => 'branch_manager',
            'branch_ids' => [$this->branch->id],
        ],
    ))->toThrow(ValidationException::class);

    expect(User::where('email', 'escalation@example.com')->exists())->toBeFalse();
});

it('denies a branch manager from granting access to a branch they do not belong to', function (): void {
    $branchManager = User::factory()->branchManager($this->branch)->create();

    expect(fn () => app(CreateStaffAction::class)->execute(
        actor: $branchManager,
        tenant: $this->branch,
        data: [
            'name' => 'Out Of Bounds',
            'email' => 'oob@example.com',
            'phone' => null,
            'password' => 'password',
            'role' => 'field_agent',
            'branch_ids' => [$this->otherBranch->id],
        ],
    ))->toThrow(ValidationException::class);

    expect(User::where('email', 'oob@example.com')->exists())->toBeFalse();
});

it('scopes the staff list company-wide for a company admin and branch-only for a branch manager', function (): void {
    $companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $branchManager = User::factory()->branchManager($this->branch)->create();
    $otherBranchAgent = User::factory()->fieldAgent($this->otherBranch)->create();
    $sameBranchAgent = User::factory()->fieldAgent($this->branch)->create();

    $this->actingAs($companyAdmin);
    bootStaffAdminPanel($this->branch);

    livewire(ListStaff::class)
        ->assertCanSeeTableRecords([$branchManager, $otherBranchAgent, $sameBranchAgent]);

    $this->actingAs($branchManager);
    bootStaffAdminPanel($this->branch);

    livewire(ListStaff::class)
        ->assertCanSeeTableRecords([$sameBranchAgent])
        ->assertCanNotSeeTableRecords([$otherBranchAgent, $companyAdmin]);
});

it('does not let staff see themselves in the staff list', function (): void {
    $companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->actingAs($companyAdmin);
    bootStaffAdminPanel($this->branch);

    livewire(ListStaff::class)->assertCanNotSeeTableRecords([$companyAdmin]);
});

it('lets a company admin promote a field agent to branch manager', function (): void {
    $companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $agent = User::factory()->fieldAgent($this->branch)->create();
    $this->actingAs($companyAdmin);
    bootStaffAdminPanel($this->branch);

    livewire(EditStaff::class, ['record' => $agent->id])
        ->fillForm([
            'name' => 'Promoted Agent',
            'email' => $agent->email,
            'role' => 'branch_manager',
            'branch_ids' => [$this->branch->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agent->refresh()->hasRole('branch_manager'))->toBeTrue();
});

it('denies a branch manager from promoting a field agent to branch manager', function (): void {
    $branchManager = User::factory()->branchManager($this->branch)->create();
    $agent = User::factory()->fieldAgent($this->branch)->create();

    expect(fn () => app(UpdateStaffAction::class)->execute(
        actor: $branchManager,
        target: $agent,
        tenant: $this->branch,
        data: [
            'name' => $agent->name,
            'email' => $agent->email,
            'phone' => null,
            'role' => 'branch_manager',
            'branch_ids' => [$this->branch->id],
        ],
    ))->toThrow(ValidationException::class);

    expect($agent->refresh()->hasRole('field_agent'))->toBeTrue();
});

it('stores an uploaded staff photo on the public disk', function (): void {
    Storage::fake('public');
    $branchManager = User::factory()->branchManager($this->branch)->create();
    $this->actingAs($branchManager);
    bootStaffAdminPanel($this->branch);

    livewire(CreateStaff::class)
        ->fillForm([
            'name' => 'Efua Quaye',
            'email' => 'efua.quaye@example.com',
            'password' => 'password',
            'role' => 'field_agent',
            'branch_ids' => [$this->branch->id],
            'photo_path' => UploadedFile::fake()->image('efua.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $staff = User::where('email', 'efua.quaye@example.com')->firstOrFail();

    expect($staff->photo_path)->toStartWith('staff/photos/');
    Storage::disk('public')->assertExists($staff->photo_path);
});
