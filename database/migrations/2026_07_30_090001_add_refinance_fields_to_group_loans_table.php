<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            // Points a NEW group loan back at the OLD group loan it superseded
            // via a restructure or top-up. Nullable — most group loans have no
            // predecessor.
            $table->foreignUuid('previous_group_loan_id')->nullable()->after('loan_number')
                ->constrained('group_loans')->restrictOnDelete();

            // The portion of THIS group loan's principal_amount rolled over
            // from previous_group_loan_id's receivable balance, as opposed to
            // fresh cash. Equals principal_amount for a pure restructure;
            // principal_amount minus the top-up cash for a top-up.
            $table->unsignedBigInteger('rolled_over_amount')->default(0)->after('previous_group_loan_id');

            // Set on the OLD group loan when it closes into Refinanced,
            // mirroring written_off_at/write_off_reason/write_off_amount exactly.
            $table->timestamp('refinanced_at')->nullable()->after('written_off_at');
            $table->string('refinance_type', 20)->nullable()->after('refinanced_at'); // 'restructure' | 'top_up'
            $table->text('refinance_reason')->nullable()->after('refinance_type');
            $table->unsignedBigInteger('refinance_amount')->nullable()->after('refinance_reason');
        });
    }

    public function down(): void
    {
        Schema::table('group_loans', function (Blueprint $table) {
            $table->dropForeign(['previous_group_loan_id']);
            $table->dropColumn(['previous_group_loan_id', 'rolled_over_amount', 'refinanced_at', 'refinance_type', 'refinance_reason', 'refinance_amount']);
        });
    }
};
