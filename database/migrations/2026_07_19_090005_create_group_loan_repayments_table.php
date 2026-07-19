<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loan_repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_loan_borrower_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamp('recorded_at')->useCurrent();
            $table->uuid('client_reference')->nullable()->unique();
            $table->timestamps();

            // Unlike group_contributions (one per member per round), a member
            // can repay a group loan many times over its life — no per-round
            // uniqueness constraint here.
            $table->index(['group_loan_id', 'group_loan_borrower_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_loan_repayments');
    }
};
