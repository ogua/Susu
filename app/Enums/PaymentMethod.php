<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';

    /** Book transfers with no external money movement (commission, reversals). */
    case Internal = 'internal';
}
