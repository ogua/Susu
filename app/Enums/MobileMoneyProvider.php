<?php

namespace App\Enums;

/** Paystack Ghana mobile money channel codes. */
enum MobileMoneyProvider: string
{
    case Mtn = 'mtn';
    case Telecel = 'vod';
    case AirtelTigo = 'atl';
}
