<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Dormant = 'dormant';
    case Closed = 'closed';
}
