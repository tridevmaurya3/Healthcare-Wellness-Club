-- Healthcare Wellness Club Business OS
-- World-ready database foundation (MySQL 8+ / MariaDB compatible).
-- Local XAMPP and future online/cloud deployments use the same logical schema.
-- IMPORTANT: Workbook sheets 1-6 are treated as derived reports/calculations,
-- not as source-of-truth data tables. Operational sheets/forms are ingested as raw source data
-- and then mapped into normalized business entities.

CREATE DATABASE IF NOT EXISTS healthcare_wellness_club
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS schema_meta (
  meta_key VARCHAR(80) PRIMARY KEY,
  meta_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS countries (
  country_code CHAR(2) PRIMARY KEY,
  country_name VARCHAR(120) NOT NULL,
  default_currency_code CHAR(3) NOT NULL,
  default_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
  default_locale VARCHAR(20) NOT NULL DEFAULT 'en',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS currencies (
  currency_code CHAR(3) PRIMARY KEY,
  currency_name VARCHAR(80) NOT NULL,
  symbol VARCHAR(12) NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_code VARCHAR(80) NOT NULL,
  organization_name VARCHAR(180) NOT NULL,
  organization_type VARCHAR(60) NOT NULL DEFAULT 'wellness_business',
  country_code CHAR(2) NOT NULL,
  default_currency_code CHAR(3) NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
  locale VARCHAR(20) NOT NULL DEFAULT 'en',
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_country FOREIGN KEY (country_code) REFERENCES countries(country_code),
  CONSTRAINT fk_org_currency FOREIGN KEY (default_currency_code) REFERENCES currencies(currency_code),
  UNIQUE KEY uq_organization_code (organization_code),
  KEY idx_organization_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clubs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_code VARCHAR(80) NOT NULL,
  club_name VARCHAR(180) NOT NULL,
  country_code CHAR(2) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
  locale VARCHAR(20) NOT NULL DEFAULT 'en',
  address_line VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  state_region VARCHAR(120) NULL,
  postal_code VARCHAR(30) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_club_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_club_country FOREIGN KEY (country_code) REFERENCES countries(country_code),
  CONSTRAINT fk_club_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  UNIQUE KEY uq_club_org_code (organization_id, club_code),
  KEY idx_club_org_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  mobile VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  global_role VARCHAR(40) NOT NULL DEFAULT 'user',
  preferred_locale VARCHAR(20) NULL,
  preferred_timezone VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_users_email (email),
  KEY idx_system_users_role (global_role),
  KEY idx_system_users_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organization_user_access (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(50) NOT NULL DEFAULT 'staff',
  permission_scope VARCHAR(50) NOT NULL DEFAULT 'club',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_access_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_access_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT fk_access_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_access_scope (organization_id, club_id, user_id, role_code),
  KEY idx_access_user (user_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  source_code VARCHAR(80) NOT NULL,
  source_name VARCHAR(150) NOT NULL,
  source_type VARCHAR(40) NOT NULL COMMENT 'excel, google_form, website_form, manual, api',
  external_reference VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_source_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_source_org_code (organization_id, source_code),
  KEY idx_source_type (source_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  data_source_id BIGINT UNSIGNED NULL,
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
  CONSTRAINT fk_import_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_import_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_import_source FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_import_created_by FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_import_org_status (organization_id, status),
  KEY idx_import_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS raw_source_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  data_source_id BIGINT UNSIGNED NULL,
  import_batch_id BIGINT UNSIGNED NULL,
  source_dataset VARCHAR(140) NOT NULL COMMENT 'Sheet/form/table name',
  external_record_id VARCHAR(190) NULL,
  source_row INT UNSIGNED NULL,
  captured_at DATETIME NULL,
  record_hash CHAR(64) NULL,
  raw_json LONGTEXT NOT NULL,
  mapping_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  mapped_entity_type VARCHAR(80) NULL,
  mapped_entity_id BIGINT UNSIGNED NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_raw_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_raw_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_raw_source FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_raw_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  UNIQUE KEY uq_raw_external (organization_id, data_source_id, source_dataset, external_record_id),
  KEY idx_raw_dataset (organization_id, source_dataset),
  KEY idx_raw_mapping (organization_id, mapping_status),
  KEY idx_raw_hash (record_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  primary_club_id BIGINT UNSIGNED NULL,
  member_code VARCHAR(80) NULL,
  external_member_code VARCHAR(120) NULL,
  full_name VARCHAR(180) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  city VARCHAR(120) NULL,
  state_region VARCHAR(120) NULL,
  country_code CHAR(2) NOT NULL,
  sponsor_member_id BIGINT UNSIGNED NULL,
  member_type VARCHAR(60) NULL,
  join_date DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_members_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_club FOREIGN KEY (primary_club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_members_country FOREIGN KEY (country_code) REFERENCES countries(country_code),
  CONSTRAINT fk_members_sponsor FOREIGN KEY (sponsor_member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_members_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_members_source_key (organization_id, source_key),
  KEY idx_members_org_name (organization_id, full_name),
  KEY idx_members_org_mobile (organization_id, mobile),
  KEY idx_members_org_status (organization_id, status),
  KEY idx_members_type (organization_id, member_type),
  KEY idx_members_join_date (organization_id, join_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ums_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  ums_code VARCHAR(100) NULL,
  set_type VARCHAR(80) NULL,
  start_date DATE NULL,
  expiry_date DATE NULL,
  renewal_due_date DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ums_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ums_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ums_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_ums_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_ums_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_ums_source_key (organization_id, source_key),
  KEY idx_ums_org_status (organization_id, status),
  KEY idx_ums_start_date (organization_id, start_date),
  KEY idx_ums_renewal_due (organization_id, renewal_due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NULL,
  order_date DATE NOT NULL,
  order_type VARCHAR(60) NOT NULL DEFAULT 'regular',
  invoice_no VARCHAR(100) NULL,
  description VARCHAR(255) NULL,
  gross_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  profit_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_orders_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_orders_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_orders_source_key (organization_id, source_key),
  KEY idx_orders_date (organization_id, order_date),
  KEY idx_orders_type (organization_id, order_type),
  KEY idx_orders_member_date (organization_id, member_id, order_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS renewals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  ums_record_id BIGINT UNSIGNED NULL,
  renewal_date DATE NOT NULL,
  period_months SMALLINT UNSIGNED NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_renewals_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_renewals_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_renewals_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_renewals_ums FOREIGN KEY (ums_record_id) REFERENCES ums_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_renewals_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_renewals_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_renewals_source_key (organization_id, source_key),
  KEY idx_renewals_date (organization_id, renewal_date),
  KEY idx_renewals_member_date (organization_id, member_id, renewal_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS income_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  income_date DATE NOT NULL,
  member_id BIGINT UNSIGNED NULL,
  income_type VARCHAR(80) NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  period_key CHAR(7) NULL COMMENT 'YYYY-MM',
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_income_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_income_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_income_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_income_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_income_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_income_source_key (organization_id, source_key),
  KEY idx_income_date (organization_id, income_date),
  KEY idx_income_type (organization_id, income_type),
  KEY idx_income_period (organization_id, period_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS royalty_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  royalty_date DATE NULL,
  period_label VARCHAR(80) NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(120) NULL,
  source_row INT UNSIGNED NULL,
  source_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_royalty_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_royalty_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_royalty_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_royalty_source_record FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_royalty_source_key (organization_id, source_key),
  KEY idx_royalty_date (organization_id, royalty_date)
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

CREATE TABLE IF NOT EXISTS report_definitions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL COMMENT 'NULL means global/default definition',
  report_key VARCHAR(100) NOT NULL,
  report_name VARCHAR(160) NOT NULL,
  workbook_sheet_name VARCHAR(160) NULL,
  report_type VARCHAR(50) NOT NULL DEFAULT 'derived',
  calculation_engine_version VARCHAR(30) NOT NULL DEFAULT '1.0',
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_report_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_report_org_key (organization_id, report_key),
  KEY idx_report_type (report_type, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS calculation_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  report_key VARCHAR(100) NOT NULL,
  rule_key VARCHAR(120) NOT NULL,
  rule_version VARCHAR(30) NOT NULL DEFAULT '1.0',
  rule_definition LONGTEXT NOT NULL COMMENT 'JSON/text rule definition; populated after workbook formula analysis',
  active_from DATE NULL,
  active_to DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rule_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_rule_version (organization_id, report_key, rule_key, rule_version),
  KEY idx_rule_report (report_key, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  club_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details_json LONGTEXT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_audit_event (organization_id, event_type),
  KEY idx_audit_entity (organization_id, entity_type, entity_id),
  KEY idx_audit_created_at (organization_id, created_at)
) ENGINE=InnoDB;

-- Local seed data. This is the first tenant/club only; the architecture supports more.
INSERT INTO currencies (currency_code, currency_name, symbol, decimal_places, is_active)
VALUES ('INR', 'Indian Rupee', '₹', 2, 1)
ON DUPLICATE KEY UPDATE currency_name = VALUES(currency_name), symbol = VALUES(symbol), is_active = 1;

INSERT INTO countries (country_code, country_name, default_currency_code, default_timezone, default_locale, is_active)
VALUES ('IN', 'India', 'INR', 'Asia/Kolkata', 'en-IN', 1)
ON DUPLICATE KEY UPDATE country_name = VALUES(country_name), default_currency_code = VALUES(default_currency_code), is_active = 1;

INSERT INTO organizations (organization_code, organization_name, organization_type, country_code, default_currency_code, timezone, locale, status)
VALUES ('HWC-001', 'Healthcare Wellness Club', 'wellness_business', 'IN', 'INR', 'Asia/Kolkata', 'en-IN', 'active')
ON DUPLICATE KEY UPDATE organization_name = VALUES(organization_name), status = 'active';

SET @hwc_org_id = (SELECT id FROM organizations WHERE organization_code = 'HWC-001' LIMIT 1);

INSERT INTO clubs (organization_id, club_code, club_name, country_code, currency_code, timezone, locale, city, state_region, status)
VALUES (@hwc_org_id, 'GHAZIPUR-001', 'Healthcare Wellness Club - Ghazipur', 'IN', 'INR', 'Asia/Kolkata', 'en-IN', 'Ghazipur', 'Uttar Pradesh', 'active')
ON DUPLICATE KEY UPDATE club_name = VALUES(club_name), status = 'active';

INSERT INTO data_sources (organization_id, source_code, source_name, source_type, is_active)
VALUES
  (@hwc_org_id, 'LEGACY-XLSX', 'Legacy Master Personal Tracking Workbook', 'excel', 1),
  (@hwc_org_id, 'GOOGLE-FORMS', 'Google Forms Operational Data', 'google_form', 1),
  (@hwc_org_id, 'WEBSITE-FORMS', 'Healthcare Wellness Club Website Forms', 'website_form', 1),
  (@hwc_org_id, 'MANUAL', 'Manual Business Entry', 'manual', 1),
  (@hwc_org_id, 'API', 'External API Integration', 'api', 1)
ON DUPLICATE KEY UPDATE source_name = VALUES(source_name), source_type = VALUES(source_type), is_active = 1;

-- Workbook sheets 1-6 are registered as DERIVED reports, not imported business facts.
INSERT INTO report_definitions (organization_id, report_key, report_name, workbook_sheet_name, report_type, description)
VALUES
  (@hwc_org_id, 'master_tracking', 'Master Tracking', 'Master_Tracking', 'derived', 'Overall business summary calculated from normalized source records.'),
  (@hwc_org_id, 'sp_house', 'SP House', 'SP_House', 'derived', 'Supervisor/PC/AS/Team VP style summary calculated from member and business data.'),
  (@hwc_org_id, 'name_wise_tracking', 'Name Wise Tracking', 'Name_Wise_Tracking', 'derived', 'Member-wise tracking calculated from operational records.'),
  (@hwc_org_id, 'master_business_tracking', 'Master Business Tracking', 'Master_Business_Tracking', 'derived', 'Business-level summary calculated from orders, VP, renewals and income.'),
  (@hwc_org_id, 'ums_renewal', 'UMS Renewal', 'UMS_Renewal', 'derived', 'Renewal status and due calculations generated from UMS and renewal transactions.'),
  (@hwc_org_id, 'ums_active_duration', 'UMS Active Duration', 'UMS_Active_Duration', 'derived', 'Active-duration calculations generated from UMS start, expiry and renewal history.')
ON DUPLICATE KEY UPDATE report_name = VALUES(report_name), workbook_sheet_name = VALUES(workbook_sheet_name), description = VALUES(description), is_active = 1;

INSERT INTO schema_meta (meta_key, meta_value)
VALUES
  ('schema_version', '2.0-world-ready'),
  ('architecture', 'multi-tenant-multi-country'),
  ('calculation_strategy', 'source-data-plus-derived-reports')
ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);

-- DATA STRATEGY
-- 1) Google Form / website / API / manual / Excel operational rows enter raw_source_records first.
-- 2) Original raw payload and source identity are retained for traceability and safe re-import.
-- 3) Mapping writes normalized facts into members, ums_records, orders, renewals, income_entries and royalty_entries.
-- 4) Workbook sheets 1-6 are rebuilt as live reports using calculation_rules and normalized facts.
-- 5) Every business row is organization-scoped, enabling multiple clubs/countries without mixing tenant data.
