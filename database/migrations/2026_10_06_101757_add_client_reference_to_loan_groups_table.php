<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_groups', function (Blueprint $table) {
            // Offline-created customer groups use this as their id (see CreateLoanGroupAction).
            $table->uuid('client_reference')->nullable()->unique()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('loan_groups', function (Blueprint $table) {
            $table->dropUnique(['client_reference']);
            $table->dropColumn('client_reference');
        });
    }
};
