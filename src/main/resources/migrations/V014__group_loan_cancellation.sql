-- V014: mirrors the backend's 2026_10_05_160826 migration. A group loan that
-- was issued but never activated can now be cancelled ('cancelled' status);
-- these columns record when, by whom and why. No FK constraints, matching
-- this schema's existing convention. SQLite requires one column per ALTER TABLE.

ALTER TABLE group_loans ADD COLUMN cancelled_at TEXT;
ALTER TABLE group_loans ADD COLUMN cancelled_by TEXT;
ALTER TABLE group_loans ADD COLUMN cancellation_reason TEXT;
