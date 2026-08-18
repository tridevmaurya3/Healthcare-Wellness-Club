<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config/database.php';require_once dirname(__DIR__).'/config/backup_step18.php';require_once dirname(__DIR__).'/config/deployment_step19.php';
$task=strtolower(trim((string)($argv[1]??'health')));if(!in_array($task,['health','offsite'],true)){fwrite(STDERR,"Usage: php business/cli/deployment_step19.php [health|offsite]\n");exit(2);}
$pdo=business_db();deployment_step19_ensure($pdo);$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("INSERT INTO deployment_scheduler_runs(organization_id,task_code,environment_code,status) VALUES(?,?,?,'running')");$stmt->execute([$orgId,'step19_'.$task,deployment_step19_env()]);$runId=(int)$pdo->lastInsertId();
try{
    if($task==='health'){$r=deployment_step19_health($pdo,true);$status=$r['status']==='pass'?'pass':'review';$detail=$r['passed'].' pass / '.$r['review'].' review';if($status!=='pass')throw new RuntimeException('Health review: '.$detail);}
    else{$r=deployment_step19_offsite_copy_latest($pdo,null);$status='pass';$detail='Offsite copy verified for '.$r['backup_code'];}
    $pdo->prepare("UPDATE deployment_scheduler_runs SET status=?,details=?,completed_at=NOW() WHERE id=?")->execute([$status,$detail,$runId]);echo strtoupper($task)." PASS: {$detail}\n";exit(0);
}catch(Throwable $e){$pdo->prepare("UPDATE deployment_scheduler_runs SET status='failed',details=?,completed_at=NOW() WHERE id=?")->execute([substr($e->getMessage(),0,2000),$runId]);fwrite(STDERR,strtoupper($task).' FAILED: '.$e->getMessage()."\n");exit(1);}