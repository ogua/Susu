<?php

namespace App\Actions\LoanGroups;

use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Enums\LoanFrequency;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Apply a loan to the group": issues the same terms to every active member
 * (or the chosen subset) through IssueGroupMemberLoanAction, one draft loan
 * each. Members who can't take one (e.g. still have an open loan) are skipped
 * and reported rather than failing the whole group.
 */
class IssueLoansToGroupAction
{
    public function __construct(private IssueGroupMemberLoanAction $issue) {}

    /**
     * @param  list<string>|null  $memberIds  null = every active member
     * @return array{issued: list<string>, skipped: array<string, string>}
     */
    public function execute(
        User $issuedBy,
        LoanGroup $loanGroup,
        int $principal,
        int $securityDeposit,
        int $periodicAmount,
        LoanFrequency $frequency,
        CarbonInterface $startDate,
        ?array $memberIds = null,
        ?string $notes = null,
    ): array {
        $members = $loanGroup->members()
            ->where('status', 'active')
            ->when($memberIds !== null, fn ($query) => $query->whereIn('id', $memberIds))
            ->with('customer')
            ->get();

        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['members' => 'The group has no active members to issue loans to.']);
        }

        $result = ['issued' => [], 'skipped' => []];

        $members->each(function (LoanGroupMember $member) use (&$result, $issuedBy, $loanGroup, $principal, $securityDeposit, $periodicAmount, $frequency, $startDate, $notes): void {
            try {
                $loan = $this->issue->execute(
                    issuedBy: $issuedBy,
                    loanGroup: $loanGroup,
                    customer: $member->customer,
                    principal: $principal,
                    securityDeposit: $securityDeposit,
                    periodicAmount: $periodicAmount,
                    frequency: $frequency,
                    startDate: $startDate,
                    notes: $notes,
                );
                $result['issued'][] = $loan->id;
            } catch (ValidationException $e) {
                $result['skipped'][$member->id] = $member->customer->fullName().': '.collect($e->errors())->flatten()->first();
            }
        });

        return $result;
    }
}
