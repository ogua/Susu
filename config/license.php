<?php

return [

    /*
     * PEM-encoded RSA private key used to sign desktop activation keys.
     * Verified against the matching public key embedded in the desktop app
     * at src/main/resources/keys/license_public.pem — see
     * App\Services\License\LicenseSigningService for the exact format.
     *
     * No key ships with this repo. Generate a real RSA-2048 keypair before
     * issuing any production key:
     *   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out private_key.pem
     *   openssl rsa -pubout -in private_key.pem -out public_key.pem
     * Place the private half at the path below (storage/app/private is
     * gitignored) and the public half in the desktop app's resources.
     */
    'private_key_path' => env('LICENSE_PRIVATE_KEY_PATH', storage_path('app/private/license_private.pem')),

    /*
     * Price/duration are single configurable values, not tiers — a
     * deliberately minimal starting point (mechanism, not policy; see the
     * plan's G4 notes). Amount is in minor units (pesewas).
     */
    'price' => (int) env('LICENSE_PRICE_MINOR_UNITS', 500_00),
    'duration_days' => (int) env('LICENSE_DURATION_DAYS', 365),
    'currency' => env('LICENSE_CURRENCY', 'GHS'),

];
