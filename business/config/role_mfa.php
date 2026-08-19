<?php
declare(strict_types=1);

require_once __DIR__ . '/role_trusted_devices.php';

const ROLE_MFA_VERSION = '1.0-totp-recovery';
const ROLE_MFA_ISSUER = 'Healthcare Wellness Club';
const ROLE_MFA_PERIOD = 30;
const ROLE_MFA_DIGITS = 6;
const ROLE_MFA_PENDING_SECONDS = 300;
const ROLE_MFA_MAX_ATTEMPTS = 5;

function role_mfa_is_local(): bool
{
    $host=strtolower((string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??''));
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    return $ip===''||$ip==='127.0.0.1'||$ip==='::1'||str_starts_with($host,'localhost')||str_starts_with($host,'127.0.0.1');
}

function role_mfa_master_key(): string
{
    $configured=trim((string)(getenv('HWC_MFA_KEY')?:''));
    if($configured===''){
        if(!role_mfa_is_local()) throw new RuntimeException('HWC_MFA_KEY must be configured before authenticator verification can be used in production.');
        $configured='HWC-LOCAL-ONLY-MFA-KEY|'.(string)(getenv('DB_NAME')?:'healthcare_wellness_club');
    }
    if(strlen($configured)<24 && !role_mfa_is_local()) throw new RuntimeException('HWC_MFA_KEY is too short for production. Use a long random server-only value.');
    return hash('sha256',$configured,true);
}

function role_mfa_ensure(PDO $pdo): void
{
    role_trusted_ensure($pdo);
    if(!extension_loaded('openssl')) throw new RuntimeException('OpenSSL PHP extension is required for authenticator verification.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS security_mfa_accounts (
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        secret_ciphertext TEXT NULL,
        secret_iv VARCHAR(64) NULL,
        secret_tag VARCHAR(64) NULL,
        enabled_at DATETIME NULL,
        disabled_at DATETIME NULL,
        last_verified_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (organization_id,user_id),
        CONSTRAINT fk_mfa_account_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_mfa_account_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        KEY idx_mfa_enabled (organization_id,is_enabled,updated_at)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS security_mfa_recovery_codes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        code_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        used_ip VARCHAR(64) NULL,
        CONSTRAINT fk_mfa_recovery_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_mfa_recovery_hash (organization_id,user_id,code_hash),
        KEY idx_mfa_recovery_available (organization_id,user_id,used_at)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS security_mfa_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        security_session_id BIGINT UNSIGNED NULL,
        method VARCHAR(30) NOT NULL,
        was_successful TINYINT(1) NOT NULL DEFAULT 0,
        ip_address VARCHAR(64) NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_mfa_attempt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_mfa_attempt_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        KEY idx_mfa_attempt_user (organization_id,user_id,attempted_at),
        KEY idx_mfa_attempt_session (organization_id,security_session_id,attempted_at)
    ) ENGINE=InnoDB");

    if(business_table_exists($pdo,'schema_meta')){
        $stmt=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('role_mfa_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([ROLE_MFA_VERSION]);
    }
}

function role_mfa_base32_encode(string $bytes): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$out='';
    foreach(str_split($bytes) as $ch)$bits.=str_pad(decbin(ord($ch)),8,'0',STR_PAD_LEFT);
    for($i=0,$len=strlen($bits);$i<$len;$i+=5){$chunk=substr($bits,$i,5);if(strlen($chunk)<5)$chunk=str_pad($chunk,5,'0',STR_PAD_RIGHT);$out.=$alphabet[bindec($chunk)];}
    return $out;
}

function role_mfa_base32_decode(string $value): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$value=strtoupper(preg_replace('/[^A-Z2-7]/i','',$value)??'');$bits='';$out='';
    if($value==='')return '';
    foreach(str_split($value) as $ch){$p=strpos($alphabet,$ch);if($p===false)return '';$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);}
    for($i=0,$len=strlen($bits);$i+8<=$len;$i+=8)$out.=chr(bindec(substr($bits,$i,8)));
    return $out;
}

function role_mfa_generate_secret(): string
{
    return role_mfa_base32_encode(random_bytes(20));
}

function role_mfa_counter_bytes(int $counter): string
{
    $high=intdiv($counter,4294967296);$low=$counter%4294967296;
    return pack('N2',$high,$low);
}

function role_mfa_code_at(string $secret,int $time): string
{
    $key=role_mfa_base32_decode($secret);if($key==='')return '';
    $counter=intdiv($time,ROLE_MFA_PERIOD);$hmac=hash_hmac('sha1',role_mfa_counter_bytes($counter),$key,true);$offset=ord($hmac[19])&0x0f;
    $bin=((ord($hmac[$offset])&0x7f)<<24)|((ord($hmac[$offset+1])&0xff)<<16)|((ord($hmac[$offset+2])&0xff)<<8)|(ord($hmac[$offset+3])&0xff);
    return str_pad((string)($bin%(10**ROLE_MFA_DIGITS)),ROLE_MFA_DIGITS,'0',STR_PAD_LEFT);
}

function role_mfa_verify_totp(string $secret,string $code): bool
{
    $code=preg_replace('/\D+/','',$code)??'';if(strlen($code)!==ROLE_MFA_DIGITS)return false;$now=time();
    foreach([-1,0,1] as $window){$expected=role_mfa_code_at($secret,$now+($window*ROLE_MFA_PERIOD));if($expected!==''&&hash_equals($expected,$code))return true;}
    return false;
}

function role_mfa_encrypt_secret(string $secret): array
{
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',role_mfa_master_key(),OPENSSL_RAW_DATA,$iv,$tag,'HWC-MFA-v1');
    if($cipher===false||$tag==='')throw new RuntimeException('Authenticator secret could not be protected.');
    return ['cipher'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
}

function role_mfa_decrypt_secret(array $row): string
{
    $cipher=base64_decode((string)($row['secret_ciphertext']??''),true);$iv=base64_decode((string)($row['secret_iv']??''),true);$tag=base64_decode((string)($row['secret_tag']??''),true);
    if($cipher===false||$iv===false||$tag===false)return '';
    $plain=openssl_decrypt($cipher,'aes-256-gcm',role_mfa_master_key(),OPENSSL_RAW_DATA,$iv,$tag,'HWC-MFA-v1');
    return $plain===false?'':$plain;
}

function role_mfa_account(PDO $pdo,int $orgId,int $userId): ?array
{
    role_mfa_ensure($pdo);$stmt=$pdo->prepare('SELECT * FROM security_mfa_accounts WHERE organization_id=? AND user_id=? LIMIT 1');$stmt->execute([$orgId,$userId]);$row=$stmt->fetch();return $row?:null;
}

function role_mfa_enabled(PDO $pdo,int $orgId,int $userId): bool
{
    $row=role_mfa_account($pdo,$orgId,$userId);return $row!==null&&(int)$row['is_enabled']===1;
}

function role_mfa_recovery_hash(string $code): string
{
    $normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',$code)??'');return hash_hmac('sha256',$normalized,role_mfa_master_key());
}

function role_mfa_generate_recovery_codes(int $count=8): array
{
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$codes=[];
    for($n=0;$n<$count;$n++){$raw='';for($i=0;$i<10;$i++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)];$codes[]=substr($raw,0,5).'-'.substr($raw,5);}
    return $codes;
}

function role_mfa_store_recovery_codes(PDO $pdo,int $orgId,int $userId,array $codes): void
{
    $pdo->prepare('DELETE FROM security_mfa_recovery_codes WHERE organization_id=? AND user_id=?')->execute([$orgId,$userId]);
    $stmt=$pdo->prepare('INSERT INTO security_mfa_recovery_codes(organization_id,user_id,code_hash) VALUES(?,?,?)');
    foreach($codes as $code)$stmt->execute([$orgId,$userId,role_mfa_recovery_hash((string)$code)]);
}

function role_mfa_recovery_remaining(PDO $pdo,int $orgId,int $userId): int
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM security_mfa_recovery_codes WHERE organization_id=? AND user_id=? AND used_at IS NULL');$stmt->execute([$orgId,$userId]);return (int)$stmt->fetchColumn();
}

function role_mfa_verify_password(PDO $pdo,int $userId,string $password): void
{
    $stmt=$pdo->prepare('SELECT password_hash FROM system_users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$userId]);$hash=$stmt->fetchColumn();
    if(!$hash||!password_verify($password,(string)$hash))throw new RuntimeException('Current password is incorrect.');
}

function role_mfa_otpauth_uri(string $email,string $secret): string
{
    $label=rawurlencode(ROLE_MFA_ISSUER.':'.strtolower(trim($email)));
    return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret).'&issuer='.rawurlencode(ROLE_MFA_ISSUER).'&algorithm=SHA1&digits='.ROLE_MFA_DIGITS.'&period='.ROLE_MFA_PERIOD;
}

function role_mfa_enable(PDO $pdo,array $user,string $currentPassword,string $secret,string $code): array
{
    role_mfa_ensure($pdo);$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];
    role_mfa_verify_password($pdo,$userId,$currentPassword);if(!role_mfa_verify_totp($secret,$code))throw new RuntimeException('Authenticator code is incorrect. Check your device time and try again.');
    $enc=role_mfa_encrypt_secret($secret);$codes=role_mfa_generate_recovery_codes();
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO security_mfa_accounts(organization_id,user_id,is_enabled,secret_ciphertext,secret_iv,secret_tag,enabled_at,disabled_at,last_verified_at) VALUES(?,?,1,?,?,?,NOW(),NULL,NOW()) ON DUPLICATE KEY UPDATE is_enabled=1,secret_ciphertext=VALUES(secret_ciphertext),secret_iv=VALUES(secret_iv),secret_tag=VALUES(secret_tag),enabled_at=NOW(),disabled_at=NULL,last_verified_at=NOW()");
        $stmt->execute([$orgId,$userId,$enc['cipher'],$enc['iv'],$enc['tag']]);role_mfa_store_recovery_codes($pdo,$orgId,$userId,$codes);
        $currentSid=(int)($user['session_row_id']??0);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='mfa_enabled' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL AND id<>?")->execute([$orgId,$userId,$currentSid]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    security_step17_audit($pdo,$userId,'security_mfa_enabled','system_user',$userId,['recovery_codes'=>count($codes),'other_sessions_revoked'=>true]);
    return $codes;
}

function role_mfa_verify_account_code(PDO $pdo,int $orgId,int $userId,string $code,bool $consumeRecovery=true): array
{
    $account=role_mfa_account($pdo,$orgId,$userId);if(!$account||(int)$account['is_enabled']!==1)return ['ok'=>false,'method'=>'none'];
    $secret=role_mfa_decrypt_secret($account);if($secret!==''&&role_mfa_verify_totp($secret,$code))return ['ok'=>true,'method'=>'totp'];
    $hash=role_mfa_recovery_hash($code);$stmt=$pdo->prepare('SELECT id FROM security_mfa_recovery_codes WHERE organization_id=? AND user_id=? AND code_hash=? AND used_at IS NULL LIMIT 1');$stmt->execute([$orgId,$userId,$hash]);$id=(int)$stmt->fetchColumn();
    if($id<=0)return ['ok'=>false,'method'=>'none'];
    if($consumeRecovery){$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$stmt=$pdo->prepare('UPDATE security_mfa_recovery_codes SET used_at=NOW(),used_ip=? WHERE id=? AND used_at IS NULL');$stmt->execute([$ip!==''?$ip:null,$id]);if($stmt->rowCount()!==1)return ['ok'=>false,'method'=>'none'];}
    return ['ok'=>true,'method'=>'recovery'];
}

function role_mfa_regenerate_recovery(PDO $pdo,array $user,string $currentPassword,string $code): array
{
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];role_mfa_verify_password($pdo,$userId,$currentPassword);$verified=role_mfa_verify_account_code($pdo,$orgId,$userId,$code,true);if(!$verified['ok'])throw new RuntimeException('Authenticator or recovery code is incorrect.');
    $codes=role_mfa_generate_recovery_codes();$pdo->beginTransaction();try{role_mfa_store_recovery_codes($pdo,$orgId,$userId,$codes);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    security_step17_audit($pdo,$userId,'security_mfa_recovery_regenerated','system_user',$userId,['count'=>count($codes),'verified_by'=>$verified['method']]);return $codes;
}

function role_mfa_disable(PDO $pdo,array $user,string $currentPassword,string $code): void
{
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];role_mfa_verify_password($pdo,$userId,$currentPassword);$verified=role_mfa_verify_account_code($pdo,$orgId,$userId,$code,true);if(!$verified['ok'])throw new RuntimeException('Authenticator or recovery code is incorrect.');
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE security_mfa_accounts SET is_enabled=0,secret_ciphertext=NULL,secret_iv=NULL,secret_tag=NULL,disabled_at=NOW() WHERE organization_id=? AND user_id=?")->execute([$orgId,$userId]);$pdo->prepare('DELETE FROM security_mfa_recovery_codes WHERE organization_id=? AND user_id=?')->execute([$orgId,$userId]);$currentSid=(int)($user['session_row_id']??0);$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='mfa_disabled' WHERE organization_id=? AND user_id=? AND revoked_at IS NULL AND id<>?")->execute([$orgId,$userId,$currentSid]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    security_step17_audit($pdo,$userId,'security_mfa_disabled','system_user',$userId,['verified_by'=>$verified['method'],'other_sessions_revoked'=>true]);
}

function role_mfa_clear_pending(): void
{
    foreach(['hwc_mfa_pending','hwc_mfa_user_id','hwc_mfa_session_id','hwc_mfa_role','hwc_mfa_started_at','hwc_mfa_next'] as $key)unset($_SESSION[$key]);
}

function role_mfa_pending_exists(): bool
{
    security_step17_session_start();return !empty($_SESSION['hwc_mfa_pending'])&&(int)($_SESSION['hwc_mfa_user_id']??0)>0&&(int)($_SESSION['hwc_mfa_session_id']??0)>0;
}

function role_mfa_login(PDO $pdo,string $email,string $password): array
{
    role_mfa_ensure($pdo);$result=role_trusted_login($pdo,$email,$password);$user=security_step17_session_user($pdo,false);if(!$user)return $result+['mfa_required'=>false];
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];if(!role_mfa_enabled($pdo,$orgId,$userId))return $result+['mfa_required'=>false];
    $sid=(int)$user['session_row_id'];$_SESSION['hwc_mfa_pending']=1;$_SESSION['hwc_mfa_user_id']=$userId;$_SESSION['hwc_mfa_session_id']=$sid;$_SESSION['hwc_mfa_role']=(string)$user['role_code'];$_SESSION['hwc_mfa_started_at']=time();$_SESSION['hwc_mfa_next']=role_portal_home($user);
    foreach(['hwc_user_id','hwc_org_id','hwc_role','hwc_security_session_id','hwc_login_at','hwc_last_activity','hwc_last_touch','hwc_idle_timeout'] as $key)unset($_SESSION[$key]);
    security_step17_audit($pdo,$userId,'security_mfa_challenge_started','security_session',$sid,['role'=>$user['role_code']]);return $result+['mfa_required'=>true];
}

function role_mfa_pending_user(PDO $pdo): ?array
{
    role_mfa_ensure($pdo);security_step17_session_start();if(!role_mfa_pending_exists())return null;$userId=(int)$_SESSION['hwc_mfa_user_id'];$sid=(int)$_SESSION['hwc_mfa_session_id'];$started=(int)($_SESSION['hwc_mfa_started_at']??0);
    if($started<=0||time()-$started>ROLE_MFA_PENDING_SECONDS){$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,'mfa_challenge_expired') WHERE id=? AND user_id=?")->execute([$sid,$userId]);role_mfa_clear_pending();return null;}
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile,u.is_active,u.must_change_password,u.last_login_at,s.id session_row_id,s.role_code,s.club_id,s.created_at session_created_at,s.last_seen_at,s.expires_at,s.revoked_at,s.user_agent,a.permission_scope FROM security_sessions s JOIN system_users u ON u.id=s.user_id JOIN organization_user_access a ON a.organization_id=s.organization_id AND a.user_id=s.user_id AND a.role_code=s.role_code AND a.is_active=1 WHERE s.id=? AND s.organization_id=? AND s.user_id=? LIMIT 1");$stmt->execute([$sid,$orgId,$userId]);$user=$stmt->fetch();
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);if(!$user||(int)$user['is_active']!==1||$user['revoked_at']!==null||strtotime((string)$user['expires_at'])<=time()||((string)$user['user_agent']!==''&&!hash_equals((string)$user['user_agent'],$ua))){role_mfa_clear_pending();return null;}
    $status=role_security_status($pdo,$orgId,$userId);if((int)($status['is_locked']??0)===1){$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,'account_locked_during_mfa') WHERE id=?")->execute([$sid]);role_mfa_clear_pending();return null;}
    return $user;
}

function role_mfa_record_attempt(PDO $pdo,int $orgId,int $userId,int $sid,string $method,bool $success): void
{
    $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$stmt=$pdo->prepare('INSERT INTO security_mfa_attempts(organization_id,user_id,security_session_id,method,was_successful,ip_address) VALUES(?,?,?,?,?,?)');$stmt->execute([$orgId,$userId,$sid,substr($method,0,30),$success?1:0,$ip!==''?$ip:null]);
}

function role_mfa_complete_pending(PDO $pdo,string $code): array
{
    $user=role_mfa_pending_user($pdo);if(!$user)throw new RuntimeException('Authenticator challenge expired. Sign in again.');$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];$sid=(int)$user['session_row_id'];
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM security_mfa_attempts WHERE organization_id=? AND user_id=? AND security_session_id=? AND was_successful=0 AND attempted_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)");$stmt->execute([$orgId,$userId,$sid]);$failures=(int)$stmt->fetchColumn();
    if($failures>=ROLE_MFA_MAX_ATTEMPTS){$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,'mfa_attempt_limit') WHERE id=?")->execute([$sid]);role_mfa_clear_pending();throw new RuntimeException('Too many verification attempts. Sign in again.');}
    $verified=role_mfa_verify_account_code($pdo,$orgId,$userId,$code,true);role_mfa_record_attempt($pdo,$orgId,$userId,$sid,(string)$verified['method'],(bool)$verified['ok']);
    if(!$verified['ok']){security_step17_audit($pdo,$userId,'security_mfa_failed','security_session',$sid,['attempt'=>$failures+1]);if($failures+1>=ROLE_MFA_MAX_ATTEMPTS){$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='mfa_attempt_limit' WHERE id=?")->execute([$sid]);role_mfa_clear_pending();throw new RuntimeException('Too many verification attempts. Sign in again.');}throw new RuntimeException('Authenticator or recovery code is incorrect.');}
    $_SESSION['hwc_user_id']=$userId;$_SESSION['hwc_org_id']=$orgId;$_SESSION['hwc_role']=(string)$user['role_code'];$_SESSION['hwc_security_session_id']=$sid;$_SESSION['hwc_login_at']=time();$_SESSION['hwc_last_activity']=time();$_SESSION['hwc_idle_timeout']=security_step17_setting($pdo,$orgId,'idle_timeout_seconds',1800);$_SESSION['hwc_last_touch']=time();
    role_mfa_clear_pending();$pdo->prepare('UPDATE security_mfa_accounts SET last_verified_at=NOW() WHERE organization_id=? AND user_id=? AND is_enabled=1')->execute([$orgId,$userId]);$pdo->prepare('UPDATE security_sessions SET last_seen_at=NOW() WHERE id=?')->execute([$sid]);security_step17_audit($pdo,$userId,'security_mfa_success','security_session',$sid,['method'=>$verified['method']]);
    return $user;
}

function role_mfa_logout(PDO $pdo,string $reason='portal_user_logout'): void
{
    security_step17_session_start();if(role_mfa_pending_exists()){$sid=(int)($_SESSION['hwc_mfa_session_id']??0);$uid=(int)($_SESSION['hwc_mfa_user_id']??0);if($sid>0)$pdo->prepare("UPDATE security_sessions SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,?) WHERE id=?")->execute([$reason,$sid]);if($uid>0)security_step17_audit($pdo,$uid,'security_mfa_cancelled','security_session',$sid,['reason'=>$reason]);security_step17_destroy_php_session();return;}security_step17_logout($pdo,$reason);
}
