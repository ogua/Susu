<?php

namespace App\Support;

use NumberFormatter;

/**
 * All amounts in SusuApp are integers in minor units (pesewas for GHS).
 * This helper is the single place that turns them into display strings;
 * arithmetic stays plain integer math everywhere.
 */
class Money
{
    public const DEFAULT_CURRENCY = 'GHS';

    public static function format(int $minorUnits, string $currency = self::DEFAULT_CURRENCY): string
    {
        return sprintf('%s %s', $currency, number_format($minorUnits / 100, 2));
    }

    /** Parse a user-entered major-unit amount ("125.50") into minor units. */
    public static function toMinorUnits(float|string $majorUnits): int
    {
        return (int) round(((float) $majorUnits) * 100);
    }
}
