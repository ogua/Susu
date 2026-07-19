<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_loan_borrowers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('loan_group_member_id')->constrained()->restrictOnDelete();
            // Denormalized (mirrors Loan.customer_id being direct rather than
            // requiring a join) — avoids a 3-hop join for every report/API query.
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('share_principal');
            $table->unsignedBigInteger('share_outstanding');
            $table->timestamps();

            $table->unique(['group_loan_id', 'loan_group_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_loan_borrowers');
    }
};
