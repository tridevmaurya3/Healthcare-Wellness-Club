<?php
declare(strict_types=1);
require_once __DIR__.'/public_site_cms.php';

function aia_ensure(PDO $pdo):void{
 static $done=false;if($done)return;
 $pdo->exec("CREATE TABLE IF NOT EXISTS public_ai_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,session_token CHAR(36) NOT NULL,event_type VARCHAR(40) NOT NULL,topic VARCHAR(80) NULL,page_path VARCHAR(300) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_ai_event_org_date(organization_id,created_at),KEY idx_ai_event_type(organization_id,event_type,created_at),CONSTRAINT fk_ai_event_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE IF NOT EXISTS public_ai_unanswered(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,session_token CHAR(36) NOT NULL,question_text VARCHAR(1000) NOT NULL,page_path VARCHAR(300) NULL,status VARCHAR(30) NOT NULL DEFAULT 'open',resolution_note TEXT NULL,resolved_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_ai_unanswered_status(organization_id,status,created_at),CONSTRAINT fk_ai_unanswered_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE) ENGINE=InnoDB");
 $done=true;
}
function aia_path(string $v):string{$v=substr(trim($v),0,300);return preg_match('~^(?:/|[a-z0-9_.?=&%/-]+)$~i',$v)?$v:'';}
function aia_metrics(PDO $pdo,int $orgId,int $days):array{
 aia_ensure($pdo);$days=max(1,min(365,$days));$since=date('Y-m-d H:i:s',time()-86400*$days);
 $s=$pdo->prepare("SELECT event_type,COUNT(*) total,COUNT(DISTINCT session_token) sessions FROM public_ai_events WHERE organization_id=? AND created_at>=? GROUP BY event_type");$s->execute([$orgId,$since]);$events=[];foreach($s->fetchAll() as $r)$events[(string)$r['event_type']=['total'=>(int)$r['total'],'sessions'=>(int)$r['sessions']];
 $s=$pdo->prepare("SELECT COUNT(*) total,SUM(status='open') open_count,SUM(status='resolved') resolved_count FROM public_ai_unanswered WHERE organization_id=? AND created_at>=?");$s->execute([$orgId,$since]);$u=$s->fetch()?:[];
 $s=$pdo->prepare("SELECT topic,COUNT(*) total FROM public_ai_events WHERE organization_id=? AND created_at>=? AND topic IS NOT NULL AND topic<>'' GROUP BY topic ORDER BY total DESC LIMIT 8");$s->execute([$orgId,$since]);
 return ['events'=>$events,'unanswered'=>['total'=>(int)($u['total']??0),'open'=>(int)($u['open_count']??0),'resolved'=>(int)($u['resolved_count']??0)],'topics'=>$s->fetchAll()];
}
