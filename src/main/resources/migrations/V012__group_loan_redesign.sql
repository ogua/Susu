-- V012: Group Loan redesign — a group loan is now ONE loan issued to ONE
-- member of a loan group (their own security deposit, their own directly
-- entered periodic repayment amount, their own outstanding balance). No
-- product, no interest, no equal-split-across-members. Mirrors the Laravel
-- backend's 2026-09 rebuild. No production data on this install, so the old
-- group-loan tables are dropped and recreated rather than migrated.
-- MigrationRunner splits this file on ';'.

DROP TABLE IF EXISTS group_loan_borrowers;
DROP TABLE IF EXISTS group_loan_repayments;
DROP TABLE IF EXISTS group_loan_installments;
DROP TABLE IF EXISTS group_loans;

CREATE TABLE IF NOT EXISTS group_loans (
    id TEXT PRIMARY KEY,
    loan_group_id TEXT NOT NULL,
    loan_group_member_id TEXT NOT NULL,
    customer_id TEXT NOT NULL,
    agent_id TEXT,
    activated_by TEXT,
    receivable_account_id TEXT,
    deposit_liability_account_id TEXT,
    loan_number VARCHAR(40) NOT NULL,
    principal_amount INTEGER NOT NULL,
    security_deposit_amount INTEGER NOT NULL DEFAULT 0,
    periodic_amount INTEGER NOT NULL,
    outstanding_balance INTEGER NOT NULL DEFAULT 0,
    repayment_frequency VARCHAR(10) NOT NULL,
    start_date TEXT NOT NULL,
    total_periods INTEGER NOT NULL DEFAULT 0,
    deposit_status VARCHAR(12) NOT NULL DEFAULT 'pending',
    status VARCHAR(12) NOT NULL DEFAULT 'draft',
    notes TEXT,
    client_reference TEXT,
    issued_at TEXT NOT NULL,
    activated_at TEXT,
    closed_at TEXT,
    written_off_at TEXT,
    write_off_reason TEXT,
    write_off_amount INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loans_number ON group_loans (loan_number);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loans_client_ref ON group_loans (client_reference);
CREATE INDEX IF NOT EXISTS idx_group_loans_loan_group ON group_loans (loan_group_id);
CREATE INDEX IF NOT EXISTS idx_group_loans_member ON group_loans (loan_group_member_id);
CREATE INDEX IF NOT EXISTS idx_group_loans_status ON group_loans (status);

-- Pure principal — no interest/penalty split, unlike loan_installments (V003).
CREATE TABLE IF NOT EXISTS group_loan_installments (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    amount_due INTEGER NOT NULL,
    amount_paid INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loan_installments_seq ON group_loan_installments (group_loan_id, sequence);
CREATE INDEX IF NOT EXISTS idx_group_loan_installments_status ON group_loan_installments (group_loan_id, status);

CREATE TABLE IF NOT EXISTS group_loan_repayments (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
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

-- One event per row in a deposit's lifecycle: held | applied | refunded | seized.
CREATE TABLE IF NOT EXISTS group_loan_deposits (
    id TEXT PRIMARY KEY,
    group_loan_id TEXT NOT NULL,
    journal_entry_id TEXT,
    recorded_by TEXT,
    amount INTEGER NOT NULL,
    type VARCHAR(12) NOT NULL,
    recorded_at TEXT NOT NULL,
    client_reference TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_loan_deposits_client_ref ON group_loan_deposits (client_reference);
CREATE INDEX IF NOT EXISTS idx_group_loan_deposits_loan ON group_loan_deposits (group_loan_id, type);
