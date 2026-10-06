<?php

namespace App\Enums;

enum AnnouncementLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
