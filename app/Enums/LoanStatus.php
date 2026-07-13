<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Applied = 'applied';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';
    case Closed = 'closed';
    case WrittenOff = 'written_off';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Closed, self::WrittenOff], true);
    }
}
