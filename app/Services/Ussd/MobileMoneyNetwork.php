<?php

namespace App\Services\Ussd;

/**
 * Ghana mobile money networks, as Paystack's mobile_money provider codes.
 *
 * Works out a caller's network from their number's prefix. A number ported to another
 * network keeps its old prefix, so callers can still pick another network by hand.
 */
class MobileMoneyNetwork
{
    /**
     * Paystack provider code => [name shown to the caller, local prefixes].
     */
    private const NETWORKS = [
        'mtn' => ['MTN MoMo', ['024', '025', '053', '054', '055', '059']],
        'vod' => ['Telecel Cash', ['020', '050']],
        'atl' => ['AirtelTigo Money', ['026', '027', '056', '057']],
    ];

    /**
     * The Paystack provider code for a number in any format (0241…, 233241…, +233 24 1…), or null when unknown.
     */
    public static function detect(string $msisdn): ?string
    {
        $prefix = '0'.substr(substr(preg_replace('/\D/', '', $msisdn), -9), 0, 2);

        foreach (self::NETWORKS as $provider => [, $prefixes]) {
            if (in_array($prefix, $prefixes, true)) {
                return $provider;
            }
        }

        return null;
    }

    public static function name(string $provider): string
    {
        return self::NETWORKS[$provider][0] ?? $provider;
    }

    /**
     * Menu option => Paystack provider code, for asking the caller.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return ['1' => 'mtn', '2' => 'vod', '3' => 'atl'];
    }
}
