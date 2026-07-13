<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('interest_method', 20)->default('flat'); // flat | reducing_balance
            $table->unsignedInteger('interest_rate_bps'); // per repayment period, e.g. 300 = 3%
            $table->unsignedSmallInteger('term_period_count');
            $table->string('repayment_frequency', 10)->default('monthly'); // weekly | monthly
            $table->unsignedBigInteger('origination_fee_amount')->default(0);
            $table->unsignedInteger('penalty_rate_bps')->default(0); // of overdue installment amount
            $table->unsignedSmallInteger('grace_period_days')->default(3);
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
