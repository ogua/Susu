<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deposits no longer settle separately from ordinary savings — 'settled' is
 * being retired from the DepositStatus enum. Collapses any historical rows
 * into 'held' before that enum case is removed from the codebase, so
 * GroupLoan::deposit_status can never fail to cast on hydration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('group_loans')->where('deposit_status', 'settled')->update(['deposit_status' => 'held']);
    }

    public function down(): void
    {
        // Lossy: which 'held' rows were previously 'settled' is not recoverable.
    }
};
