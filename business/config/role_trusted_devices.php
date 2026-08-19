<?php
declare(strict_types=1);

require_once __DIR__ . '/role_security_alerts.php';

const ROLE_TRUSTED_DEVICES_VERSION = '1.0-secure-device-recognition';
const ROLE_TRUSTED_DEVICE_DAYS = 90;

function role_trusted_ensure(PDO $pdo): void
{
    role_security_ensure($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_trusted_devices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        device_label VARCHAR(120) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        user_agent_hash CHAR(64) NOT NULL,
        user_agent VARCHAR(500) NULL,
        created_ip VARCHAR(64) NULL,
        last_ip VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        revoked_by BIGINT UNSIGNED NULL,
        revoke_reason VARCHAR(120) NULL,
        CONSTRAINT fk_trusted_device_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_trusted_device_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_trusted_device_revoker FOREIGN KEY (revoked_by) REFERENCES system_users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_trusted_device_token (token_hash),
        KEY idx_trusted_device_user (organization_id,user_id,revoked_at,expires_at,last_used_at)
    ) ENGINE=InnoDB");
    $pdo->exec("UPDATE security_trusted_devices SET revoked_at=COALESCE(revoked_at,NOW()),revoke_reason=COALESCE(revoke_reason,'expired') WHERE revoked_at IS NULL AND expires_at<=NOW()");
    if (business_table_exists($pdo,'schema_meta')) {
        $stmt=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('role_trusted_devices_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute([ROLE_TRUSTED_DEVICES_VERSION]);
    }
}

function role_trusted_cookie_name(int $userId): string
{
    return 'hwc_trusted_device_'.$userId;
}

function role_trusted_cookie_path(): string
{
    $script=(string)($_SERVER['SCRIPT_NAME']??'/');
    $dir=str_replace('\\','/',dirname($script));
    if($dir==='.'||$dir==='\\'||$dir==='')return '/';
    if(str_ends_with($dir,'/business'))$dir=substr($dir,0,-9);
    return $dir===''?'/':$dir;
}

function role_trusted_is_https(): bool
{
    if(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
}

function role_trusted_set_cookie(int $userId, string $value, int $expires): void
{
    setcookie(role_trusted_cookie_name($userId),$value,[
        'expires'=>$expires,
        'path'=>role_trusted_cookie_path(),
        'secure'=>role_trusted_is_https(),
        'httponly'=>true,
        'samesite'=>'Strict',
    ]);
    $_COOKIE[role_trusted_cookie_name($userId)]=$value;
}

function role_trusted_clear_cookie(int $userId): void
{
    setcookie(role_trusted_cookie_name($userId),'',[
        'expires'=>time()-3600,
        'path'=>role_trusted_cookie_path(),
        'secure'=>role_trusted_is_https(),
        'httponly'=>true,
        'samesite'=>'Strict',
    ]);
    unset($_COOKIE[role_trusted_cookie_name($userId)]);
}

function role_trusted_ua_hash(string $ua): string
{
    $normalized=strtolower(trim($ua));
    $normalized=(string)preg_replace('/\d+(?:\.\d+)*/','*',$normalized);
    $normalized=(string)preg_replace('/\s+/',' ',$normalized);
    return hash('sha256',$normalized);
}

function role_trusted_default_label(string $ua): string
{
    $platform='Device';$browser='Browser';
    if(stripos($ua,'Android')!==false)$platform='Android';
    elseif(stripos($ua,'iPhone')!==false)$platform='iPhone';
    elseif(stripos($ua,'iPad')!==false)$platform='iPad';
    elseif(stripos($ua,'Windows')!==false)$platform='Windows PC';
    elseif(stripos($ua,'Macintosh')!==false||stripos($ua,'Mac OS')!==false)$platform='Mac';
    elseif(stripos($ua,'Linux')!==false)$platform='Linux';
    if(stripos($ua,'Edg/')!==false)$browser='Microsoft Edge';
    elseif(stripos($ua,'Chrome/')!==false)$browser='Chrome';
    elseif(stripos($ua,'Firefox/')!==false)$browser='Firefox';
    elseif(stripos($ua,'Safari/')!==false)$browser='Safari';
    return $platform.' • '.$browser;
}

function role_trusted_cookie_parts(int $userId): ?array
{
    $value=(string)($_COOKIE[role_trusted_cookie_name($userId)]??'');
    if($value===''||!str_contains($value,'.'))return null;
    [$id,$token]=explode('.',$value,2);
    if(!ctype_digit($id)||strlen($token)<30||strlen($token)>120)return null;
    return ['id'=>(int)$id,'token'=>$token];
}

function role_trusted_current(PDO $pdo, int $orgId, int $userId, bool $touch=true): ?array
{
    role_trusted_ensure($pdo);
    $parts=role_trusted_cookie_parts($userId);
    if(!$parts)return null;
    $tokenHash=hash('sha256',(string)$parts['token']);
    $stmt=$pdo->prepare("SELECT * FROM security_trusted_devices WHERE id=? AND organization_id=? AND user_id=? AND token_hash=? LIMIT 1");
    $stmt->execute([(int)$parts['id'],$orgId,$userId,$tokenHash]);$row=$stmt->fetch();
    if(!$row||$row['revoked_at']!==null||strtotime((string)$row['expires_at'])<=time()){
        role_trusted_clear_cookie($userId);return null;
    }
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
    if(!hash_equals((string)$row['user_agent_hash'],role_trusted_ua_hash($ua))){
        role_trusted_clear_cookie($userId);return null;
    }
    if($touch){
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
        $pdo->prepare("UPDATE security_trusted_devices SET last_used_at=NOW(),last_ip=? WHERE id=?")->execute([$ip!==''?$ip:null,(int)$row['id']]);
        $row['last_used_at']=date('Y-m-d H:i:s');$row['last_ip']=$ip!==''?$ip:null;
    }
    return $row;
}

function role_trusted_active_count(PDO $pdo, int $orgId, int $userId): int
{
    role_trusted_ensure($pdo);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM security_trusted_devices WHERE organization_id=? AND user_id=? AND revoked_at IS NULL AND expires_at>NOW()");
    $stmt->execute([$orgId,$userId]);return (int)$stmt->fetchColumn();
}

function role_trusted_login(PDO $pdo, string $email, string $password): array
{
    role_trusted_ensure($pdo);
    $result=role_security_login($pdo,$email,$password);
    $user=security_step17_session_user($pdo,false);
    if(!$user)return $result;
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];
    $trusted=role_trusted_current($pdo,$orgId,$userId,true);
    if($trusted){
        security_step17_audit($pdo,$userId,'security_trusted_device_login','security_trusted_device',(int)$trusted['id'],['device_label'=>$trusted['device_label']]);
    }elseif(role_trusted_active_count($pdo,$orgId,$userId)>0){
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
        role_security_create_alert($pdo,$orgId,$userId,'untrusted_device_login','medium','Sign-in from an untrusted device','A successful sign-in did not present a valid trusted-device token. Review the device and trust it only if it is yours.',$ip,$ua);
    }
    return $result;
}

function role_trusted_create_current(PDO $pdo, array $user, string $currentPassword, string $label): int
{
    role_trusted_ensure($pdo);
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];
    $stmt=$pdo->prepare("SELECT password_hash FROM system_users WHERE id=? AND is_active=1 LIMIT 1");$stmt->execute([$userId]);$hash=$stmt->fetchColumn();
    if(!$hash||!password_verify($currentPassword,(string)$hash))throw new RuntimeException('Current password is incorrect.');
    if(role_trusted_current($pdo,$orgId,$userId,false))throw new RuntimeException('This browser is already a trusted device.');
    if(role_trusted_active_count($pdo,$orgId,$userId)>=12)throw new RuntimeException('Trusted-device limit reached. Revoke an old device before adding another.');
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
    $label=trim($label);if($label==='')$label=role_trusted_default_label($ua);if(strlen($label)<2||strlen($label)>120)throw new RuntimeException('Device name must be between 2 and 120 characters.');
    $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$tokenHash=hash('sha256',$token);
    $expires=(new DateTimeImmutable())->modify('+'.ROLE_TRUSTED_DEVICE_DAYS.' days')->format('Y-m-d H:i:s');
    $stmt=$pdo->prepare("INSERT INTO security_trusted_devices(organization_id,user_id,device_label,token_hash,user_agent_hash,user_agent,created_ip,last_ip,expires_at) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$userId,$label,$tokenHash,role_trusted_ua_hash($ua),$ua!==''?$ua:null,$ip!==''?$ip:null,$ip!==''?$ip:null,$expires]);$id=(int)$pdo->lastInsertId();
    role_trusted_set_cookie($userId,$id.'.'.$token,strtotime($expires));
    security_step17_audit($pdo,$userId,'security_trusted_device_added','security_trusted_device',$id,['device_label'=>$label,'expires_at'=>$expires]);
    return $id;
}

function role_trusted_rows(PDO $pdo, int $orgId, int $userId): array
{
    role_trusted_ensure($pdo);
    $stmt=$pdo->prepare("SELECT id,device_label,user_agent,created_ip,last_ip,created_at,last_used_at,expires_at,revoked_at,revoke_reason FROM security_trusted_devices WHERE organization_id=? AND user_id=? ORDER BY (revoked_at IS NULL AND expires_at>NOW()) DESC,last_used_at DESC,id DESC LIMIT 80");
    $stmt->execute([$orgId,$userId]);return $stmt->fetchAll();
}

function role_trusted_revoke(PDO $pdo, array $user, int $deviceId): void
{
    role_trusted_ensure($pdo);$ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];
    $stmt=$pdo->prepare("UPDATE security_trusted_devices SET revoked_at=NOW(),revoked_by=?,revoke_reason='revoked_by_account_owner' WHERE id=? AND organization_id=? AND user_id=? AND revoked_at IS NULL");
    $stmt->execute([$userId,$deviceId,$orgId,$userId]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Trusted device is already revoked or does not belong to this account.');
    $parts=role_trusted_cookie_parts($userId);if($parts&&(int)$parts['id']===$deviceId)role_trusted_clear_cookie($userId);
    security_step17_audit($pdo,$userId,'security_trusted_device_revoked','security_trusted_device',$deviceId,[]);
}
