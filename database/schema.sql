-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible foundation for XAMPP development.
-- This schema is intentionally normalized and keeps Excel source traceability.

CREATE DATABASE IF NOT EXISTS healthcare_wellness_club
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS system_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  mobile VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_users_email (email),
  KEY idx_system_users_role (role),
  KEY idx_system_users_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_code VARCHAR(80) NULL,
  full_name VARCHAR(180) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(120) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'IN',
  sponsor_member_id BIGINT UNSIGNED NULL,
  member_type VARCHAR(60) NULL,
  join_date DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_members_sponsor FOREIGN KEY (sponsor_member_id) REFERENCES members(id) ON DELETE SET NULL,
  UNIQUE KEY uq_members_source_key (source_key),
  KEY idx_members_name (full_name),
  KEY idx_members_mobile (mobile),
  KEY idx_members_status (status),
  KEY idx_members_type (member_type),
  KEY idx_members_join_date (join_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ums_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  ums_code VARCHAR(100) NULL,
  set_type VARCHAR(80) NULL,
  start_date DATE NULL,
  expiry_date DATE NULL,
  renewal_due_date DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(14,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ums_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ums_source_key (source_key),
  KEY idx_ums_status (status),
  KEY idx_ums_start_date (start_date),
  KEY idx_ums_renewal_due (renewal_due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NULL,
  order_date DATE NOT NULL,
  order_type VARCHAR(60) NOT NULL DEFAULT 'regular',
  invoice_no VARCHAR(100) NULL,
  description VARCHAR(255) NULL,
  gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  profit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(14,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  UNIQUE KEY uq_orders_source_key (source_key),
  KEY idx_orders_date (order_date),
  KEY idx_orders_type (order_type),
  KEY idx_orders_member_date (member_id, order_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS renewals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id BIGINT UNSIGNED NOT NULL,
  ums_record_id BIGINT UNSIGNED NULL,
  renewal_date DATE NOT NULL,
  period_months SMALLINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(14,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_renewals_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_renewals_ums FOREIGN KEY (ums_record_id) REFERENCES ums_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_renewals_source_key (source_key),
  KEY idx_renewals_date (renewal_date),
  KEY idx_renewals_member_date (member_id, renewal_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS income_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  income_date DATE NOT NULL,
  member_id BIGINT UNSIGNED NULL,
  income_type VARCHAR(80) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  period_key CHAR(7) NULL COMMENT 'YYYY-MM',
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_income_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  UNIQUE KEY uq_income_source_key (source_key),
  KEY idx_income_date (income_date),
  KEY idx_income_type (income_type),
  KEY idx_income_period (period_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS royalty_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  royalty_date DATE NULL,
  period_label VARCHAR(80) NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(14,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_royalty_source_key (source_key),
  KEY idx_royalty_date (royalty_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_file_name VARCHAR(255) NOT NULL,
  file_sha256 CHAR(64) NULL,
  import_type VARCHAR(60) NOT NULL DEFAULT 'excel',
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_created_by FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_import_status (status),
  KEY idx_import_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staging_excel_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  sheet_name VARCHAR(120) NOT NULL,
  row_number INT UNSIGNED NOT NULL,
  row_hash CHAR(64) NULL,
  raw_json LONGTEXT NOT NULL,
  mapping_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  mapped_entity_type VARCHAR(80) NULL,
  mapped_entity_id BIGINT UNSIGNED NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_staging_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
  UNIQUE KEY uq_staging_batch_sheet_row (batch_id, sheet_name, row_number),
  KEY idx_staging_sheet (sheet_name),
  KEY idx_staging_status (mapping_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details_json LONGTEXT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_audit_event (event_type),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created_at (created_at)
) ENGINE=InnoDB;

-- The staging layer is deliberate: the Excel workbook can first be imported without
-- losing any original columns. Later steps will map sheets such as New UMS,
-- Volume Points, Renewal UMS, Monthely_Income and Royalty_Tracking into the
-- normalized business tables while retaining source_sheet/source_row traceability.
