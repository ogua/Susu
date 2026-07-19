<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('loan_group_id')->constrained()->restrictOnDelete();
            // Reuses the same loan_products table as individual loans — it's a
            // pure rate/term/frequency template with no individual-borrower
            // assumptions, so no duplicate product table is needed.
            $table->foreignUuid('loan_product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agent_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('receivable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();

            $table->string('loan_number', 40);

            // Snapshotted from the product at apply time — later product
            // changes must never retroactively alter an already-applied loan.
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

            // Snapshot of how many active members the equal split was divided
            // across at disbursement — keeps the math reconstructable even if
            // the roster changes afterward.
            $table->unsignedSmallInteger('member_count_at_disbursement')->nullable();

            $table->string('status', 20)->default('applied');
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
        Schema::dropIfExists('group_loans');
    }
};
