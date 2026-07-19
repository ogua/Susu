<?php

namespace App\Enums;

enum ResidencyStatus: string
{
    case Tenant = 'tenant';
    case PropertyOwner = 'property_owner';
}
