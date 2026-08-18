-- STEP 19: Cloud Deployment, Multi-Device Access & Production Readiness

CREATE TABLE IF NOT EXISTS deployment_environments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    environment_code VARCHAR(32) NOT NULL,
    environment_name VARCHAR(120) NOT NULL,
    app_url VARCHAR(500) NULL,
    status ENUM('planned','ready','active','retired') NOT NULL DEFAULT 'planned',
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    requires_https TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deployment_environment (organization_id, environment_code),
    CONSTRAINT fk_deployment_environment_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_releases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    release_code VARCHAR(80) NOT NULL,
    version_label VARCHAR(120) NOT NULL,
    git_commit_sha VARCHAR(64) NULL,
    environment_code VARCHAR(32) NOT NULL,
    release_status ENUM('planned','ready','deployed','rolled_back','failed') NOT NULL DEFAULT 'planned',
    deployed_at DATETIME NULL,
    deployed_by BIGINT UNSIGNED NULL,
    notes VARCHAR(2000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deployment_release_code (organization_id, release_code),
    KEY idx_deployment_release_env (organization_id, environment_code, release_status),
    CONSTRAINT fk_deployment_release_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_deployment_release_user FOREIGN KEY (deployed_by) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    environment_code VARCHAR(32) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_status ENUM('info','pass','review','failed') NOT NULL DEFAULT 'info',
    details_json LONGTEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_deployment_event_org_date (organization_id, created_at),
    CONSTRAINT fk_deployment_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_deployment_event_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_health_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    environment_code VARCHAR(32) NOT NULL,
    status ENUM('pass','review','failed') NOT NULL,
    checks_passed INT UNSIGNED NOT NULL DEFAULT 0,
    checks_review INT UNSIGNED NOT NULL DEFAULT 0,
    details_json LONGTEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_deployment_health_org_date (organization_id, started_at),
    CONSTRAINT fk_deployment_health_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_scheduler_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    task_code VARCHAR(80) NOT NULL,
    environment_code VARCHAR(32) NOT NULL,
    status ENUM('running','pass','review','failed') NOT NULL DEFAULT 'running',
    details VARCHAR(2000) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_deployment_scheduler_org_task (organization_id, task_code, started_at),
    CONSTRAINT fk_deployment_scheduler_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_offsite_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    target_code VARCHAR(80) NOT NULL,
    target_name VARCHAR(150) NOT NULL,
    adapter_type ENUM('filesystem','provider_pending') NOT NULL DEFAULT 'filesystem',
    location_label VARCHAR(500) NULL,
    secret_env_key VARCHAR(120) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_tested_at DATETIME NULL,
    last_status ENUM('unknown','pass','review','failed') NOT NULL DEFAULT 'unknown',
    last_detail VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deployment_offsite_target (organization_id, target_code),
    CONSTRAINT fk_deployment_offsite_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_migration_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    migration_code VARCHAR(80) NOT NULL,
    source_environment VARCHAR(32) NOT NULL,
    target_environment VARCHAR(32) NOT NULL,
    backup_record_id BIGINT UNSIGNED NULL,
    status ENUM('planned','backup_ready','target_ready','restored','validated','completed','failed') NOT NULL DEFAULT 'planned',
    source_schema_fingerprint CHAR(64) NULL,
    target_schema_fingerprint CHAR(64) NULL,
    notes VARCHAR(2000) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_deployment_migration_code (organization_id, migration_code),
    CONSTRAINT fk_deployment_migration_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_deployment_migration_backup FOREIGN KEY (backup_record_id) REFERENCES backup_records(id) ON DELETE SET NULL,
    CONSTRAINT fk_deployment_migration_user FOREIGN KEY (created_by) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deployment_settings (
    organization_id BIGINT UNSIGNED PRIMARY KEY,
    maintenance_enabled TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_message VARCHAR(500) NULL,
    maintenance_started_at DATETIME NULL,
    maintenance_started_by BIGINT UNSIGNED NULL,
    require_https_in_production TINYINT(1) NOT NULL DEFAULT 1,
    require_offsite_before_production TINYINT(1) NOT NULL DEFAULT 1,
    require_verified_backup_before_release TINYINT(1) NOT NULL DEFAULT 1,
    minimum_php_version VARCHAR(32) NOT NULL DEFAULT '8.1.0',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_settings_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_deployment_settings_user FOREIGN KEY (maintenance_started_by) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
