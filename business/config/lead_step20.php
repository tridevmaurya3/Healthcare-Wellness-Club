<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const LEAD_STEP20_VERSION = '1.0-complete';

function lead_step20_tables(): array
{
    return ['crm_lead_sources','crm_leads','crm_lead_submissions','crm_lead_activities','crm_lead_tasks','crm_appointments','crm_lead_conversions'];
}

function lead_step20_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function lead_step20_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/016_step20_public_lead_crm.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 20 migration is missing.');
    $sql=(string)file_get_contents($file);
    foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [] as $statement) {
        $statement=trim($statement);
        if($statement==='') continue;
        $statement=preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement) ?? $statement;
        $statement=trim($statement);
        if($statement===''||preg_match('/^USE\s+/i',$statement)) continue;
        $pdo->exec($statement);
    }
}

function lead_step20_ensure(PDO $pdo): void
{
    foreach(lead_step20_tables() as $t){ if(!business_table_exists($pdo,$t)){ lead_step20_run_migration($pdo); break; } }
    foreach(lead_step20_tables() as $t){ if(!business_table_exists($pdo,$t)) throw new RuntimeException('STEP 20 table missing: '.$t); }
    $orgId=(int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if($orgId<=0) throw new RuntimeException('Healthcare Wellness Club organization is unavailable.');
    $s=$pdo->prepare("INSERT INTO crm_lead_sources(organization_id,source_code,source_name,channel,is_active) VALUES(?,'PUBLIC-WEB','Public Website','website',1) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),channel='website',is_active=1");
    $s->execute([$orgId]);
    if(business_table_exists($pdo,'schema_meta')){
        $s=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('lead_step20_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $s->execute([LEAD_STEP20_VERSION]);
    }
}

function lead_step20_context(PDO $pdo): array
{
    lead_step20_ensure($pdo);
    $r=$pdo->query("SELECT o.id organization_id,c.id club_id FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1")->fetch();
    if(!$r) throw new RuntimeException('Lead CRM context unavailable.');
    return ['organization_id'=>(int)$r['organization_id'],'club_id'=>$r['club_id']!==null?(int)$r['club_id']:null];
}

function lead_step20_source_id(PDO $pdo,int $orgId,string $code='PUBLIC-WEB'): int
{
    $s=$pdo->prepare("SELECT id FROM crm_lead_sources WHERE organization_id=? AND source_code=? AND is_active=1 LIMIT 1");$s->execute([$orgId,$code]);$id=(int)$s->fetchColumn();
    if($id<=0) throw new RuntimeException('Lead source is unavailable.');
    return $id;
}

function lead_step20_types(): array { return ['contact','wellness','join','appointment','product']; }
function lead_step20_stages(): array { return ['new','contacted','qualified','appointment','converting','converted','lost']; }
function lead_step20_priorities(): array { return ['low','normal','high','urgent']; }

function lead_step20_normalize_mobile(string $v): string
{
    $d=preg_replace('/\D+/','',$v) ?? '';
    if(strlen($d)>10 && str_starts_with($d,'91')) $d=substr($d,-10);
    return $d;
}
function lead_step20_normalize_email(string $v): string { return strtolower(trim($v)); }
function lead_step20_duplicate_hash(string $mobile,string $email): ?string
{
    $m=lead_step20_normalize_mobile($mobile);$e=lead_step20_normalize_email($email);
    if($m!=='') return hash('sha256','mobile|'.$m);
    if($e!=='') return hash('sha256','email|'.$e);
    return null;
}
function lead_step20_ip_hash(string $ip): string
{
    $pepper=(string)(getenv('HWC_PUBLIC_FORM_PEPPER')?:'hwc-public-form-local');
    return hash('sha256',$pepper.'|'.$ip);
}
function lead_step20_uuid(): string
{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function lead_step20_code(string $prefix='LEAD'): string { return $prefix.'-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6)); }

function lead_step20_audit(PDO $pdo,?int $userId,string $event,?int $leadId,array $detail=[]): void
{
    if(function_exists('security_step17_audit')) security_step17_audit($pdo,$userId,$event,'crm_lead',$leadId,$detail);
}

function lead_step20_public_origin_ok(array $server): bool
{
    $origin=trim((string)($server['HTTP_ORIGIN']??''));
    if($origin==='') return true;
    $originHost=strtolower((string)(parse_url($origin,PHP_URL_HOST)?:''));
    $host=strtolower(preg_replace('/:\d+$/','',(string)($server['HTTP_HOST']??''))??'');
    return $originHost!=='' && $host!=='' && hash_equals($host,$originHost);
}

function lead_step20_public_capture(PDO $pdo,array $input,array $server): array
{
    lead_step20_ensure($pdo);$ctx=lead_step20_context($pdo);$orgId=(int)$ctx['organization_id'];$sourceId=lead_step20_source_id($pdo,$orgId);
    if(!lead_step20_public_origin_ok($server)) throw new RuntimeException('Form origin is not allowed.');
    $type=strtolower(trim((string)($input['enquiry_type']??'contact')));if(!in_array($type,lead_step20_types(),true))$type='contact';
    $name=trim((string)($input['name']??''));$mobile=trim((string)($input['mobile']??''));$email=lead_step20_normalize_email((string)($input['email']??$input['_replyto']??''));$message=trim((string)($input['message']??''));
    $honeypot=trim((string)($input['website']??''));$consent=(string)($input['consent']??'')==='1';$page=substr(trim((string)($input['page_path']??'')),0,255);
    $ip=(string)($server['REMOTE_ADDR']??'');$ipHash=lead_step20_ip_hash($ip);$ua=substr((string)($server['HTTP_USER_AGENT']??''),0,500);$refHost=substr((string)(parse_url((string)($server['HTTP_REFERER']??''),PHP_URL_HOST)?:''),0,190);
    $started=(int)($input['started_at']??0);$tooFast=$started>0 && ((int)floor(microtime(true)*1000)-$started)<1800;
    $appointment=null;$date=trim((string)($input['appointment_date']??''));$time=trim((string)($input['appointment_time']??''));
    if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){$time=preg_match('/^\d{2}:\d{2}$/',$time)?$time:'09:00';$appointment=$date.' '.$time.':00';}
    if(strlen($name)<2||strlen($name)>190) throw new RuntimeException('Please enter a valid name.');
    $mobileNorm=lead_step20_normalize_mobile($mobile);if(strlen($mobileNorm)<10) throw new RuntimeException('Please enter a valid mobile number.');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
    if(strlen($message)>5000) throw new RuntimeException('Message is too long.');
    if(!$consent) throw new RuntimeException('Please allow the club to contact you about this enquiry.');
    $rate=$pdo->prepare("SELECT COUNT(*) FROM crm_lead_submissions WHERE organization_id=? AND ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");$rate->execute([$orgId,$ipHash]);if((int)$rate->fetchColumn()>=6) throw new RuntimeException('Too many recent submissions. Please try again later.');
    $uuid=lead_step20_uuid();
    if($honeypot!==''||$tooFast){
        $reason=$honeypot!==''?'honeypot':'too_fast';$s=$pdo->prepare("INSERT INTO crm_lead_submissions(organization_id,lead_id,source_id,submission_uuid,lead_type,full_name,mobile,email,message,requested_appointment_at,page_path,referrer_host,ip_hash,user_agent,spam_status,spam_reason) VALUES(?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,'blocked',?)");
        $s->execute([$orgId,$sourceId,$uuid,$type,$name,$mobileNorm?:null,$email?:null,$message?:null,$appointment,$page?:null,$refHost?:null,$ipHash,$ua?:null,$reason]);
        return ['ok'=>true,'message'=>'Thanks. Your request has been received.','lead_code'=>null,'duplicate'=>false];
    }
    $dup=lead_step20_duplicate_hash($mobile,$email);$leadId=0;$leadCode='';$isDuplicate=false;
    $pdo->beginTransaction();
    try{
        if($dup!==null){$s=$pdo->prepare("SELECT id,lead_code FROM crm_leads WHERE organization_id=? AND duplicate_key_hash=? AND status='active' AND stage NOT IN ('converted','lost') AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY id DESC LIMIT 1 FOR UPDATE");$s->execute([$orgId,$dup]);$r=$s->fetch();if($r){$leadId=(int)$r['id'];$leadCode=(string)$r['lead_code'];$isDuplicate=true;}}
        if($leadId<=0){
            $leadCode=lead_step20_code();$s=$pdo->prepare("INSERT INTO crm_leads(organization_id,lead_code,source_id,lead_type,full_name,mobile,email,message,stage,priority,consent_to_contact,requested_appointment_at,duplicate_key_hash,submission_count,first_seen_at,last_seen_at,status) VALUES(?,?,?,?,?,?,?,?, 'new','normal',1,?,?,1,NOW(),NOW(),'active')");
            $s->execute([$orgId,$leadCode,$sourceId,$type,$name,$mobileNorm?:null,$email?:null,$message?:null,$appointment,$dup]);$leadId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes) VALUES(?,?,'public_submission','Website enquiry received',?)")->execute([$orgId,$leadId,$message?:null]);
        }else{
            $s=$pdo->prepare("UPDATE crm_leads SET lead_type=?,full_name=?,mobile=COALESCE(NULLIF(?,''),mobile),email=COALESCE(NULLIF(?,''),email),message=CASE WHEN ?<>'' THEN ? ELSE message END,requested_appointment_at=COALESCE(?,requested_appointment_at),submission_count=submission_count+1,last_seen_at=NOW() WHERE id=? AND organization_id=?");
            $s->execute([$type,$name,$mobileNorm,$email,$message,$message,$appointment,$leadId,$orgId]);
            $pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes) VALUES(?,?,'repeat_submission','Repeat website enquiry received',?)")->execute([$orgId,$leadId,$message?:null]);
        }
        $s=$pdo->prepare("INSERT INTO crm_lead_submissions(organization_id,lead_id,source_id,submission_uuid,lead_type,full_name,mobile,email,message,requested_appointment_at,page_path,referrer_host,ip_hash,user_agent,spam_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'accepted')");
        $s->execute([$orgId,$leadId,$sourceId,$uuid,$type,$name,$mobileNorm?:null,$email?:null,$message?:null,$appointment,$page?:null,$refHost?:null,$ipHash,$ua?:null]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['ok'=>true,'message'=>'Thanks! Your enquiry has been saved. The club can now follow it up from the Business OS.','lead_code'=>$leadCode,'duplicate'=>$isDuplicate];
}

function lead_step20_metrics(PDO $pdo,int $orgId): array
{
    lead_step20_ensure($pdo);$m=[];
    foreach(['total'=>"COUNT(*)",'new'=>"SUM(stage='new' AND status='active')",'open'=>"SUM(stage NOT IN ('converted','lost') AND status='active')",'converted'=>"SUM(stage='converted')"] as $k=>$expr){$s=$pdo->prepare("SELECT COALESCE({$expr},0) FROM crm_leads WHERE organization_id=?");$s->execute([$orgId]);$m[$k]=(int)$s->fetchColumn();}
    $s=$pdo->prepare("SELECT COUNT(*) FROM crm_lead_tasks WHERE organization_id=? AND status='pending' AND due_at<NOW()");$s->execute([$orgId]);$m['overdue_tasks']=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM crm_appointments WHERE organization_id=? AND status='scheduled' AND start_at>=NOW() AND start_at<DATE_ADD(NOW(),INTERVAL 7 DAY)");$s->execute([$orgId]);$m['appointments_7d']=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM crm_lead_submissions WHERE organization_id=? AND spam_status='blocked'");$s->execute([$orgId]);$m['blocked']=$s->fetchColumn();
    return $m;
}

function lead_step20_rows(PDO $pdo,int $orgId,string $stage='all',string $q=''): array
{
    $where=['l.organization_id=?'];$args=[$orgId];if($stage!=='all'&&in_array($stage,lead_step20_stages(),true)){$where[]='l.stage=?';$args[]=$stage;}if(trim($q)!==''){$where[]='(l.lead_code LIKE ? OR l.full_name LIKE ? OR COALESCE(l.mobile,\'\') LIKE ? OR COALESCE(l.email,\'\') LIKE ?)';$like='%'.trim($q).'%';array_push($args,$like,$like,$like,$like);}
    $s=$pdo->prepare("SELECT l.*,s.source_name,u.full_name assigned_name,(SELECT COUNT(*) FROM crm_lead_tasks t WHERE t.lead_id=l.id AND t.status='pending') pending_tasks,(SELECT MIN(start_at) FROM crm_appointments a WHERE a.lead_id=l.id AND a.status='scheduled' AND a.start_at>=NOW()) next_appointment FROM crm_leads l LEFT JOIN crm_lead_sources s ON s.id=l.source_id LEFT JOIN system_users u ON u.id=l.assigned_user_id WHERE ".implode(' AND ',$where)." ORDER BY FIELD(l.priority,'urgent','high','normal','low'),l.last_seen_at DESC,l.id DESC LIMIT 500");$s->execute($args);return $s->fetchAll();
}

function lead_step20_get(PDO $pdo,int $orgId,int $leadId): array
{
    $s=$pdo->prepare("SELECT l.*,s.source_name,u.full_name assigned_name FROM crm_leads l LEFT JOIN crm_lead_sources s ON s.id=l.source_id LEFT JOIN system_users u ON u.id=l.assigned_user_id WHERE l.organization_id=? AND l.id=? LIMIT 1");$s->execute([$orgId,$leadId]);$r=$s->fetch();if(!$r)throw new RuntimeException('Lead not found.');return $r;
}

function lead_step20_update_stage(PDO $pdo,int $orgId,int $leadId,string $stage,string $priority,?int $userId,string $notes=''): void
{
    if(!in_array($stage,lead_step20_stages(),true)||!in_array($priority,lead_step20_priorities(),true))throw new RuntimeException('Invalid lead stage or priority.');$lead=lead_step20_get($pdo,$orgId,$leadId);$pdo->beginTransaction();try{$pdo->prepare("UPDATE crm_leads SET stage=?,priority=?,updated_at=NOW() WHERE organization_id=? AND id=?")->execute([$stage,$priority,$orgId,$leadId]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,old_stage,new_stage,actor_user_id) VALUES(?,?,'stage_change','Lead stage updated',?,?,?,?,?)")->execute([$orgId,$leadId,trim($notes)?:null,$lead['stage'],$stage,$userId]);$pdo->commit();lead_step20_audit($pdo,$userId,'lead_stage_changed',$leadId,['old'=>$lead['stage'],'new'=>$stage,'priority'=>$priority]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function lead_step20_assign(PDO $pdo,int $orgId,int $leadId,?int $assignedUserId,?int $actorId): void
{
    if($assignedUserId!==null){$s=$pdo->prepare("SELECT COUNT(*) FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? WHERE u.id=? AND u.is_active=1 AND a.is_active=1");$s->execute([$orgId,$assignedUserId]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Assigned user is not active in this organization.');}
    $pdo->prepare("UPDATE crm_leads SET assigned_user_id=? WHERE organization_id=? AND id=?")->execute([$assignedUserId,$orgId,$leadId]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,actor_user_id) VALUES(?,?,'assignment','Lead assignment changed',?,?)")->execute([$orgId,$leadId,$assignedUserId?'Assigned user #'.$assignedUserId:'Unassigned',$actorId]);lead_step20_audit($pdo,$actorId,'lead_assignment_changed',$leadId,['assigned_user_id'=>$assignedUserId]);
}

function lead_step20_add_task(PDO $pdo,int $orgId,int $leadId,string $type,string $subject,string $dueAt,?int $assigned,?int $actor,string $notes=''): int
{
    if(!in_array($type,['call','whatsapp','visit','email','note'],true))throw new RuntimeException('Invalid follow-up type.');if(trim($subject)===''||strtotime($dueAt)===false)throw new RuntimeException('Follow-up subject and valid due date are required.');
    $s=$pdo->prepare("INSERT INTO crm_lead_tasks(organization_id,lead_id,task_type,subject,due_at,assigned_user_id,status,notes,created_by) VALUES(?,?,?,?,?,?, 'pending',?,?)");$s->execute([$orgId,$leadId,$type,trim($subject),date('Y-m-d H:i:s',strtotime($dueAt)),$assigned,trim($notes)?:null,$actor]);$id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,actor_user_id) VALUES(?,?,'followup_created',?,?,?)")->execute([$orgId,$leadId,$subject,$notes?:null,$actor]);return $id;
}

function lead_step20_complete_task(PDO $pdo,int $orgId,int $taskId,?int $actor): void
{
    $s=$pdo->prepare("SELECT lead_id FROM crm_lead_tasks WHERE organization_id=? AND id=? AND status='pending' LIMIT 1");$s->execute([$orgId,$taskId]);$leadId=(int)$s->fetchColumn();if($leadId<=0)throw new RuntimeException('Pending follow-up not found.');$pdo->prepare("UPDATE crm_lead_tasks SET status='done',completed_at=NOW() WHERE organization_id=? AND id=?")->execute([$orgId,$taskId]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,actor_user_id) VALUES(?,?,'followup_done','Follow-up completed',?)")->execute([$orgId,$leadId,$actor]);
}

function lead_step20_create_appointment(PDO $pdo,int $orgId,int $leadId,string $startAt,string $mode,string $purpose,?int $assigned,?int $actor,string $notes=''): int
{
    if(strtotime($startAt)===false)throw new RuntimeException('Valid appointment date/time is required.');if(!in_array($mode,['club','phone','video','home'],true))$mode='club';$lead=lead_step20_get($pdo,$orgId,$leadId);$code=lead_step20_code('APT');$s=$pdo->prepare("INSERT INTO crm_appointments(organization_id,lead_id,customer_id,appointment_code,start_at,appointment_mode,purpose,status,notes,assigned_user_id,created_by) VALUES(?,?,?,?,?,?,?,'scheduled',?,?,?)");$s->execute([$orgId,$leadId,$lead['converted_customer_id']?:null,$code,date('Y-m-d H:i:s',strtotime($startAt)),$mode,trim($purpose)?:null,trim($notes)?:null,$assigned,$actor]);$id=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE crm_leads SET stage=IF(stage IN ('new','contacted','qualified'),'appointment',stage) WHERE organization_id=? AND id=?")->execute([$orgId,$leadId]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,actor_user_id) VALUES(?,?,'appointment','Appointment scheduled',?,?)")->execute([$orgId,$leadId,$code.' • '.$startAt,$actor]);return $id;
}

function lead_step20_appointment_status(PDO $pdo,int $orgId,int $id,string $status,?int $actor,string $notes=''): void
{
    if(!in_array($status,['scheduled','completed','cancelled','no_show','rescheduled'],true))throw new RuntimeException('Invalid appointment status.');$s=$pdo->prepare("SELECT lead_id FROM crm_appointments WHERE organization_id=? AND id=? LIMIT 1");$s->execute([$orgId,$id]);$leadId=(int)$s->fetchColumn();if($leadId<=0)throw new RuntimeException('Appointment not found.');$pdo->prepare("UPDATE crm_appointments SET status=?,notes=CASE WHEN ?<>'' THEN CONCAT(COALESCE(notes,''),IF(COALESCE(notes,'')<>'','\n',''),?) ELSE notes END WHERE organization_id=? AND id=?")->execute([$status,$notes,$notes,$orgId,$id]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,actor_user_id) VALUES(?,?,'appointment_status',?,?,?)")->execute([$orgId,$leadId,'Appointment '.$status,$notes?:null,$actor]);
}

function lead_step20_convert_customer(PDO $pdo,int $orgId,int $leadId,?int $existingCustomerId,?int $actor,string $notes=''): int
{
    $lead=lead_step20_get($pdo,$orgId,$leadId);$customerId=0;
    if($existingCustomerId){$s=$pdo->prepare("SELECT id FROM crm_customers WHERE organization_id=? AND id=? AND status='active' LIMIT 1");$s->execute([$orgId,$existingCustomerId]);$customerId=(int)$s->fetchColumn();if($customerId<=0)throw new RuntimeException('Existing customer was not found.');}
    else{
        $m=lead_step20_normalize_mobile((string)$lead['mobile']);$e=lead_step20_normalize_email((string)$lead['email']);$args=[$orgId];$clauses=[];if($m!==''){$clauses[]="REPLACE(REPLACE(REPLACE(COALESCE(mobile,''),'+',''),' ',''),'-','') LIKE ?";$args[]='%'.$m;}if($e!==''){$clauses[]='LOWER(COALESCE(email,\'\'))=?';$args[]=$e;}if($clauses){$s=$pdo->prepare("SELECT id,customer_name FROM crm_customers WHERE organization_id=? AND status='active' AND (".implode(' OR ',$clauses).") LIMIT 1");$s->execute($args);if($r=$s->fetch())throw new RuntimeException('A matching customer already exists (#'.$r['id'].' '.$r['customer_name'].'). Link that existing customer instead of creating a duplicate.');}
        $code=lead_step20_code('CUST');$s=$pdo->prepare("INSERT INTO crm_customers(organization_id,member_id,customer_code,customer_name,mobile,email,customer_type,notes,status) VALUES(?,NULL,?,?,?,?, 'retail',?,'active')");$s->execute([$orgId,$code,$lead['full_name'],$lead['mobile']?:null,$lead['email']?:null,'Created from '.$lead['lead_code'].($notes!==''?' • '.$notes:'')]);$customerId=(int)$pdo->lastInsertId();
    }
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE crm_leads SET converted_customer_id=?,stage='converted',status='active' WHERE organization_id=? AND id=?")->execute([$customerId,$orgId,$leadId]);$pdo->prepare("INSERT INTO crm_lead_conversions(organization_id,lead_id,conversion_type,customer_id,notes,converted_by) VALUES(?,?,'customer',?,?,?)")->execute([$orgId,$leadId,$customerId,trim($notes)?:null,$actor]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,old_stage,new_stage,actor_user_id) VALUES(?,?,'conversion','Converted to Customer',? ,?,'converted',?)")->execute([$orgId,$leadId,'Customer #'.$customerId,$lead['stage'],$actor]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return $customerId;
}

function lead_step20_link_member(PDO $pdo,int $orgId,int $leadId,int $memberId,?int $actor,string $notes=''): void
{
    $s=$pdo->prepare("SELECT id,full_name FROM members WHERE organization_id=? AND id=? AND COALESCE(source_sheet,'')<>? LIMIT 1");$rev=defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed';$s->execute([$orgId,$memberId,$rev]);$m=$s->fetch();if(!$m)throw new RuntimeException('Member not found.');$lead=lead_step20_get($pdo,$orgId,$leadId);$pdo->prepare("UPDATE crm_leads SET converted_member_id=?,stage='converted' WHERE organization_id=? AND id=?")->execute([$memberId,$orgId,$leadId]);$pdo->prepare("INSERT INTO crm_lead_conversions(organization_id,lead_id,conversion_type,member_id,notes,converted_by) VALUES(?,?,'member',?,?,?)")->execute([$orgId,$leadId,$memberId,trim($notes)?:null,$actor]);$pdo->prepare("INSERT INTO crm_lead_activities(organization_id,lead_id,activity_type,subject,notes,old_stage,new_stage,actor_user_id) VALUES(?,?,'conversion','Linked to existing Member',?,?, 'converted',?)")->execute([$orgId,$leadId,'Member #'.$memberId.' '.$m['full_name'],$lead['stage'],$actor]);
}

function lead_step20_whatsapp_url(string $mobile): string
{
    $d=preg_replace('/\D+/','',$mobile)??'';if(strlen($d)===10)$d='91'.$d;return $d!==''?'https://wa.me/'.$d:'#';
}
