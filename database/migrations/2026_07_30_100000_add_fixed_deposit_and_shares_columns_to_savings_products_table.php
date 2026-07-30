<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            // Applies to type = fixed_deposit only: per-annum rate, prorated
            // by term length and paid into the account balance at maturity.
            $table->unsignedInteger('interest_rate_bps')->default(0)->after('early_withdrawal_penalty_bps');
            // Applies to type = shares only: minor units per share. Nullable
            // (not a 0-default) so an unconfigured value is unambiguous —
            // 0 would silently zero out share_count * par_value math.
            $table->unsignedBigInteger('par_value')->nullable()->after('interest_rate_bps');
        });
    }

    public function down(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            $table->dropColumn(['interest_rate_bps', 'par_value']);
        });
    }
};
