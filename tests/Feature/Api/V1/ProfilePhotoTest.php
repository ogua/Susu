<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedRoles();
    Storage::fake('public');
    $this->agent = User::factory()->fieldAgent(Branch::factory()->create())->create();
});

it('lets an agent upload their own profile photo', function (): void {
    Sanctum::actingAs($this->agent);

    $response = $this->post('/api/v1/auth/me/photo', [
        'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonPath('user.id', $this->agent->id)
        ->assertJsonPath('user.photo_url', fn (string $url): bool => str_contains($url, '/storage/staff/photos/'));

    $path = $this->agent->refresh()->photo_path;
    expect($path)->toStartWith('staff/photos/');
    Storage::disk('public')->assertExists($path);
});

it('deletes the previous photo when a new one is uploaded', function (): void {
    Sanctum::actingAs($this->agent);

    $this->post('/api/v1/auth/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
    $firstPath = $this->agent->refresh()->photo_path;

    $this->post('/api/v1/auth/me/photo', ['photo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($this->agent->refresh()->photo_path);
});

it('removes the profile photo', function (): void {
    Sanctum::actingAs($this->agent);
    $this->post('/api/v1/auth/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
    $path = $this->agent->refresh()->photo_path;

    $this->deleteJson('/api/v1/auth/me/photo')
        ->assertOk()
        ->assertJsonPath('user.photo_url', null);

    expect($this->agent->refresh()->photo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects files that are not images', function (): void {
    Sanctum::actingAs($this->agent);

    $this->post('/api/v1/auth/me/photo', [
        'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/auth/me/photo')->assertUnauthorized();
});
