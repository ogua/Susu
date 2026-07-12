<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2332'.fake()->unique()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('super_admin'));
    }

    public function companyAdmin(?Company $company = null): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'company_id' => $company?->id ?? Company::factory(),
            ])
            ->afterCreating(function (User $user): void {
                $user->assignRole('company_admin');
                $user->branches()->syncWithoutDetaching(
                    Branch::where('company_id', $user->company_id)->pluck('id')
                );
            });
    }

    public function branchManager(?Branch $branch = null): static
    {
        return $this->forBranchRole($branch, 'branch_manager');
    }

    public function fieldAgent(?Branch $branch = null): static
    {
        return $this->forBranchRole($branch, 'field_agent');
    }

    public function customerUser(?Company $company = null): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'company_id' => $company?->id ?? Company::factory(),
            ])
            ->afterCreating(fn (User $user) => $user->assignRole('customer'));
    }

    /**
     * Attach the user to a branch (creating one if needed) with the given role.
     */
    protected function forBranchRole(?Branch $branch, string $role): static
    {
        return $this
            ->state(function (array $attributes) use ($branch) {
                $resolved = $branch ?? Branch::factory()->create();

                return [
                    'company_id' => $resolved->company_id,
                    'branch_id' => $resolved->id,
                ];
            })
            ->afterCreating(function (User $user) use ($role): void {
                $user->assignRole($role);
                $user->branches()->syncWithoutDetaching([$user->branch_id]);
            });
    }
}
