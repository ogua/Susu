<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    // The payout's journal entry was reversed; the money is back in the account.
    case Reversed = 'reversed';
}
