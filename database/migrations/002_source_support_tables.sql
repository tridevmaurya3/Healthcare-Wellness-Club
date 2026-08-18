-- Healthcare Wellness Club Business OS
-- Migration 002: dedicated source-support tables for workbook normalization.
-- Safe to run more than once: tables use IF NOT EXISTS and metadata uses UPSERT.
-- This migration does NOT import or modify workbook business records.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS volume_point_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NULL,
  member_name_snapshot VARCHAR(180) NULL,
  entry_date DATE NULL,
  level_label VARCHAR(80) NULL,
  week_label VARCHAR(80) NULL,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  order_type VARCHAR(80) NULL,
  vp_from VARCHAR(180) NULL,
  ordered_by VARCHAR(180) NULL,
  vp_type VARCHAR(80) NULL,
  order_set VARCHAR(80) NULL,
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vp_entry_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vp_entry_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_vp_entry_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_vp_entry_source FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_vp_entry_source_key (organization_id, source_key),
  KEY idx_vp_entry_date (organization_id, entry_date),
  KEY idx_vp_entry_member_date (organization_id, member_id, entry_date),
  KEY idx_vp_entry_type (organization_id, vp_type, order_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ums_activity_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NULL,
  member_name_snapshot VARCHAR(180) NULL,
  snapshot_year SMALLINT UNSIGNED NOT NULL,
  snapshot_month VARCHAR(40) NOT NULL,
  snapshot_month_number TINYINT UNSIGNED NULL,
  snapshot_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ums_snapshot_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ums_snapshot_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ums_snapshot_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_ums_snapshot_source FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_ums_snapshot_source_key (organization_id, source_key),
  KEY idx_ums_snapshot_period (organization_id, snapshot_year, snapshot_month_number),
  KEY idx_ums_snapshot_member_period (organization_id, member_id, snapshot_year, snapshot_month_number),
  KEY idx_ums_snapshot_active (organization_id, is_active)
) ENGINE=InnoDB;

INSERT INTO schema_meta (meta_key, meta_value)
VALUES
  ('source_support_version', '1.0'),
  ('source_support_tables', 'volume_point_entries,ums_activity_snapshots')
ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);
