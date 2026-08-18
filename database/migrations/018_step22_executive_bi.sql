-- STEP 22 • Executive BI & Advanced Analytics
-- Live facts remain in operational source tables. BI stores only management targets/notes/signal workflow.
USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS bi_targets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  target_code VARCHAR(100) NOT NULL,
  metric_code VARCHAR(80) NOT NULL,
  target_name VARCHAR(190) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  target_value DECIMAL(18,3) NOT NULL,
  unit_code VARCHAR(30) NOT NULL DEFAULT 'number',
  direction_code VARCHAR(30) NOT NULL DEFAULT 'higher_better',
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bi_target_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_bi_target_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_bi_target_code (organization_id,target_code),
  KEY idx_bi_target_period (organization_id,status,period_start,period_end),
  KEY idx_bi_target_metric (organization_id,metric_code,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bi_signal_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  signal_key VARCHAR(190) NOT NULL,
  signal_type VARCHAR(80) NOT NULL,
  signal_status VARCHAR(30) NOT NULL DEFAULT 'acknowledged',
  note TEXT NULL,
  action_by BIGINT UNSIGNED NULL,
  action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bi_signal_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_bi_signal_user FOREIGN KEY (action_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_bi_signal_key (organization_id,signal_key),
  KEY idx_bi_signal_status (organization_id,signal_status,action_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bi_executive_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  note_date DATE NOT NULL,
  note_type VARCHAR(40) NOT NULL DEFAULT 'management',
  title VARCHAR(190) NOT NULL,
  note_text TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bi_note_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_bi_note_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_bi_note_date (organization_id,status,note_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bi_export_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  export_type VARCHAR(50) NOT NULL,
  period_start DATE NULL,
  period_end DATE NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  exported_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bi_export_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_bi_export_user FOREIGN KEY (exported_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_bi_export_date (organization_id,created_at)
) ENGINE=InnoDB;
