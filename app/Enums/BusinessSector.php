<?php

namespace App\Enums;

/** Best-guess option set — eBanQR's video only showed "AGRICULTURE" as an example. */
enum BusinessSector: string
{
    case Agriculture = 'agriculture';
    case TradingRetail = 'trading_retail';
    case Manufacturing = 'manufacturing';
    case Services = 'services';
    case Construction = 'construction';
    case Transportation = 'transportation';
    case Hospitality = 'hospitality';
    case Technology = 'technology';
    case Other = 'other';
}
