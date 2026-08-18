-- Healthcare Wellness Club — STEP 10 Business OS completion support
-- Additive-only migration. Legacy Excel source/raw/normalized facts are untouched.

CREATE TABLE IF NOT EXISTS business_followups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  member_id BIGINT UNSIGNED NULL,
  followup_type VARCHAR(60) NOT NULL DEFAULT 'general',
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  due_date DATE NOT NULL,
  due_time TIME NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  source_entity_type VARCHAR(80) NULL,
  source_entity_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_followup_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_followup_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_followup_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_followup_user FOREIGN KEY (created_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_followup_due (organization_id, status, due_date),
  KEY idx_followup_member (organization_id, member_id, status),
  KEY idx_followup_source (organization_id, source_entity_type, source_entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS business_saved_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  view_name VARCHAR(120) NOT NULL,
  view_type VARCHAR(60) NOT NULL,
  target_page VARCHAR(160) NOT NULL,
  query_string VARCHAR(1000) NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_view_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_view_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  KEY idx_saved_view_org_type (organization_id, view_type, is_favorite)
) ENGINE=InnoDB;
