<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Branch;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lets staff set their own photo from the profile page', function (): void {
    seedRoles();
    Storage::fake('public');
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();

    $this->actingAs($agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();

    livewire(EditProfile::class)
        ->fillForm(['photo_path' => UploadedFile::fake()->image('me.jpg')])
        ->call('save')
        ->assertHasNoFormErrors();

    $agent->refresh();

    expect($agent->photo_path)->toStartWith('staff/photos/')
        ->and($agent->getFilamentAvatarUrl())->toContain('/storage/staff/photos/');
    Storage::disk('public')->assertExists($agent->photo_path);
});
