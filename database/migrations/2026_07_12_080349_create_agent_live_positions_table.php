<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_live_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->unique()->references('id')->on('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('on_duty')->default(false);
            $table->timestamp('located_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'on_duty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_live_positions');
    }
};
