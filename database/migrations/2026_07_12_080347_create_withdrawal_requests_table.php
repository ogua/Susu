<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('savings_account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('reason')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignUuid('requested_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->foreignUuid('paid_entry_id')->nullable()->references('id')->on('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
