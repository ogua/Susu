<?php

namespace App\Enums;

enum SavingsProductType: string
{
    case DailySusu = 'daily_susu';

    /** Fixed target amount + maturity date; early withdrawal incurs a penalty. */
    case Target = 'target';

    /** Locked principal + maturity date; earns interest, paid at maturity. Withdrawal blocked before maturity. */
    case FixedDeposit = 'fixed_deposit';

    /** Cooperative share capital: balance is always share_count * product par_value. No maturity, no penalty, no interest. */
    case Shares = 'shares';
}
