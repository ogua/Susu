<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** The end of a billing period that starts at $start. */
    public function endFrom(CarbonInterface $start): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Yearly => $start->copy()->addYearNoOverflow(),
        };
    }
}
