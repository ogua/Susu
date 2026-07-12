<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('reference')->unique();
            $table->uuid('client_reference')->nullable()->unique();
            $table->string('origin', 10)->default('web');
            $table->string('type', 30);
            $table->string('status', 12)->default('completed');
            $table->string('payment_method', 16)->default('cash');
            $table->text('description')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamp('posted_at')->useCurrent();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->uuid('reversed_entry_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('reversed_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'branch_id', 'recorded_at']);
            $table->index(['recorded_by', 'recorded_at']);
            $table->index(['type', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
