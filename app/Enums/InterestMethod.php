<?php

namespace App\Enums;

enum InterestMethod: string
{
    /** Rate applies per period to the original principal for the whole term — every installment's interest is identical. */
    case Flat = 'flat';

    /** Equal principal each period; interest computed on the declining outstanding balance, so it shrinks over time. */
    case ReducingBalance = 'reducing_balance';
}
