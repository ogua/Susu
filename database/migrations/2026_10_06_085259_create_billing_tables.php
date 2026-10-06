<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_amount')->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->string('billing_period', 10)->default('monthly');
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 12);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_subscription_id')->constrained(indexName: 'sub_invoices_subscription_fk')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('due_at');
            $table->string('status', 10)->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->foreignUuid('recorded_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['company_subscription_id', 'period_start'], 'sub_invoices_subscription_period_unique');
            $table->index(['status', 'due_at'], 'sub_invoices_status_due_index');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('suspended_reason', 20)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('suspended_reason');
        });

        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('plans');
    }
};
