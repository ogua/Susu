-- V016: default term on target / fixed deposit products (mirrors the
-- backend's savings_products.term_days), plus clearing commission that the
-- old product form let fixed deposit and shares products carry. SQLite
-- requires one column per ALTER TABLE statement; MigrationRunner already
-- splits this file on ';'.

ALTER TABLE savings_products ADD COLUMN term_days INTEGER;

UPDATE savings_products SET commission_type = 'none', commission_value = 0
    WHERE type IN ('fixed_deposit', 'shares');
