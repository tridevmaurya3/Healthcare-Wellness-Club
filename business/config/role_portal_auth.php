<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const ROLE_PORTAL_VERSION = '1.1-advanced-rbac-recovery';

function role_portal_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'healthcare_wellness_club';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function role_portal_roles(): array
{
    return [
        'admin' => 'Administrator',
        'coach' => 'Coach',
        'customer' => 'Customer',
        'manager' => 'Manager (legacy internal)',
        'staff' => 'Staff (legacy internal)',
        'viewer' => 'Viewer (legacy internal)',
    ];
}

function role_portal_ensure(PDO $pdo): void
{
    security_step17_ensure($pdo);
    $ctx = security_step17_context($pdo);
    $orgId = (int)$ctx['organization_id'];

    $permission = $pdo->prepare("INSERT INTO security_permissions(permission_code,permission_name,module_code,risk_level,description,is_active) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_code=VALUES(module_code),risk_level=VALUES(risk_level),description=VALUES(description),is_active=1");
    $permission->execute(['portal.coach','Coach Portal','portal','sensitive','Open the restricted coach workspace.']);
    $permission->execute(['portal.customer','Customer Portal','portal','normal','Open the signed-in customer self-service workspace.']);

    $role = $pdo->prepare("INSERT INTO security_roles(organization_id,role_code,role_name,description,is_system,is_active) VALUES(?,?,?,?,1,1) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),description=VALUES(description),is_active=1");
    $role->execute([$orgId,'coach','Coach','Restricted coaching workspace for customer follow-up, public leads and product guidance.']);
    $role->execute([$orgId,'customer','Customer','Self-service customer portal only; no Business OS access.']);

    $insert = $pdo->prepare("INSERT IGNORE INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,?,?,1)");
    foreach (['portal.coach','customers.view','customers.manage','leads.view','leads.manage','products.view'] as $code) {
        $insert->execute([$orgId,'coach',$code]);
    }
    $insert->execute([$orgId,'customer','portal.customer']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_recovery_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        identifier_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        user_agent VARCHAR(500) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        handled_at DATETIME NULL,
        handled_by BIGINT UNSIGNED NULL,
        resolution_note VARCHAR(255) NULL,
        CONSTRAINT fk_recovery_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE SET NULL,
        CONSTRAINT fk_recovery_handler FOREIGN KEY (handled_by) REFERENCES system_users(id) ON DELETE SET NULL,
        KEY idx_recovery_status (organization_id,status,requested_at),
        KEY idx_recovery_identifier (organization_id,identifier_hash,requested_at),
        KEY idx_recovery_ip (organization_id,ip_hash,requested_at),
        KEY idx_recovery_user (organization_id,user_id,requested_at)
    ) ENGINE=InnoDB");

    if (business_table_exists($pdo,'schema_meta')) {
        $stmt = $pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('role_portal_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([ROLE_PORTAL_VERSION]);
    }
}

function role_portal_home(array $user): string
{
    return match ((string)($user['role_code'] ?? '')) {
        'coach' => 'coach_portal.php',
        'customer' => 'customer_portal.php',
        default => 'business/index.php',
    };
}

function role_portal_require(PDO $pdo, string $role): array
{
    role_portal_ensure($pdo);
    $user = security_step17_session_user($pdo, true);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header('Location: change_password.php?required=1');
        exit;
    }
    if ((string)$user['role_code'] !== $role) {
        header('Location: ' . role_portal_home($user));
        exit;
    }
    return $user;
}

function role_portal_create_user(PDO $pdo, string $name, string $email, string $mobile, string $role, string $temporaryPassword): int
{
    role_portal_ensure($pdo);
    $actor = security_step17_current_user($pdo);
    if (!security_step17_has_permission($pdo,'security.users.manage',$actor)) throw new RuntimeException('Administrator permission is required to create role accounts.');
    if (!in_array($role,['admin','coach','customer'],true)) throw new RuntimeException('Choose Administrator, Coach or Customer.');
    $name = trim($name); $email = strtolower(trim($email)); $mobile = trim($mobile);
    if ($name === '' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Valid name and email are required.');
    $hash = security_step17_password_hash($temporaryPassword);
    $ctx = security_step17_context($pdo); $orgId = (int)$ctx['organization_id']; $clubId = $ctx['club_id'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO system_users(full_name,email,mobile,password_hash,global_role,is_active,must_change_password,password_changed_at) VALUES(?,?,?,?,?,1,1,NOW())");
        $stmt->execute([$name,$email,$mobile !== '' ? $mobile : null,$hash,$role]);
        $uid = (int)$pdo->lastInsertId();
        $scope = $role === 'admin' ? 'organization' : 'club';
        $pdo->prepare("INSERT INTO organization_user_access(organization_id,club_id,user_id,role_code,permission_scope,is_active) VALUES(?,?,?,?,?,1)")->execute([$orgId,$clubId,$uid,$role,$scope]);
        $pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")->execute([$uid,$hash]);
        $pdo->commit();
        security_step17_audit($pdo,(int)$actor['id'],'role_portal_user_created','system_user',$uid,['email'=>$email,'role'=>$role,'must_change_password'=>true]);
        return $uid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (string)$e->getCode()==='23000') throw new RuntimeException('An account with this email already exists.');
        throw $e;
    }
}

function role_portal_update_user(PDO $pdo, int $userId, string $name, string $mobile, string $role, bool $active): void
{
    role_portal_ensure($pdo);
    $actor = security_step17_current_user($pdo);
    if (!security_step17_has_permission($pdo,'security.users.manage',$actor)) throw new RuntimeException('Administrator permission is required to manage role accounts.');
    if (!array_key_exists($role,role_portal_roles())) throw new RuntimeException('Choose a valid role.');
    $ctx = security_step17_context($pdo); $orgId = (int)$ctx['organization_id'];
    $stmt = $pdo->prepare("SELECT u.*,a.role_code old_role FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? WHERE u.id=? LIMIT 1");
    $stmt->execute([$orgId,$userId]); $u = $stmt->fetch();
    if (!$u) throw new RuntimeException('User was not found.');
    $wasAdmin = (string)$u['old_role']==='admin' && (int)$u['is_active']===1;
    if ($wasAdmin && (!$active || $role!=='admin') && security_step17_admin_count($pdo,$orgId)<=1) throw new RuntimeException('The last active Administrator cannot be disabled or demoted.');
    if ($userId === (int)$actor['id'] && !$active) throw new RuntimeException('You cannot disable your own signed-in account.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE system_users SET full_name=?,mobile=?,global_role=?,is_active=? WHERE id=?")->execute([trim($name)!==''?trim($name):$u['full_name'],trim($mobile)!==''?trim($mobile):null,$role,$active?1:0,$userId]);
        $scope = $role==='admin'?'organization':'club';
        $pdo->prepare("UPDATE organization_user_access SET role_code=?,permission_scope=?,is_active=? WHERE organization_id=? AND user_id=?")->execute([$role,$scope,$active?1:0,$orgId,$userId]);
        if (!$active || $role !== (string)$u['old_role']) $pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='role_portal_access_changed' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL")->execute([$orgId,$userId]);
        $pdo->commit();
        security_step17_audit($pdo,(int)$actor['id'],'role_portal_user_updated','system_user',$userId,['old_role'=>$u['old_role'],'new_role'=>$role,'active'=>$active]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function role_portal_recovery_pepper(): string
{
    $pepper = (string)(getenv('HWC_PUBLIC_FORM_PEPPER') ?: 'hwc-local-recovery-pepper');
    return hash('sha256','account-recovery|'.$pepper);
}

function role_portal_recovery_hash(string $value): string
{
    return hash_hmac('sha256',strtolower(trim($value)),role_portal_recovery_pepper());
}

function role_portal_request_recovery(PDO $pdo, string $email): void
{
    role_portal_ensure($pdo);
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return;

    $identifierHash=role_portal_recovery_hash($email);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $ipHash=role_portal_recovery_hash('ip|'.$ip);

    $rate=$pdo->prepare("SELECT COUNT(*) FROM password_recovery_requests WHERE organization_id=? AND requested_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) AND (identifier_hash=? OR ip_hash=?)");
    $rate->execute([$orgId,$identifierHash,$ipHash]);
    if((int)$rate->fetchColumn()>=3) return;

    $stmt=$pdo->prepare("SELECT u.id FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 WHERE LOWER(u.email)=? AND u.is_active=1 LIMIT 1");
    $stmt->execute([$orgId,$email]);$userId=(int)$stmt->fetchColumn();
    $status=$userId>0?'pending':'unmatched';
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
    $stmt=$pdo->prepare("INSERT INTO password_recovery_requests(organization_id,user_id,identifier_hash,ip_hash,user_agent,status) VALUES(?,?,?,?,?,?)");
    $stmt->execute([$orgId,$userId>0?$userId:null,$identifierHash,$ipHash,$ua!==''?$ua:null,$status]);
    security_step17_audit($pdo,$userId>0?$userId:null,'password_recovery_requested','system_user',$userId>0?$userId:null,['matched_account'=>$userId>0]);
}

function role_portal_recovery_rows(PDO $pdo, int $orgId, int $limit=100): array
{
    role_portal_ensure($pdo);$limit=max(1,min(200,$limit));
    $sql="SELECT r.id,r.user_id,r.status,r.requested_at,r.handled_at,r.resolution_note,u.full_name,u.email,u.mobile,u.is_active,a.role_code,h.full_name handled_by_name
          FROM password_recovery_requests r
          LEFT JOIN system_users u ON u.id=r.user_id
          LEFT JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=r.organization_id AND a.is_active=1
          LEFT JOIN system_users h ON h.id=r.handled_by
          WHERE r.organization_id=?
          ORDER BY CASE WHEN r.status='pending' THEN 0 WHEN r.status='unmatched' THEN 1 ELSE 2 END,r.requested_at DESC,r.id DESC
          LIMIT {$limit}";
    $stmt=$pdo->prepare($sql);$stmt->execute([$orgId]);return $stmt->fetchAll();
}

function role_portal_pending_recovery_count(PDO $pdo, int $orgId): int
{
    role_portal_ensure($pdo);$stmt=$pdo->prepare("SELECT COUNT(*) FROM password_recovery_requests WHERE organization_id=? AND status IN ('pending','unmatched')");$stmt->execute([$orgId]);return (int)$stmt->fetchColumn();
}

function role_portal_handle_recovery(PDO $pdo, int $requestId, string $action, string $temporaryPassword=''): void
{
    role_portal_ensure($pdo);$actor=security_step17_current_user($pdo);
    if((string)($actor['role_code']??'')!=='admin' || !security_step17_has_permission($pdo,'security.users.manage',$actor)) throw new RuntimeException('Administrator permission is required for account recovery.');
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("SELECT * FROM password_recovery_requests WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$requestId]);$r=$stmt->fetch();
    if(!$r) throw new RuntimeException('Recovery request was not found.');
    if(!in_array((string)$r['status'],['pending','unmatched'],true)) throw new RuntimeException('This recovery request is already closed.');

    if($action==='dismiss'){
        $pdo->prepare("UPDATE password_recovery_requests SET status='dismissed',handled_at=NOW(),handled_by=?,resolution_note='Dismissed by administrator' WHERE id=?")->execute([(int)$actor['id'],$requestId]);
        security_step17_audit($pdo,(int)$actor['id'],'password_recovery_dismissed','password_recovery_request',$requestId,['matched_account'=>(int)($r['user_id']??0)>0]);
        return;
    }
    if($action!=='reset') throw new RuntimeException('Recovery action is invalid.');
    $userId=(int)($r['user_id']??0);if($userId<=0) throw new RuntimeException('This request is not linked to an active account and cannot be reset.');
    security_step17_admin_reset_password($pdo,$userId,$temporaryPassword);
    $pdo->prepare("UPDATE password_recovery_requests SET status='reset',handled_at=NOW(),handled_by=?,resolution_note='Temporary password issued; change required at next sign-in' WHERE id=?")->execute([(int)$actor['id'],$requestId]);
    $pdo->prepare("UPDATE password_recovery_requests SET status='superseded',handled_at=NOW(),handled_by=?,resolution_note='Closed by newer successful recovery' WHERE organization_id=? AND user_id=? AND status='pending' AND id<>?")->execute([(int)$actor['id'],$orgId,$userId,$requestId]);
    security_step17_audit($pdo,(int)$actor['id'],'password_recovery_reset_completed','system_user',$userId,['request_id'=>$requestId,'force_change'=>true]);
}
