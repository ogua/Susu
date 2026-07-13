<?php

namespace App\Enums;

enum TransactionType: string
{
    case Collection = 'collection';
    case Commission = 'commission';
    case Withdrawal = 'withdrawal';
    case Remittance = 'remittance';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case Disbursement = 'disbursement';
    case Repayment = 'repayment';
    case Penalty = 'penalty';
}
