<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';

const ROLE_SECURITY_ALERTS_VERSION = '1.1-account-risk-alerts';

function role_security_ensure(PDO $pdo): void
{
    role_portal_ensure($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_security_status (
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        lock_reason VARCHAR(255) NULL,
        locked_at DATETIME NULL,
        locked_by BIGINT UNSIGNED NULL,
        unlocked_at DATETIME NULL,
        unlocked_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (organization_id,user_id),
        CONSTRAINT fk_account_security_status_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_security_status_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_security_status_locked_by FOREIGN KEY (locked_by) REFERENCES system_users(id) ON DELETE SET NULL,
        CONSTRAINT fk_account_security_status_unlocked_by FOREIGN KEY (unlocked_by) REFERENCES system_users(id) ON DELETE SET NULL,
        KEY idx_account_security_locked (organization_id,is_locked,updated_at)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_security_alerts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        alert_type VARCHAR(60) NOT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'info',
        title VARCHAR(180) NOT NULL,
        details VARCHAR(800) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        acknowledged_at DATETIME NULL,
        acknowledged_by BIGINT UNSIGNED NULL,
        resolved_at DATETIME NULL,
        resolved_by BIGINT UNSIGNED NULL,
        CONSTRAINT fk_account_security_alert_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_security_alert_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_security_alert_ack FOREIGN KEY (acknowledged_by) REFERENCES system_users(id) ON DELETE SET NULL,
        CONSTRAINT fk_account_security_alert_resolve FOREIGN KEY (resolved_by) REFERENCES system_users(id) ON DELETE SET NULL,
        KEY idx_account_security_alert_status (organization_id,status,severity,created_at),
        KEY idx_account_security_alert_user (organization_id,user_id,created_at),
        KEY idx_account_security_alert_dedupe (organization_id,user_id,alert_type,status,created_at)
    ) ENGINE=InnoDB");

    if (business_table_exists($pdo,'schema_meta')) {
        $stmt=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('role_security_alerts_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([ROLE_SECURITY_ALERTS_VERSION]);
    }
}

function role_security_status(PDO $pdo, int $orgId, int $userId): array
{
    role_security_ensure($pdo);
    $stmt=$pdo->prepare("SELECT s.*,lb.full_name locked_by_name,ub.full_name unlocked_by_name
        FROM account_security_status s
        LEFT JOIN system_users lb ON lb.id=s.locked_by
        LEFT JOIN system_users ub ON ub.id=s.unlocked_by
        WHERE s.organization_id=? AND s.user_id=? LIMIT 1");
    $stmt->execute([$orgId,$userId]);
    $row=$stmt->fetch();
    return $row ?: ['organization_id'=>$orgId,'user_id'=>$userId,'is_locked'=>0,'lock_reason'=>null,'locked_at'=>null,'locked_by'=>null,'unlocked_at'=>null,'unlocked_by'=>null];
}

function role_security_create_alert(PDO $pdo, int $orgId, int $userId, string $type, string $severity, string $title, string $details, string $ip='', string $ua=''): void
{
    role_security_ensure($pdo);
    $severity=in_array($severity,['info','medium','high','critical'],true)?$severity:'info';
    $type=substr(trim($type),0,60);$title=substr(trim($title),0,180);$details=substr(trim($details),0,800);
    $ip=substr($ip,0,64);$ua=substr($ua,0,500);

    $stmt=$pdo->prepare("SELECT id FROM account_security_alerts
        WHERE organization_id=? AND user_id=? AND alert_type=? AND status='open'
          AND created_at>=DATE_SUB(NOW(),INTERVAL 6 HOUR)
          AND COALESCE(ip_address,'')=?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orgId,$userId,$type,$ip]);
    if((int)$stmt->fetchColumn()>0) return;

    $stmt=$pdo->prepare("INSERT INTO account_security_alerts(organization_id,user_id,alert_type,severity,title,details,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$userId,$type,$severity,$title,$details,$ip!==''?$ip:null,$ua!==''?$ua:null]);
    $alertId=(int)$pdo->lastInsertId();
    security_step17_audit($pdo,$userId,'security_alert_created','account_security_alert',$alertId,['type'=>$type,'severity'=>$severity]);
}

function role_security_detect_successful_login(PDO $pdo, array $user): void
{
    role_security_ensure($pdo);
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];$currentSid=(int)($user['session_row_id']??0);
    $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);

    $stmt=$pdo->prepare("SELECT COUNT(*) prior_count,
        SUM(CASE WHEN COALESCE(user_agent,'')=? THEN 1 ELSE 0 END) same_agent,
        SUM(CASE WHEN COALESCE(ip_address,'')=? THEN 1 ELSE 0 END) same_ip
        FROM security_sessions WHERE organization_id=? AND user_id=? AND id<>?");
    $stmt->execute([$ua,$ip,$orgId,$userId,$currentSid]);$history=$stmt->fetch()?:[];
    $prior=(int)($history['prior_count']??0);$sameAgent=(int)($history['same_agent']??0);$sameIp=(int)($history['same_ip']??0);

    if($prior>0 && $sameAgent===0) {
        role_security_create_alert($pdo,$orgId,$userId,'new_device','medium','New browser or device detected','A successful sign-in used a browser/device signature not seen in previous sessions. Review My Account if this was not you.',$ip,$ua);
    }
    if($prior>0 && $ip!=='' && $sameIp===0) {
        role_security_create_alert($pdo,$orgId,$userId,'new_ip','medium','New network address detected','A successful sign-in came from an IP address not seen in previous sessions. This can be normal after changing networks.',$ip,$ua);
    }

    $hash=security_step17_identifier_hash((string)$user['email']);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM security_login_attempts WHERE organization_id=? AND identifier_hash=? AND was_successful=0 AND attempted_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE)");
    $stmt->execute([$orgId,$hash]);$recentFailures=(int)$stmt->fetchColumn();
    if($recentFailures>=2) {
        $severity=$recentFailures>=4?'high':'medium';
        role_security_create_alert($pdo,$orgId,$userId,'failed_attempts_before_login',$severity,'Failed sign-in attempts detected before login',$recentFailures.' failed sign-in attempt(s) were recorded for this Login ID during the previous 30 minutes before a successful sign-in.',$ip,$ua);
    }

    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT COALESCE(ip_address,'')) FROM security_sessions WHERE organization_id=? AND user_id=? AND revoked_at IS NULL AND expires_at>NOW()");
    $stmt->execute([$orgId,$userId]);$activeIps=(int)$stmt->fetchColumn();
    if($activeIps>1) {
        role_security_create_alert($pdo,$orgId,$userId,'multiple_active_networks','medium','Account active on multiple networks','More than one active network address is currently associated with this account. Use My Account to sign out devices you do not recognize.',$ip,$ua);
    }
}

function role_security_login(PDO $pdo, string $email, string $password): array
{
    role_security_ensure($pdo);
    $result=security_step17_login($pdo,$email,$password);
    $user=security_step17_session_user($pdo,false);
    if(!$user) throw new RuntimeException('Secure session could not be verified.');
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $status=role_security_status($pdo,$orgId,(int)$user['id']);
    if((int)($status['is_locked']??0)===1) {
        security_step17_audit($pdo,(int)$user['id'],'security_locked_account_login_blocked','system_user',(int)$user['id'],[]);
        security_step17_logout($pdo,'account_locked_by_administrator');
        throw new RuntimeException('Sign-in is unavailable for this account. Contact the Administrator.');
    }
    role_security_detect_successful_login($pdo,$user);
    return $result;
}

function role_security_user_alerts(PDO $pdo, int $orgId, int $userId, int $limit=50): array
{
    role_security_ensure($pdo);$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT id,alert_type,severity,title,details,ip_address,user_agent,status,created_at,acknowledged_at,resolved_at FROM account_security_alerts WHERE organization_id=? AND user_id=? ORDER BY CASE WHEN status='open' THEN 0 WHEN status='acknowledged' THEN 1 ELSE 2 END,created_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$orgId,$userId]);return $stmt->fetchAll();
}

function role_security_open_alert_count(PDO $pdo, int $orgId, ?int $userId=null): int
{
    role_security_ensure($pdo);
    if($userId!==null){$stmt=$pdo->prepare("SELECT COUNT(*) FROM account_security_alerts WHERE organization_id=? AND user_id=? AND status='open'");$stmt->execute([$orgId,$userId]);}
    else{$stmt=$pdo->prepare("SELECT COUNT(*) FROM account_security_alerts WHERE organization_id=? AND status='open'");$stmt->execute([$orgId]);}
    return (int)$stmt->fetchColumn();
}

function role_security_acknowledge_own_alert(PDO $pdo, int $alertId): void
{
    role_security_ensure($pdo);$user=security_step17_current_user($pdo);$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("UPDATE account_security_alerts SET status='acknowledged',acknowledged_at=NOW(),acknowledged_by=? WHERE id=? AND organization_id=? AND user_id=? AND status='open'");
    $stmt->execute([(int)$user['id'],$alertId,$orgId,(int)$user['id']]);
    if($stmt->rowCount()!==1) throw new RuntimeException('Security alert is already closed or does not belong to this account.');
    security_step17_audit($pdo,(int)$user['id'],'security_alert_acknowledged','account_security_alert',$alertId,[]);
}

function role_security_account_rows(PDO $pdo, int $orgId): array
{
    role_security_ensure($pdo);
    $stmt=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile,u.is_active,u.last_login_at,a.role_code,
        COALESCE(s.is_locked,0) is_locked,s.lock_reason,s.locked_at,s.unlocked_at,
        SUM(CASE WHEN al.status='open' THEN 1 ELSE 0 END) open_alerts,
        SUM(CASE WHEN al.status='open' AND al.severity IN ('high','critical') THEN 1 ELSE 0 END) high_alerts
        FROM system_users u
        JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
        LEFT JOIN account_security_status s ON s.organization_id=a.organization_id AND s.user_id=u.id
        LEFT JOIN account_security_alerts al ON al.organization_id=a.organization_id AND al.user_id=u.id
        GROUP BY u.id,u.full_name,u.email,u.mobile,u.is_active,u.last_login_at,a.role_code,s.is_locked,s.lock_reason,s.locked_at,s.unlocked_at
        ORDER BY COALESCE(s.is_locked,0) DESC,high_alerts DESC,open_alerts DESC,u.full_name");
    $stmt->execute([$orgId]);return $stmt->fetchAll();
}

function role_security_admin_alerts(PDO $pdo, int $orgId, int $limit=150): array
{
    role_security_ensure($pdo);$limit=max(1,min(300,$limit));
    $stmt=$pdo->prepare("SELECT al.*,u.full_name,u.email,a.role_code,ab.full_name acknowledged_by_name,rb.full_name resolved_by_name
        FROM account_security_alerts al
        JOIN system_users u ON u.id=al.user_id
        LEFT JOIN organization_user_access a ON a.organization_id=al.organization_id AND a.user_id=al.user_id
        LEFT JOIN system_users ab ON ab.id=al.acknowledged_by
        LEFT JOIN system_users rb ON rb.id=al.resolved_by
        WHERE al.organization_id=?
        ORDER BY CASE al.status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END,
                 CASE al.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
                 al.created_at DESC,al.id DESC LIMIT {$limit}");
    $stmt->execute([$orgId]);return $stmt->fetchAll();
}

function role_security_set_lock(PDO $pdo, int $userId, bool $locked, string $reason=''): void
{
    role_security_ensure($pdo);$actor=security_step17_current_user($pdo);
    if((string)($actor['role_code']??'')!=='admin' || !security_step17_has_permission($pdo,'security.users.manage',$actor)) throw new RuntimeException('Administrator permission is required to lock or unlock accounts.');
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    if($userId===(int)$actor['id']) throw new RuntimeException('You cannot lock or unlock your own currently signed-in Administrator account here.');
    $stmt=$pdo->prepare("SELECT u.id,u.full_name,u.is_active,a.role_code FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? WHERE u.id=? LIMIT 1");
    $stmt->execute([$orgId,$userId]);$target=$stmt->fetch();if(!$target)throw new RuntimeException('Account was not found.');
    if($locked && (string)$target['role_code']==='admin' && (int)$target['is_active']===1 && security_step17_admin_count($pdo,$orgId)<=1) throw new RuntimeException('The last active Administrator cannot be locked.');
    $reason=trim($reason);
    if($locked && strlen($reason)<5) throw new RuntimeException('Enter a short security reason before locking the account.');

    if($locked){
        $stmt=$pdo->prepare("INSERT INTO account_security_status(organization_id,user_id,is_locked,lock_reason,locked_at,locked_by,unlocked_at,unlocked_by) VALUES(?,?,1,?,NOW(),?,NULL,NULL) ON DUPLICATE KEY UPDATE is_locked=1,lock_reason=VALUES(lock_reason),locked_at=NOW(),locked_by=VALUES(locked_by),unlocked_at=NULL,unlocked_by=NULL");
        $stmt->execute([$orgId,$userId,substr($reason,0,255),(int)$actor['id']]);
        $pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='account_locked_by_administrator' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL")->execute([$orgId,$userId]);
        role_security_create_alert($pdo,$orgId,$userId,'account_locked','high','Account locked by Administrator','Administrator locked this account. Reason: '.substr($reason,0,220),'','');
        security_step17_audit($pdo,(int)$actor['id'],'security_account_locked','system_user',$userId,['reason'=>$reason,'sessions_revoked'=>true]);
    }else{
        $stmt=$pdo->prepare("INSERT INTO account_security_status(organization_id,user_id,is_locked,lock_reason,unlocked_at,unlocked_by) VALUES(?,?,0,NULL,NOW(),?) ON DUPLICATE KEY UPDATE is_locked=0,lock_reason=NULL,unlocked_at=NOW(),unlocked_by=VALUES(unlocked_by)");
        $stmt->execute([$orgId,$userId,(int)$actor['id']]);
        security_step17_audit($pdo,(int)$actor['id'],'security_account_unlocked','system_user',$userId,[]);
    }
}

function role_security_admin_resolve_alert(PDO $pdo, int $alertId): void
{
    role_security_ensure($pdo);$actor=security_step17_current_user($pdo);
    if((string)($actor['role_code']??'')!=='admin' || !security_step17_has_permission($pdo,'security.audit.view',$actor)) throw new RuntimeException('Administrator permission is required to resolve security alerts.');
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("UPDATE account_security_alerts SET status='resolved',resolved_at=NOW(),resolved_by=? WHERE id=? AND organization_id=? AND status<>'resolved'");
    $stmt->execute([(int)$actor['id'],$alertId,$orgId]);
    if($stmt->rowCount()!==1) throw new RuntimeException('Security alert is already resolved or was not found.');
    security_step17_audit($pdo,(int)$actor['id'],'security_alert_resolved','account_security_alert',$alertId,[]);
}
