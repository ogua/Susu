<?php

namespace App\Actions\LoanGroups;

use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\GroupLoanRepayment;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * A single timeline of everything that happened in a customer group, rebuilt
 * from the records themselves (membership, every member loan's lifecycle
 * timestamps, deposits, repayments) plus the audit log for edits to the group.
 * Rebuilding from source rows means history exists even for events recorded
 * before the audit log covered groups, and can never drift from the ledger.
 */
class BuildLoanGroupHistoryAction
{
    /**
     * @return list<array{at: string, type: string, description: string, member: ?string, amount: ?int, by: ?string}>
     */
    public function execute(LoanGroup $loanGroup, int $limit = 200): array
    {
        $loanGroup->loadMissing('createdBy');
        $events = collect();

        $events->push($this->event($loanGroup->created_at, 'group_created', "Group {$loanGroup->name} created", null, null, $loanGroup->createdBy?->name));

        $loanGroup->members()->with('customer')->get()->each(function (LoanGroupMember $member) use ($events): void {
            $name = $member->customer?->fullName();
            $events->push($this->event($member->joined_at, 'member_joined', "{$name} joined the group", $name));
            if ($member->left_at !== null) {
                $events->push($this->event($member->left_at, 'member_left', "{$name} left the group", $name));
            }
        });

        $loans = $loanGroup->groupLoans()->with(['customer', 'activatedBy'])->get();
        $loans->each(function (GroupLoan $loan) use ($events): void {
            $this->pushLoanEvents($events, $loan);
        });

        $loanIds = $loans->pluck('id');
        $loansById = $loans->keyBy('id');

        GroupLoanDeposit::whereIn('group_loan_id', $loanIds)->with('recordedBy')->get()
            ->each(function (GroupLoanDeposit $deposit) use ($events, $loansById): void {
                $loan = $loansById->get($deposit->group_loan_id);
                $events->push($this->event($deposit->recorded_at, 'deposit_recorded', "Security deposit for {$loan->loan_number}", $loan->customer?->fullName(), $deposit->amount, $deposit->recordedBy?->name));
            });

        GroupLoanRepayment::whereIn('group_loan_id', $loanIds)->with('recordedBy')->get()
            ->each(function (GroupLoanRepayment $repayment) use ($events, $loansById): void {
                $loan = $loansById->get($repayment->group_loan_id);
                $events->push($this->event($repayment->recorded_at, 'repayment_recorded', "Repayment on {$loan->loan_number}", $loan->customer?->fullName(), $repayment->amount, $repayment->recordedBy?->name));
            });

        Activity::where('subject_type', $loanGroup->getMorphClass())
            ->where('subject_id', $loanGroup->getKey())
            ->whereIn('event', ['updated', 'deleted', 'restored'])
            ->with('causer')
            ->get()
            ->each(function (Activity $activity) use ($events): void {
                $changed = implode(', ', array_keys($activity->properties['attributes'] ?? []));
                $events->push($this->event($activity->created_at, 'group_'.$activity->event, 'Group '.$activity->event.($changed ? " ({$changed})" : ''), null, null, $activity->causer?->name));
            });

        return $events
            ->filter(fn (array $event): bool => $event['at'] !== null)
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushLoanEvents(Collection $events, GroupLoan $loan): void
    {
        $member = $loan->customer?->fullName();

        $events->push($this->event($loan->issued_at, 'loan_issued', "Loan {$loan->loan_number} issued", $member, $loan->principal_amount));
        $events->push($this->event($loan->activated_at, 'loan_activated', "Loan {$loan->loan_number} disbursed", $member, $loan->principal_amount, $loan->activatedBy?->name));
        $events->push($this->event($loan->closed_at, 'loan_closed', "Loan {$loan->loan_number} fully repaid", $member));
        $events->push($this->event($loan->cancelled_at, 'loan_cancelled', "Loan {$loan->loan_number} cancelled".($loan->cancellation_reason ? ": {$loan->cancellation_reason}" : ''), $member));
        $events->push($this->event($loan->written_off_at, 'loan_written_off', "Loan {$loan->loan_number} written off", $member, $loan->write_off_amount));
    }

    /**
     * @return array{at: ?string, type: string, description: string, member: ?string, amount: ?int, by: ?string}
     */
    private function event(?CarbonInterface $at, string $type, string $description, ?string $member = null, ?int $amount = null, ?string $by = null): array
    {
        return [
            'at' => $at?->toIso8601String(),
            'type' => $type,
            'description' => $description,
            'member' => $member,
            'amount' => $amount,
            'by' => $by,
        ];
    }
}
