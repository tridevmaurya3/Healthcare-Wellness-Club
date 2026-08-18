-- Healthcare Wellness Club Business OS
-- Migration 003: compatibility for preservation-first final Excel seeding.
-- This migration does NOT import workbook data.
-- It allows Renewal UMS facts to remain queryable even when a source name cannot
-- yet be linked to exactly one canonical member.

USE healthcare_wellness_club;

-- Renewal facts are valid source facts even before identity reconciliation.
ALTER TABLE renewals
  MODIFY member_id BIGINT UNSIGNED NULL;

-- MariaDB/MySQL installations used by this project support ADD COLUMN IF NOT EXISTS.
ALTER TABLE renewals
  ADD COLUMN IF NOT EXISTS member_name_snapshot VARCHAR(180) NULL AFTER member_id;

CREATE INDEX IF NOT EXISTS idx_renewals_name_snapshot
  ON renewals (organization_id, member_name_snapshot);

INSERT INTO schema_meta (meta_key, meta_value)
VALUES
  ('remaining_excel_seeding_compat', '1.0'),
  ('renewal_identity_policy', 'nullable-member-id-plus-source-name-snapshot')
ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);
