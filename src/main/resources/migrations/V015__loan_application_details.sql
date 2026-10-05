-- V015: mirrors the backend's 2026_10_05_164924 migration (loan application
-- wizard). Loans gain a purpose and a chosen first repayment date; charges,
-- collateral and guarantors are itemised in their own tables. The legacy
-- guarantor_name/guarantor_phone columns stay, mirrored from the first
-- guarantor. No FK constraints, matching this schema's convention. SQLite
-- requires one column per ALTER TABLE. MigrationRunner splits on ';'.

ALTER TABLE loans ADD COLUMN purpose VARCHAR(255);
ALTER TABLE loans ADD COLUMN first_repayment_date TEXT;

CREATE TABLE IF NOT EXISTS loan_charges (
    id TEXT PRIMARY KEY,
    loan_id TEXT NOT NULL,
    name VARCHAR(120) NOT NULL,
    amount INTEGER NOT NULL,
    created_at TEXT,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS loan_collaterals (
    id TEXT PRIMARY KEY,
    loan_id TEXT NOT NULL,
    type VARCHAR(60) NOT NULL,
    description VARCHAR(255) NOT NULL,
    estimated_value INTEGER NOT NULL DEFAULT 0,
    serial_number VARCHAR(120),
    notes TEXT,
    created_at TEXT,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS loan_guarantors (
    id TEXT PRIMARY KEY,
    loan_id TEXT NOT NULL,
    customer_id TEXT,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(32),
    relationship VARCHAR(60),
    address TEXT,
    guaranteed_amount INTEGER,
    created_at TEXT,
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_loan_charges_loan ON loan_charges (loan_id);
CREATE INDEX IF NOT EXISTS idx_loan_collaterals_loan ON loan_collaterals (loan_id);
CREATE INDEX IF NOT EXISTS idx_loan_guarantors_loan ON loan_guarantors (loan_id);
