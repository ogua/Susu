<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('suspended_reason');
            $table->timestamp('personal_data_erased_at')->nullable()->after('archived_at');
        });

        Schema::create('company_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->string('status', 10)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_exports');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'personal_data_erased_at']);
        });
    }
};
