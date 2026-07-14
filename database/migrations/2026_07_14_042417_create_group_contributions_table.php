<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_contributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_round_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_member_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamp('recorded_at')->useCurrent();
            $table->uuid('client_reference')->nullable()->unique();
            $table->timestamps();

            // One contribution per member per round — a repeat attempt should
            // be an idempotent no-op (found via client_reference), not a
            // second charge.
            $table->unique(['group_round_id', 'group_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_contributions');
    }
};
