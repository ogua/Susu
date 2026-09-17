<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_loan_deposits', function (Blueprint $table) {
            $table->foreignUuid('savings_account_id')->nullable()->after('group_loan_id')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_loan_deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('savings_account_id');
        });
    }
};
