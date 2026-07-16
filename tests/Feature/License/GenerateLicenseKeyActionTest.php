<?php

use App\Actions\License\GenerateLicenseKeyAction;
use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
});

it('signs a key, sets expires_at from duration_days, and marks the sale issued', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'install_id' => 'install-999',
        'duration_days' => 90,
        'status' => LicenseSaleStatus::Paid,
        'license_key' => null,
    ]);

    $result = app(GenerateLicenseKeyAction::class)->execute($sale);

    expect($result->status)->toBe(LicenseSaleStatus::Issued)
        ->and($result->license_key)->not->toBeNull()
        ->and($result->license_key)->toContain('.')
        ->and($result->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

it('is idempotent: calling it again on an already-issued sale returns the same key', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Paid,
        'license_key' => null,
    ]);

    $first = app(GenerateLicenseKeyAction::class)->execute($sale);
    $firstKey = $first->license_key;

    $second = app(GenerateLicenseKeyAction::class)->execute($first->fresh());

    expect($second->license_key)->toBe($firstKey);
});
