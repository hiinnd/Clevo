-- Compatibility entry point for EMPTY development databases only. No seeds, resets or DROP statements.
-- For upgrades use scripts/migrate-security.php then scripts/migrate-database.php.
SOURCE migrations/000_legacy_schema.sql;
