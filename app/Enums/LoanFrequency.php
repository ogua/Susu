<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum LoanFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function addPeriod(Carbon $date, int $periods = 1): Carbon
    {
        return match ($this) {
            self::Daily => $date->copy()->addDays($periods),
            self::Weekly => $date->copy()->addWeeks($periods),
            self::Monthly => $date->copy()->addMonthsNoOverflow($periods),
        };
    }
}
