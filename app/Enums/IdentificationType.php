<?php

namespace App\Enums;

enum IdentificationType: string
{
    case GhanaCard = 'ghana_card';
    case VotersId = 'voters_id';
    case Passport = 'passport';
    case DriversLicense = 'drivers_license';
    case Other = 'other';
}
