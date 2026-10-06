<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Demo requests from the public OguaFinance website — platform-level
        // leads, not tied to any company.
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('organisation');
            $table->string('email');
            $table->string('phone', 50);
            $table->string('organisation_type', 40);
            $table->unsignedInteger('branches_count')->nullable();
            $table->text('message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->foreignUuid('handled_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email', 'created_at']);
            $table->index('handled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
