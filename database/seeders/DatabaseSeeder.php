<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SuperAdminSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
            $this->call(CustomerSeeder::class);
            $this->call(SavingsSeeder::class);
            $this->call(LoanProductSeeder::class);
            $this->call(LoanGroupSeeder::class);
        }
    }
}
