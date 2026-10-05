<?php

namespace App\Actions\Customers;

use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves a customer — with their savings accounts, open individual loans and
 * pending withdrawal requests — to another branch of the same company, and
 * optionally hands them to a field agent there.
 *
 * Journal history is NOT rewritten: entries already posted stay under the
 * branch where the cash actually moved. Only the customer's own sub-ledger
 * accounts (savings liability, loan receivable) are re-homed, so future
 * postings and branch-scoped balances follow the customer. The trial balance
 * is company-wide, so this never unbalances the books.
 *
 * Group memberships are branch rosters, so a customer still on an active
 * loan group / susu group (or with an open group loan) must be removed from
 * it first — silently dragging a member out of a roster would break the
 * group's collection sheet.
 */
class TransferCustomerAction
{
    public function __construct(private AssignCustomerAgentAction $assignAgent) {}

    public function execute(
        Customer $customer,
        Branch $toBranch,
        User $transferredBy,
        ?User $agent = null,
        ?string $reason = null,
    ): Customer {
        $this->assertTransferable($customer, $toBranch);

        return DB::transaction(function () use ($customer, $toBranch, $transferredBy, $agent, $reason): Customer {
            $fromBranchId = $customer->branch_id;

            $customer->update(['branch_id' => $toBranch->id, 'assigned_agent_id' => null]);

            $savingsLedgerIds = $customer->savingsAccounts()->pluck('ledger_account_id')->filter();
            $customer->savingsAccounts()->update(['branch_id' => $toBranch->id, 'agent_id' => null]);

            $openLoans = $customer->loans()
                ->whereIn('status', [LoanStatus::Applied, LoanStatus::Approved, LoanStatus::Disbursed]);
            $receivableIds = (clone $openLoans)->pluck('receivable_account_id')->filter();
            $openLoans->update(['branch_id' => $toBranch->id, 'agent_id' => null]);

            LedgerAccount::whereIn('id', $savingsLedgerIds->merge($receivableIds))
                ->update(['branch_id' => $toBranch->id]);

            $customer->withdrawalRequests()
                ->where('status', WithdrawalStatus::Pending)
                ->update(['branch_id' => $toBranch->id]);

            activity('customer')
                ->performedOn($customer)
                ->causedBy($transferredBy)
                ->event('transferred')
                ->withProperties([
                    'from_branch_id' => $fromBranchId,
                    'to_branch_id' => $toBranch->id,
                    'reason' => $reason,
                ])
                ->log("Transferred to {$toBranch->name}");

            if ($agent !== null) {
                $this->assignAgent->execute($customer->refresh(), $agent, $transferredBy);
            }

            return $customer->refresh();
        });
    }

    /**
     * Transfers each customer independently so one blocked customer doesn't
     * stop the rest of a bulk transfer.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array{transferred: list<string>, failed: array<string, string>}
     */
    public function executeMany(
        Collection $customers,
        Branch $toBranch,
        User $transferredBy,
        ?User $agent = null,
        ?string $reason = null,
    ): array {
        $result = ['transferred' => [], 'failed' => []];

        foreach ($customers as $customer) {
            try {
                $this->execute($customer, $toBranch, $transferredBy, $agent, $reason);
                $result['transferred'][] = $customer->id;
            } catch (ValidationException $e) {
                $result['failed'][$customer->id] = collect($e->errors())->flatten()->first();
            } catch (Throwable $e) {
                report($e);
                $result['failed'][$customer->id] = 'Could not be transferred.';
            }
        }

        return $result;
    }

    public function assertTransferable(Customer $customer, Branch $toBranch): void
    {
        $name = $customer->fullName() ?: $customer->business_name;

        if ($toBranch->company_id !== $customer->company_id) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch in the same company.']);
        }
        if ($toBranch->id === $customer->branch_id) {
            throw ValidationException::withMessages(['branch_id' => "{$name} is already in {$toBranch->name}."]);
        }
        if ($customer->loanGroupMemberships()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['customer' => "{$name} is still on a customer group — remove them from it first."]);
        }
        if ($customer->groupLoans()->whereIn('status', [GroupLoanStatus::Draft, GroupLoanStatus::Active])->exists()) {
            throw ValidationException::withMessages(['customer' => "{$name} has an open group loan."]);
        }
        if ($customer->groupMemberships()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['customer' => "{$name} is still on a susu group — remove them from it first."]);
        }
    }
}
