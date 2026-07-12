<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'admin@susuapp.test')],
            [
                'name' => 'Super Admin',
                'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
                'is_active' => true,
            ],
        );

        $user->assignRole('super_admin');
    }
}
