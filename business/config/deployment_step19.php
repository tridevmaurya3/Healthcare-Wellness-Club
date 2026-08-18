<?php
declare(strict_types=1);

const DEPLOYMENT_STEP19_VERSION = '1.0-complete';

function deployment_step19_support_tables(): array
{
    return [
        'deployment_environments','deployment_releases','deployment_events','deployment_health_runs',
        'deployment_scheduler_runs','deployment_offsite_targets','deployment_migration_runs','deployment_settings',
    ];
}

function deployment_step19_run_migration(PDO $pdo): void
{
    $file=dirname(__DIR__,2).'/database/migrations/015_step19_cloud_production_readiness.sql';
    if(!is_file($file))throw new RuntimeException('STEP 19 migration is missing.');
    $sql=(string)file_get_contents($file);
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){
        $statement=trim($statement);
        if($statement==='')continue;
        $statement=preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement)??$statement;
        $statement=trim($statement);
        if($statement===''||preg_match('/^USE\s+/i',$statement))continue;
        $pdo->exec($statement);
    }
}

function deployment_step19_env(): string
{
    $env=strtolower(trim((string)(getenv('HWC_APP_ENV')?:'local')));
    return in_array($env,['local','staging','production'],true)?$env:'local';
}

function deployment_step19_is_production(): bool{return deployment_step19_env()==='production';}
function deployment_step19_h(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function deployment_step19_bool_env(string $name,bool $fallback=false): bool
{
    $v=getenv($name);if($v===false||trim((string)$v)==='')return $fallback;
    return in_array(strtolower(trim((string)$v)),['1','true','yes','on'],true);
}

function deployment_step19_trusted_proxies(): array
{
    $raw=trim((string)(getenv('HWC_TRUSTED_PROXIES')?:''));if($raw==='')return [];
    $out=[];foreach(explode(',',$raw) as $ip){$ip=trim($ip);if($ip!==''&&filter_var($ip,FILTER_VALIDATE_IP))$out[]=$ip;}
    return array_values(array_unique($out));
}

function deployment_step19_proxy_is_trusted(): bool
{
    $remote=(string)($_SERVER['REMOTE_ADDR']??'');
    return $remote!==''&&in_array($remote,deployment_step19_trusted_proxies(),true);
}

function deployment_step19_is_https(): bool
{
    if((!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||(int)($_SERVER['SERVER_PORT']??0)===443)return true;
    if(deployment_step19_proxy_is_trusted()){
        $proto=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
        return $proto==='https';
    }
    return false;
}

function deployment_step19_request_host(): string
{
    $host=trim((string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??''));
    if(str_contains($host,':')){
        if(str_starts_with($host,'[')){$end=strpos($host,']');if($end!==false)$host=substr($host,1,$end-1);}else{$host=explode(':',$host,2)[0];}
    }
    $host=strtolower(rtrim($host,'.'));
    return preg_match('/^[a-z0-9.-]+$/',$host)?$host:'';
}

function deployment_step19_allowed_hosts(): array
{
    $raw=trim((string)(getenv('HWC_ALLOWED_HOSTS')?:''));if($raw==='')return [];
    $out=[];foreach(explode(',',$raw) as $host){$host=strtolower(rtrim(trim($host),'.'));if($host!==''&&preg_match('/^[a-z0-9.-]+$/',$host))$out[]=$host;}
    return array_values(array_unique($out));
}

function deployment_step19_host_allowed(?string $host=null): bool
{
    $host=$host??deployment_step19_request_host();
    if(!deployment_step19_is_production())return true;
    $allowed=deployment_step19_allowed_hosts();
    return $host!==''&&$allowed!==[]&&in_array($host,$allowed,true);
}

function deployment_step19_app_url(): string
{
    $configured=rtrim(trim((string)(getenv('HWC_APP_URL')?:'')),'/');
    if($configured!=='')return $configured;
    $host=deployment_step19_request_host();if($host==='')$host='localhost';
    $scheme=deployment_step19_is_https()?'https':'http';
    $script=(string)($_SERVER['SCRIPT_NAME']??'');$base=preg_replace('#/business/[^/]*$#','',$script)??'';
    return $scheme.'://'.$host.rtrim($base,'/');
}

function deployment_step19_ensure(PDO $pdo): void
{
    foreach(deployment_step19_support_tables() as $table){if(!business_table_exists($pdo,$table)){deployment_step19_run_migration($pdo);break;}}
    foreach(deployment_step19_support_tables() as $table){if(!business_table_exists($pdo,$table))throw new RuntimeException('STEP 19 table missing: '.$table);}
    $orgId=(int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if($orgId<=0)return;
    $env=deployment_step19_env();$url=(string)(getenv('HWC_APP_URL')?:'');
    $stmt=$pdo->prepare("INSERT INTO deployment_environments(organization_id,environment_code,environment_name,app_url,status,is_current,requires_https,notes) VALUES(?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE app_url=COALESCE(NULLIF(VALUES(app_url),''),app_url),is_current=1,status=IF(status='planned','ready',status),requires_https=VALUES(requires_https)");
    $stmt->execute([$orgId,$env,ucfirst($env).' Environment',$url?:null,$env==='local'?'active':'ready',$env==='local'?0:1,'Runtime environment discovered by STEP 19.']);
    $pdo->prepare("UPDATE deployment_environments SET is_current=0 WHERE organization_id=? AND environment_code<>?")->execute([$orgId,$env]);
    $pdo->prepare("INSERT IGNORE INTO deployment_settings(organization_id) VALUES(?)")->execute([$orgId]);
    if(business_table_exists($pdo,'schema_meta')){$stmt=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('deployment_step19_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$stmt->execute([DEPLOYMENT_STEP19_VERSION]);}
}

function deployment_step19_context(PDO $pdo): array
{
    deployment_step19_ensure($pdo);$stmt=$pdo->query("SELECT o.id organization_id,o.organization_code,c.id club_id FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1");$r=$stmt->fetch();
    if(!$r)throw new RuntimeException('Healthcare Wellness Club deployment context is unavailable.');
    return ['organization_id'=>(int)$r['organization_id'],'organization_code'=>(string)$r['organization_code'],'club_id'=>$r['club_id']!==null?(int)$r['club_id']:null];
}

function deployment_step19_settings(PDO $pdo,int $orgId): array
{
    deployment_step19_ensure($pdo);$stmt=$pdo->prepare("SELECT * FROM deployment_settings WHERE organization_id=? LIMIT 1");$stmt->execute([$orgId]);return $stmt->fetch()?:[];
}

function deployment_step19_apply_headers(): void
{
    if(headers_sent())return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Robots-Tag: noindex, nofollow');
    if(deployment_step19_is_production()&&deployment_step19_is_https())header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function deployment_step19_runtime_checks(PDO $pdo): array
{
    deployment_step19_ensure($pdo);$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$settings=deployment_step19_settings($pdo,$orgId);
    $env=deployment_step19_env();$appUrl=trim((string)(getenv('HWC_APP_URL')?:''));$allowed=deployment_step19_allowed_hosts();$proxies=deployment_step19_trusted_proxies();
    $dbUser=(string)(getenv('DB_USER')?:'root');$dbPass=getenv('DB_PASS');$dbPass=$dbPass===false?'':(string)$dbPass;
    $healthToken=(string)(getenv('HWC_HEALTH_TOKEN')?:'');$offsitePath=(string)(getenv('HWC_OFFSITE_PATH')?:'');
    $phpOk=version_compare(PHP_VERSION,(string)($settings['minimum_php_version']??'8.1.0'),'>=');
    $urlHttps=$appUrl!==''&&str_starts_with(strtolower($appUrl),'https://');
    $productionSecrets=$dbUser!==''&&strtolower($dbUser)!=='root'&&$dbPass!=='';
    return [
        'environment'=>$env,'app_url'=>$appUrl,'is_https'=>deployment_step19_is_https(),'host'=>deployment_step19_request_host(),
        'allowed_hosts'=>$allowed,'trusted_proxies'=>$proxies,'php_ok'=>$phpOk,'openssl'=>extension_loaded('openssl'),'pdo_mysql'=>extension_loaded('pdo_mysql'),
        'production_url_ready'=>$urlHttps,'production_db_credentials_ready'=>$productionSecrets,'health_token_ready'=>strlen($healthToken)>=24,
        'offsite_path_ready'=>$offsitePath!==''&&is_dir($offsitePath)&&is_writable($offsitePath),'offsite_path_configured'=>$offsitePath!=='',
        'force_https_policy'=>(int)($settings['require_https_in_production']??1)===1,'allowed_hosts_ready'=>$allowed!==[],
    ];
}

function deployment_step19_log(PDO $pdo,string $eventType,string $status,array $details=[],?int $actorId=null): void
{
    $ctx=deployment_step19_context($pdo);$stmt=$pdo->prepare("INSERT INTO deployment_events(organization_id,environment_code,event_type,event_status,details_json,created_by) VALUES(?,?,?,?,?,?)");$json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);$stmt->execute([(int)$ctx['organization_id'],deployment_step19_env(),$eventType,$status,$json===false?null:$json,$actorId]);
    if(function_exists('security_step17_audit'))security_step17_audit($pdo,$actorId,'deployment_'.$eventType,'deployment_event',null,$details+['status'=>$status,'environment'=>deployment_step19_env()]);
}

function deployment_step19_preflight_request(PDO $pdo): void
{
    if(PHP_SAPI==='cli'||PHP_SAPI==='phpdbg')return;
    deployment_step19_ensure($pdo);deployment_step19_apply_headers();
    if(deployment_step19_is_production()&&!deployment_step19_host_allowed()){
        http_response_code(421);echo '<!doctype html><meta charset="utf-8"><title>Host rejected</title><h1>Request host is not allowed.</h1>';exit;
    }
    $ctx=deployment_step19_context($pdo);$settings=deployment_step19_settings($pdo,(int)$ctx['organization_id']);
    if(deployment_step19_is_production()&&(int)($settings['require_https_in_production']??1)===1&&!deployment_step19_is_https()){
        $host=deployment_step19_request_host();if($host===''){http_response_code(503);echo 'HTTPS configuration is incomplete.';exit;}
        $uri=(string)($_SERVER['REQUEST_URI']??'/');header('Location: https://'.$host.$uri,true,308);exit;
    }
}

function deployment_step19_guard_request(PDO $pdo): void
{
    if(PHP_SAPI==='cli'||PHP_SAPI==='phpdbg')return;
    deployment_step19_preflight_request($pdo);$script=basename((string)($_SERVER['SCRIPT_NAME']??$_SERVER['PHP_SELF']??''));
    $ctx=deployment_step19_context($pdo);$settings=deployment_step19_settings($pdo,(int)$ctx['organization_id']);
    if((int)($settings['maintenance_enabled']??0)!==1)return;
    $always=['login.php','logout.php','access_denied.php','maintenance.php','healthz.php','password_change.php'];
    if(in_array($script,$always,true)||str_starts_with($script,'deployment_')||in_array($script,['production_health.php','multi_device_access.php','offsite_backup.php','migration_center.php','scheduler_center.php','step19_audit.php'],true))return;
    $user=function_exists('security_step17_session_user')?security_step17_session_user($pdo,false):null;
    $allowed=$user&&((string)($user['role_code']??'')==='admin'||(function_exists('security_step17_has_permission')&&security_step17_has_permission($pdo,'deployment.manage',$user)));
    if(!$allowed){header('Location: maintenance.php',true,302);exit;}
}

function deployment_step19_set_maintenance(PDO $pdo,bool $enabled,string $message,?int $actorId): void
{
    $ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("UPDATE deployment_settings SET maintenance_enabled=?,maintenance_message=?,maintenance_started_at=?,maintenance_started_by=? WHERE organization_id=?");
    $stmt->execute([$enabled?1:0,trim($message)?:null,$enabled?date('Y-m-d H:i:s'):null,$enabled?$actorId:null,$orgId]);
    deployment_step19_log($pdo,$enabled?'maintenance_enabled':'maintenance_disabled','pass',['message'=>trim($message)],$actorId);
}

function deployment_step19_release_code(): string{return 'REL-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));}
function deployment_step19_register_release(PDO $pdo,string $version,string $sha,string $status,string $notes,?int $actorId): int
{
    $ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$version=trim($version);if($version==='')throw new RuntimeException('Release version is required.');
    if($sha!==''&&!preg_match('/^[a-f0-9]{7,64}$/i',$sha))throw new RuntimeException('Git commit SHA format is invalid.');
    if(!in_array($status,['planned','ready','deployed','rolled_back','failed'],true))throw new RuntimeException('Release status is invalid.');
    if($status==='deployed'&&deployment_step19_is_production()){
        $checks=deployment_step19_runtime_checks($pdo);if(!$checks['production_url_ready']||!$checks['production_db_credentials_ready']||!$checks['allowed_hosts_ready'])throw new RuntimeException('Production environment requirements are not complete.');
        if(business_table_exists($pdo,'backup_records')){$verified=(int)$pdo->query("SELECT COUNT(*) FROM backup_records WHERE organization_id={$orgId} AND verification_status='verified' AND status<>'expired'")->fetchColumn();if($verified<1)throw new RuntimeException('A verified recovery backup is required before a production release can be marked deployed.');}
    }
    $code=deployment_step19_release_code();$stmt=$pdo->prepare("INSERT INTO deployment_releases(organization_id,release_code,version_label,git_commit_sha,environment_code,release_status,deployed_at,deployed_by,notes) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$code,$version,$sha?:null,deployment_step19_env(),$status,$status==='deployed'?date('Y-m-d H:i:s'):null,$status==='deployed'?$actorId:null,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();deployment_step19_log($pdo,'release_registered','pass',['release_code'=>$code,'version'=>$version,'status'=>$status,'sha'=>$sha?:null],$actorId);return $id;
}

function deployment_step19_health(PDO $pdo,bool $record=false): array
{
    deployment_step19_ensure($pdo);$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$checks=[];
    $add=function(string $name,bool $ok,string $detail)use(&$checks):void{$checks[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail];};
    $runtime=deployment_step19_runtime_checks($pdo);$add('Database connection',true,(string)$pdo->query('SELECT DATABASE()')->fetchColumn());$add('PHP runtime',$runtime['php_ok'],'PHP '.PHP_VERSION);$add('PDO MySQL',$runtime['pdo_mysql'],'pdo_mysql '.($runtime['pdo_mysql']?'loaded':'missing'));$add('OpenSSL',$runtime['openssl'],'OpenSSL '.($runtime['openssl']?'loaded':'missing'));
    $add('STEP 18 recovery tables',business_table_exists($pdo,'backup_records')&&business_table_exists($pdo,'backup_restore_jobs'),'backup/restore foundation');$add('STEP 17 security tables',business_table_exists($pdo,'security_sessions')&&business_table_exists($pdo,'security_permissions'),'security foundation');
    if(deployment_step19_is_production()){$add('Production HTTPS URL',$runtime['production_url_ready'],'HWC_APP_URL uses HTTPS');$add('Production DB credentials',$runtime['production_db_credentials_ready'],'non-root DB user + non-empty password');$add('Allowed host policy',$runtime['allowed_hosts_ready'],count($runtime['allowed_hosts']).' host(s) configured');$add('Health token',$runtime['health_token_ready'],'health endpoint token configured');}
    else{$add('Local mode isolated',deployment_step19_env()==='local','production-only requirements are not forced on XAMPP');}
    $failed=count(array_filter($checks,static fn($c)=>!$c['ok']));$passed=count($checks)-$failed;$status=$failed===0?'pass':'review';
    if($record){$stmt=$pdo->prepare("INSERT INTO deployment_health_runs(organization_id,environment_code,status,checks_passed,checks_review,details_json,completed_at) VALUES(?,?,?,?,?,?,NOW())");$stmt->execute([$orgId,deployment_step19_env(),$status,$passed,$failed,json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
    return ['status'=>$status,'passed'=>$passed,'review'=>$failed,'checks'=>$checks,'runtime'=>$runtime];
}

function deployment_step19_latest_verified_backup(PDO $pdo,int $orgId): ?array
{
    if(!business_table_exists($pdo,'backup_records'))return null;$stmt=$pdo->prepare("SELECT * FROM backup_records WHERE organization_id=? AND verification_status='verified' AND status<>'expired' ORDER BY id DESC LIMIT 1");$stmt->execute([$orgId]);$r=$stmt->fetch();return $r?:null;
}

function deployment_step19_offsite_copy_latest(PDO $pdo,?int $actorId=null): array
{
    if(!function_exists('backup_step18_resolve_path'))throw new RuntimeException('STEP 18 backup service is unavailable.');$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$record=deployment_step19_latest_verified_backup($pdo,$orgId);if(!$record)throw new RuntimeException('No verified backup is available for offsite copy.');
    $target=trim((string)(getenv('HWC_OFFSITE_PATH')?:''));if($target==='')throw new RuntimeException('HWC_OFFSITE_PATH is not configured.');if(!is_dir($target)&&!@mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Offsite target directory could not be created.');if(!is_writable($target))throw new RuntimeException('Offsite target is not writable.');
    $source=backup_step18_resolve_path((string)$record['storage_path']);if(!is_file($source))throw new RuntimeException('Verified backup source file is missing.');if(!hash_equals((string)$record['file_sha256'],hash_file('sha256',$source)))throw new RuntimeException('Verified backup source hash does not match.');
    $dest=rtrim($target,'/\\').DIRECTORY_SEPARATOR.basename((string)$record['stored_name']);$tmp=$dest.'.tmp';if(!copy($source,$tmp))throw new RuntimeException('Offsite copy failed.');if(!hash_equals((string)$record['file_sha256'],hash_file('sha256',$tmp))){@unlink($tmp);throw new RuntimeException('Offsite copy SHA-256 verification failed.');}if(!@rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('Offsite copy could not be finalized.');}@chmod($dest,0600);
    $stmt=$pdo->prepare("INSERT INTO deployment_offsite_targets(organization_id,target_code,target_name,adapter_type,location_label,is_enabled,last_tested_at,last_status,last_detail) VALUES(?,'PRIMARY','Primary Offsite Backup','filesystem',?,1,NOW(),'pass',?) ON DUPLICATE KEY UPDATE location_label=VALUES(location_label),is_enabled=1,last_tested_at=NOW(),last_status='pass',last_detail=VALUES(last_detail)");$stmt->execute([$orgId,$target,'Copied '.$record['backup_code'].' and verified SHA-256']);deployment_step19_log($pdo,'offsite_backup_copied','pass',['backup_code'=>$record['backup_code'],'target'=>'filesystem','sha256'=>$record['file_sha256']],$actorId);return ['path'=>$dest,'backup_code'=>$record['backup_code'],'sha256'=>$record['file_sha256']];
}

function deployment_step19_create_migration(PDO $pdo,string $targetEnvironment,?int $backupId,string $notes,?int $actorId): int
{
    if(!in_array($targetEnvironment,['staging','production'],true))throw new RuntimeException('Migration target must be staging or production.');$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$schema=function_exists('backup_step18_schema_fingerprint')?backup_step18_schema_fingerprint($pdo):null;$code='MIG-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    if($backupId!==null){$stmt=$pdo->prepare("SELECT COUNT(*) FROM backup_records WHERE id=? AND organization_id=? AND verification_status='verified'");$stmt->execute([$backupId,$orgId]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Choose a verified STEP 18 backup.');}
    $status=$backupId?'backup_ready':'planned';$stmt=$pdo->prepare("INSERT INTO deployment_migration_runs(organization_id,migration_code,source_environment,target_environment,backup_record_id,status,source_schema_fingerprint,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$code,deployment_step19_env(),$targetEnvironment,$backupId,$status,$schema,trim($notes)?:null,$actorId]);$id=(int)$pdo->lastInsertId();deployment_step19_log($pdo,'migration_planned','pass',['migration_code'=>$code,'target'=>$targetEnvironment,'backup_id'=>$backupId],$actorId);return $id;
}
