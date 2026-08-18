-- Healthcare Wellness Club Business OS
-- Migration 003: compatibility for preservation-first final Excel seeding.
-- This migration does NOT import workbook data.
-- It allows Renewal UMS facts to remain queryable even when a source name cannot
-- yet be linked to exactly one canonical member.
-- Compatible with MySQL 8+ and MariaDB without relying on ADD/INDEX IF NOT EXISTS.

USE healthcare_wellness_club;

-- Renewal facts are valid source facts even before identity reconciliation.
ALTER TABLE renewals
  MODIFY member_id BIGINT UNSIGNED NULL;

SET @has_member_snapshot := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'renewals'
    AND column_name = 'member_name_snapshot'
);
SET @sql_member_snapshot := IF(
  @has_member_snapshot = 0,
  'ALTER TABLE renewals ADD COLUMN member_name_snapshot VARCHAR(180) NULL AFTER member_id',
  'SELECT 1'
);
PREPARE stmt_member_snapshot FROM @sql_member_snapshot;
EXECUTE stmt_member_snapshot;
DEALLOCATE PREPARE stmt_member_snapshot;

SET @has_name_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'renewals'
    AND index_name = 'idx_renewals_name_snapshot'
);
SET @sql_name_index := IF(
  @has_name_index = 0,
  'CREATE INDEX idx_renewals_name_snapshot ON renewals (organization_id, member_name_snapshot)',
  'SELECT 1'
);
PREPARE stmt_name_index FROM @sql_name_index;
EXECUTE stmt_name_index;
DEALLOCATE PREPARE stmt_name_index;

INSERT INTO schema_meta (meta_key, meta_value)
VALUES
  ('remaining_excel_seeding_compat', '1.0'),
  ('renewal_identity_policy', 'nullable-member-id-plus-source-name-snapshot')
ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);
