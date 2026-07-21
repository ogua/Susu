<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->timestamp('written_off_at')->nullable()->after('closed_at');
            $table->text('write_off_reason')->nullable()->after('written_off_at');
            // Snapshot of outstanding_balance immediately before write-off, so
            // reports can sum total written-off exposure without a ledger join.
            $table->unsignedBigInteger('write_off_amount')->nullable()->after('write_off_reason');
        });
    }

    public function down(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->dropColumn(['written_off_at', 'write_off_reason', 'write_off_amount']);
        });
    }
};
