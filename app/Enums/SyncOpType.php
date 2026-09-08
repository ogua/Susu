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
    case IssueGroupMemberLoan = 'group_loan.issue';
    case RecordGroupLoanDeposit = 'group_loan.deposit.record';
    case ApplyGroupLoanDeposit = 'group_loan.deposit.apply';
    case ActivateGroupLoan = 'group_loan.activate';
    case RecordGroupLoanRepayment = 'group_loan.repayment.record';
    case WriteOffGroupLoan = 'group_loan.write_off';
    case RestructureLoan = 'loan.restructure';
    case TopUpLoan = 'loan.top_up';
    case WriteOffLoan = 'loan.write_off';

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
                // Group loans have no maker-checker — each member's loan is
                // issued and activated in the field when their deposit lands.
                self::IssueGroupMemberLoan,
                self::RecordGroupLoanDeposit,
                self::ApplyGroupLoanDeposit,
                self::ActivateGroupLoan,
                self::RecordGroupLoanRepayment,
            ],
            // Managers/admins additionally own the individual-loan approve/
            // reject/disburse decisions and every write-off.
            'branch_manager', 'company_admin' => self::cases(),
            default => [],
        };
    }
}
