<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->foreignUuid('write_off_savings_account_id')->nullable()->after('write_off_amount')
                ->constrained('savings_accounts')->nullOnDelete();
            $table->unsignedBigInteger('write_off_savings_applied')->nullable()->after('write_off_savings_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('write_off_savings_account_id');
            $table->dropColumn('write_off_savings_applied');
        });
    }
};
