<?php

namespace App\Services\License;

use Carbon\CarbonInterface;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Signs desktop activation keys. Must produce byte-for-byte the same format
 * the desktop app's service.LicenseManager (Java) verifies:
 *
 *   base64url(payload_json) . "." . base64url(signature)
 *
 * — both halves unpadded, payload = {"uid": "<installId>", "exp": "YYYY-MM-DD"}
 * as raw UTF-8 bytes, signature = SHA256withRSA over those exact bytes. See
 * that class's docblock (D:\Desktop App\susuDesktop\src\main\java\service\
 * LicenseManager.java) for the counterpart verification logic.
 */
class LicenseSigningService
{
    public function sign(string $installId, CarbonInterface $expiresAt): string
    {
        $payload = json_encode(['uid' => $installId, 'exp' => $expiresAt->toDateString()], JSON_THROW_ON_ERROR);

        $privateKey = $this->loadPrivateKey();

        if (! openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the license payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return $this->base64UrlEncode($payload).'.'.$this->base64UrlEncode($signature);
    }

    private function loadPrivateKey(): OpenSSLAsymmetricKey
    {
        $path = config('license.private_key_path');

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(
                "License private key not found at [{$path}]. See config/license.php for how to generate one."
            );
        }

        $pem = file_get_contents($path);
        $key = $pem !== false ? openssl_pkey_get_private($pem) : false;

        if ($key === false) {
            throw new RuntimeException('The license private key could not be read — is it a valid PEM-encoded RSA key?');
        }

        return $key;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
