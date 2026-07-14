-- V003: micro-credit / loans engine — products, loans, and their
-- installment schedules. Mirrors the backend's loan_products/loans/
-- loan_installments tables, minus company_id/branch_id (this desktop
-- install represents a single company/branch, per V002's convention).

CREATE TABLE IF NOT EXISTS loan_products (
    id TEXT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(20) NOT NULL,
    interest_method VARCHAR(20) NOT NULL DEFAULT 'flat', -- flat | reducing_balance
    interest_rate_bps INTEGER NOT NULL, -- per repayment period, e.g. 300 = 3%
    term_period_count INTEGER NOT NULL,
    repayment_frequency VARCHAR(10) NOT NULL DEFAULT 'monthly', -- weekly | monthly
    origination_fee_amount INTEGER NOT NULL DEFAULT 0,
    penalty_rate_bps INTEGER NOT NULL DEFAULT 0, -- of overdue installment amount
    grace_period_days INTEGER NOT NULL DEFAULT 3,
    min_amount INTEGER NOT NULL,
    max_amount INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loan_products_code ON loan_products (code);

CREATE TABLE IF NOT EXISTS loans (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    loan_product_id TEXT NOT NULL,
    savings_account_id TEXT,
    agent_id TEXT,
    approved_by TEXT,
    receivable_account_id TEXT,

    loan_number VARCHAR(40) NOT NULL,

    -- Snapshotted from the product at application time — later product
    -- changes must never retroactively alter an already-applied loan's terms.
    principal_amount INTEGER NOT NULL,
    interest_method VARCHAR(20) NOT NULL,
    interest_rate_bps INTEGER NOT NULL,
    term_period_count INTEGER NOT NULL,
    repayment_frequency VARCHAR(10) NOT NULL,
    origination_fee_amount INTEGER NOT NULL DEFAULT 0,
    penalty_rate_bps INTEGER NOT NULL DEFAULT 0,
    grace_period_days INTEGER NOT NULL DEFAULT 3,

    total_interest INTEGER NOT NULL DEFAULT 0,
    total_repayable INTEGER NOT NULL DEFAULT 0,
    outstanding_balance INTEGER NOT NULL DEFAULT 0,

    status VARCHAR(20) NOT NULL DEFAULT 'applied',
    guarantor_name VARCHAR(150),
    guarantor_phone VARCHAR(32),
    rejection_reason TEXT,
    notes TEXT,
    client_reference TEXT,

    applied_at TEXT NOT NULL,
    approved_at TEXT,
    disbursed_at TEXT,
    closed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loans_number ON loans (loan_number);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loans_client_ref ON loans (client_reference);
CREATE INDEX IF NOT EXISTS idx_loans_customer ON loans (customer_id);
CREATE INDEX IF NOT EXISTS idx_loans_status ON loans (status);

CREATE TABLE IF NOT EXISTS loan_installments (
    id TEXT PRIMARY KEY,
    loan_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    principal_due INTEGER NOT NULL,
    interest_due INTEGER NOT NULL,
    penalty_due INTEGER NOT NULL DEFAULT 0,
    -- Tracked separately (not one amount_paid total) so a repayment's ledger
    -- split (Cr receivable vs Cr interest-income vs Cr penalty-income) never
    -- has to be inferred from an application-order convention.
    principal_paid INTEGER NOT NULL DEFAULT 0,
    interest_paid INTEGER NOT NULL DEFAULT 0,
    penalty_paid INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | partially_paid | paid | overdue
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loan_installments_seq ON loan_installments (loan_id, sequence);
CREATE INDEX IF NOT EXISTS idx_loan_installments_status ON loan_installments (loan_id, status);
