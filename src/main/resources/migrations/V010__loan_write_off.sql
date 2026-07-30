-- V010: loan write-off — mirrors the backend's
-- 2026_07_21_09000{0,1} migrations. Declares a disbursed loan's remaining
-- balance uncollectible in one atomic transition, same shape as the
-- restructure/top-up fields added in V009. No FK constraints, matching
-- this schema's existing no-FK convention. SQLite requires one column per
-- ALTER TABLE statement; MigrationRunner already splits this file on ';'.

ALTER TABLE loans ADD COLUMN written_off_at TEXT;
ALTER TABLE loans ADD COLUMN write_off_reason TEXT;
ALTER TABLE loans ADD COLUMN write_off_amount INTEGER;

ALTER TABLE group_loans ADD COLUMN written_off_at TEXT;
ALTER TABLE group_loans ADD COLUMN write_off_reason TEXT;
ALTER TABLE group_loans ADD COLUMN write_off_amount INTEGER;
