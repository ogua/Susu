<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_liability_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->foreignUuid('deposit_liability_account_id')->nullable()->after('receivable_account_id')
                ->constrained('ledger_accounts')->restrictOnDelete();
        });
    }
};
