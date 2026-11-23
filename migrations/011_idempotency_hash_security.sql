-- Additive marker. PHP migrator upgrades existing digests using the protected APP_KEY.
-- Response JSON, scopes, business keys and all financial/history records are preserved.
ALTER TABLE request_idempotency ADD COLUMN RequestHashVersion TINYINT UNSIGNED NOT NULL DEFAULT 1, ADD KEY idx_request_hash_upgrade(RequestHashVersion);
