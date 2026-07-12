<?php

namespace App\Enums;

enum ClientOrigin: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Desktop = 'desktop';
    case System = 'system';
}
