-- STEP 18 • Backup, Restore, Disaster Recovery & Cloud Readiness
-- Backup passphrases are never stored in this schema.

CREATE TABLE IF NOT EXISTS backup_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  backup_code VARCHAR(80) NOT NULL,
  backup_type VARCHAR(40) NOT NULL DEFAULT 'manual',
  backup_scope VARCHAR(40) NOT NULL DEFAULT 'database',
  status VARCHAR(30) NOT NULL DEFAULT 'created',
  original_name VARCHAR(255) NULL,
  stored_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(700) NOT NULL,
  package_version VARCHAR(30) NOT NULL DEFAULT 'HWCBAK18-1',
  encryption_algorithm VARCHAR(80) NOT NULL DEFAULT 'AES-256-GCM',
  key_derivation VARCHAR(120) NOT NULL DEFAULT 'PBKDF2-HMAC-SHA256',
  kdf_iterations INT UNSIGNED NOT NULL DEFAULT 210000,
  salt_b64 VARCHAR(255) NOT NULL,
  nonce_b64 VARCHAR(255) NOT NULL,
  auth_tag_b64 VARCHAR(255) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  plaintext_sha256 CHAR(64) NOT NULL,
  schema_fingerprint CHAR(64) NOT NULL,
  table_count INT UNSIGNED NOT NULL DEFAULT 0,
  row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  verification_status VARCHAR(30) NOT NULL DEFAULT 'not_verified',
  expires_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_record_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_record_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_backup_record_code (organization_id, backup_code),
  KEY idx_backup_record_status (organization_id, status, created_at),
  KEY idx_backup_record_expiry (organization_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_restore_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NULL,
  backup_record_id BIGINT UNSIGNED NULL,
  rollback_backup_id BIGINT UNSIGNED NULL,
  job_code VARCHAR(80) NOT NULL,
  uploaded_name VARCHAR(255) NOT NULL,
  staged_path VARCHAR(700) NOT NULL,
  package_sha256 CHAR(64) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'uploaded',
  validation_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  schema_fingerprint CHAR(64) NULL,
  preview_json LONGTEXT NULL,
  error_message TEXT NULL,
  validated_at DATETIME NULL,
  restore_started_at DATETIME NULL,
  restored_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_restore_job_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_restore_job_user FOREIGN KEY (requested_by) REFERENCES system_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_restore_job_backup FOREIGN KEY (backup_record_id) REFERENCES backup_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_restore_job_rollback FOREIGN KEY (rollback_backup_id) REFERENCES backup_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_restore_job_code (organization_id, job_code),
  KEY idx_restore_job_status (organization_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  policy_code VARCHAR(80) NOT NULL DEFAULT 'PRIMARY',
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  frequency_code VARCHAR(30) NOT NULL DEFAULT 'daily',
  preferred_time TIME NOT NULL DEFAULT '02:00:00',
  retention_daily INT UNSIGNED NOT NULL DEFAULT 7,
  retention_weekly INT UNSIGNED NOT NULL DEFAULT 4,
  retention_monthly INT UNSIGNED NOT NULL DEFAULT 6,
  require_verified_copy TINYINT(1) NOT NULL DEFAULT 1,
  require_offsite_copy TINYINT(1) NOT NULL DEFAULT 1,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  last_failure_message TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_policy_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_policy_user FOREIGN KEY (updated_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_backup_policy_code (organization_id, policy_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_verification_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  backup_record_id BIGINT UNSIGNED NULL,
  verified_by BIGINT UNSIGNED NULL,
  verification_type VARCHAR(40) NOT NULL DEFAULT 'package',
  status VARCHAR(30) NOT NULL,
  file_hash_ok TINYINT(1) NOT NULL DEFAULT 0,
  authentication_ok TINYINT(1) NOT NULL DEFAULT 0,
  schema_ok TINYINT(1) NOT NULL DEFAULT 0,
  payload_ok TINYINT(1) NOT NULL DEFAULT 0,
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_verify_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_verify_record FOREIGN KEY (backup_record_id) REFERENCES backup_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_backup_verify_user FOREIGN KEY (verified_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_backup_verify_record (organization_id, backup_record_id, created_at)
) ENGINE=InnoDB;

INSERT INTO schema_meta(meta_key,meta_value)
VALUES('backup_step18_version','1.0-complete')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
