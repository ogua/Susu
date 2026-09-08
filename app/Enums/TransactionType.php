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
    case GroupLoanDepositHeld = 'group_loan_deposit_held';
    case GroupLoanDepositRefunded = 'group_loan_deposit_refunded';
    case GroupLoanDepositApplied = 'group_loan_deposit_applied';
    case WriteOff = 'loan_write_off';
    case GroupLoanWriteOff = 'group_loan_write_off';
    case LoanRestructure = 'loan_restructure';
    case LoanTopUp = 'loan_top_up';
    case GroupLoanRestructure = 'group_loan_restructure';
    case GroupLoanTopUp = 'group_loan_top_up';
    case SavingsInterest = 'savings_interest';
    case SharesPurchase = 'shares_purchase';
}
