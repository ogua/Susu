<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan application wizard: purpose + a chosen first repayment date on the
 * loan, and itemised charges, collateral and guarantors. The legacy
 * guarantor_name/guarantor_phone columns stay (mirrored from the first
 * guarantor) so older API clients keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('purpose', 255)->nullable()->after('notes');
            $table->date('first_repayment_date')->nullable()->after('grace_period_days');
        });

        Schema::create('loan_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });

        Schema::create('loan_collaterals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('description', 255);
            $table->unsignedBigInteger('estimated_value')->default(0);
            $table->string('serial_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_guarantors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('phone', 32)->nullable();
            $table->string('relationship', 60)->nullable();
            $table->text('address')->nullable();
            $table->string('id_type', 30)->nullable();
            $table->text('id_number')->nullable();
            $table->unsignedBigInteger('guaranteed_amount')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantors');
        Schema::dropIfExists('loan_collaterals');
        Schema::dropIfExists('loan_charges');

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'first_repayment_date']);
        });
    }
};
