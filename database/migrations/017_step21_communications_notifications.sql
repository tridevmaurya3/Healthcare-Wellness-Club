-- STEP 21 • Communications & Notifications System
-- Provider secrets are never stored in the database.
-- External Email/WhatsApp/SMS delivery remains provider-neutral and auditable.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS communication_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  template_code VARCHAR(100) NOT NULL,
  channel VARCHAR(30) NOT NULL,
  template_name VARCHAR(190) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'general',
  subject_template VARCHAR(255) NULL,
  body_template TEXT NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_template_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_template (organization_id, template_code, channel),
  KEY idx_comm_template_active (organization_id, channel, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(190) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  rule_code VARCHAR(100) NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  severity VARCHAR(30) NOT NULL DEFAULT 'normal',
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  action_url VARCHAR(500) NULL,
  metadata_json LONGTEXT NULL,
  event_status VARCHAR(30) NOT NULL DEFAULT 'open',
  occurred_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_event_key (organization_id, event_key),
  KEY idx_comm_event_status (organization_id, event_status, severity, occurred_at),
  KEY idx_comm_event_entity (organization_id, entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  action_url VARCHAR(500) NULL,
  priority VARCHAR(30) NOT NULL DEFAULT 'normal',
  status VARCHAR(30) NOT NULL DEFAULT 'unread',
  read_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_notification_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_notification_event FOREIGN KEY (event_id) REFERENCES communication_events(id) ON DELETE SET NULL,
  CONSTRAINT fk_comm_notification_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_notification_event_user (organization_id, event_id, user_id),
  KEY idx_comm_notification_user (organization_id, user_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  template_id BIGINT UNSIGNED NULL,
  idempotency_key CHAR(64) NOT NULL,
  channel VARCHAR(30) NOT NULL,
  recipient_type VARCHAR(40) NOT NULL,
  recipient_id BIGINT UNSIGNED NULL,
  recipient_name_snapshot VARCHAR(190) NULL,
  recipient_address_snapshot VARCHAR(255) NOT NULL,
  subject_snapshot VARCHAR(255) NULL,
  body_snapshot TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued',
  provider_mode VARCHAR(30) NOT NULL DEFAULT 'disabled',
  provider_message_id VARCHAR(255) NULL,
  scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  sent_at DATETIME NULL,
  last_error TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_outbox_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_outbox_event FOREIGN KEY (event_id) REFERENCES communication_events(id) ON DELETE SET NULL,
  CONSTRAINT fk_comm_outbox_template FOREIGN KEY (template_id) REFERENCES communication_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_comm_outbox_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_comm_outbox_idempotency (organization_id, idempotency_key),
  KEY idx_comm_outbox_queue (organization_id, status, scheduled_at),
  KEY idx_comm_outbox_recipient (organization_id, recipient_type, recipient_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  outbox_id BIGINT UNSIGNED NOT NULL,
  attempt_no INT UNSIGNED NOT NULL,
  outcome VARCHAR(30) NOT NULL,
  response_code VARCHAR(80) NULL,
  response_excerpt VARCHAR(1000) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_attempt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_attempt_outbox FOREIGN KEY (outbox_id) REFERENCES communication_outbox(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_attempt_no (outbox_id, attempt_no),
  KEY idx_comm_attempt_outcome (organization_id, outcome, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_preferences (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  quiet_start TIME NULL,
  quiet_end TIME NULL,
  timezone_name VARCHAR(80) NOT NULL DEFAULT 'Asia/Kolkata',
  digest_frequency VARCHAR(30) NOT NULL DEFAULT 'instant',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_pref_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_pref_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_pref_user (organization_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  rule_code VARCHAR(100) NOT NULL,
  rule_name VARCHAR(190) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  category VARCHAR(60) NOT NULL,
  severity VARCHAR(30) NOT NULL DEFAULT 'normal',
  audience_permission VARCHAR(100) NOT NULL DEFAULT 'dashboard.view',
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  auto_external TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_rule_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comm_rule_code (organization_id, rule_code),
  KEY idx_comm_rule_event (organization_id, event_type, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_channel_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(30) NOT NULL,
  provider_mode VARCHAR(30) NOT NULL DEFAULT 'disabled',
  sender_label VARCHAR(190) NULL,
  webhook_url_env VARCHAR(100) NULL,
  webhook_token_env VARCHAR(100) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_channel_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_channel_user FOREIGN KEY (updated_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_comm_channel (organization_id, channel)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_scheduler_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  run_type VARCHAR(40) NOT NULL DEFAULT 'sync_dispatch',
  status VARCHAR(30) NOT NULL,
  events_synced INT UNSIGNED NOT NULL DEFAULT 0,
  messages_processed INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT fk_comm_scheduler_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  KEY idx_comm_scheduler_run (organization_id, status, started_at)
) ENGINE=InnoDB;
