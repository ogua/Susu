-- V009: loan restructuring & top-up — mirrors the backend's
-- 2026_07_30_09000{0,1} migrations. A restructure/top-up closes an old
-- loan/group loan (status -> refinanced) and opens a new linked one that
-- carries the rolled-over principal forward. No FK constraints, matching
-- this schema's existing no-FK convention. SQLite requires one column per
-- ALTER TABLE statement; MigrationRunner already splits this file on ';'.

ALTER TABLE loans ADD COLUMN previous_loan_id TEXT;
ALTER TABLE loans ADD COLUMN rolled_over_amount INTEGER NOT NULL DEFAULT 0;
ALTER TABLE loans ADD COLUMN refinanced_at TEXT;
ALTER TABLE loans ADD COLUMN refinance_type VARCHAR(20);
ALTER TABLE loans ADD COLUMN refinance_reason TEXT;
ALTER TABLE loans ADD COLUMN refinance_amount INTEGER;

ALTER TABLE group_loans ADD COLUMN previous_group_loan_id TEXT;
ALTER TABLE group_loans ADD COLUMN rolled_over_amount INTEGER NOT NULL DEFAULT 0;
ALTER TABLE group_loans ADD COLUMN refinanced_at TEXT;
ALTER TABLE group_loans ADD COLUMN refinance_type VARCHAR(20);
ALTER TABLE group_loans ADD COLUMN refinance_reason TEXT;
ALTER TABLE group_loans ADD COLUMN refinance_amount INTEGER;
