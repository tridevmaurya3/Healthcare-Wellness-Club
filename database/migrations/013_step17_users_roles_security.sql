-- STEP 17 • Users, Roles, Permissions & Security
-- Extends the existing system_users + organization_user_access foundation.
-- No default user/password is seeded. First admin is created explicitly by the owner.

CREATE TABLE IF NOT EXISTS security_permissions (
  permission_code VARCHAR(100) PRIMARY KEY,
  permission_name VARCHAR(160) NOT NULL,
  module_code VARCHAR(80) NOT NULL,
  risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_security_permissions_module (module_code,is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(50) NOT NULL,
  role_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_security_roles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_security_role (organization_id,role_code),
  KEY idx_security_roles_active (organization_id,is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_role_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(50) NOT NULL,
  permission_code VARCHAR(100) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_security_role_perm_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_security_role_perm_permission FOREIGN KEY (permission_code) REFERENCES security_permissions(permission_code) ON DELETE CASCADE,
  UNIQUE KEY uq_security_role_permission (organization_id,role_code,permission_code),
  KEY idx_security_role_perm_role (organization_id,role_code,is_allowed)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_user_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  permission_code VARCHAR(100) NOT NULL,
  is_allowed TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_security_user_perm_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_security_user_perm_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_security_user_perm_permission FOREIGN KEY (permission_code) REFERENCES security_permissions(permission_code) ON DELETE CASCADE,
  UNIQUE KEY uq_security_user_permission (organization_id,user_id,permission_code),
  KEY idx_security_user_perm_user (organization_id,user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(50) NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoke_reason VARCHAR(255) NULL,
  CONSTRAINT fk_security_session_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_security_session_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_security_session_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_security_session_hash (session_token_hash),
  KEY idx_security_session_user (organization_id,user_id,revoked_at,last_seen_at),
  KEY idx_security_session_expiry (organization_id,expires_at,revoked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  identifier_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(64) NULL,
  was_successful TINYINT(1) NOT NULL DEFAULT 0,
  failure_reason VARCHAR(80) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_security_login_identifier (identifier_hash,attempted_at),
  KEY idx_security_login_ip (ip_address,attempted_at),
  KEY idx_security_login_org (organization_id,attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_password_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_security_password_history_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  KEY idx_security_password_history_user (user_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_settings (
  organization_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(100) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id,setting_key),
  CONSTRAINT fk_security_setting_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
