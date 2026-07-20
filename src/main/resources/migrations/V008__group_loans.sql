-- V008: Group Loans — joint & several liability loans for a persistent
-- LoanGroup roster, structurally separate from susu groups (V005) and
-- individual loans (V003). Mirrors the Laravel backend's six
-- 2026_07_19_09000{0..5} migrations, minus company_id/branch_id per this
-- desktop install's single-tenant convention (V002/V003's note). No FK
-- constraints, matching this schema's existing no-FK convention. SQLite
-- requires one column per ALTER TABLE statement; MigrationRunner already
-- splits this file on ';'.

CREATE TABLE IF NOT EXISTS loan_groups (
    id TEXT PRIMARY KEY,
    created_by TEXT,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(20) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loan_groups_code ON loan_groups (code);

-- No rotation_position — the entire reason this is a separate model tree
-- from susu group_members (V005).
CREATE TABLE IF NOT EXISTS loan_group_members (
    id TEXT PRIMARY KEY,
    loan_group_id TEXT NOT NULL,
    customer_id TEXT NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    joined_at TEXT NOT NULL,
    left_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_loan_group_members_group ON loan_group_members (loan_group_id);
CREATE INDEX IF NOT EXISTS idx_loan_group_members_customer ON loan_group_members (customer_id);

-- Reuses loan_products (V003) as its rate/term/frequency template — no
-- duplicate product table needed.
CREATE TABLE IF NOT EXISTS group_loans (
    id TEXT PRIMARY KEY,
    loan_group_id TEXT NOT NULL,
    loan_product_id TEXT NOT NULL,
    agent_id TEXT,
    approved_by TEXT,
    receivable_account_id TEXT,
    loan_number VARCHAR(40) NOT NULL,
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
    member_count_at_disbursement INTEGER,
    status VARCHAR(20) NOT NULL DEFAULT 'applied',
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
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loans_number ON group_loans (loan_number);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loans_client_ref ON group_loans (client_reference);
CREATE INDEX IF NOT EXISTS idx_group_loans_loan_group ON group_loans (loan_group_id);
CREATE INDEX IF NOT EXISTS idx_group_loans_status ON group_loans (status);

-- Per-member equal share of the principal, computed at disbursement.
-- share_outstanding is accountability bookkeeping only — the group's own
-- outstanding_balance on group_loans is what's legally owed (joint & several).
CREATE TABLE IF NOT EXISTS group_loan_borrowers (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
    loan_group_member_id TEXT NOT NULL,
    customer_id TEXT NOT NULL,
    share_principal INTEGER NOT NULL,
    share_outstanding INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_group_loan_borrowers_loan ON group_loan_borrowers (group_loan_id);
CREATE INDEX IF NOT EXISTS idx_group_loan_borrowers_member ON group_loan_borrowers (loan_group_member_id);

-- Structurally identical to loan_installments (V003), just group_loan_id FK.
CREATE TABLE IF NOT EXISTS group_loan_installments (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    principal_due INTEGER NOT NULL,
    interest_due INTEGER NOT NULL,
    penalty_due INTEGER NOT NULL DEFAULT 0,
    principal_paid INTEGER NOT NULL DEFAULT 0,
    interest_paid INTEGER NOT NULL DEFAULT 0,
    penalty_paid INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loan_installments_seq ON group_loan_installments (group_loan_id, sequence);
CREATE INDEX IF NOT EXISTS idx_group_loan_installments_status ON group_loan_installments (group_loan_id, status);

-- Unlike group_contributions (one per member per round), a member can repay
-- a group loan many times over its life — no per-round uniqueness here.
CREATE TABLE IF NOT EXISTS group_loan_repayments (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
    group_loan_borrower_id TEXT NOT NULL,
    journal_entry_id TEXT,
    recorded_by TEXT,
    amount INTEGER NOT NULL,
    recorded_at TEXT NOT NULL,
    client_reference TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loan_repayments_client_ref ON group_loan_repayments (client_reference);
CREATE INDEX IF NOT EXISTS idx_group_loan_repayments_loan ON group_loan_repayments (group_loan_id);
CREATE INDEX IF NOT EXISTS idx_group_loan_repayments_borrower ON group_loan_repayments (group_loan_borrower_id);
