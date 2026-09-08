<?php

namespace App\Actions\GroupLoans;

use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanFrequency;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\User;
use App\Services\Loans\PeriodicScheduleGenerator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues one loan to one member of a loan group. The member enters the total
 * principal, a refundable security deposit, and the amount they pay each
 * period; nothing is posted to the ledger yet — the loan sits in `draft`
 * until the deposit is recorded and it is activated. Idempotent on
 * client_reference (the offline-generated UUID becomes the loan's id so later
 * synced deposit/activate/repayment ops resolve).
 */
class IssueGroupMemberLoanAction
{
    public function __construct(private PeriodicScheduleGenerator $schedule) {}

    public function execute(
        User $issuedBy,
        LoanGroup $loanGroup,
        Customer $customer,
        int $principal,
        int $securityDeposit,
        int $periodicAmount,
        LoanFrequency $frequency,
        CarbonInterface $startDate,
        ?string $notes = null,
        ?string $clientReference = null,
    ): GroupLoan {
        if ($clientReference !== null) {
            $existing = GroupLoan::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($customer->company_id !== $loanGroup->company_id) {
            throw ValidationException::withMessages(['customer_id' => 'Customer not found in this company.']);
        }
        if (! $loanGroup->is_active) {
            throw ValidationException::withMessages(['loan_group_id' => 'This loan group is not active.']);
        }
        if ($principal <= 0) {
            throw ValidationException::withMessages(['principal_amount' => 'The loan amount must be greater than zero.']);
        }
        if ($periodicAmount <= 0) {
            throw ValidationException::withMessages(['periodic_amount' => 'The periodic amount must be greater than zero.']);
        }
        if ($securityDeposit < 0) {
            throw ValidationException::withMessages(['security_deposit_amount' => 'The security deposit cannot be negative.']);
        }
        if ($startDate->copy()->startOfDay()->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['start_date' => 'The first payment date cannot be in the past.']);
        }

        return DB::transaction(function () use ($issuedBy, $loanGroup, $customer, $principal, $securityDeposit, $periodicAmount, $frequency, $startDate, $notes, $clientReference): GroupLoan {
            $member = $this->resolveMember($loanGroup, $customer);

            if ($member->groupLoans()->where('status', GroupLoanStatus::Active)->exists()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'This member already has an active loan in the group.',
                ]);
            }

            try {
                $totalPeriods = $this->schedule->periodCount($principal, $periodicAmount);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['periodic_amount' => $e->getMessage()]);
            }

            $groupLoan = new GroupLoan([
                'company_id' => $loanGroup->company_id,
                'branch_id' => $loanGroup->branch_id,
                'loan_group_id' => $loanGroup->id,
                'loan_group_member_id' => $member->id,
                'customer_id' => $customer->id,
                'agent_id' => $issuedBy->hasRole('field_agent') ? $issuedBy->id : null,
                'loan_number' => $this->nextLoanNumber($loanGroup->branch),
                'principal_amount' => $principal,
                'security_deposit_amount' => $securityDeposit,
                'periodic_amount' => $periodicAmount,
                'outstanding_balance' => 0,
                'repayment_frequency' => $frequency,
                'start_date' => $startDate->toDateString(),
                'total_periods' => $totalPeriods,
                'deposit_status' => $securityDeposit > 0 ? DepositStatus::Pending : DepositStatus::Held,
                'status' => GroupLoanStatus::Draft,
                'notes' => $notes,
                'client_reference' => $clientReference,
                'issued_at' => now(),
            ]);

            if ($clientReference !== null) {
                $groupLoan->forceFill(['id' => $clientReference]);
            }

            $groupLoan->save();

            return $groupLoan;
        });
    }

    private function resolveMember(LoanGroup $loanGroup, Customer $customer): LoanGroupMember
    {
        $member = $loanGroup->members()->where('customer_id', $customer->id)->first();

        if ($member === null) {
            return LoanGroupMember::create([
                'loan_group_id' => $loanGroup->id,
                'customer_id' => $customer->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        if ($member->status !== 'active') {
            $member->forceFill(['status' => 'active', 'left_at' => null, 'joined_at' => now()])->save();
        }

        return $member;
    }

    /** {branch_code}-GL{sequence} — GL distinguishes a group member loan from an individual loan's L prefix. */
    private function nextLoanNumber(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-GL';
        $sequence = GroupLoan::where('branch_id', $branch->id)->count() + 1;

        while (GroupLoan::where('company_id', $branch->company_id)
            ->where('loan_number', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
