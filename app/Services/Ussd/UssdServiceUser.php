<?php

namespace App\Services\Ussd;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The user a USSD MoMo contribution is initiated as.
 *
 * A customer with an app login initiates as themselves (RecordCollectionAction
 * already allows a customer to fund their own account). Anyone else uses their
 * company's `ussd_service` account: inactive with a random password, so it can
 * never sign in to the panel or the API — it exists only to attribute charges
 * the customer approves on their own phone.
 */
class UssdServiceUser
{
    public const ROLE = 'ussd_service';

    public function initiatorFor(Customer $customer): User
    {
        return $customer->user ?? $this->forCompany((string) $customer->company_id);
    }

    public function forCompany(string $companyId): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => "ussd-service+{$companyId}@system.susuapp.local"],
            [
                'company_id' => $companyId,
                'name' => 'USSD Service',
                'password' => Hash::make(Str::random(64)),
                'is_active' => false,
            ],
        );

        if (! $user->hasRole(self::ROLE)) {
            Role::findOrCreate(self::ROLE, 'web');
            $user->assignRole(self::ROLE);
        }

        return $user;
    }
}
