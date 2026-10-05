<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            // platform | company — which Paystack account (and so which secret
            // key) the charge was created on; verify/OTP/webhooks must use it.
            $table->string('paystack_account', 10)->default('platform')->after('flow');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn('paystack_account');
        });
    }
};
