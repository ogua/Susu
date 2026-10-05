<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('paystack_public_key')->nullable();
            $table->text('paystack_secret_key')->nullable(); // encrypted cast
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_settings');
    }
};
