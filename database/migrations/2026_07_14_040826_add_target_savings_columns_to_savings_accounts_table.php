<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            // Set at open time for target-type accounts only.
            $table->unsignedBigInteger('target_amount')->nullable()->after('balance');
            $table->date('matures_at')->nullable()->after('target_amount');
            // Flipped by savings:mature-target-accounts once matures_at has
            // passed — waives the early-withdrawal penalty from that point on.
            $table->timestamp('matured_at')->nullable()->after('matures_at');
        });
    }

    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['target_amount', 'matures_at', 'matured_at']);
        });
    }
};
