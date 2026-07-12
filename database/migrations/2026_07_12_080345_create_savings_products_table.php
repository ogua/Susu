<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('type', 20)->default('daily_susu');
            $table->unsignedBigInteger('contribution_amount');
            $table->unsignedSmallInteger('cycle_length_days')->default(31);
            $table->string('commission_type', 40)->default('first_contribution_per_cycle');
            $table->unsignedBigInteger('commission_value')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_products');
    }
};
