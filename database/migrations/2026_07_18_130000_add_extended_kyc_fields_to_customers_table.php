<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('client_type', 12)->default('individual')->after('customer_code');
            $table->string('external_id', 60)->nullable();
            $table->string('place_of_birth', 150)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('email')->nullable();

            // Address & Location.
            $table->string('city_town', 120)->nullable();
            $table->string('state_region', 120)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('digital_address', 40)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Marital / family (purged on KYC retention expiry, see PurgeExpiredKycData).
            $table->string('marital_status', 20)->nullable();
            $table->string('spouse_name')->nullable();
            $table->date('spouse_date_of_birth')->nullable();
            $table->string('spouse_occupation', 120)->nullable();
            $table->boolean('has_past_loan')->nullable();
            $table->string('past_loan_institution', 150)->nullable();
            $table->string('spouse_employer_name')->nullable();
            $table->text('spouse_employer_address')->nullable();
            $table->string('spouse_employer_town', 120)->nullable();
            $table->string('spouse_employer_county', 120)->nullable();
            $table->string('spouse_employer_region', 120)->nullable();
            $table->string('religion', 60)->nullable();

            // Business/employment profile (individual client's own business).
            $table->string('business_name')->nullable();
            $table->string('business_phone', 32)->nullable();
            $table->string('business_tin', 40)->nullable();
            $table->string('business_line', 80)->nullable();
            $table->string('business_structure', 40)->nullable();
            $table->date('business_start_date')->nullable();
            $table->string('business_income_level', 20)->nullable();
            $table->text('business_address')->nullable();
            $table->string('business_town', 120)->nullable();
            $table->string('business_county', 120)->nullable();
            $table->string('business_region', 120)->nullable();
            $table->decimal('business_latitude', 10, 7)->nullable();
            $table->decimal('business_longitude', 10, 7)->nullable();

            // Personal TIN (individual, or a business client's principal contact).
            $table->string('tin', 40)->nullable();

            // Principal contact fields (business clients only).
            $table->string('other_names')->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('country_of_residence', 80)->nullable();
            $table->string('residence_permit', 60)->nullable();

            $table->string('residency_status', 20)->nullable();
            $table->foreignUuid('assigned_agent_id')->nullable()->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_id']);

            $table->dropColumn([
                'assigned_agent_id',
                'client_type', 'external_id', 'place_of_birth', 'nationality', 'email',
                'city_town', 'state_region', 'country', 'digital_address', 'latitude', 'longitude',
                'marital_status', 'spouse_name', 'spouse_date_of_birth', 'spouse_occupation',
                'has_past_loan', 'past_loan_institution', 'spouse_employer_name', 'spouse_employer_address',
                'spouse_employer_town', 'spouse_employer_county', 'spouse_employer_region', 'religion',
                'business_name', 'business_phone', 'business_tin', 'business_line', 'business_structure',
                'business_start_date', 'business_income_level', 'business_address', 'business_town',
                'business_county', 'business_region', 'business_latitude', 'business_longitude', 'tin',
                'other_names', 'occupation', 'job_title', 'country_of_residence', 'residence_permit',
                'residency_status',
            ]);
        });
    }
};
