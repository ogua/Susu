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
        Schema::create('desktop_license_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Deliberately no company_id/customer FK — the desktop app has no
            // account concept tied to an install (G3/G4), so this is a guest
            // purchase keyed only by the install id the activation screen shows.
            $table->string('install_id');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();

            $table->unsignedInteger('duration_days');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('GHS');

            $table->string('status', 20)->default('pending'); // pending|paid|issued|failed
            $table->string('provider_reference')->nullable()->unique();
            $table->text('license_key')->nullable();
            $table->date('expires_at')->nullable();

            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('web_purchase'); // web_purchase|admin_manual
            $table->timestamp('notified_at')->nullable();
            $table->json('raw_response')->nullable();

            $table->timestamps();

            $table->index('install_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('desktop_license_sales');
    }
};
