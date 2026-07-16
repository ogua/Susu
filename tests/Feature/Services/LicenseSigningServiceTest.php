<?php

use App\Services\License\LicenseSigningService;
use Illuminate\Support\Facades\Config;

// PHP's openssl_pkey_new() fails on this environment (no openssl.cnf
// discoverable), so throwaway keys can't be generated at runtime — a fixed
// throwaway fixture keypair (never used for a real license) is used instead.
// Signing/loading an existing PEM (the real production code path) is
// unaffected; only openssl_pkey_new() itself is broken here.
beforeEach(function (): void {
    $this->publicKeyPem = file_get_contents(__DIR__.'/../../Fixtures/license/test_public_key.pem');
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
});

function licenseTestBase64UrlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

it('produces a key whose signature verifies against the matching public key', function (): void {
    $key = app(LicenseSigningService::class)->sign('install-123', now()->addYear());

    [$payloadPart, $signaturePart] = explode('.', $key, 2);
    $payloadBytes = licenseTestBase64UrlDecode($payloadPart);
    $signatureBytes = licenseTestBase64UrlDecode($signaturePart);

    $result = openssl_verify($payloadBytes, $signatureBytes, $this->publicKeyPem, OPENSSL_ALGO_SHA256);

    expect($result)->toBe(1);
});

it('embeds the install id and expiry date in the payload exactly as the desktop app expects', function (): void {
    $expiresAt = now()->addDays(30);
    $key = app(LicenseSigningService::class)->sign('install-abc', $expiresAt);

    [$payloadPart] = explode('.', $key, 2);
    $payload = json_decode(licenseTestBase64UrlDecode($payloadPart), true);

    expect($payload)->toBe(['uid' => 'install-abc', 'exp' => $expiresAt->toDateString()]);
});

it('produces base64url output with no padding characters, matching Base64.getUrlEncoder().withoutPadding()', function (): void {
    $key = app(LicenseSigningService::class)->sign('install-xyz', now()->addYear());

    expect($key)->not->toContain('=')
        ->not->toContain('+')
        ->not->toContain('/');
});

it('fails a signature check against a different public key (tamper detection sanity check)', function (): void {
    $key = app(LicenseSigningService::class)->sign('install-123', now()->addYear());
    $otherPublicKeyPem = file_get_contents(__DIR__.'/../../Fixtures/license/other_public_key.pem');

    [$payloadPart, $signaturePart] = explode('.', $key, 2);
    $result = openssl_verify(
        licenseTestBase64UrlDecode($payloadPart),
        licenseTestBase64UrlDecode($signaturePart),
        $otherPublicKeyPem,
        OPENSSL_ALGO_SHA256,
    );

    expect($result)->toBe(0);
});

it('throws when the configured private key path does not exist', function (): void {
    Config::set('license.private_key_path', '/no/such/file.pem');

    app(LicenseSigningService::class)->sign('install-123', now()->addYear());
})->throws(RuntimeException::class, 'not found');
