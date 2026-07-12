-- V001: desktop shell base schema.
-- One SQL file serves SQLite and MySQL; MigrationRunner adapts dialects
-- (AUTOINCREMENT rewrite, "--mysql:" prefix for MySQL-only statements).
-- Domain tables (customers, ledger, accounts) arrive with Phase 1 migrations.

-- Local login accounts (bcrypt hashes). In standalone mode these are the only
-- users; in hybrid mode they cache cloud credentials for offline re-entry.
CREATE TABLE IF NOT EXISTS local_users (
    id TEXT PRIMARY KEY,
    server_user_id TEXT,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL,
    phone VARCHAR(32),
    role VARCHAR(32) NOT NULL,
    password_hash VARCHAR(100) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_local_users_email ON local_users (email);

-- Key/value application settings that must live with the data (not the
-- per-machine config.properties), e.g. company identity in standalone mode.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(191) PRIMARY KEY,
    setting_value TEXT
);

-- Sync bookkeeping (hybrid mode): pull cursors, last sync timestamps.
CREATE TABLE IF NOT EXISTS sync_state (
    state_key VARCHAR(191) PRIMARY KEY,
    state_value TEXT
);

-- Outbox: operations recorded locally, awaiting replay against the Laravel
-- backend via POST /api/v1/sync/batch. op_id is the client-generated UUID
-- the server dedupes on.
CREATE TABLE IF NOT EXISTS outbox (
    op_id TEXT PRIMARY KEY,
    op_type VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    recorded_at TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox (status, created_at);

-- Offline license activation (standalone installs).
CREATE TABLE IF NOT EXISTS license (
    id INTEGER PRIMARY KEY,
    license_key TEXT,
    activated_at TEXT,
    expires_at TEXT,
    last_check_at TEXT
);
