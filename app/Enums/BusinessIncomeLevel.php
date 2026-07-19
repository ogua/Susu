<?php

namespace App\Enums;

/** Best-guess option set — eBanQR's video showed this as a select but never expanded the options. */
enum BusinessIncomeLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
