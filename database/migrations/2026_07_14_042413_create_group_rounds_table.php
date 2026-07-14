<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payout_member_id')->constrained('group_members')->restrictOnDelete();
            $table->foreignUuid('payout_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->date('due_date');
            $table->unsignedBigInteger('total_expected');
            $table->unsignedBigInteger('total_collected')->default(0);
            $table->string('status', 12)->default('pending'); // pending | collecting | completed
            $table->timestamp('paid_out_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'round_number']);
            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_rounds');
    }
};
