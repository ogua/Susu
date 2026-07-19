<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            // No rotation_position — that's the whole reason this is a
            // separate model tree from the susu group_members table.
            $table->string('status', 12)->default('active'); // active | left
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_group_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_group_members');
    }
};
