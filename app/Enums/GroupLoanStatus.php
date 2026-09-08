<?php

namespace App\Enums;

enum GroupLoanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case WrittenOff = 'written_off';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::WrittenOff], true);
    }
}
