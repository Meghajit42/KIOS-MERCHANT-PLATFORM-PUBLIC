-- MIGRATION_008_LOCAL_ONLY_PENDING_K7_GREEN
-- Existing operational and historical fields are preserved.
ALTER TABLE products
    ADD COLUMN verification_status VARCHAR(80) NOT NULL DEFAULT 'confirmed',
    ADD COLUMN verification_note VARCHAR(500) NULL,
    ADD COLUMN verification_evidence TEXT NULL;
