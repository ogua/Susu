-- V002: core susu domain — double-entry ledger, customers, daily susu
-- products/accounts, withdrawals, agent day sheets.
--
-- This desktop install represents a single company/branch (provisioned by
-- the setup wizard), so unlike the Laravel schema these tables carry no
-- company_id/branch_id columns. If this office later syncs with the cloud
-- (hybrid mode), the sync layer attaches the right tenant context server-side.

CREATE TABLE IF NOT EXISTS ledger_accounts (
    id TEXT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(191) NOT NULL,
    type VARCHAR(20) NOT NULL, -- asset | liability | income | expense | equity
    accountable_type VARCHAR(191),
    accountable_id TEXT,
    balance INTEGER NOT NULL DEFAULT 0,
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ledger_accounts_code ON ledger_accounts (code);

-- Append-only. Corrections are reversal entries, never edits (mirrors the
-- backend's LedgerService — this is what keeps offline sync conflict-safe).
CREATE TABLE IF NOT EXISTS journal_entries (
    id TEXT PRIMARY KEY,
    reference TEXT NOT NULL,
    client_reference TEXT,
    origin VARCHAR(10) NOT NULL DEFAULT 'desktop',
    type VARCHAR(30) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'completed',
    payment_method VARCHAR(16) NOT NULL DEFAULT 'cash',
    description TEXT,
    recorded_by TEXT,
    recorded_at TEXT NOT NULL,
    posted_at TEXT NOT NULL,
    reversed_entry_id TEXT,
    meta TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_journal_entries_reference ON journal_entries (reference);
CREATE UNIQUE INDEX IF NOT EXISTS idx_journal_entries_client_ref ON journal_entries (client_reference);

CREATE TABLE IF NOT EXISTS journal_lines (
    id TEXT PRIMARY KEY,
    journal_entry_id TEXT NOT NULL,
    ledger_account_id TEXT NOT NULL,
    debit INTEGER NOT NULL DEFAULT 0,
    credit INTEGER NOT NULL DEFAULT 0,
    memo VARCHAR(191),
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_journal_lines_account ON journal_lines (ledger_account_id);
CREATE INDEX IF NOT EXISTS idx_journal_lines_entry ON journal_lines (journal_entry_id);

CREATE TABLE IF NOT EXISTS customers (
    id TEXT PRIMARY KEY,
    customer_code VARCHAR(40) NOT NULL,
    first_name VARCHAR(191) NOT NULL,
    last_name VARCHAR(191) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    gender VARCHAR(10),
    date_of_birth TEXT,
    id_type VARCHAR(30) DEFAULT 'ghana_card',
    id_number TEXT,
    next_of_kin_name VARCHAR(191),
    next_of_kin_phone VARCHAR(32),
    next_of_kin_relationship VARCHAR(40),
    address TEXT,
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    client_reference TEXT,
    registered_by TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_code ON customers (customer_code);
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_phone ON customers (phone);

CREATE TABLE IF NOT EXISTS savings_products (
    id TEXT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(20) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'daily_susu',
    contribution_amount INTEGER NOT NULL,
    cycle_length_days INTEGER NOT NULL DEFAULT 31,
    commission_type VARCHAR(40) NOT NULL DEFAULT 'first_contribution_per_cycle',
    commission_value INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_savings_products_code ON savings_products (code);

CREATE TABLE IF NOT EXISTS savings_accounts (
    id TEXT PRIMARY KEY,
    customer_id TEXT NOT NULL,
    savings_product_id TEXT NOT NULL,
    agent_id TEXT,
    ledger_account_id TEXT,
    account_number VARCHAR(40) NOT NULL,
    contribution_amount INTEGER NOT NULL,
    cycle_number INTEGER NOT NULL DEFAULT 1,
    cycle_started_at TEXT,
    contributions_this_cycle INTEGER NOT NULL DEFAULT 0,
    balance INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    opened_at TEXT NOT NULL,
    closed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_savings_accounts_number ON savings_accounts (account_number);
CREATE INDEX IF NOT EXISTS idx_savings_accounts_customer ON savings_accounts (customer_id);

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id TEXT PRIMARY KEY,
    savings_account_id TEXT NOT NULL,
    customer_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    reason TEXT,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    requested_by TEXT,
    approved_by TEXT,
    rejected_reason TEXT,
    paid_entry_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_withdrawal_requests_account ON withdrawal_requests (savings_account_id);

CREATE TABLE IF NOT EXISTS agent_daily_summaries (
    id TEXT PRIMARY KEY,
    agent_id TEXT NOT NULL,
    summary_date TEXT NOT NULL,
    collections_total INTEGER NOT NULL DEFAULT 0,
    collections_count INTEGER NOT NULL DEFAULT 0,
    expected_cash INTEGER NOT NULL DEFAULT 0,
    declared_cash INTEGER,
    variance INTEGER,
    status VARCHAR(12) NOT NULL DEFAULT 'open',
    reconciled_by TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_agent_summary_agent_date ON agent_daily_summaries (agent_id, summary_date);
