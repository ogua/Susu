<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lifecycle of one member's refundable security deposit. One `held` row
 * when it is paid in, then a terminal `applied` (offset against the loan
 * balance, non-cash), `refunded` (paid back in cash) and/or `seized`
 * (applied to outstanding during a write-off) row. `client_reference` makes
 * each deposit event idempotent under offline replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loan_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('type', 12); // held | applied | refunded | seized
            $table->timestamp('recorded_at')->useCurrent();
            $table->uuid('client_reference')->nullable()->unique();
            $table->timestamps();

            $table->index(['group_loan_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_loan_deposits');
    }
};
