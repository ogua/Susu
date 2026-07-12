<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->unique()->references('id')->on('users')->nullOnDelete();
            $table->string('customer_code', 40);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 32);
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('id_type', 30)->default('ghana_card');
            $table->text('id_number')->nullable(); // stored via encrypted cast (Act 843)
            $table->string('id_photo_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();
            $table->string('next_of_kin_relationship', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 12)->default('active');
            $table->uuid('client_reference')->nullable()->unique();
            $table->foreignUuid('registered_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'customer_code']);
            $table->unique(['company_id', 'phone']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
