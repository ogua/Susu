<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GroupLoan;
use App\Models\GroupLoanInstallment;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoan>
 */
class GroupLoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'branch_id' => $branch,
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'loan_group_id' => fn (array $attributes) => LoanGroup::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'loan_group_member_id' => fn (array $attributes) => LoanGroupMember::factory()->create([
                'loan_group_id' => $attributes['loan_group_id'],
            ])->id,
            'customer_id' => fn (array $attributes) => LoanGroupMember::find($attributes['loan_group_member_id'])->customer_id,
            'loan_number' => strtoupper(fake()->unique()->bothify('GL-######')),
            'principal_amount' => 1000_00,
            'security_deposit_amount' => 100_00,
            'periodic_amount' => 100_00,
            'outstanding_balance' => 0,
            'repayment_frequency' => 'weekly',
            'start_date' => now()->toDateString(),
            'total_periods' => 10,
            'deposit_status' => 'pending',
            'status' => 'draft',
            'issued_at' => now(),
        ];
    }

    /** Deposit paid in and held. */
    public function held(): static
    {
        return $this->state(fn (): array => ['deposit_status' => 'held']);
    }

    /** Activated: schedule generated, principal outstanding. */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deposit_status' => $attributes['security_deposit_amount'] > 0 ? 'held' : 'held',
            'status' => 'active',
            'outstanding_balance' => $attributes['principal_amount'],
            'activated_at' => now(),
        ])->afterCreating(function (GroupLoan $groupLoan): void {
            if ($groupLoan->installments()->exists()) {
                return;
            }

            $periodic = $groupLoan->periodic_amount;
            $principal = $groupLoan->principal_amount;
            $count = $periodic >= $principal ? 1 : intdiv($principal, $periodic) + ($principal % $periodic > 0 ? 1 : 0);
            $due = $groupLoan->start_date->copy();

            for ($sequence = 1; $sequence <= $count; $sequence++) {
                $amount = $sequence === $count ? $principal - ($periodic * ($count - 1)) : $periodic;
                GroupLoanInstallment::create([
                    'group_loan_id' => $groupLoan->id,
                    'sequence' => $sequence,
                    'due_date' => $due->toDateString(),
                    'amount_due' => $amount,
                ]);
                $due->addWeek();
            }
        });
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'closed',
            'deposit_status' => 'held',
            'outstanding_balance' => 0,
            'closed_at' => now(),
        ]);
    }

    public function writtenOff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'written_off',
            'deposit_status' => 'held',
            'outstanding_balance' => 0,
            'written_off_at' => now(),
            'write_off_reason' => 'Uncollectible',
            'write_off_amount' => $attributes['principal_amount'],
        ]);
    }
}
