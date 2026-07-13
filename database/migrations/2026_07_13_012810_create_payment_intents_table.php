<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->uuidMorphs('payable'); // savings_account today; loan/group_contribution in later phases
            $table->foreignUuid('initiated_by')->constrained('users')->restrictOnDelete();
            $table->string('flow', 20); // charge_api | checkout
            $table->string('channel', 20)->nullable(); // mtn | vod | atl (mobile money) | card
            $table->string('phone', 32)->nullable();
            $table->bigInteger('amount');
            $table->string('status', 20);
            $table->string('provider_reference')->nullable()->unique();
            $table->uuid('client_reference')->unique();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
