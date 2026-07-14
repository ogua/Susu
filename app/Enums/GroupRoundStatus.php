<?php

namespace App\Enums;

enum GroupRoundStatus: string
{
    case Pending = 'pending';
    case Collecting = 'collecting';
    case Completed = 'completed';
}
