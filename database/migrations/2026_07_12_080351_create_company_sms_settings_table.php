<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sms_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('log'); // log | arkesel | hubtel
            $table->string('sender_id', 11)->nullable();
            $table->text('api_key')->nullable(); // encrypted cast
            $table->boolean('notifications_enabled')->default(true);
            $table->time('quiet_hours_start')->default('21:00');
            $table->time('quiet_hours_end')->default('07:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sms_settings');
    }
};
