<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A client build channel a release is published for. Mobile builds ship
 * through the stores; the desktop build is a direct download.
 */
enum AppPlatform: string implements HasLabel
{
    case Android = 'android';
    case Ios = 'ios';
    case Desktop = 'desktop';

    public function getLabel(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
            self::Desktop => 'Desktop (Windows)',
        };
    }
}
