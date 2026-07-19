<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loan_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->unsignedBigInteger('principal_due');
            $table->unsignedBigInteger('interest_due');
            $table->unsignedBigInteger('penalty_due')->default(0);
            $table->unsignedBigInteger('principal_paid')->default(0);
            $table->unsignedBigInteger('interest_paid')->default(0);
            $table->unsignedBigInteger('penalty_paid')->default(0);
            $table->string('status', 20)->default('pending'); // pending | partially_paid | paid | overdue
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['group_loan_id', 'sequence']);
            $table->index(['group_loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_loan_installments');
    }
};
