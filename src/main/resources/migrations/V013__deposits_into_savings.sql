-- V013: mirrors the backend's 2026_09_17_1521xx migrations. A group loan's
-- security deposit is now credited straight into one of the member's savings
-- accounts (no per-loan GLDEP escrow account), and a write-off can draw a
-- chosen amount down from the borrower's savings before booking the residual
-- as bad debt. No FK constraints, matching this schema's existing convention.
-- SQLite requires one column per ALTER TABLE statement.

ALTER TABLE group_loan_deposits ADD COLUMN savings_account_id TEXT;

ALTER TABLE group_loans DROP COLUMN deposit_liability_account_id;

-- 'settled' is retired from DepositStatus; collapse historical rows first so
-- they still parse on load.
UPDATE group_loans SET deposit_status = 'held' WHERE deposit_status = 'settled';

ALTER TABLE loans ADD COLUMN write_off_savings_account_id TEXT;
ALTER TABLE loans ADD COLUMN write_off_savings_applied INTEGER;

ALTER TABLE group_loans ADD COLUMN write_off_savings_account_id TEXT;
ALTER TABLE group_loans ADD COLUMN write_off_savings_applied INTEGER;
