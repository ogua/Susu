<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a demo company with two branches and one staff user per tier.
 * Local/dev only — never run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::factory()->create([
            'name' => 'Demo Susu Company',
            'slug' => 'demo-susu',
            'domain_alias' => 'demo.susuapp.test',
            'contact_email' => 'info@demo.susuapp.test',
        ]);

        $mainBranch = Branch::factory()->for($company)->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
            'code' => 'MB',
        ]);

        $eastBranch = Branch::factory()->for($company)->create([
            'name' => 'East Branch',
            'slug' => 'east-branch',
            'code' => 'EB',
        ]);

        User::factory()->companyAdmin($company)->create([
            'name' => 'Demo Company Admin',
            'email' => 'admin@demo.susuapp.test',
        ]);

        User::factory()->branchManager($mainBranch)->create([
            'name' => 'Demo Branch Manager',
            'email' => 'manager@demo.susuapp.test',
        ]);

        User::factory()->fieldAgent($mainBranch)->create([
            'name' => 'Demo Field Agent',
            'email' => 'agent@demo.susuapp.test',
        ]);

        User::factory()->fieldAgent($eastBranch)->create([
            'name' => 'Demo East Agent',
            'email' => 'agent.east@demo.susuapp.test',
        ]);
    }
}
