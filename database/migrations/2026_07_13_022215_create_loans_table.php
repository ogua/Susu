<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('loan_product_id')->constrained()->restrictOnDelete();
            // Optional: the susu account EligibilityService evaluated for this application.
            $table->foreignUuid('savings_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('receivable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();

            $table->string('loan_number', 40);

            // Snapshotted from the product at approval time — later product changes
            // must never retroactively alter an already-approved loan's terms.
            $table->unsignedBigInteger('principal_amount');
            $table->string('interest_method', 20);
            $table->unsignedInteger('interest_rate_bps');
            $table->unsignedSmallInteger('term_period_count');
            $table->string('repayment_frequency', 10);
            $table->unsignedBigInteger('origination_fee_amount')->default(0);
            $table->unsignedInteger('penalty_rate_bps')->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(3);

            $table->unsignedBigInteger('total_interest')->default(0);
            $table->unsignedBigInteger('total_repayable')->default(0);
            $table->unsignedBigInteger('outstanding_balance')->default(0);

            $table->string('status', 20)->default('applied');
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_phone', 32)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_reference')->nullable()->unique();

            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'loan_number']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
