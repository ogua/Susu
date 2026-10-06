<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            // Applies to target and fixed_deposit only: default term used to
            // derive an account's matures_at when none is given at opening.
            $table->unsignedSmallInteger('term_days')->nullable()->after('interest_rate_bps');
        });

        // Fixed deposits and shares are never cycle-collected, so any
        // commission saved on them by the old form must not keep charging.
        DB::table('savings_products')
            ->whereIn('type', ['fixed_deposit', 'shares'])
            ->update(['commission_type' => 'none', 'commission_value' => 0]);
    }

    public function down(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            $table->dropColumn('term_days');
        });
    }
};
