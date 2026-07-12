<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * The application role hierarchy. Permissions are attached to roles by
     * Filament Shield (`shield:generate`); super_admin bypasses checks via gate.
     */
    public const ROLES = [
        'super_admin',
        'company_admin',
        'branch_manager',
        'field_agent',
        'customer',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
