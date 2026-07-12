<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('savings_product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agent_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignUuid('ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('account_number', 40);
            $table->unsignedBigInteger('contribution_amount');
            $table->unsignedInteger('cycle_number')->default(1);
            $table->date('cycle_started_at')->nullable();
            $table->unsignedSmallInteger('contributions_this_cycle')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->string('status', 12)->default('active');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('client_reference')->nullable()->unique();
            $table->timestamps();

            $table->unique(['company_id', 'account_number']);
            $table->index(['agent_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_accounts');
    }
};
