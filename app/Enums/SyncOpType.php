<?php

namespace App\Enums;

enum SyncOpType: string
{
    case RegisterCustomer = 'customer.register';
    case OpenSavingsAccount = 'account.open';
    case RecordCollection = 'collection.record';
    case SubmitDailySummary = 'summary.submit';
    case RecordLocationPings = 'locations.record';
    case ApplyForLoan = 'loan.apply';
    case RecordLoanRepayment = 'loan.repayment.record';
    case ApproveLoan = 'loan.approve';
    case RejectLoan = 'loan.reject';
    case DisburseLoan = 'loan.disburse';
    case RecordGroupContribution = 'group.contribution.record';
    case ApplyForGroupLoan = 'group_loan.apply';
    case RecordGroupLoanRepayment = 'group_loan.repayment.record';
    case ApproveGroupLoan = 'group_loan.approve';
    case RejectGroupLoan = 'group_loan.reject';
    case DisburseGroupLoan = 'group_loan.disburse';
    case RestructureLoan = 'loan.restructure';
    case TopUpLoan = 'loan.top_up';
    case RestructureGroupLoan = 'group_loan.restructure';
    case TopUpGroupLoan = 'group_loan.top_up';
    case WriteOffLoan = 'loan.write_off';
    case WriteOffGroupLoan = 'group_loan.write_off';

    /**
     * Op types each role may push through /sync/batch.
     *
     * @return array<int, self>
     */
    public static function allowedFor(string $role): array
    {
        return match ($role) {
            'field_agent' => [
                self::RegisterCustomer,
                self::OpenSavingsAccount,
                self::RecordCollection,
                self::SubmitDailySummary,
                self::RecordLocationPings,
                self::ApplyForLoan,
                self::RecordLoanRepayment,
                self::RecordGroupContribution,
                self::ApplyForGroupLoan,
                self::RecordGroupLoanRepayment,
            ],
            // Loan/group-loan approve/reject/disburse mirror LoanPolicy/
            // GroupLoanPolicy: agents can apply and record repayments, but
            // only managers/admins decide loans.
            'branch_manager', 'company_admin' => self::cases(),
            default => [],
        };
    }
}
