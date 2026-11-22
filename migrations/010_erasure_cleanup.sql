-- Forward-only completion marker: location deletion runs under a dedicated worker identity.
ALTER TABLE account_deletion_requests ADD COLUMN LocationErasedAt DATETIME(6) NULL;
