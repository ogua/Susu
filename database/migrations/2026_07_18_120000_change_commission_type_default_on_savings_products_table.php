<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            // Act 843-aligned: new products default to charging no commission;
            // a company opts in to First Contribution/Percentage/Flat/etc.
            $table->string('commission_type', 40)->default('none')->change();
        });
    }

    public function down(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            $table->string('commission_type', 40)->default('first_contribution_per_cycle')->change();
        });
    }
};
