<?php
declare(strict_types=1);

const BACKUP_STEP18_VERSION = '1.0-complete';
const BACKUP_STEP18_PACKAGE = 'HWCBAK18-1';
const BACKUP_STEP18_AAD = 'Healthcare-Wellness-Club|STEP18';
const BACKUP_STEP18_KDF_ITERATIONS = 210000;

function backup_step18_support_tables(): array
{
    return ['backup_records','backup_restore_jobs','backup_policies','backup_verification_runs'];
}

function backup_step18_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/014_step18_backup_disaster_recovery.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 18 migration is missing.');
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

function backup_step18_project_root(): string
{
    return dirname(__DIR__,2);
}

function backup_step18_storage_dir(string $kind='backup'): string
{
    $dir=backup_step18_project_root().'/storage/'.($kind==='staging'?'restore-staging':'backups');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Backup storage directory could not be created.');
    if(!is_writable($dir))throw new RuntimeException('Backup storage directory is not writable: '.$dir);
    return $dir;
}

function backup_step18_relative_path(string $absolute): string
{
    $root=str_replace('\\','/',backup_step18_project_root()).'/';
    $path=str_replace('\\','/',$absolute);
    return str_starts_with($path,$root)?substr($path,strlen($root)):$path;
}

function backup_step18_resolve_path(string $stored): string
{
    if($stored==='')throw new RuntimeException('Backup file path is empty.');
    if(preg_match('/^[A-Za-z]:[\\\\\/]/',$stored)||str_starts_with($stored,'/'))return $stored;
    return backup_step18_project_root().'/'.ltrim(str_replace('\\','/',$stored),'/');
}

function backup_step18_ensure(PDO $pdo): void
{
    foreach(backup_step18_support_tables() as $table){if(!business_table_exists($pdo,$table)){backup_step18_run_migration($pdo);break;}}
    foreach(backup_step18_support_tables() as $table){if(!business_table_exists($pdo,$table))throw new RuntimeException('STEP 18 table missing: '.$table);}
    backup_step18_storage_dir('backup');backup_step18_storage_dir('staging');
    $orgId=(int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if($orgId>0){
        $stmt=$pdo->prepare("INSERT INTO backup_policies(organization_id,policy_code,is_enabled,frequency_code,preferred_time,retention_daily,retention_weekly,retention_monthly,require_verified_copy,require_offsite_copy) VALUES(?,'PRIMARY',0,'daily','02:00:00',7,4,6,1,1) ON DUPLICATE KEY UPDATE policy_code=VALUES(policy_code)");
        $stmt->execute([$orgId]);
    }
    if(business_table_exists($pdo,'schema_meta')){
        $stmt=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('backup_step18_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$stmt->execute([BACKUP_STEP18_VERSION]);
    }
}

function backup_step18_context(PDO $pdo): array
{
    backup_step18_ensure($pdo);
    $stmt=$pdo->query("SELECT o.id organization_id,o.organization_code,c.id club_id FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1");
    $r=$stmt->fetch();if(!$r)throw new RuntimeException('Healthcare Wellness Club backup context is unavailable.');
    return ['organization_id'=>(int)$r['organization_id'],'organization_code'=>(string)$r['organization_code'],'club_id'=>$r['club_id']!==null?(int)$r['club_id']:null];
}

function backup_step18_h(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function backup_step18_code(string $prefix): string{return $prefix.'-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));}
function backup_step18_json(mixed $data): string{$j=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if($j===false)throw new RuntimeException('Backup JSON encoding failed.');return $j;}
function backup_step18_qid(string $name): string{if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Unsafe database identifier.');return '`'.$name.'`';}

function backup_step18_excluded_tables(): array
{
    return ['backup_records','backup_restore_jobs','backup_policies','backup_verification_runs','security_sessions','security_login_attempts'];
}

function backup_step18_data_tables(PDO $pdo): array
{
    $stmt=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name");
    $excluded=array_flip(backup_step18_excluded_tables());$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $table){$table=(string)$table;if(!isset($excluded[$table]))$out[]=$table;}
    return $out;
}

function backup_step18_columns(PDO $pdo,string $table,bool $insertableOnly=false): array
{
    $sql="SELECT column_name,column_type,is_nullable,column_default,extra,ordinal_position FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position";
    $stmt=$pdo->prepare($sql);$stmt->execute([$table]);$rows=$stmt->fetchAll();$out=[];
    foreach($rows as $r){if($insertableOnly&&str_contains(strtoupper((string)$r['extra']),'GENERATED'))continue;$out[]=$r;}
    return $out;
}

function backup_step18_schema_fingerprint(PDO $pdo,?array $tables=null): string
{
    $tables=$tables??backup_step18_data_tables($pdo);$schema=[];
    foreach($tables as $table){
        $cols=backup_step18_columns($pdo,$table,false);
        $idx=$pdo->prepare("SELECT index_name,non_unique,seq_in_index,column_name,sub_part,index_type FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name,seq_in_index");$idx->execute([$table]);
        $schema[$table]=['columns'=>$cols,'indexes'=>$idx->fetchAll()];
    }
    return hash('sha256',backup_step18_json($schema));
}

function backup_step18_max_plaintext_bytes(): int
{
    $v=(int)(getenv('HWC_BACKUP_MAX_PLAINTEXT_BYTES')?:0);return $v>0?$v:67108864;
}

function backup_step18_estimated_db_bytes(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COALESCE(SUM(data_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
}

function backup_step18_build_payload(PDO $pdo): array
{
    $ctx=backup_step18_context($pdo);$tables=backup_step18_data_tables($pdo);$payloadTables=[];$totalRows=0;
    foreach($tables as $table){
        $cols=backup_step18_columns($pdo,$table,true);$names=array_map(static fn(array $c):string=>(string)$c['column_name'],$cols);
        if(!$names){$payloadTables[$table]=['columns'=>[],'rows'=>[]];continue;}
        $select=implode(',',array_map('backup_step18_qid',$names));$stmt=$pdo->query('SELECT '.$select.' FROM '.backup_step18_qid($table));$rows=[];
        while($r=$stmt->fetch(PDO::FETCH_ASSOC))$rows[]=$r;
        $totalRows+=count($rows);$payloadTables[$table]=['columns'=>$names,'rows'=>$rows];
    }
    $payload=['format'=>BACKUP_STEP18_PACKAGE,'created_at'=>date(DATE_ATOM),'application'=>'Healthcare Wellness Club','organization_code'=>$ctx['organization_code'],'organization_id'=>$ctx['organization_id'],'schema_fingerprint'=>backup_step18_schema_fingerprint($pdo,$tables),'table_count'=>count($tables),'row_count'=>$totalRows,'tables'=>$payloadTables];
    $json=backup_step18_json($payload);if(strlen($json)>backup_step18_max_plaintext_bytes())throw new RuntimeException('Portable web backup exceeded the configured safe plaintext limit. Use a larger HWC_BACKUP_MAX_PLAINTEXT_BYTES only after confirming PHP memory, or use a provider/native database backup for large production data.');
    return [$payload,$json];
}

function backup_step18_assert_passphrase(string $passphrase): void
{
    if(strlen($passphrase)<12)throw new RuntimeException('Backup passphrase must be at least 12 characters. It is never stored by the application.');
}

function backup_step18_encrypt_payload(string $plaintext,string $schemaFingerprint,int $tableCount,int $rowCount): array
{
    backup_step18_assert_passphrase($GLOBALS['__hwc_backup_passphrase']??'');
    $pass=(string)$GLOBALS['__hwc_backup_passphrase'];$salt=random_bytes(16);$nonce=random_bytes(12);$key=hash_pbkdf2('sha256',$pass,$salt,BACKUP_STEP18_KDF_ITERATIONS,32,true);
    $compressed=gzencode($plaintext,6);if($compressed===false)throw new RuntimeException('Backup compression failed.');$tag='';
    $cipher=openssl_encrypt($compressed,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,BACKUP_STEP18_AAD,16);if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Backup encryption failed. OpenSSL AES-256-GCM is required.');
    $envelope=['format'=>BACKUP_STEP18_PACKAGE,'created_at'=>date(DATE_ATOM),'compression'=>'gzip','cipher'=>'AES-256-GCM','kdf'=>'PBKDF2-HMAC-SHA256','iterations'=>BACKUP_STEP18_KDF_ITERATIONS,'salt'=>base64_encode($salt),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'plaintext_sha256'=>hash('sha256',$plaintext),'schema_fingerprint'=>$schemaFingerprint,'table_count'=>$tableCount,'row_count'=>$rowCount,'ciphertext'=>base64_encode($cipher)];
    return [$envelope,backup_step18_json($envelope)];
}

function backup_step18_expiry_for_type(PDO $pdo,int $orgId,string $type): ?string
{
    if($type==='manual')return null;if($type==='pre_restore')return date('Y-m-d H:i:s',strtotime('+30 days'));
    $stmt=$pdo->prepare("SELECT retention_daily,retention_weekly,retention_monthly FROM backup_policies WHERE organization_id=? AND policy_code='PRIMARY' LIMIT 1");$stmt->execute([$orgId]);$p=$stmt->fetch()?:['retention_daily'=>7,'retention_weekly'=>4,'retention_monthly'=>6];
    if($type==='scheduled_monthly')return date('Y-m-d H:i:s',strtotime('+'.max(1,(int)$p['retention_monthly']).' months'));
    if($type==='scheduled_weekly')return date('Y-m-d H:i:s',strtotime('+'.max(1,(int)$p['retention_weekly']).' weeks'));
    return date('Y-m-d H:i:s',strtotime('+'.max(1,(int)$p['retention_daily']).' days'));
}

function backup_step18_create_backup(PDO $pdo,string $passphrase,string $type='manual',string $notes='',?int $actorId=null): array
{
    backup_step18_assert_passphrase($passphrase);backup_step18_ensure($pdo);$ctx=backup_step18_context($pdo);$orgId=(int)$ctx['organization_id'];
    if(!extension_loaded('openssl'))throw new RuntimeException('OpenSSL PHP extension is required for encrypted backups.');if(!function_exists('gzencode'))throw new RuntimeException('zlib/gzip support is required for backups.');
    [$payload,$plaintext]=backup_step18_build_payload($pdo);$GLOBALS['__hwc_backup_passphrase']=$passphrase;try{[$envelope,$package]=backup_step18_encrypt_payload($plaintext,(string)$payload['schema_fingerprint'],(int)$payload['table_count'],(int)$payload['row_count']);}finally{unset($GLOBALS['__hwc_backup_passphrase']);}
    $dir=backup_step18_storage_dir('backup');$stored='hwc-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.hwcbak';$final=$dir.'/'.$stored;$tmp=$final.'.tmp';
    if(file_put_contents($tmp,$package,LOCK_EX)===false)throw new RuntimeException('Encrypted backup package could not be written.');if(!@rename($tmp,$final)){@unlink($tmp);throw new RuntimeException('Encrypted backup package could not be finalized.');}@chmod($final,0600);
    $fileHash=hash_file('sha256',$final);$size=(int)filesize($final);$code=backup_step18_code('BAK');$expires=backup_step18_expiry_for_type($pdo,$orgId,$type);
    try{
        $stmt=$pdo->prepare("INSERT INTO backup_records(organization_id,created_by,backup_code,backup_type,backup_scope,status,original_name,stored_name,storage_path,package_version,encryption_algorithm,key_derivation,kdf_iterations,salt_b64,nonce_b64,auth_tag_b64,file_sha256,plaintext_sha256,schema_fingerprint,table_count,row_count,file_size,expires_at,notes) VALUES(?,?,?,?,?,'created',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$orgId,$actorId,$code,$type,'database',$stored,$stored,backup_step18_relative_path($final),BACKUP_STEP18_PACKAGE,'AES-256-GCM','PBKDF2-HMAC-SHA256',BACKUP_STEP18_KDF_ITERATIONS,(string)$envelope['salt'],(string)$envelope['nonce'],(string)$envelope['tag'],$fileHash,(string)$envelope['plaintext_sha256'],(string)$envelope['schema_fingerprint'],(int)$envelope['table_count'],(int)$envelope['row_count'],$size,$expires,trim($notes)?:null]);
        $id=(int)$pdo->lastInsertId();
    }catch(Throwable $e){@unlink($final);throw $e;}
    if(function_exists('security_step17_audit'))security_step17_audit($pdo,$actorId,'backup_created','backup_record',$id,['backup_code'=>$code,'type'=>$type,'file_sha256'=>$fileHash,'tables'=>$payload['table_count'],'rows'=>$payload['row_count'],'encrypted'=>true]);
    return ['id'=>$id,'backup_code'=>$code,'path'=>$final,'stored_name'=>$stored,'file_sha256'=>$fileHash,'file_size'=>$size,'table_count'=>(int)$payload['table_count'],'row_count'=>(int)$payload['row_count'],'schema_fingerprint'=>(string)$payload['schema_fingerprint']];
}

function backup_step18_read_envelope(string $path,?string $expectedFileHash=null): array
{
    if(!is_file($path)||!is_readable($path))throw new RuntimeException('Backup package file is missing or unreadable.');
    if($expectedFileHash!==null&&$expectedFileHash!==''&&!hash_equals(strtolower($expectedFileHash),strtolower(hash_file('sha256',$path))))throw new RuntimeException('Backup file SHA-256 does not match its recorded integrity hash.');
    $raw=file_get_contents($path);if($raw===false)throw new RuntimeException('Backup package could not be read.');$env=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($env)||($env['format']??'')!==BACKUP_STEP18_PACKAGE)throw new RuntimeException('This is not a supported Healthcare Wellness Club backup package.');
    foreach(['cipher','kdf','iterations','salt','nonce','tag','plaintext_sha256','schema_fingerprint','ciphertext'] as $k)if(!array_key_exists($k,$env))throw new RuntimeException('Backup package metadata is incomplete: '.$k);
    return $env;
}

function backup_step18_decrypt_file(string $path,string $passphrase,?string $expectedFileHash=null): array
{
    backup_step18_assert_passphrase($passphrase);$env=backup_step18_read_envelope($path,$expectedFileHash);
    if(($env['cipher']??'')!=='AES-256-GCM'||($env['kdf']??'')!=='PBKDF2-HMAC-SHA256')throw new RuntimeException('Unsupported backup encryption format.');
    $salt=base64_decode((string)$env['salt'],true);$nonce=base64_decode((string)$env['nonce'],true);$tag=base64_decode((string)$env['tag'],true);$cipher=base64_decode((string)$env['ciphertext'],true);
    if($salt===false||$nonce===false||$tag===false||$cipher===false)throw new RuntimeException('Backup package contains invalid encoded cryptographic data.');
    $key=hash_pbkdf2('sha256',$passphrase,$salt,(int)$env['iterations'],32,true);$compressed=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,BACKUP_STEP18_AAD);
    if($compressed===false)throw new RuntimeException('Backup authentication failed. The passphrase is wrong or the encrypted package was modified.');
    $plain=gzdecode($compressed);if($plain===false)throw new RuntimeException('Backup payload decompression failed.');
    if(!hash_equals((string)$env['plaintext_sha256'],hash('sha256',$plain)))throw new RuntimeException('Backup plaintext integrity verification failed.');
    $payload=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($payload)||($payload['format']??'')!==BACKUP_STEP18_PACKAGE||!isset($payload['tables'])||!is_array($payload['tables']))throw new RuntimeException('Backup payload structure is invalid.');
    if(!hash_equals((string)$env['schema_fingerprint'],(string)($payload['schema_fingerprint']??'')))throw new RuntimeException('Backup manifest and payload schema hashes do not agree.');
    return ['envelope'=>$env,'payload'=>$payload,'plaintext'=>$plain];
}

function backup_step18_record(PDO $pdo,int $orgId,int $id): array
{
    $stmt=$pdo->prepare("SELECT b.*,u.full_name created_by_name FROM backup_records b LEFT JOIN system_users u ON u.id=b.created_by WHERE b.organization_id=? AND b.id=? LIMIT 1");$stmt->execute([$orgId,$id]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Backup record was not found.');return $r;
}

function backup_step18_records(PDO $pdo,int $orgId,int $limit=100): array
{
    $limit=max(1,min(250,$limit));$stmt=$pdo->prepare("SELECT b.*,u.full_name created_by_name,(SELECT vr.status FROM backup_verification_runs vr WHERE vr.organization_id=b.organization_id AND vr.backup_record_id=b.id ORDER BY vr.id DESC LIMIT 1) latest_verification FROM backup_records b LEFT JOIN system_users u ON u.id=b.created_by WHERE b.organization_id=? ORDER BY b.id DESC LIMIT {$limit}");$stmt->execute([$orgId]);return $stmt->fetchAll();
}

function backup_step18_verify_record(PDO $pdo,int $backupId,string $passphrase,?int $actorId=null): array
{
    backup_step18_ensure($pdo);$ctx=backup_step18_context($pdo);$orgId=(int)$ctx['organization_id'];$r=backup_step18_record($pdo,$orgId,$backupId);$path=backup_step18_resolve_path((string)$r['storage_path']);
    $fileOk=is_file($path)&&hash_equals((string)$r['file_sha256'],hash_file('sha256',$path));$auth=false;$schema=false;$payloadOk=false;$detail='';
    try{$decoded=backup_step18_decrypt_file($path,$passphrase,(string)$r['file_sha256']);$auth=true;$payloadOk=(int)$decoded['payload']['table_count']===(int)$r['table_count']&&(int)$decoded['payload']['row_count']===(int)$r['row_count'];$schema=hash_equals(backup_step18_schema_fingerprint($pdo),(string)$decoded['payload']['schema_fingerprint']);$detail=$schema?'Package authenticated and current-schema compatible.':'Package authenticated; schema differs from the current database.';}catch(Throwable $e){$detail=$e->getMessage();}
    $status=$fileOk&&$auth&&$payloadOk?($schema?'verified':'verified_incompatible'):'failed';$stmt=$pdo->prepare("INSERT INTO backup_verification_runs(organization_id,backup_record_id,verified_by,verification_type,status,file_hash_ok,authentication_ok,schema_ok,payload_ok,details_json) VALUES(?,?,?,'package',?,?,?,?,?,?)");$stmt->execute([$orgId,$backupId,$actorId,$status,$fileOk?1:0,$auth?1:0,$schema?1:0,$payloadOk?1:0,backup_step18_json(['detail'=>$detail])]);
    $pdo->prepare("UPDATE backup_records SET verification_status=?,verified_at=? WHERE organization_id=? AND id=?")->execute([$status,$status==='verified'?date('Y-m-d H:i:s'):null,$orgId,$backupId]);
    if(function_exists('security_step17_audit'))security_step17_audit($pdo,$actorId,'backup_verified','backup_record',$backupId,['status'=>$status,'file_hash_ok'=>$fileOk,'authentication_ok'=>$auth,'schema_ok'=>$schema,'payload_ok'=>$payloadOk]);
    return ['status'=>$status,'file_hash_ok'=>$fileOk,'authentication_ok'=>$auth,'schema_ok'=>$schema,'payload_ok'=>$payloadOk,'detail'=>$detail];
}

function backup_step18_stage_path(PDO $pdo,string $sourcePath,string $originalName,string $passphrase,?int $actorId=null,?int $backupRecordId=null): array
{
    backup_step18_ensure($pdo);$ctx=backup_step18_context($pdo);$orgId=(int)$ctx['organization_id'];if(!is_file($sourcePath))throw new RuntimeException('Restore source package is missing.');
    $size=(int)filesize($sourcePath);if($size<=0||$size>100663296)throw new RuntimeException('Restore package must be between 1 byte and 96 MB for the current portable restore engine.');
    $dir=backup_step18_storage_dir('staging');$stage='restore-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.hwcbak';$dest=$dir.'/'.$stage;
    if(!@copy($sourcePath,$dest))throw new RuntimeException('Restore package could not be staged.');@chmod($dest,0600);$hash=hash_file('sha256',$dest);
    try{$decoded=backup_step18_decrypt_file($dest,$passphrase);$payload=$decoded['payload'];$current=backup_step18_schema_fingerprint($pdo);$schemaOk=hash_equals($current,(string)$payload['schema_fingerprint']);$orgOk=(string)($payload['organization_code']??'')==='HWC-001';if(!$orgOk)throw new RuntimeException('Backup belongs to a different organization context.');$validation=$schemaOk?'valid':'schema_mismatch';$preview=['created_at'=>$payload['created_at']??null,'organization_code'=>$payload['organization_code']??null,'table_count'=>(int)($payload['table_count']??0),'row_count'=>(int)($payload['row_count']??0),'schema_fingerprint'=>$payload['schema_fingerprint']??null,'current_schema_fingerprint'=>$current,'schema_compatible'=>$schemaOk];}
    catch(Throwable $e){@unlink($dest);throw $e;}
    if($backupRecordId===null){$stmt=$pdo->prepare("SELECT id FROM backup_records WHERE organization_id=? AND file_sha256=? ORDER BY id DESC LIMIT 1");$stmt->execute([$orgId,$hash]);$found=(int)$stmt->fetchColumn();$backupRecordId=$found>0?$found:null;}
    $job=backup_step18_code('RST');$stmt=$pdo->prepare("INSERT INTO backup_restore_jobs(organization_id,requested_by,backup_record_id,job_code,uploaded_name,staged_path,package_sha256,status,validation_status,schema_fingerprint,preview_json,validated_at) VALUES(?,?,?,?,?,?,?,'validated',?,?,?,NOW())");$stmt->execute([$orgId,$actorId,$backupRecordId,$job,substr($originalName,0,255),backup_step18_relative_path($dest),$hash,$validation,(string)$payload['schema_fingerprint'],backup_step18_json($preview)]);$id=(int)$pdo->lastInsertId();
    if(function_exists('security_step17_audit'))security_step17_audit($pdo,$actorId,'restore_package_validated','backup_restore_job',$id,['job_code'=>$job,'schema_compatible'=>$schemaOk,'package_sha256'=>$hash]);
    return ['id'=>$id,'job_code'=>$job,'validation_status'=>$validation,'preview'=>$preview];
}

function backup_step18_restore_job(PDO $pdo,int $orgId,int $id): array
{
    $stmt=$pdo->prepare("SELECT j.*,b.backup_code FROM backup_restore_jobs j LEFT JOIN backup_records b ON b.id=j.backup_record_id WHERE j.organization_id=? AND j.id=? LIMIT 1");$stmt->execute([$orgId,$id]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Restore job was not found.');return $r;
}

function backup_step18_apply_payload(PDO $pdo,array $payload): void
{
    if(($payload['format']??'')!==BACKUP_STEP18_PACKAGE||!is_array($payload['tables']??null))throw new RuntimeException('Restore payload is invalid.');$tables=array_keys($payload['tables']);
    $currentTables=backup_step18_data_tables($pdo);sort($tables);sort($currentTables);if($tables!==$currentTables)throw new RuntimeException('Restore table set does not match the current application schema.');
    if(!hash_equals(backup_step18_schema_fingerprint($pdo,$currentTables),(string)($payload['schema_fingerprint']??'')))throw new RuntimeException('Restore schema fingerprint is not compatible with this deployment.');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');$pdo->beginTransaction();
    try{
        foreach($currentTables as $table)$pdo->exec('DELETE FROM '.backup_step18_qid($table));
        foreach($payload['tables'] as $table=>$block){$columns=$block['columns']??[];$rows=$block['rows']??[];if(!$columns||!$rows)continue;$colSql=implode(',',array_map('backup_step18_qid',$columns));$ph=implode(',',array_fill(0,count($columns),'?'));$ins=$pdo->prepare('INSERT INTO '.backup_step18_qid((string)$table).' ('.$colSql.') VALUES ('.$ph.')');foreach($rows as $row){$vals=[];foreach($columns as $c)$vals[]=$row[$c]??null;$ins->execute($vals);}}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET FOREIGN_KEY_CHECKS=1');throw $e;}
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function backup_step18_execute_restore(PDO $pdo,int $jobId,string $passphrase,string $currentPassword,string $confirmation): array
{
    backup_step18_assert_passphrase($passphrase);if(trim($confirmation)!=='RESTORE DATABASE')throw new RuntimeException('Type RESTORE DATABASE exactly to confirm the destructive restore.');
    $ctx=backup_step18_context($pdo);$orgId=(int)$ctx['organization_id'];$user=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,'backup.restore',$user))throw new RuntimeException('You do not have Backup Restore permission.');
    $stmt=$pdo->prepare("SELECT password_hash FROM system_users WHERE id=? AND is_active=1 LIMIT 1");$stmt->execute([(int)$user['id']]);$hash=(string)($stmt->fetchColumn()?:'');if($hash===''||!password_verify($currentPassword,$hash))throw new RuntimeException('Current administrator password is incorrect.');
    $job=backup_step18_restore_job($pdo,$orgId,$jobId);if((string)$job['validation_status']!=='valid')throw new RuntimeException('Restore package has not passed current-schema validation.');if(!in_array((string)$job['status'],['validated','ready'],true))throw new RuntimeException('Restore job is not in an executable state.');$stage=backup_step18_resolve_path((string)$job['staged_path']);
    $decoded=backup_step18_decrypt_file($stage,$passphrase,(string)$job['package_sha256']);if(!hash_equals(backup_step18_schema_fingerprint($pdo),(string)$decoded['payload']['schema_fingerprint']))throw new RuntimeException('Database schema changed after restore preview. Validate the package again.');
    $rollback=backup_step18_create_backup($pdo,$passphrase,'pre_restore','Automatic rollback point before restore '.$job['job_code'],(int)$user['id']);
    $pdo->prepare("UPDATE backup_restore_jobs SET status='restoring',restore_started_at=NOW(),rollback_backup_id=? WHERE organization_id=? AND id=?")->execute([(int)$rollback['id'],$orgId,$jobId]);
    try{
        backup_step18_apply_payload($pdo,$decoded['payload']);
        if(business_table_exists($pdo,'security_sessions'))$pdo->exec("DELETE FROM security_sessions");if(business_table_exists($pdo,'security_login_attempts'))$pdo->exec("DELETE FROM security_login_attempts");
        $newOrg=(int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();if($newOrg<=0)throw new RuntimeException('Restored organization context is missing.');
        foreach(['backup_records','backup_restore_jobs','backup_policies','backup_verification_runs'] as $t)$pdo->exec('UPDATE '.backup_step18_qid($t).' SET organization_id='.(int)$newOrg.' WHERE organization_id='.(int)$orgId);
        $pdo->prepare("UPDATE backup_restore_jobs SET status='restored',validation_status='restored',restored_at=NOW(),error_message=NULL WHERE id=?")->execute([$jobId]);
        foreach([['backup_records','created_by'],['backup_restore_jobs','requested_by'],['backup_policies','updated_by'],['backup_verification_runs','verified_by']] as [$t,$c])$pdo->exec('UPDATE '.backup_step18_qid($t).' x LEFT JOIN system_users u ON u.id=x.'.backup_step18_qid($c).' SET x.'.backup_step18_qid($c).'=NULL WHERE x.'.backup_step18_qid($c).' IS NOT NULL AND u.id IS NULL');
        if(function_exists('security_step17_audit'))security_step17_audit($pdo,null,'database_restore_completed','backup_restore_job',$jobId,['rollback_backup_id'=>$rollback['id'],'restored_package_sha256'=>$job['package_sha256']]);
    }catch(Throwable $e){$pdo->prepare("UPDATE backup_restore_jobs SET status='failed',error_message=? WHERE id=?")->execute([substr($e->getMessage(),0,4000),$jobId]);throw $e;}
    return ['job_id'=>$jobId,'rollback_backup_id'=>(int)$rollback['id'],'restored'=>true];
}

function backup_step18_policy(PDO $pdo,int $orgId): array
{
    $stmt=$pdo->prepare("SELECT * FROM backup_policies WHERE organization_id=? AND policy_code='PRIMARY' LIMIT 1");$stmt->execute([$orgId]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Primary backup policy is missing.');return $r;
}

function backup_step18_update_policy(PDO $pdo,int $orgId,int $userId,array $input): void
{
    $enabled=!empty($input['is_enabled'])?1:0;$frequency=in_array(($input['frequency_code']??''),['daily','weekly'],true)?(string)$input['frequency_code']:'daily';$time=(string)($input['preferred_time']??'02:00');if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time))throw new RuntimeException('Choose a valid scheduled backup time.');
    $daily=max(1,min(90,(int)($input['retention_daily']??7)));$weekly=max(1,min(52,(int)($input['retention_weekly']??4)));$monthly=max(1,min(60,(int)($input['retention_monthly']??6)));$verified=!empty($input['require_verified_copy'])?1:0;$offsite=!empty($input['require_offsite_copy'])?1:0;
    $stmt=$pdo->prepare("UPDATE backup_policies SET is_enabled=?,frequency_code=?,preferred_time=?,retention_daily=?,retention_weekly=?,retention_monthly=?,require_verified_copy=?,require_offsite_copy=?,updated_by=? WHERE organization_id=? AND policy_code='PRIMARY'");$stmt->execute([$enabled,$frequency,$time.':00',$daily,$weekly,$monthly,$verified,$offsite,$userId,$orgId]);
    security_step17_audit($pdo,$userId,'backup_policy_updated','backup_policy',null,['enabled'=>$enabled,'frequency'=>$frequency,'preferred_time'=>$time,'retention'=>['daily'=>$daily,'weekly'=>$weekly,'monthly'=>$monthly],'require_verified'=>$verified,'require_offsite'=>$offsite]);
}

function backup_step18_scheduled_type(): string
{
    $day=(int)date('j');$weekday=(int)date('N');if($day===1)return 'scheduled_monthly';if($weekday===7)return 'scheduled_weekly';return 'scheduled_daily';
}

function backup_step18_cleanup_expired(PDO $pdo,int $orgId,?int $actorId=null): int
{
    $stmt=$pdo->prepare("SELECT id,storage_path FROM backup_records WHERE organization_id=? AND expires_at IS NOT NULL AND expires_at<NOW() AND status IN ('created','verified')");$stmt->execute([$orgId]);$count=0;foreach($stmt->fetchAll() as $r){$path=backup_step18_resolve_path((string)$r['storage_path']);if(is_file($path))@unlink($path);$pdo->prepare("UPDATE backup_records SET status='expired' WHERE organization_id=? AND id=?")->execute([$orgId,(int)$r['id']]);$count++;}if($count&&function_exists('security_step17_audit'))security_step17_audit($pdo,$actorId,'backup_retention_cleanup','backup_policy',null,['expired_records'=>$count]);return $count;
}

function backup_step18_runtime_checks(PDO $pdo): array
{
    $dir=backup_step18_storage_dir('backup');$stage=backup_step18_storage_dir('staging');$dbBytes=backup_step18_estimated_db_bytes($pdo);$limit=backup_step18_max_plaintext_bytes();
    $dumpCandidates=['C:\\xampp\\mysql\\bin\\mysqldump.exe','/usr/bin/mysqldump','/usr/local/bin/mysqldump'];$dump='';foreach($dumpCandidates as $c)if(is_file($c)){$dump=$c;break;}
    return ['openssl'=>extension_loaded('openssl'),'zlib'=>function_exists('gzencode')&&function_exists('gzdecode'),'storage_writable'=>is_writable($dir),'staging_writable'=>is_writable($stage),'db_bytes'=>$dbBytes,'portable_limit'=>$limit,'portable_size_ok'=>$dbBytes<$limit,'mysqldump'=>$dump,'https'=>(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||(int)($_SERVER['SERVER_PORT']??0)===443,'env_db_user'=>(string)(getenv('DB_USER')?:''),'env_db_pass_set'=>(getenv('DB_PASS')!==false&&getenv('DB_PASS')!=='')];
}
