<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_daily_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedBigInteger('collections_total')->default(0);
            $table->unsignedInteger('collections_count')->default(0);
            $table->bigInteger('expected_cash')->default(0);
            $table->bigInteger('declared_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->string('status', 12)->default('open');
            $table->foreignUuid('reconciled_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('client_reference')->nullable()->unique();
            $table->timestamps();

            $table->unique(['agent_id', 'summary_date']);
            $table->index(['branch_id', 'summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_daily_summaries');
    }
};
