<?php

namespace App\Actions\Customers;

use App\Enums\AccountStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a field agent responsible for a customer. The agent is set both on the
 * customer (assigned_agent_id) and on the customer's active savings accounts
 * (agent_id) — the latter is what decides which agent sees and collects on an
 * account in the mobile app, so assigning only the customer would change nothing
 * in the field. Passing null unassigns.
 */
class AssignCustomerAgentAction
{
    public function execute(Customer $customer, ?User $agent, User $assignedBy): Customer
    {
        $this->assertAssignable($customer, $agent);

        DB::transaction(function () use ($customer, $agent, $assignedBy): void {
            $previousAgentId = $customer->assigned_agent_id;

            $customer->update(['assigned_agent_id' => $agent?->id]);

            $customer->savingsAccounts()
                ->where('status', AccountStatus::Active)
                ->update(['agent_id' => $agent?->id]);

            activity('customer')
                ->performedOn($customer)
                ->causedBy($assignedBy)
                ->event('agent_assigned')
                ->withProperties(['from_agent_id' => $previousAgentId, 'to_agent_id' => $agent?->id])
                ->log($agent ? "Assigned to agent {$agent->name}" : 'Agent unassigned');
        });

        return $customer->refresh();
    }

    public function assertAssignable(Customer $customer, ?User $agent): void
    {
        if ($agent === null) {
            return;
        }

        if ($agent->company_id !== $customer->company_id || ! $agent->hasRole('field_agent')) {
            throw ValidationException::withMessages(['agent_id' => 'Choose a field agent from this company.']);
        }

        if (! $agent->is_active) {
            throw ValidationException::withMessages(['agent_id' => "{$agent->name} is deactivated."]);
        }

        if ($agent->branch_id !== $customer->branch_id) {
            throw ValidationException::withMessages(['agent_id' => "{$agent->name} does not work in the customer's branch."]);
        }
    }
}
