<?php

namespace App\Actions\LoanGroups;

use App\Actions\Savings\OpenSavingsAccountAction;
use App\Enums\AccountStatus;
use App\Enums\SavingsProductType;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Apply a saving to the group": opens an account on the chosen savings
 * product for every active member who doesn't already hold an active one,
 * through OpenSavingsAccountAction (so each gets its own ledger sub-account).
 * Without an explicit agent, each account goes to the member's assigned agent.
 */
class OpenSavingsForGroupAction
{
    public function __construct(private OpenSavingsAccountAction $open) {}

    /**
     * @return array{opened: list<string>, skipped: array<string, string>}
     */
    public function execute(LoanGroup $loanGroup, SavingsProduct $product, ?User $agent = null, ?int $contributionAmount = null): array
    {
        if ($product->company_id !== $loanGroup->company_id || ! $product->is_active) {
            throw ValidationException::withMessages(['savings_product_id' => 'This savings product is not available.']);
        }
        if ($product->type !== SavingsProductType::DailySusu) {
            throw ValidationException::withMessages(['savings_product_id' => 'Only daily susu products can be opened for a whole group — target and fixed deposits need per-member amounts and dates.']);
        }

        $members = $loanGroup->members()->where('status', 'active')->with('customer')->get();
        $result = ['opened' => [], 'skipped' => []];

        $members->each(function (LoanGroupMember $member) use (&$result, $product, $agent, $contributionAmount): void {
            $customer = $member->customer;

            if ($customer->savingsAccounts()->where('savings_product_id', $product->id)->where('status', AccountStatus::Active)->exists()) {
                $result['skipped'][$member->id] = $customer->fullName().': already has this account.';

                return;
            }

            try {
                $result['opened'][] = $this->open->execute($customer, $product, $agent ?? $customer->assignedAgent, $contributionAmount)->id;
            } catch (ValidationException $e) {
                $result['skipped'][$member->id] = $customer->fullName().': '.collect($e->errors())->flatten()->first();
            } catch (Throwable $e) {
                report($e);
                $result['skipped'][$member->id] = $customer->fullName().': could not be opened.';
            }
        });

        return $result;
    }
}
