<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum LoanFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** "day" / "week" / "month" — for "5% per week" style labels. */
    public function periodNoun(): string
    {
        return match ($this) {
            self::Daily => 'day',
            self::Weekly => 'week',
            self::Monthly => 'month',
        };
    }

    public function addPeriod(Carbon $date, int $periods = 1): Carbon
    {
        return match ($this) {
            self::Daily => $date->copy()->addDays($periods),
            self::Weekly => $date->copy()->addWeeks($periods),
            self::Monthly => $date->copy()->addMonthsNoOverflow($periods),
        };
    }
}
