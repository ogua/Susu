<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20);
            // Unlike susu groups (draft -> active -> completed, locked after
            // activation), a loan group is a persistent roster that can take
            // out loans repeatedly over time — a plain toggle, not a lifecycle.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_groups');
    }
};
