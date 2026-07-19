<?php

namespace App\Enums;

/** Best-guess option set — eBanQR's video only showed "LIMITED LIABILITY" as an example. */
enum BusinessStructure: string
{
    case SoleProprietorship = 'sole_proprietorship';
    case Partnership = 'partnership';
    case LimitedLiabilityCompany = 'limited_liability_company';
    case NgoCbo = 'ngo_cbo';
    case Cooperative = 'cooperative';
    case Other = 'other';
}
