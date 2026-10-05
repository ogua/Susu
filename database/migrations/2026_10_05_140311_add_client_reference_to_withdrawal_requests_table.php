<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // Client-generated idempotency key: a retried mobile request
            // (e.g. after a timeout) returns the original withdrawal request
            // instead of creating a second one.
            $table->uuid('client_reference')->nullable()->unique()->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropUnique(['client_reference']);
            $table->dropColumn('client_reference');
        });
    }
};
