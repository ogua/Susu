<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            // Applies to type = target only: percentage (bps) of the
            // requested amount withheld when a target account is withdrawn
            // from before its matures_at date.
            $table->unsignedInteger('early_withdrawal_penalty_bps')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            $table->dropColumn('early_withdrawal_penalty_bps');
        });
    }
};
