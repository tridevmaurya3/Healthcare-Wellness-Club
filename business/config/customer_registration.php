<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';

const CUSTOMER_REGISTRATION_VERSION = '1.0-secure-public-signup';

function customer_registration_ensure(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) return;

    role_portal_ensure($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_registration_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        email_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        attempt_status VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_customer_registration_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        KEY idx_customer_registration_ip (organization_id,ip_hash,created_at),
        KEY idx_customer_registration_email (organization_id,email_hash,created_at),
        KEY idx_customer_registration_status (organization_id,attempt_status,created_at)
    ) ENGINE=InnoDB");

    // Keep only a short anti-abuse history. No plaintext email or IP address is stored here.
    try {
        $pdo->exec("DELETE FROM customer_registration_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Throwable) {
        // Registration must not fail only because best-effort cleanup could not run.
    }

    if (business_table_exists($pdo, 'schema_meta')) {
        $stmt = $pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('customer_registration_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([CUSTOMER_REGISTRATION_VERSION]);
    }

    $ready[$key] = true;
}

function customer_registration_hash(string $value): string
{
    return role_portal_recovery_hash('customer-registration|' . strtolower(trim($value)));
}

function customer_registration_ip_hash(): string
{
    return customer_registration_hash('ip|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function customer_registration_log(PDO $pdo, int $orgId, string $email, string $status): void
{
    $stmt = $pdo->prepare("INSERT INTO customer_registration_attempts(organization_id,email_hash,ip_hash,attempt_status) VALUES(?,?,?,?)");
    $stmt->execute([$orgId, customer_registration_hash($email), customer_registration_ip_hash(), substr($status, 0, 30)]);
}

function customer_registration_rate_limit(PDO $pdo, int $orgId, string $email): void
{
    $ipHash = customer_registration_ip_hash();
    $emailHash = customer_registration_hash($email);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_registration_attempts WHERE organization_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) AND (ip_hash=? OR email_hash=?)");
    $stmt->execute([$orgId, $ipHash, $emailHash]);
    if ((int)$stmt->fetchColumn() >= 5) {
        customer_registration_log($pdo, $orgId, $email, 'blocked');
        throw new RuntimeException('Too many registration attempts. Please wait and try again.');
    }
}

function customer_registration_mobile(string $mobile): ?string
{
    $mobile = trim($mobile);
    if ($mobile === '') return null;
    $normalized = preg_replace('/[\s()\-]/', '', $mobile) ?? $mobile;
    if (!preg_match('/^\+?[0-9]{7,15}$/', $normalized)) {
        throw new RuntimeException('Enter a valid mobile number.');
    }
    return $normalized;
}

function customer_registration_create(PDO $pdo, string $name, string $email, string $mobile, string $password, string $confirmPassword): int
{
    customer_registration_ensure($pdo);
    $ctx = security_step17_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = $ctx['club_id'];

    $name = trim($name);
    $email = strtolower(trim($email));

    customer_registration_rate_limit($pdo, $orgId, $email);

    if (strlen($name) < 2 || strlen($name) > 120) {
        customer_registration_log($pdo, $orgId, $email, 'invalid');
        throw new RuntimeException('Enter your full name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        customer_registration_log($pdo, $orgId, $email, 'invalid');
        throw new RuntimeException('Enter a valid email address.');
    }
    if ($password !== $confirmPassword) {
        customer_registration_log($pdo, $orgId, $email, 'invalid');
        throw new RuntimeException('Password and Confirm Password do not match.');
    }

    $mobile = customer_registration_mobile($mobile) ?? '';
    $hash = security_step17_password_hash($password);

    $exists = $pdo->prepare("SELECT id FROM system_users WHERE LOWER(email)=? LIMIT 1");
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        customer_registration_log($pdo, $orgId, $email, 'duplicate');
        throw new RuntimeException('An account with this email already exists. Please sign in or use Forgot password.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO system_users(full_name,email,mobile,password_hash,global_role,is_active,must_change_password,password_changed_at) VALUES(?,?,?,?, 'customer',1,0,NOW())");
        $stmt->execute([$name, $email, $mobile !== '' ? $mobile : null, $hash]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO organization_user_access(organization_id,club_id,user_id,role_code,permission_scope,is_active) VALUES(?,?,?,'customer','club',1)")
            ->execute([$orgId, $clubId, $userId]);
        $pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")
            ->execute([$userId, $hash]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        customer_registration_log($pdo, $orgId, $email, 'failed');
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new RuntimeException('An account with this email already exists. Please sign in or use Forgot password.');
        }
        throw $e;
    }

    customer_registration_log($pdo, $orgId, $email, 'success');
    security_step17_audit($pdo, null, 'customer_self_registered', 'system_user', $userId, [
        'role' => 'customer',
        'club_member_granted' => false,
        'self_registration' => true,
    ]);

    return $userId;
}
