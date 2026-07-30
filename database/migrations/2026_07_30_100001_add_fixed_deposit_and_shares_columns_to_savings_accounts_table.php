<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            // Snapshotted from the product at open time (type = fixed_deposit
            // only) — deliberately NOT read live off the product like
            // early_withdrawal_penalty_bps is, since a fixed deposit's whole
            // premise is that the rate is locked in at inception.
            $table->unsignedInteger('interest_rate_bps')->nullable()->after('matured_at');
            // type = shares only. balance stays the ledger-authoritative
            // column; share_count is kept in lockstep by BuySharesAction.
            $table->unsignedInteger('share_count')->default(0)->after('interest_rate_bps');
        });
    }

    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['interest_rate_bps', 'share_count']);
        });
    }
};
