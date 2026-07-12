<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_ops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('op_id')->unique();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('origin', 10);
            $table->string('op_type', 40);
            $table->string('status', 12); // applied | rejected
            $table->json('result')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_ops');
    }
};
