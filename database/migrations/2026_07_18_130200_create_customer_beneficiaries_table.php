<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_beneficiaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('relationship', 60)->nullable();
            $table->unsignedBigInteger('amount_of_legacy')->default(0);
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('town', 120)->nullable();
            $table->string('county', 120)->nullable();
            $table->string('state_region', 120)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_beneficiaries');
    }
};
