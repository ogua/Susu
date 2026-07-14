-- V004: target savings — a savings product type with a fixed goal amount
-- and maturity date, plus an early-withdrawal penalty. Mirrors the
-- backend's savings_products.early_withdrawal_penalty_bps and
-- savings_accounts.{target_amount,matures_at,matured_at} columns.

ALTER TABLE savings_products ADD COLUMN early_withdrawal_penalty_bps INTEGER NOT NULL DEFAULT 0;

ALTER TABLE savings_accounts ADD COLUMN target_amount INTEGER;
ALTER TABLE savings_accounts ADD COLUMN matures_at TEXT;
ALTER TABLE savings_accounts ADD COLUMN matured_at TEXT;

-- Computed at request time (target accounts withdrawn from before maturity
-- only) so the net payout is known up front.
ALTER TABLE withdrawal_requests ADD COLUMN penalty_amount INTEGER NOT NULL DEFAULT 0;
