<?php

namespace App\Enums;

enum SavingsProductType: string
{
    case DailySusu = 'daily_susu';

    /** Fixed target amount + maturity date; early withdrawal incurs a penalty. */
    case Target = 'target';
}
