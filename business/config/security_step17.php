<?php
declare(strict_types=1);

const SECURITY_STEP17_VERSION = '1.0-complete';

function security_step17_tables(): array
{
    return [
        'security_permissions','security_roles','security_role_permissions','security_user_permissions',
        'security_sessions','security_login_attempts','security_password_history','security_settings',
    ];
}

function security_step17_script(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
}

function security_step17_is_cli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

function security_step17_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function security_step17_session_start(): void
{
    if (security_step17_is_cli() || session_status() === PHP_SESSION_ACTIVE) return;
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_name('HWCSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function security_step17_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/013_step17_users_roles_security.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 17 security migration is missing.');
    $sql = (string)file_get_contents($file);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        $statement = preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement) ?? $statement;
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^USE\s+/i', $statement)) continue;
        $pdo->exec($statement);
    }
}

function security_step17_permission_catalog(): array
{
    return [
        ['dashboard.view','Dashboard','dashboard','normal','Open Business OS dashboard, search, today and insights.'],
        ['members.view','Members: View','members','normal','View member profiles and sponsor network.'],
        ['members.manage','Members: Manage','members','sensitive','Create or change member/network operational data.'],
        ['business.view','Business Operations: View','business','normal','View operational orders, VP, income and renewal workspaces.'],
        ['business.manage','Business Operations: Manage','business','sensitive','Create/correct/reverse/restore operational facts.'],
        ['reports.view','Reports: View','reports','normal','View live derived reports and analytics.'],
        ['reports.export','Reports: Export','reports','sensitive','Export business data to CSV/print/PDF-ready views.'],
        ['products.view','Products: View','products','normal','View product catalog, exact PDF prices and history.'],
        ['products.manage','Products: Manage','products','sensitive','Manage product data, images and price imports.'],
        ['sales.view','Sales: View','sales','normal','View quotes, sales, payments and sale detail.'],
        ['sales.manage','Sales: Manage','sales','sensitive','Finalize/cancel/restore sales and manage sale payments/cost basis.'],
        ['inventory.view','Inventory: View','inventory','normal','View stock, batches, expiry and inventory analytics.'],
        ['inventory.manage','Inventory: Manage','inventory','sensitive','Post stock inward, adjustments, stocktake and sale sync.'],
        ['purchases.view','Purchases: View','purchases','normal','View suppliers, purchase orders, bills and payables.'],
        ['purchases.manage','Purchases: Manage','purchases','sensitive','Create suppliers/PO/bills/receipts/payments/returns.'],
        ['customers.view','Customers: View','customers','sensitive','View customer CRM, fulfillment, receivables and follow-ups.'],
        ['customers.manage','Customers: Manage','customers','sensitive','Manage customers, delivery, returns, refunds and CRM follow-ups.'],
        ['finance.view','Finance: View','finance','restricted','View journals, cashbook, P&L, cash flow and reconciliation.'],
        ['finance.manage','Finance: Manage','finance','restricted','Create accounts/manual finance entries and perform reconciliation.'],
        ['audit.view','Audit & Data Quality','audit','restricted','View audit, data quality and system health workspaces.'],
        ['security.users.view','Security Users: View','security','restricted','View system users and access roles.'],
        ['security.users.manage','Security Users: Manage','security','critical','Create/deactivate users, set roles and reset passwords.'],
        ['security.roles.manage','Security Roles & Permissions','security','critical','Change Manager/Staff/Viewer permission matrix and user overrides.'],
        ['security.audit.view','Security Audit','security','critical','View login failures, security events and access history.'],
        ['security.sessions.manage','Security Sessions','security','critical','View and revoke active sessions.'],
    ];
}

function security_step17_role_defaults(): array
{
    $manager = [
        'dashboard.view','members.view','members.manage','business.view','business.manage','reports.view','reports.export',
        'products.view','products.manage','sales.view','sales.manage','inventory.view','inventory.manage',
        'purchases.view','purchases.manage','customers.view','customers.manage','finance.view','audit.view',
    ];
    $staff = [
        'dashboard.view','members.view','business.view','business.manage','reports.view',
        'products.view','sales.view','sales.manage','inventory.view','inventory.manage',
        'purchases.view','purchases.manage','customers.view','customers.manage',
    ];
    $viewer = ['dashboard.view','members.view','business.view','reports.view','products.view','sales.view'];
    return ['manager'=>$manager,'staff'=>$staff,'viewer'=>$viewer];
}

function security_step17_ensure(PDO $pdo): void
{
    foreach (security_step17_tables() as $table) {
        if (!business_table_exists($pdo, $table)) { security_step17_run_migration($pdo); break; }
    }
    foreach (security_step17_tables() as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException('STEP 17 security table missing: ' . $table);
    }

    $columns = [
        'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0",
        'password_changed_at' => "DATETIME NULL",
        'last_login_ip' => "VARCHAR(64) NULL",
        'last_login_user_agent' => "VARCHAR(500) NULL",
    ];
    foreach ($columns as $column => $definition) {
        if (!business_column_exists($pdo, 'system_users', $column)) {
            $pdo->exec("ALTER TABLE system_users ADD COLUMN `{$column}` {$definition}");
        }
    }

    $orgId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($orgId <= 0) return;

    $perm = $pdo->prepare("INSERT IGNORE INTO security_permissions(permission_code,permission_name,module_code,risk_level,description,is_active) VALUES(?,?,?,?,?,1)");
    foreach (security_step17_permission_catalog() as $p) $perm->execute($p);

    $roles = [
        ['admin','Administrator','Full system administration including users, security and finance.'],
        ['manager','Manager','Operational management with Finance read access by default.'],
        ['staff','Staff','Day-to-day business, sales, inventory, purchase and customer operations.'],
        ['viewer','Viewer','Read-only business/report/product access by default.'],
    ];
    $roleStmt = $pdo->prepare("INSERT INTO security_roles(organization_id,role_code,role_name,description,is_system,is_active) VALUES(?,?,?,?,1,1) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),description=VALUES(description),is_active=1");
    foreach ($roles as $r) $roleStmt->execute([$orgId,$r[0],$r[1],$r[2]]);

    $allCodes = array_map(static fn(array $p): string => $p[0], security_step17_permission_catalog());
    $insertRolePerm = $pdo->prepare("INSERT IGNORE INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,?,?,1)");
    foreach ($allCodes as $code) $insertRolePerm->execute([$orgId,'admin',$code]);
    foreach (security_step17_role_defaults() as $role => $codes) {
        foreach ($codes as $code) $insertRolePerm->execute([$orgId,$role,$code]);
    }

    $settings = [
        'idle_timeout_seconds' => '1800',
        'absolute_timeout_seconds' => '43200',
        'login_attempt_limit' => '5',
        'login_window_seconds' => '900',
        'login_lock_seconds' => '900',
        'password_history_count' => '5',
    ];
    $settingStmt = $pdo->prepare("INSERT IGNORE INTO security_settings(organization_id,setting_key,setting_value) VALUES(?,?,?)");
    foreach ($settings as $k => $v) $settingStmt->execute([$orgId,$k,$v]);

    if (business_table_exists($pdo, 'schema_meta')) {
        $stmt = $pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('security_step17_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([SECURITY_STEP17_VERSION]);
    }
}

function security_step17_context(PDO $pdo): array
{
    security_step17_ensure($pdo);
    $stmt=$pdo->query("SELECT o.id organization_id,c.id club_id FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1");
    $row=$stmt->fetch();
    if(!$row) throw new RuntimeException('Healthcare Wellness Club security context is unavailable.');
    return ['organization_id'=>(int)$row['organization_id'],'club_id'=>$row['club_id']!==null?(int)$row['club_id']:null];
}

function security_step17_setting(PDO $pdo,int $orgId,string $key,int $fallback): int
{
    $stmt=$pdo->prepare("SELECT setting_value FROM security_settings WHERE organization_id=? AND setting_key=? LIMIT 1");
    $stmt->execute([$orgId,$key]);$v=$stmt->fetchColumn();
    return $v===false ? $fallback : max(1,(int)$v);
}

function security_step17_audit(PDO $pdo,?int $userId,string $event,string $entityType,?int $entityId,array $details=[]): void
{
    if(!business_table_exists($pdo,'audit_logs')) return;
    $orgId=(int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    $clubId=$orgId>0?(int)$pdo->query("SELECT COALESCE((SELECT id FROM clubs WHERE organization_id={$orgId} AND club_code='GHAZIPUR-001' LIMIT 1),0)")->fetchColumn():0;
    $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $stmt=$pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,user_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId?:null,$clubId?:null,$userId,$event,$entityType,$entityId,$json===false?null:$json,substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
}

function security_step17_csrf(): string
{
    security_step17_session_start();
    if (empty($_SESSION['hwc_security_csrf'])) $_SESSION['hwc_security_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['hwc_security_csrf'];
}

function security_step17_verify_csrf(string $token): void
{
    $expected=security_step17_csrf();
    if($token===''||!hash_equals($expected,$token)) throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
}

function security_step17_password_policy(string $password): void
{
    if(strlen($password)<12) throw new RuntimeException('Password must be at least 12 characters.');
    if(!preg_match('/[A-Z]/',$password)||!preg_match('/[a-z]/',$password)||!preg_match('/[0-9]/',$password)||!preg_match('/[^A-Za-z0-9]/',$password)) {
        throw new RuntimeException('Password must include uppercase, lowercase, number and special character.');
    }
}

function security_step17_password_hash(string $password): string
{
    security_step17_password_policy($password);
    $algo=defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash=password_hash($password,$algo);
    if($hash===false) throw new RuntimeException('Password could not be secured.');
    return $hash;
}

function security_step17_admin_count(PDO $pdo,int $orgId): int
{
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 AND a.role_code='admin' WHERE u.is_active=1");
    $stmt->execute([$orgId]);return(int)$stmt->fetchColumn();
}

function security_step17_total_users(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM system_users")->fetchColumn();
}

function security_step17_setup_allowed(): bool
{
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    if($ip===''||$ip==='127.0.0.1'||$ip==='::1') return true;
    $expected=(string)(getenv('HWC_SETUP_KEY')?:'');
    $provided=(string)($_POST['setup_key']??$_GET['setup_key']??'');
    return $expected!=='' && $provided!=='' && hash_equals($expected,$provided);
}

function security_step17_create_first_admin(PDO $pdo,string $name,string $email,string $mobile,string $password): int
{
    security_step17_ensure($pdo);
    if(security_step17_total_users($pdo)>0) throw new RuntimeException('First-admin setup is already closed because a system user exists.');
    if(!security_step17_setup_allowed()) throw new RuntimeException('First-admin setup is restricted. Use localhost or configure HWC_SETUP_KEY for a remote deployment.');
    $name=trim($name);$email=strtolower(trim($email));$mobile=trim($mobile);
    if($name==='') throw new RuntimeException('Administrator name is required.');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid administrator email address.');
    $hash=security_step17_password_hash($password);
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=$ctx['club_id'];
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO system_users(full_name,email,mobile,password_hash,global_role,is_active,must_change_password,password_changed_at) VALUES(?,?,?,?, 'admin',1,0,NOW())");
        $stmt->execute([$name,$email,$mobile?:null,$hash]);$userId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare("INSERT INTO organization_user_access(organization_id,club_id,user_id,role_code,permission_scope,is_active) VALUES(?,?,?,'admin','organization',1)");
        $stmt->execute([$orgId,$clubId,$userId]);
        $pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")->execute([$userId,$hash]);
        $pdo->commit();
        security_step17_audit($pdo,$userId,'security_first_admin_created','system_user',$userId,['email'=>$email,'role'=>'admin','bootstrap'=>'explicit']);
        return $userId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function security_step17_identifier_hash(string $email): string
{
    return hash('sha256',strtolower(trim($email)));
}

function security_step17_record_login_attempt(PDO $pdo,?int $orgId,string $email,bool $success,string $reason): void
{
    $stmt=$pdo->prepare("INSERT INTO security_login_attempts(organization_id,identifier_hash,ip_address,was_successful,failure_reason) VALUES(?,?,?,?,?)");
    $stmt->execute([$orgId,security_step17_identifier_hash($email),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),$success?1:0,$success?null:substr($reason,0,80)]);
}

function security_step17_assert_not_throttled(PDO $pdo,int $orgId,string $email): void
{
    $limit=security_step17_setting($pdo,$orgId,'login_attempt_limit',5);
    $window=security_step17_setting($pdo,$orgId,'login_window_seconds',900);
    $cutoff=(new DateTimeImmutable())->modify('-'.$window.' seconds')->format('Y-m-d H:i:s');
    $hash=security_step17_identifier_hash($email);$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM security_login_attempts WHERE attempted_at>=? AND was_successful=0 AND (identifier_hash=? OR (?<>'' AND ip_address=?))");
    $stmt->execute([$cutoff,$hash,$ip,$ip]);
    if((int)$stmt->fetchColumn()>=$limit) throw new RuntimeException('Too many sign-in attempts. Please wait before trying again.');
}

function security_step17_login(PDO $pdo,string $email,string $password): array
{
    security_step17_ensure($pdo);security_step17_session_start();
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=$ctx['club_id'];
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email or password is incorrect.');
    security_step17_assert_not_throttled($pdo,$orgId,$email);
    $stmt=$pdo->prepare("SELECT u.*,a.role_code,a.club_id access_club_id,a.permission_scope FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 WHERE LOWER(u.email)=? LIMIT 1");
    $stmt->execute([$orgId,$email]);$u=$stmt->fetch();
    if(!$u || !(int)$u['is_active'] || !password_verify($password,(string)$u['password_hash'])) {
        security_step17_record_login_attempt($pdo,$orgId,$email,false,'invalid_credentials');
        security_step17_audit($pdo,$u?(int)$u['id']:null,'security_login_failed','system_user',$u?(int)$u['id']:null,['identifier_hash'=>security_step17_identifier_hash($email),'reason'=>'invalid_credentials']);
        throw new RuntimeException('Email or password is incorrect.');
    }
    $role=(string)$u['role_code'];
    $roleStmt=$pdo->prepare("SELECT COUNT(*) FROM security_roles WHERE organization_id=? AND role_code=? AND is_active=1");$roleStmt->execute([$orgId,$role]);
    if((int)$roleStmt->fetchColumn()!==1){security_step17_record_login_attempt($pdo,$orgId,$email,false,'role_inactive');throw new RuntimeException('This account does not currently have active system access.');}

    if(password_needs_rehash((string)$u['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT)) {
        $newHash=password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
        if($newHash!==false)$pdo->prepare("UPDATE system_users SET password_hash=? WHERE id=?")->execute([$newHash,(int)$u['id']]);
    }

    session_regenerate_id(true);
    $idle=security_step17_setting($pdo,$orgId,'idle_timeout_seconds',1800);$absolute=security_step17_setting($pdo,$orgId,'absolute_timeout_seconds',43200);
    $expires=(new DateTimeImmutable())->modify('+'.$absolute.' seconds')->format('Y-m-d H:i:s');
    $tokenHash=hash('sha256',session_id());$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
    $stmt=$pdo->prepare("INSERT INTO security_sessions(organization_id,club_id,user_id,role_code,session_token_hash,ip_address,user_agent,expires_at) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$u['access_club_id']!==null?(int)$u['access_club_id']:$clubId,(int)$u['id'],$role,$tokenHash,$ip?:null,$ua?:null,$expires]);$sessionRowId=(int)$pdo->lastInsertId();
    $_SESSION['hwc_user_id']=(int)$u['id'];$_SESSION['hwc_org_id']=$orgId;$_SESSION['hwc_role']=$role;$_SESSION['hwc_security_session_id']=$sessionRowId;$_SESSION['hwc_login_at']=time();$_SESSION['hwc_last_activity']=time();$_SESSION['hwc_idle_timeout']=$idle;
    security_step17_record_login_attempt($pdo,$orgId,$email,true,'');
    $pdo->prepare("UPDATE system_users SET last_login_at=NOW(),last_login_ip=?,last_login_user_agent=? WHERE id=?")->execute([$ip?:null,$ua?:null,(int)$u['id']]);
    security_step17_audit($pdo,(int)$u['id'],'security_login_success','system_user',(int)$u['id'],['role'=>$role,'session_id'=>$sessionRowId]);
    return ['id'=>(int)$u['id'],'full_name'=>(string)$u['full_name'],'email'=>(string)$u['email'],'role_code'=>$role,'must_change_password'=>(int)$u['must_change_password']===1];
}

function security_step17_destroy_php_session(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)return;
    $_SESSION=[];
    if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}
    session_destroy();
}

function security_step17_logout(PDO $pdo,string $reason='user_logout'): void
{
    security_step17_session_start();$userId=(int)($_SESSION['hwc_user_id']??0);$sid=(int)($_SESSION['hwc_security_session_id']??0);
    if($sid>0)$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,?) WHERE id=?")->execute([$reason,$sid]);
    if($userId>0)security_step17_audit($pdo,$userId,'security_logout','system_user',$userId,['session_id'=>$sid,'reason'=>$reason]);
    security_step17_destroy_php_session();
}

function security_step17_session_user(PDO $pdo,bool $touch=true): ?array
{
    security_step17_session_start();$userId=(int)($_SESSION['hwc_user_id']??0);$sessionRowId=(int)($_SESSION['hwc_security_session_id']??0);
    if($userId<=0||$sessionRowId<=0)return null;
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile,u.is_active,u.must_change_password,u.last_login_at,s.id session_row_id,s.role_code,s.created_at session_created_at,s.last_seen_at,s.expires_at,s.revoked_at,s.user_agent,a.permission_scope FROM security_sessions s JOIN system_users u ON u.id=s.user_id JOIN organization_user_access a ON a.organization_id=s.organization_id AND a.user_id=s.user_id AND a.role_code=s.role_code AND a.is_active=1 WHERE s.id=? AND s.organization_id=? AND s.user_id=? LIMIT 1");
    $stmt->execute([$sessionRowId,$orgId,$userId]);$u=$stmt->fetch();if(!$u||(int)$u['is_active']!==1||$u['revoked_at']!==null)return null;
    $now=new DateTimeImmutable();$expires=new DateTimeImmutable((string)$u['expires_at']);$lastSeen=new DateTimeImmutable((string)$u['last_seen_at']);$idle=security_step17_setting($pdo,$orgId,'idle_timeout_seconds',1800);
    $currentUa=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
    if($expires<=$now || $lastSeen->modify('+'.$idle.' seconds')<=$now || ((string)$u['user_agent']!=='' && (string)$u['user_agent']!==$currentUa)) {
        $pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,'expired_or_fingerprint_changed') WHERE id=?")->execute([$sessionRowId]);
        return null;
    }
    if($touch && (time()-(int)($_SESSION['hwc_last_touch']??0))>=60){$pdo->prepare("UPDATE security_sessions SET last_seen_at=NOW() WHERE id=?")->execute([$sessionRowId]);$_SESSION['hwc_last_touch']=time();}
    $_SESSION['hwc_last_activity']=time();return $u;
}

function security_step17_permissions(PDO $pdo,int $orgId,int $userId,string $role): array
{
    if($role==='admin'){
        $stmt=$pdo->query("SELECT permission_code FROM security_permissions WHERE is_active=1 ORDER BY permission_code");return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true);
    }
    $stmt=$pdo->prepare("SELECT permission_code,is_allowed FROM security_role_permissions WHERE organization_id=? AND role_code=?");$stmt->execute([$orgId,$role]);$result=[];foreach($stmt->fetchAll() as $r)$result[(string)$r['permission_code']]=(int)$r['is_allowed']===1;
    $stmt=$pdo->prepare("SELECT permission_code,is_allowed FROM security_user_permissions WHERE organization_id=? AND user_id=?");$stmt->execute([$orgId,$userId]);foreach($stmt->fetchAll() as $r)$result[(string)$r['permission_code']]=(int)$r['is_allowed']===1;
    return $result;
}

function security_step17_has_permission(PDO $pdo,string $permission,?array $user=null): bool
{
    $user=$user??security_step17_session_user($pdo,false);if(!$user)return false;if((string)$user['role_code']==='admin')return true;
    $ctx=security_step17_context($pdo);$perms=security_step17_permissions($pdo,(int)$ctx['organization_id'],(int)$user['id'],(string)$user['role_code']);return !empty($perms[$permission]);
}

function security_step17_route_permission(string $script,string $method='GET'): string
{
    $write=strtoupper($method)==='POST';
    if(in_array($script,['user_management.php'],true))return $write?'security.users.manage':'security.users.view';
    if($script==='permission_matrix.php')return 'security.roles.manage';
    if(in_array($script,['security_center.php','security_audit.php'],true))return 'security.audit.view';
    if($script==='security_sessions.php')return 'security.sessions.manage';
    if(str_starts_with($script,'finance_')||$script==='step16_audit.php')return $write?'finance.manage':'finance.view';
    if(str_starts_with($script,'inventory_')||$script==='step13_audit.php')return $write?'inventory.manage':'inventory.view';
    if(str_starts_with($script,'purchase_')||str_starts_with($script,'supplier_')||$script==='step14_audit.php')return $write?'purchases.manage':'purchases.view';
    if(str_starts_with($script,'customer_')||str_starts_with($script,'crm_')||str_starts_with($script,'sales_')||$script==='step15_audit.php')return $write?'customers.manage':'customers.view';
    if(str_starts_with($script,'product_')||$script==='product_audit.php'){
        $salesFiles=['product_order_builder.php','product_quotes.php','product_sales_center.php','product_sales_center_core.php','product_sale_detail.php','product_payments.php','product_cost_center.php','product_sales_analytics.php','product_sales_export.php','product_sales_sync.php','step12_audit.php'];
        if(in_array($script,$salesFiles,true))return $write?'sales.manage':'sales.view';
        $manageFiles=['product_data_center.php','product_images.php','product_import_center.php'];
        if($write&&in_array($script,$manageFiles,true))return 'products.manage';
        return 'products.view';
    }
    if(in_array($script,['members.php','member_profile.php','sponsor_network.php'],true))return $write?'members.manage':'members.view';
    if(in_array($script,['data_entry_center.php','correction_center.php','reversal_center.php','restore_center.php','data_management.php','operations_center.php'],true))return $write?'business.manage':'business.view';
    if(in_array($script,['report_center.php','master_tracking.php','sp_house.php','name_wise_tracking.php','master_business_tracking.php','ums_renewal.php','ums_active_duration.php','insights_center.php'],true))return 'reports.view';
    if(str_contains($script,'export'))return 'reports.export';
    if(in_array($script,['audit_center.php','data_quality.php','health_center.php','step10_audit.php'],true))return 'audit.view';
    return 'dashboard.view';
}

function security_step17_next_url(string $candidate): string
{
    $candidate=trim($candidate);
    if($candidate===''||str_contains($candidate,'://')||str_starts_with($candidate,'//')||str_contains($candidate,"\n")||str_contains($candidate,"\r"))return 'index.php';
    $candidate=ltrim($candidate,'/');
    if(str_contains($candidate,'..'))return 'index.php';
    return $candidate;
}

function security_step17_redirect_login(): never
{
    $target=security_step17_script();$qs=(string)($_SERVER['QUERY_STRING']??'');if($qs!=='')$target.='?'.$qs;
    header('Location: login.php?next='.rawurlencode($target));exit;
}

function security_step17_guard_request(PDO $pdo): void
{
    if(security_step17_is_cli())return;
    security_step17_ensure($pdo);security_step17_session_start();$script=security_step17_script();
    $public=['login.php','setup_admin.php','logout.php','access_denied.php'];
    if(in_array($script,$public,true))return;
    if(security_step17_total_users($pdo)===0){header('Location: setup_admin.php');exit;}
    $user=security_step17_session_user($pdo,true);
    if(!$user){security_step17_destroy_php_session();security_step17_redirect_login();}
    if((int)$user['must_change_password']===1 && !in_array($script,['password_change.php','logout.php'],true)){header('Location: password_change.php?required=1');exit;}
    if($script==='password_change.php')return;
    $permission=security_step17_route_permission($script,(string)($_SERVER['REQUEST_METHOD']??'GET'));
    if(!security_step17_has_permission($pdo,$permission,$user)){
        header('Location: access_denied.php?permission='.rawurlencode($permission));exit;
    }
}

function security_step17_current_user(PDO $pdo): array
{
    $u=security_step17_session_user($pdo,false);if(!$u)throw new RuntimeException('No authenticated user session.');return $u;
}

function security_step17_change_password(PDO $pdo,int $userId,string $currentPassword,string $newPassword): void
{
    security_step17_ensure($pdo);$stmt=$pdo->prepare("SELECT * FROM system_users WHERE id=? LIMIT 1");$stmt->execute([$userId]);$u=$stmt->fetch();if(!$u||!password_verify($currentPassword,(string)$u['password_hash']))throw new RuntimeException('Current password is incorrect.');security_step17_password_policy($newPassword);
    $count=5;$stmt=$pdo->prepare("SELECT password_hash FROM security_password_history WHERE user_id=? ORDER BY id DESC LIMIT {$count}");$stmt->execute([$userId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $old){if(password_verify($newPassword,(string)$old))throw new RuntimeException('Choose a password you have not recently used.');}
    $hash=security_step17_password_hash($newPassword);$pdo->beginTransaction();try{$pdo->prepare("UPDATE system_users SET password_hash=?,must_change_password=0,password_changed_at=NOW() WHERE id=?")->execute([$hash,$userId]);$pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")->execute([$userId,$hash]);$currentSid=(int)($_SESSION['hwc_security_session_id']??0);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='password_changed' WHERE user_id=? AND revoked_at IS NULL AND id<>?")->execute([$userId,$currentSid]);$pdo->commit();security_step17_audit($pdo,$userId,'security_password_changed','system_user',$userId,['other_sessions_revoked'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function security_step17_users(PDO $pdo,int $orgId): array
{
    $stmt=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile,u.global_role,u.is_active,u.must_change_password,u.last_login_at,u.password_changed_at,a.role_code,a.permission_scope,a.is_active access_active,(SELECT COUNT(*) FROM security_sessions s WHERE s.user_id=u.id AND s.organization_id=? AND s.revoked_at IS NULL AND s.expires_at>NOW()) active_sessions FROM system_users u LEFT JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? ORDER BY u.is_active DESC,u.full_name,u.id");$stmt->execute([$orgId,$orgId]);return $stmt->fetchAll();
}

function security_step17_create_user(PDO $pdo,string $name,string $email,string $mobile,string $role,string $temporaryPassword): int
{
    $actor=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'security.users.manage',$actor))throw new RuntimeException('You do not have permission to create users.');$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=$ctx['club_id'];$name=trim($name);$email=strtolower(trim($email));$mobile=trim($mobile);
    if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Valid name and email are required.');if(!in_array($role,['admin','manager','staff','viewer'],true))throw new RuntimeException('Choose a valid role.');$hash=security_step17_password_hash($temporaryPassword);
    $pdo->beginTransaction();try{$stmt=$pdo->prepare("INSERT INTO system_users(full_name,email,mobile,password_hash,global_role,is_active,must_change_password,password_changed_at) VALUES(?,?,?,?,?,1,1,NOW())");$stmt->execute([$name,$email,$mobile?:null,$hash,$role]);$uid=(int)$pdo->lastInsertId();$scope=$role==='admin'?'organization':'club';$pdo->prepare("INSERT INTO organization_user_access(organization_id,club_id,user_id,role_code,permission_scope,is_active) VALUES(?,?,?,?,?,1)")->execute([$orgId,$clubId,$uid,$role,$scope]);$pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")->execute([$uid,$hash]);$pdo->commit();security_step17_audit($pdo,(int)$actor['id'],'security_user_created','system_user',$uid,['email'=>$email,'role'=>$role,'must_change_password'=>true]);return $uid;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function security_step17_update_user(PDO $pdo,int $userId,string $name,string $mobile,string $role,bool $active): void
{
    $actor=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'security.users.manage',$actor))throw new RuntimeException('You do not have permission to manage users.');$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];if(!in_array($role,['admin','manager','staff','viewer'],true))throw new RuntimeException('Choose a valid role.');$stmt=$pdo->prepare("SELECT u.*,a.role_code old_role FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? WHERE u.id=? LIMIT 1");$stmt->execute([$orgId,$userId]);$u=$stmt->fetch();if(!$u)throw new RuntimeException('User was not found.');
    $wasAdmin=(string)$u['old_role']==='admin'&&(int)$u['is_active']===1;if($wasAdmin&&(!$active||$role!=='admin')&&security_step17_admin_count($pdo,$orgId)<=1)throw new RuntimeException('The last active Administrator cannot be disabled or demoted.');
    if($userId===(int)$actor['id']&&!$active)throw new RuntimeException('You cannot disable your own signed-in account.');
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE system_users SET full_name=?,mobile=?,global_role=?,is_active=? WHERE id=?")->execute([trim($name)?:$u['full_name'],trim($mobile)?:null,$role,$active?1:0,$userId]);$scope=$role==='admin'?'organization':'club';$pdo->prepare("UPDATE organization_user_access SET role_code=?,permission_scope=?,is_active=? WHERE organization_id=? AND user_id=?")->execute([$role,$scope,$active?1:0,$orgId,$userId]);if(!$active||$role!==(string)$u['old_role'])$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='user_access_changed' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL")->execute([$orgId,$userId]);$pdo->commit();security_step17_audit($pdo,(int)$actor['id'],'security_user_updated','system_user',$userId,['old_role'=>$u['old_role'],'new_role'=>$role,'active'=>$active]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function security_step17_admin_reset_password(PDO $pdo,int $userId,string $temporaryPassword): void
{
    $actor=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'security.users.manage',$actor))throw new RuntimeException('You do not have permission to reset passwords.');$hash=security_step17_password_hash($temporaryPassword);$stmt=$pdo->prepare("SELECT id FROM system_users WHERE id=? LIMIT 1");$stmt->execute([$userId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('User was not found.');$pdo->beginTransaction();try{$pdo->prepare("UPDATE system_users SET password_hash=?,must_change_password=1,password_changed_at=NOW() WHERE id=?")->execute([$hash,$userId]);$pdo->prepare("INSERT INTO security_password_history(user_id,password_hash) VALUES(?,?)")->execute([$userId,$hash]);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='admin_password_reset' WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);$pdo->commit();security_step17_audit($pdo,(int)$actor['id'],'security_admin_password_reset','system_user',$userId,['force_change'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function security_step17_set_role_permission(PDO $pdo,string $role,string $permission,bool $allowed): void
{
    $actor=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'security.roles.manage',$actor))throw new RuntimeException('You do not have permission to change role permissions.');if($role==='admin')throw new RuntimeException('Administrator permissions are immutable full-access.');if(!in_array($role,['manager','staff','viewer'],true))throw new RuntimeException('Role is invalid.');$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("SELECT COUNT(*) FROM security_permissions WHERE permission_code=? AND is_active=1");$stmt->execute([$permission]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Permission is invalid.');$stmt=$pdo->prepare("INSERT INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed)");$stmt->execute([$orgId,$role,$permission,$allowed?1:0]);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='role_permissions_changed' WHERE organization_id=? AND role_code=? AND revoked_at IS NULL")->execute([$orgId,$role]);security_step17_audit($pdo,(int)$actor['id'],'security_role_permission_changed','security_role',null,['role'=>$role,'permission'=>$permission,'allowed'=>$allowed]);
}

function security_step17_set_user_override(PDO $pdo,int $userId,string $permission,?bool $allowed): void
{
    $actor=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'security.roles.manage',$actor))throw new RuntimeException('You do not have permission to change user overrides.');$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("SELECT COUNT(*) FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? WHERE u.id=?");$stmt->execute([$orgId,$userId]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('User was not found.');if($allowed===null)$pdo->prepare("DELETE FROM security_user_permissions WHERE organization_id=? AND user_id=? AND permission_code=?")->execute([$orgId,$userId,$permission]);else$pdo->prepare("INSERT INTO security_user_permissions(organization_id,user_id,permission_code,is_allowed) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed)")->execute([$orgId,$userId,$permission,$allowed?1:0]);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='user_permissions_changed' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL")->execute([$orgId,$userId]);security_step17_audit($pdo,(int)$actor['id'],'security_user_permission_override','system_user',$userId,['permission'=>$permission,'override'=>$allowed]);
}

function security_step17_revoke_session(PDO $pdo,int $sessionId,string $reason='admin_revoked'): void
{
    $actor=security_step17_current_user($pdo);$target=$pdo->prepare("SELECT * FROM security_sessions WHERE id=? LIMIT 1");$target->execute([$sessionId]);$s=$target->fetch();if(!$s)throw new RuntimeException('Session was not found.');$own=(int)$s['user_id']===(int)$actor['id'];if(!$own&&!security_step17_has_permission($pdo,'security.sessions.manage',$actor))throw new RuntimeException('You do not have permission to revoke this session.');$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,?) WHERE id=?")->execute([substr($reason,0,255),$sessionId]);security_step17_audit($pdo,(int)$actor['id'],'security_session_revoked','security_session',$sessionId,['target_user_id'=>(int)$s['user_id'],'reason'=>$reason]);
}
