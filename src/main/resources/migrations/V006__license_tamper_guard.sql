-- V006: license tamper-guard column. `license` (V001) already stores
-- license_key/activated_at/expires_at/last_check_at for the single row this
-- install ever has; `mac` is an HMAC over last_check_at keyed off the
-- embedded public key, so LicenseManager can detect a system clock rolled
-- backward or the row edited directly in the database (mirrors Oguaschoolz's
-- license_checks.mac, collapsed onto the existing single-row table since
-- this app has no per-company scoping to key it by).

ALTER TABLE license ADD COLUMN mac TEXT;
