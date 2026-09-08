<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group loan is one loan issued to ONE member of a loan group. The group is
 * just a persistent roster; each member borrows independently, with their own
 * security deposit, their own directly-entered periodic repayment amount, and
 * their own outstanding balance. There is no product, no interest, and no
 * equal-split-across-members — the group's outstanding is simply the sum of
 * its active members' loans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('loan_group_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('loan_group_member_id')->constrained()->restrictOnDelete();
            // Denormalized from the member — keeps company/branch scoping and
            // "whose loan is this" lookups a single hop.
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agent_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('activated_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('receivable_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignUuid('deposit_liability_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();

            $table->string('loan_number', 40);

            // All entered per member at issue time — no product template.
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedBigInteger('security_deposit_amount')->default(0);
            $table->unsignedBigInteger('periodic_amount'); // "amount to be paid for the week/date"
            $table->unsignedBigInteger('outstanding_balance')->default(0);
            $table->string('repayment_frequency', 10); // daily | weekly | monthly
            $table->date('start_date');
            $table->unsignedSmallInteger('total_periods')->default(0);

            $table->string('deposit_status', 12)->default('pending'); // pending | held | settled
            $table->string('status', 12)->default('draft'); // draft | active | closed | written_off

            $table->text('notes')->nullable();
            $table->uuid('client_reference')->nullable()->unique();

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->text('write_off_reason')->nullable();
            $table->unsignedBigInteger('write_off_amount')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'loan_number']);
            $table->index(['company_id', 'status']);
            $table->index(['loan_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_loans');
    }
};
