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
    case ActivateGroupLoan = 'group_loan.activate';
    case RecordGroupLoanRepayment = 'group_loan.repayment.record';
    case WriteOffGroupLoan = 'group_loan.write_off';
    case CancelGroupLoan = 'group_loan.cancel';
    case RestructureLoan = 'loan.restructure';
    case TopUpLoan = 'loan.top_up';
    case WriteOffLoan = 'loan.write_off';
    case RecalculateLoanSchedule = 'loan.schedule.recalculate';
    case RecalculateGroupLoanSchedule = 'group_loan.schedule.recalculate';
    case RequestWithdrawal = 'withdrawal.request';
    case ApproveWithdrawal = 'withdrawal.approve';
    case RejectWithdrawal = 'withdrawal.reject';
    case PayWithdrawal = 'withdrawal.pay';
    case BuyShares = 'shares.purchase';
    case UpdateCustomer = 'customer.update';

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
                self::ActivateGroupLoan,
                self::RecordGroupLoanRepayment,
                self::CancelGroupLoan,
                // Agents raise withdrawals for their customers (a manager
                // decides and pays them) and sell shares in the field.
                self::RequestWithdrawal,
                self::BuyShares,
                self::UpdateCustomer,
            ],
            // Managers/admins additionally own the individual-loan approve/
            // reject/disburse decisions, every write-off and schedule
            // recalculation, and withdrawal approve/reject/pay.
            'branch_manager', 'company_admin' => self::cases(),
            default => [],
        };
    }
}
