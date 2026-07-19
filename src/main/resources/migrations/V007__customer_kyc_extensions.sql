-- V007: eBanQR-parity KYC extensions — mirrors the Laravel backend's
-- 2026_07_18_130000_add_extended_kyc_fields_to_customers_table.php plus the
-- three new child tables (customer_identifications/beneficiaries/family_members).
-- No FK constraints, matching this schema's existing single-tenant, no-FK
-- convention (see V002's header comment). SQLite requires one column per
-- ALTER TABLE statement; MigrationRunner already splits this file on ';'.

ALTER TABLE customers ADD COLUMN client_type VARCHAR(12) NOT NULL DEFAULT 'individual';
ALTER TABLE customers ADD COLUMN external_id VARCHAR(60);
ALTER TABLE customers ADD COLUMN place_of_birth VARCHAR(150);
ALTER TABLE customers ADD COLUMN nationality VARCHAR(80);
ALTER TABLE customers ADD COLUMN email VARCHAR(255);
ALTER TABLE customers ADD COLUMN city_town VARCHAR(120);
ALTER TABLE customers ADD COLUMN state_region VARCHAR(120);
ALTER TABLE customers ADD COLUMN country VARCHAR(80);
ALTER TABLE customers ADD COLUMN digital_address VARCHAR(40);
ALTER TABLE customers ADD COLUMN latitude DECIMAL(10,7);
ALTER TABLE customers ADD COLUMN longitude DECIMAL(10,7);
ALTER TABLE customers ADD COLUMN marital_status VARCHAR(20);
ALTER TABLE customers ADD COLUMN spouse_name VARCHAR(255);
ALTER TABLE customers ADD COLUMN spouse_date_of_birth TEXT;
ALTER TABLE customers ADD COLUMN spouse_occupation VARCHAR(120);
ALTER TABLE customers ADD COLUMN has_past_loan INTEGER;
ALTER TABLE customers ADD COLUMN past_loan_institution VARCHAR(150);
ALTER TABLE customers ADD COLUMN spouse_employer_name VARCHAR(255);
ALTER TABLE customers ADD COLUMN spouse_employer_address TEXT;
ALTER TABLE customers ADD COLUMN spouse_employer_town VARCHAR(120);
ALTER TABLE customers ADD COLUMN spouse_employer_county VARCHAR(120);
ALTER TABLE customers ADD COLUMN spouse_employer_region VARCHAR(120);
ALTER TABLE customers ADD COLUMN religion VARCHAR(60);
ALTER TABLE customers ADD COLUMN business_name VARCHAR(255);
ALTER TABLE customers ADD COLUMN business_phone VARCHAR(32);
ALTER TABLE customers ADD COLUMN business_tin VARCHAR(40);
ALTER TABLE customers ADD COLUMN business_line VARCHAR(80);
ALTER TABLE customers ADD COLUMN business_structure VARCHAR(40);
ALTER TABLE customers ADD COLUMN business_start_date TEXT;
ALTER TABLE customers ADD COLUMN business_income_level VARCHAR(20);
ALTER TABLE customers ADD COLUMN business_address TEXT;
ALTER TABLE customers ADD COLUMN business_town VARCHAR(120);
ALTER TABLE customers ADD COLUMN business_county VARCHAR(120);
ALTER TABLE customers ADD COLUMN business_region VARCHAR(120);
ALTER TABLE customers ADD COLUMN business_latitude DECIMAL(10,7);
ALTER TABLE customers ADD COLUMN business_longitude DECIMAL(10,7);
ALTER TABLE customers ADD COLUMN tin VARCHAR(40);
ALTER TABLE customers ADD COLUMN other_names VARCHAR(255);
ALTER TABLE customers ADD COLUMN occupation VARCHAR(120);
ALTER TABLE customers ADD COLUMN job_title VARCHAR(120);
ALTER TABLE customers ADD COLUMN country_of_residence VARCHAR(80);
ALTER TABLE customers ADD COLUMN residence_permit VARCHAR(60);
ALTER TABLE customers ADD COLUMN residency_status VARCHAR(20);
ALTER TABLE customers ADD COLUMN assigned_agent_id TEXT;

CREATE TABLE IF NOT EXISTS customer_identifications (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    id_type VARCHAR(30) NOT NULL DEFAULT 'ghana_card',
    id_number TEXT NOT NULL,
    issue_date TEXT,
    expiry_date TEXT,
    description VARCHAR(255),
    is_primary INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_customer_identifications_customer ON customer_identifications (customer_id);

CREATE TABLE IF NOT EXISTS customer_beneficiaries (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    name VARCHAR(150) NOT NULL,
    relationship VARCHAR(60),
    amount_of_legacy INTEGER NOT NULL DEFAULT 0,
    phone VARCHAR(32),
    address TEXT,
    town VARCHAR(120),
    county VARCHAR(120),
    state_region VARCHAR(120),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_customer_beneficiaries_customer ON customer_beneficiaries (customer_id);

CREATE TABLE IF NOT EXISTS customer_family_members (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    name VARCHAR(150) NOT NULL,
    relationship VARCHAR(60),
    contact_phone VARCHAR(32),
    occupation VARCHAR(120),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_customer_family_members_customer ON customer_family_members (customer_id);
