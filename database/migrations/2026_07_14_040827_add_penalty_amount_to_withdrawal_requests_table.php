<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // Computed at request time (target accounts withdrawn from before
            // maturity only) so the approver sees the net payout up front.
            $table->unsignedBigInteger('penalty_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('penalty_amount');
        });
    }
};
