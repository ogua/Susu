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
    case GroupContribution = 'group_contribution';
    case GroupPayout = 'group_payout';
    case GroupLoanDisbursement = 'group_loan_disbursement';
    case GroupLoanRepayment = 'group_loan_repayment';
    case WriteOff = 'loan_write_off';
    case GroupLoanWriteOff = 'group_loan_write_off';
}
