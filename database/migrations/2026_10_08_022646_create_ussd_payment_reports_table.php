<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A MoMo charge started over USSD, keyed by the Ogua USSD platform's transaction
        // id (the PaymentIntent's client_reference). Kept apart from payment_intents so
        // the core payments table is untouched; tracks reporting the final outcome back.
        Schema::create('ussd_payment_reports', function (Blueprint $table) {
            $table->uuid('client_reference')->primary();
            $table->string('reported_status', 16)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ussd_payment_reports');
    }
};
