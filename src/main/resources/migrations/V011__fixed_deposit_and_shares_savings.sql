-- V011: fixed deposit and shares savings products — two new savings_products
-- types mirroring the backend's Fixed Deposit (interest paid once at
-- maturity, snapshotted onto the account at open time) and Shares
-- (cooperative share capital, balance = share_count * par_value). No FK
-- constraints, matching this schema's existing no-FK convention. SQLite
-- requires one column per ALTER TABLE statement; MigrationRunner already
-- splits this file on ';'.

ALTER TABLE savings_products ADD COLUMN interest_rate_bps INTEGER NOT NULL DEFAULT 0;
ALTER TABLE savings_products ADD COLUMN par_value INTEGER;

ALTER TABLE savings_accounts ADD COLUMN interest_rate_bps INTEGER NOT NULL DEFAULT 0;
ALTER TABLE savings_accounts ADD COLUMN share_count INTEGER NOT NULL DEFAULT 0;
