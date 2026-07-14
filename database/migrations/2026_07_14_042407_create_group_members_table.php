<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            // Order in which this member receives the pooled payout — the
            // rotation. Assigned by staff when the member joins.
            $table->unsignedSmallInteger('rotation_position');
            $table->string('status', 12)->default('active'); // active | left
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'customer_id']);
            $table->unique(['group_id', 'rotation_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
