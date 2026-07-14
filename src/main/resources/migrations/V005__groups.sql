-- V005: ROSCA susu groups — members contribute a fixed amount per round;
-- one member is paid out the pool each round in rotation order. Mirrors the
-- backend's groups/group_members/group_rounds/group_contributions tables,
-- minus company_id/branch_id per this desktop install's single-tenant
-- convention (V002's note).

CREATE TABLE IF NOT EXISTS groups_table (
    id TEXT PRIMARY KEY,
    liability_account_id TEXT,
    created_by TEXT,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(20) NOT NULL,
    contribution_amount INTEGER NOT NULL,
    frequency VARCHAR(10) NOT NULL DEFAULT 'monthly', -- weekly | monthly
    status VARCHAR(12) NOT NULL DEFAULT 'draft', -- draft | active | completed
    activated_at TEXT,
    completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_groups_code ON groups_table (code);

CREATE TABLE IF NOT EXISTS group_members (
    id TEXT PRIMARY KEY,
    group_id TEXT NOT NULL,
    customer_id TEXT NOT NULL,
    rotation_position INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'active', -- active | left
    joined_at TEXT NOT NULL,
    left_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_members_customer ON group_members (group_id, customer_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_members_position ON group_members (group_id, rotation_position);

CREATE TABLE IF NOT EXISTS group_rounds (
    id TEXT PRIMARY KEY,
    group_id TEXT NOT NULL,
    payout_member_id TEXT NOT NULL,
    payout_entry_id TEXT,
    round_number INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    total_expected INTEGER NOT NULL,
    total_collected INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL DEFAULT 'pending', -- pending | collecting | completed
    paid_out_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_rounds_number ON group_rounds (group_id, round_number);
CREATE INDEX IF NOT EXISTS idx_group_rounds_status ON group_rounds (group_id, status);

CREATE TABLE IF NOT EXISTS group_contributions (
    id TEXT PRIMARY KEY,
    group_round_id TEXT NOT NULL,
    group_member_id TEXT NOT NULL,
    journal_entry_id TEXT,
    recorded_by TEXT,
    amount INTEGER NOT NULL,
    recorded_at TEXT NOT NULL,
    client_reference TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_contributions_member ON group_contributions (group_round_id, group_member_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_contributions_client_ref ON group_contributions (client_reference);
