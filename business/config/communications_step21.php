<?php
declare(strict_types=1);

require_once __DIR__.'/lead_step20.php';

const COMM_STEP21_VERSION='1.0-complete';

function comm_step21_tables(): array
{
    return ['communication_templates','communication_events','communication_notifications','communication_outbox','communication_attempts','communication_preferences','communication_rules','communication_channel_settings','communication_scheduler_runs'];
}

function comm_step21_h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

function comm_step21_run_migration(PDO $pdo): void
{
    $file=dirname(__DIR__,2).'/database/migrations/017_step21_communications_notifications.sql';
    if(!is_file($file))throw new RuntimeException('STEP 21 migration is missing.');
    $sql=(string)file_get_contents($file);
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){
        $statement=trim($statement);if($statement==='')continue;
        $statement=preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement)??$statement;
        $statement=trim($statement);if($statement===''||preg_match('/^USE\s+/i',$statement))continue;
        $pdo->exec($statement);
    }
}

function comm_step21_context(PDO $pdo): array
{
    $r=$pdo->query("SELECT o.id organization_id,c.id club_id FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1")->fetch();
    if(!$r)throw new RuntimeException('Communication context unavailable.');
    return ['organization_id'=>(int)$r['organization_id'],'club_id'=>$r['club_id']!==null?(int)$r['club_id']:null];
}

function comm_step21_system_templates(): array
{
    return [
        ['INTERNAL_ALERT','in_app','Internal Alert','system',null,'{{message}}'],
        ['GENERAL_FOLLOWUP','email','General Follow-up','followup','Follow-up from Healthcare Wellness Club','Hello {{name}},\n\n{{message}}\n\nRegards,\nHealthcare Wellness Club'],
        ['GENERAL_FOLLOWUP','whatsapp','General Follow-up','followup',null,'Hello {{name}}, {{message}} — Healthcare Wellness Club'],
        ['GENERAL_FOLLOWUP','sms','General Follow-up','followup',null,'Healthcare Wellness Club: {{message}}'],
        ['APPOINTMENT_REMINDER','email','Appointment Reminder','appointment','Appointment reminder — Healthcare Wellness Club','Hello {{name}},\n\nThis is a reminder for your appointment on {{date}}. Please contact the club if you need to reschedule.'],
        ['APPOINTMENT_REMINDER','whatsapp','Appointment Reminder','appointment',null,'Hello {{name}}, reminder: your Healthcare Wellness Club appointment is on {{date}}. Please contact us if you need to reschedule.'],
        ['APPOINTMENT_REMINDER','sms','Appointment Reminder','appointment',null,'HWC appointment reminder: {{date}}. Contact the club if you need to reschedule.'],
        ['PAYMENT_REMINDER','email','Payment Reminder','finance','Payment reminder — Healthcare Wellness Club','Hello {{name}},\n\nOur records show an outstanding amount of {{amount}} for {{reference}}. Please contact the club if you need clarification.'],
        ['PAYMENT_REMINDER','whatsapp','Payment Reminder','finance',null,'Hello {{name}}, a payment of {{amount}} remains outstanding for {{reference}}. Please contact Healthcare Wellness Club for details.'],
        ['PAYMENT_REMINDER','sms','Payment Reminder','finance',null,'HWC: {{amount}} remains outstanding for {{reference}}. Please contact the club for details.'],
        ['ORDER_UPDATE','email','Order Update','order','Order update — Healthcare Wellness Club','Hello {{name}},\n\nUpdate for {{reference}}: {{message}}'],
        ['ORDER_UPDATE','whatsapp','Order Update','order',null,'Hello {{name}}, update for {{reference}}: {{message}}'],
    ];
}

function comm_step21_system_rules(): array
{
    return [
        ['LEAD_NEW','New Website Lead','lead.new','lead','normal','leads.view'],
        ['LEAD_FOLLOWUP_DUE','Lead Follow-up Due','lead.followup_due','lead','high','leads.view'],
        ['APPOINTMENT_UPCOMING','Appointment Upcoming','appointment.upcoming','appointment','normal','leads.view'],
        ['CUSTOMER_RECEIVABLE','Customer Receivable','customer.receivable','finance','high','customers.view'],
        ['DELIVERY_PENDING','Delivery Pending','delivery.pending','delivery','normal','customers.view'],
        ['PURCHASE_OVERDUE','Supplier Bill Overdue','purchase.overdue','purchase','high','purchases.view'],
        ['INVENTORY_LOW','Low Inventory','inventory.low','inventory','high','inventory.view'],
        ['INVENTORY_EXPIRY','Inventory Expiry','inventory.expiry','inventory','high','inventory.view'],
        ['BACKUP_STALE','Backup Recovery Point Stale','backup.stale','backup','critical','backup.view'],
    ];
}

function comm_step21_ensure(PDO $pdo): void
{
    foreach(comm_step21_tables() as $t){if(!business_table_exists($pdo,$t)){comm_step21_run_migration($pdo);break;}}
    foreach(comm_step21_tables() as $t){if(!business_table_exists($pdo,$t))throw new RuntimeException('STEP 21 table missing: '.$t);}
    $ctx=comm_step21_context($pdo);$orgId=(int)$ctx['organization_id'];
    $tpl=$pdo->prepare("INSERT INTO communication_templates(organization_id,template_code,channel,template_name,category,subject_template,body_template,is_system,is_active) VALUES(?,?,?,?,?,?,?,1,1) ON DUPLICATE KEY UPDATE template_name=VALUES(template_name),category=VALUES(category),subject_template=VALUES(subject_template),body_template=VALUES(body_template),is_active=1");
    foreach(comm_step21_system_templates() as $t)$tpl->execute([$orgId,$t[0],$t[1],$t[2],$t[3],$t[4],$t[5]]);
    $rule=$pdo->prepare("INSERT INTO communication_rules(organization_id,rule_code,rule_name,event_type,category,severity,audience_permission,in_app_enabled,email_enabled,whatsapp_enabled,sms_enabled,auto_external,is_active) VALUES(?,?,?,?,?,?,?,1,0,0,0,0,1) ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name),event_type=VALUES(event_type),category=VALUES(category),severity=VALUES(severity),audience_permission=VALUES(audience_permission),is_active=1");
    foreach(comm_step21_system_rules() as $r)$rule->execute([$orgId,$r[0],$r[1],$r[2],$r[3],$r[4],$r[5]]);
    $channels=[
        ['in_app','internal','Healthcare Wellness Club',null,null,1],
        ['email','disabled','Healthcare Wellness Club','HWC_COMM_EMAIL_WEBHOOK_URL','HWC_COMM_EMAIL_WEBHOOK_TOKEN',0],
        ['whatsapp','disabled','Healthcare Wellness Club','HWC_COMM_WHATSAPP_WEBHOOK_URL','HWC_COMM_WHATSAPP_WEBHOOK_TOKEN',0],
        ['sms','disabled','Healthcare Wellness Club','HWC_COMM_SMS_WEBHOOK_URL','HWC_COMM_SMS_WEBHOOK_TOKEN',0],
    ];
    $ch=$pdo->prepare("INSERT INTO communication_channel_settings(organization_id,channel,provider_mode,sender_label,webhook_url_env,webhook_token_env,is_enabled) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE channel=VALUES(channel)");
    foreach($channels as $c)$ch->execute([$orgId,$c[0],$c[1],$c[2],$c[3],$c[4],$c[5]]);
    $pref=$pdo->prepare("INSERT IGNORE INTO communication_preferences(organization_id,user_id) SELECT ?,u.id FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 WHERE u.is_active=1");$pref->execute([$orgId,$orgId]);
    if(business_table_exists($pdo,'schema_meta')){$s=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('communications_step21_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$s->execute([COMM_STEP21_VERSION]);}
}

function comm_step21_rule(PDO $pdo,int $orgId,string $ruleCode): ?array
{
    $s=$pdo->prepare("SELECT * FROM communication_rules WHERE organization_id=? AND rule_code=? AND is_active=1 LIMIT 1");$s->execute([$orgId,$ruleCode]);$r=$s->fetch();return $r?:null;
}

function comm_step21_user_pref(PDO $pdo,int $orgId,int $userId): array
{
    $s=$pdo->prepare("SELECT * FROM communication_preferences WHERE organization_id=? AND user_id=? LIMIT 1");$s->execute([$orgId,$userId]);$r=$s->fetch();
    if($r)return $r;
    $pdo->prepare("INSERT INTO communication_preferences(organization_id,user_id) VALUES(?,?)")->execute([$orgId,$userId]);
    $s->execute([$orgId,$userId]);return $s->fetch()?:[];
}

function comm_step21_active_users(PDO $pdo,int $orgId,string $permission): array
{
    $s=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile,a.role_code FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 WHERE u.is_active=1 ORDER BY u.id");$s->execute([$orgId]);$out=[];
    foreach($s->fetchAll() as $u){if(security_step17_has_permission($pdo,$permission,$u))$out[]=$u;}
    return $out;
}

function comm_step21_upsert_event(PDO $pdo,int $orgId,string $eventKey,string $eventType,string $ruleCode,string $entityType,?int $entityId,string $severity,string $title,string $body,string $actionUrl,array $metadata=[]): int
{
    $s=$pdo->prepare("INSERT INTO communication_events(organization_id,event_key,event_type,rule_code,entity_type,entity_id,severity,title,body,action_url,metadata_json,event_status,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'open',NOW()) ON DUPLICATE KEY UPDATE event_type=VALUES(event_type),rule_code=VALUES(rule_code),entity_type=VALUES(entity_type),entity_id=VALUES(entity_id),severity=VALUES(severity),title=VALUES(title),body=VALUES(body),action_url=VALUES(action_url),metadata_json=VALUES(metadata_json),event_status='open',resolved_at=NULL");
    $s->execute([$orgId,$eventKey,$eventType,$ruleCode,$entityType,$entityId,$severity,$title,$body,$actionUrl,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $s=$pdo->prepare("SELECT id FROM communication_events WHERE organization_id=? AND event_key=? LIMIT 1");$s->execute([$orgId,$eventKey]);$eventId=(int)$s->fetchColumn();
    $rule=comm_step21_rule($pdo,$orgId,$ruleCode);
    if($eventId>0&&$rule&&(int)$rule['in_app_enabled']===1){
        foreach(comm_step21_active_users($pdo,$orgId,(string)$rule['audience_permission']) as $u){
            $pref=comm_step21_user_pref($pdo,$orgId,(int)$u['id']);
            if((int)($pref['in_app_enabled']??1)!==1&&$severity!=='critical')continue;
            $n=$pdo->prepare("INSERT INTO communication_notifications(organization_id,event_id,user_id,title,body,action_url,priority,status) VALUES(?,?,?,?,?,?,?,'unread') ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),action_url=VALUES(action_url),priority=VALUES(priority)");
            $n->execute([$orgId,$eventId,(int)$u['id'],$title,$body,$actionUrl,$severity]);
        }
    }
    return $eventId;
}

function comm_step21_resolve_missing(PDO $pdo,int $orgId,string $eventType,array $activeKeys): void
{
    $args=[$orgId,$eventType];$sql="SELECT id FROM communication_events WHERE organization_id=? AND event_type=? AND event_status='open'";
    if($activeKeys){$sql.=' AND event_key NOT IN ('.implode(',',array_fill(0,count($activeKeys),'?')).')';$args=array_merge($args,$activeKeys);}
    $s=$pdo->prepare($sql);$s->execute($args);$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    if(!$ids)return;
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("UPDATE communication_events SET event_status='resolved',resolved_at=NOW() WHERE id IN ($ph)")->execute($ids);
    $pdo->prepare("UPDATE communication_notifications SET status=IF(status='unread','read',status),read_at=IF(status='unread',NOW(),read_at) WHERE event_id IN ($ph)")->execute($ids);
}

function comm_step21_sync_events(PDO $pdo,int $orgId): int
{
    comm_step21_ensure($pdo);$count=0;
    $sync=function(string $eventType,string $ruleCode,array $rows,callable $builder)use($pdo,$orgId,&$count):void{
        $keys=[];foreach($rows as $r){$d=$builder($r);$keys[]=$d['key'];comm_step21_upsert_event($pdo,$orgId,$d['key'],$eventType,$ruleCode,$d['entity_type'],$d['entity_id'],$d['severity'],$d['title'],$d['body'],$d['url'],$d['meta']??[]);$count++;}comm_step21_resolve_missing($pdo,$orgId,$eventType,$keys);
    };

    if(business_table_exists($pdo,'crm_leads')){
        $s=$pdo->prepare("SELECT id,lead_code,full_name,lead_type,created_at FROM crm_leads WHERE organization_id=? AND status='active' AND stage='new'");$s->execute([$orgId]);
        $sync('lead.new','LEAD_NEW',$s->fetchAll(),fn($r)=>['key'=>'lead.new:'.$r['id'],'entity_type'=>'lead','entity_id'=>(int)$r['id'],'severity'=>'normal','title'=>'New website lead • '.$r['lead_code'],'body'=>$r['full_name'].' submitted a '.str_replace('_',' ',(string)$r['lead_type']).' enquiry.','url'=>'lead_detail.php?id='.(int)$r['id'],'meta'=>['lead_code'=>$r['lead_code']]]);
    }
    if(business_table_exists($pdo,'crm_lead_tasks')){
        $s=$pdo->prepare("SELECT t.id,t.lead_id,t.subject,t.due_at,l.lead_code,l.full_name FROM crm_lead_tasks t JOIN crm_leads l ON l.id=t.lead_id AND l.organization_id=t.organization_id WHERE t.organization_id=? AND t.status='pending' AND t.due_at<=NOW()");$s->execute([$orgId]);
        $sync('lead.followup_due','LEAD_FOLLOWUP_DUE',$s->fetchAll(),fn($r)=>['key'=>'lead.followup_due:'.$r['id'],'entity_type'=>'lead_task','entity_id'=>(int)$r['id'],'severity'=>'high','title'=>'Lead follow-up overdue • '.$r['lead_code'],'body'=>$r['subject'].' for '.$r['full_name'].' was due '.$r['due_at'].'.','url'=>'lead_detail.php?id='.(int)$r['lead_id']]);
    }
    if(business_table_exists($pdo,'crm_appointments')){
        $s=$pdo->prepare("SELECT a.id,a.lead_id,a.appointment_code,a.start_at,l.full_name FROM crm_appointments a JOIN crm_leads l ON l.id=a.lead_id AND l.organization_id=a.organization_id WHERE a.organization_id=? AND a.status='scheduled' AND a.start_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR)");$s->execute([$orgId]);
        $sync('appointment.upcoming','APPOINTMENT_UPCOMING',$s->fetchAll(),fn($r)=>['key'=>'appointment.upcoming:'.$r['id'],'entity_type'=>'appointment','entity_id'=>(int)$r['id'],'severity'=>'normal','title'=>'Appointment in next 24 hours • '.$r['appointment_code'],'body'=>$r['full_name'].' • '.$r['start_at'],'url'=>'lead_detail.php?id='.(int)$r['lead_id']]);
    }
    if(business_table_exists($pdo,'sales_fulfillment_ledger')){
        $s=$pdo->prepare("SELECT f.order_id,f.customer_id,f.receivable_amount,COALESCE(c.customer_name,l.customer_name_snapshot,'Customer') customer_name FROM sales_fulfillment_ledger f LEFT JOIN crm_customers c ON c.id=f.customer_id LEFT JOIN sales_customer_links l ON l.organization_id=f.organization_id AND l.order_id=f.order_id WHERE f.organization_id=? AND f.receivable_amount>0.009");$s->execute([$orgId]);
        $sync('customer.receivable','CUSTOMER_RECEIVABLE',$s->fetchAll(),fn($r)=>['key'=>'customer.receivable:'.$r['order_id'],'entity_type'=>'order','entity_id'=>(int)$r['order_id'],'severity'=>'high','title'=>'Customer receivable pending • Order #'.$r['order_id'],'body'=>$r['customer_name'].' • ₹'.number_format((float)$r['receivable_amount'],2).' outstanding.','url'=>'sales_receivables.php']);
    }
    if(business_table_exists($pdo,'sales_deliveries')){
        $s=$pdo->prepare("SELECT d.id,d.order_id,d.dispatch_number,d.dispatch_date,COALESCE(c.customer_name,l.customer_name_snapshot,'Customer') customer_name FROM sales_deliveries d LEFT JOIN crm_customers c ON c.id=d.customer_id LEFT JOIN sales_customer_links l ON l.organization_id=d.organization_id AND l.order_id=d.order_id WHERE d.organization_id=? AND d.status NOT IN ('delivered','cancelled') AND d.dispatch_date<=DATE_SUB(CURDATE(),INTERVAL 2 DAY)");$s->execute([$orgId]);
        $sync('delivery.pending','DELIVERY_PENDING',$s->fetchAll(),fn($r)=>['key'=>'delivery.pending:'.$r['id'],'entity_type'=>'delivery','entity_id'=>(int)$r['id'],'severity'=>'normal','title'=>'Delivery still pending • '.$r['dispatch_number'],'body'=>$r['customer_name'].' • dispatched '.$r['dispatch_date'].'.','url'=>'sales_delivery.php']);
    }
    if(business_table_exists($pdo,'purchase_bills')){
        $s=$pdo->prepare("SELECT b.id,b.invoice_number,b.due_date,b.total_amount,b.return_credit,b.paid_amount,s.supplier_name FROM purchase_bills b JOIN purchase_suppliers s ON s.id=b.supplier_id WHERE b.organization_id=? AND b.status='active' AND b.payment_status<>'paid' AND b.due_date IS NOT NULL AND b.due_date<CURDATE()");$s->execute([$orgId]);
        $sync('purchase.overdue','PURCHASE_OVERDUE',$s->fetchAll(),function($r){$out=max(0,(float)$r['total_amount']-(float)$r['return_credit']-(float)$r['paid_amount']);return ['key'=>'purchase.overdue:'.$r['id'],'entity_type'=>'purchase_bill','entity_id'=>(int)$r['id'],'severity'=>'high','title'=>'Supplier bill overdue • '.$r['invoice_number'],'body'=>$r['supplier_name'].' • ₹'.number_format($out,2).' outstanding since '.$r['due_date'].'.','url'=>'supplier_payments.php'];});
    }
    if(business_table_exists($pdo,'inventory_product_settings')&&business_table_exists($pdo,'inventory_batches')){
        $s=$pdo->prepare("SELECT ps.id setting_id,ps.product_id,ps.listing_id,ps.location_id,ps.reorder_level,p.product_name,COALESCE(SUM(CASE WHEN b.status='active' THEN b.current_quantity ELSE 0 END),0) current_qty FROM inventory_product_settings ps JOIN products p ON p.id=ps.product_id LEFT JOIN inventory_batches b ON b.organization_id=ps.organization_id AND b.location_id=ps.location_id AND b.listing_id=ps.listing_id WHERE ps.organization_id=? AND ps.track_stock=1 AND ps.reorder_level>0 GROUP BY ps.id,ps.product_id,ps.listing_id,ps.location_id,ps.reorder_level,p.product_name HAVING current_qty<=ps.reorder_level");$s->execute([$orgId]);
        $sync('inventory.low','INVENTORY_LOW',$s->fetchAll(),fn($r)=>['key'=>'inventory.low:'.$r['setting_id'],'entity_type'=>'inventory_setting','entity_id'=>(int)$r['setting_id'],'severity'=>'high','title'=>'Low stock • '.$r['product_name'],'body'=>'Current '.rtrim(rtrim(number_format((float)$r['current_qty'],3,'.',''),'0'),'.').' • reorder level '.rtrim(rtrim(number_format((float)$r['reorder_level'],3,'.',''),'0'),'.').'.','url'=>'inventory_center.php']);
        $s=$pdo->prepare("SELECT b.id,b.product_id,b.batch_code,b.expiry_date,b.current_quantity,p.product_name,COALESCE(ps.expiry_alert_days,60) alert_days FROM inventory_batches b JOIN products p ON p.id=b.product_id LEFT JOIN inventory_product_settings ps ON ps.organization_id=b.organization_id AND ps.location_id=b.location_id AND ps.listing_id=b.listing_id WHERE b.organization_id=? AND b.status='active' AND b.current_quantity>0 AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL COALESCE(ps.expiry_alert_days,60) DAY)");$s->execute([$orgId]);
        $sync('inventory.expiry','INVENTORY_EXPIRY',$s->fetchAll(),fn($r)=>['key'=>'inventory.expiry:'.$r['id'],'entity_type'=>'inventory_batch','entity_id'=>(int)$r['id'],'severity'=>'high','title'=>'Batch nearing expiry • '.$r['product_name'],'body'=>'Batch '.$r['batch_code'].' • expires '.$r['expiry_date'].' • quantity '.$r['current_quantity'].'.','url'=>'inventory_center.php']);
    }
    if(business_table_exists($pdo,'backup_policies')){
        $s=$pdo->prepare("SELECT * FROM backup_policies WHERE organization_id=? AND policy_code='PRIMARY' AND is_enabled=1 LIMIT 1");$s->execute([$orgId]);$p=$s->fetch();$rows=[];
        if($p){$seconds=match((string)$p['frequency_code']){'weekly'=>10*86400,'monthly'=>40*86400,default=>2*86400};$last=$p['last_success_at']?strtotime((string)$p['last_success_at']):false;if($last===false||$last<time()-$seconds)$rows[]=$p;}
        $sync('backup.stale','BACKUP_STALE',$rows,fn($r)=>['key'=>'backup.stale:PRIMARY','entity_type'=>'backup_policy','entity_id'=>(int)$r['id'],'severity'=>'critical','title'=>'Verified recovery point is overdue','body'=>'Primary backup policy has no recent successful recovery point.','url'=>'backup_center.php']);
    }
    return $count;
}

function comm_step21_metrics(PDO $pdo,int $orgId,?int $userId=null): array
{
    $scalar=function(string $sql,array $args=[])use($pdo):int{$s=$pdo->prepare($sql);$s->execute($args);return(int)$s->fetchColumn();};
    return [
        'open_events'=>$scalar("SELECT COUNT(*) FROM communication_events WHERE organization_id=? AND event_status='open'",[$orgId]),
        'critical_events'=>$scalar("SELECT COUNT(*) FROM communication_events WHERE organization_id=? AND event_status='open' AND severity='critical'",[$orgId]),
        'unread'=>$userId?$scalar("SELECT COUNT(*) FROM communication_notifications WHERE organization_id=? AND user_id=? AND status='unread'",[$orgId,$userId]):0,
        'outbox_waiting'=>$scalar("SELECT COUNT(*) FROM communication_outbox WHERE organization_id=? AND status IN ('queued','waiting_provider','manual_ready')",[$orgId]),
        'failed'=>$scalar("SELECT COUNT(*) FROM communication_outbox WHERE organization_id=? AND status='failed'",[$orgId]),
        'sent_30d'=>$scalar("SELECT COUNT(*) FROM communication_outbox WHERE organization_id=? AND status='sent' AND sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",[$orgId]),
    ];
}

function comm_step21_mark_notification(PDO $pdo,int $orgId,int $userId,int $id,string $action): void
{
    if($action==='read')$pdo->prepare("UPDATE communication_notifications SET status='read',read_at=COALESCE(read_at,NOW()) WHERE organization_id=? AND user_id=? AND id=?")->execute([$orgId,$userId,$id]);
    elseif($action==='unread')$pdo->prepare("UPDATE communication_notifications SET status='unread',read_at=NULL,dismissed_at=NULL WHERE organization_id=? AND user_id=? AND id=?")->execute([$orgId,$userId,$id]);
    elseif($action==='dismiss')$pdo->prepare("UPDATE communication_notifications SET status='dismissed',dismissed_at=NOW() WHERE organization_id=? AND user_id=? AND id=?")->execute([$orgId,$userId,$id]);
}

function comm_step21_save_preferences(PDO $pdo,int $orgId,int $userId,array $d): void
{
    $freq=in_array(($d['digest_frequency']??''),['instant','daily','weekly'],true)?$d['digest_frequency']:'instant';
    $tz=trim((string)($d['timezone_name']??'Asia/Kolkata'));if($tz===''||strlen($tz)>80)$tz='Asia/Kolkata';
    $time=function(mixed $v):?string{$v=trim((string)$v);return preg_match('/^\d{2}:\d{2}$/',$v)?$v.':00':null;};
    $s=$pdo->prepare("INSERT INTO communication_preferences(organization_id,user_id,in_app_enabled,email_enabled,whatsapp_enabled,sms_enabled,quiet_start,quiet_end,timezone_name,digest_frequency) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE in_app_enabled=VALUES(in_app_enabled),email_enabled=VALUES(email_enabled),whatsapp_enabled=VALUES(whatsapp_enabled),sms_enabled=VALUES(sms_enabled),quiet_start=VALUES(quiet_start),quiet_end=VALUES(quiet_end),timezone_name=VALUES(timezone_name),digest_frequency=VALUES(digest_frequency)");
    $s->execute([$orgId,$userId,!empty($d['in_app_enabled'])?1:0,!empty($d['email_enabled'])?1:0,!empty($d['whatsapp_enabled'])?1:0,!empty($d['sms_enabled'])?1:0,$time($d['quiet_start']??''),$time($d['quiet_end']??''),$tz,$freq]);
}

function comm_step21_channel_setting(PDO $pdo,int $orgId,string $channel): array
{
    $s=$pdo->prepare("SELECT * FROM communication_channel_settings WHERE organization_id=? AND channel=? LIMIT 1");$s->execute([$orgId,$channel]);return $s->fetch()?:[];
}

function comm_step21_channel_runtime(array $setting): array
{
    $urlEnv=(string)($setting['webhook_url_env']??'');$tokenEnv=(string)($setting['webhook_token_env']??'');
    $url=$urlEnv!==''?(string)(getenv($urlEnv)?:''):'';$token=$tokenEnv!==''?(string)(getenv($tokenEnv)?:''):'';
    return ['url_configured'=>$url!=='','token_configured'=>$token!=='','url'=>$url,'token'=>$token];
}

function comm_step21_save_channel(PDO $pdo,int $orgId,string $channel,string $mode,string $sender,?int $actor): void
{
    if(!in_array($channel,['email','whatsapp','sms'],true))throw new RuntimeException('Invalid external channel.');
    if(!in_array($mode,['disabled','manual','webhook'],true))throw new RuntimeException('Invalid provider mode.');
    $s=$pdo->prepare("UPDATE communication_channel_settings SET provider_mode=?,sender_label=?,is_enabled=?,updated_by=? WHERE organization_id=? AND channel=?");$s->execute([$mode,trim($sender)?:'Healthcare Wellness Club',$mode==='disabled'?0:1,$actor,$orgId,$channel]);
}

function comm_step21_recipient(PDO $pdo,int $orgId,string $type,int $id,string $channel): array
{
    $map=[
        'lead'=>["SELECT full_name name,mobile,email FROM crm_leads WHERE organization_id=? AND id=? LIMIT 1",'full_name'],
        'customer'=>["SELECT customer_name name,mobile,email FROM crm_customers WHERE organization_id=? AND id=? AND status='active' LIMIT 1",'customer_name'],
        'supplier'=>["SELECT supplier_name name,mobile,email FROM purchase_suppliers WHERE organization_id=? AND id=? AND status='active' LIMIT 1",'supplier_name'],
        'user'=>["SELECT u.full_name name,u.mobile,u.email FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 WHERE u.id=? AND u.is_active=1 LIMIT 1",'full_name'],
    ];
    if(!isset($map[$type]))throw new RuntimeException('Recipient type is invalid.');$s=$pdo->prepare($map[$type][0]);$s->execute([$orgId,$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('Recipient was not found.');
    $address=$channel==='email'?trim((string)($r['email']??'')):preg_replace('/\D+/','',(string)($r['mobile']??''));
    if($channel==='email'&&!filter_var($address,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Recipient does not have a valid email address.');
    if($channel!=='email'){if(strlen($address)===10)$address='91'.$address;if(strlen($address)<10)throw new RuntimeException('Recipient does not have a valid mobile number.');}
    return ['type'=>$type,'id'=>$id,'name'=>(string)$r['name'],'address'=>$address];
}

function comm_step21_render(string $text,array $vars): string
{
    $replace=[];foreach($vars as $k=>$v)$replace['{{'.$k.'}}']=(string)$v;return strtr($text,$replace);
}

function comm_step21_template(PDO $pdo,int $orgId,string $code,string $channel): array
{
    $s=$pdo->prepare("SELECT * FROM communication_templates WHERE organization_id=? AND template_code=? AND channel=? AND is_active=1 LIMIT 1");$s->execute([$orgId,$code,$channel]);$r=$s->fetch();if(!$r)throw new RuntimeException('Communication template was not found for this channel.');return $r;
}

function comm_step21_queue(PDO $pdo,int $orgId,string $recipientType,int $recipientId,string $channel,string $templateCode,array $vars,?int $actor,?string $scheduledAt=null,?int $eventId=null,?string $idempotencySeed=null): int
{
    if(!in_array($channel,['email','whatsapp','sms'],true))throw new RuntimeException('External channel is invalid.');$recipient=comm_step21_recipient($pdo,$orgId,$recipientType,$recipientId,$channel);$tpl=comm_step21_template($pdo,$orgId,$templateCode,$channel);$setting=comm_step21_channel_setting($pdo,$orgId,$channel);$mode=(string)($setting['provider_mode']??'disabled');$enabled=(int)($setting['is_enabled']??0)===1;
    $subject=$tpl['subject_template']!==null?comm_step21_render((string)$tpl['subject_template'],$vars+['name'=>$recipient['name']]):null;$body=comm_step21_render((string)$tpl['body_template'],$vars+['name'=>$recipient['name']]);
    $status='waiting_provider';if($enabled&&$mode==='manual')$status='manual_ready';elseif($enabled&&$mode==='webhook'){$rt=comm_step21_channel_runtime($setting);$status=$rt['url_configured']?'queued':'waiting_provider';}
    $when=$scheduledAt&&strtotime($scheduledAt)!==false?date('Y-m-d H:i:s',strtotime($scheduledAt)):date('Y-m-d H:i:s');
    $seed=$idempotencySeed??bin2hex(random_bytes(16));$idem=hash('sha256',implode('|',[$channel,$recipientType,$recipientId,$templateCode,$seed]));
    $s=$pdo->prepare("INSERT INTO communication_outbox(organization_id,event_id,template_id,idempotency_key,channel,recipient_type,recipient_id,recipient_name_snapshot,recipient_address_snapshot,subject_snapshot,body_snapshot,status,provider_mode,scheduled_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$s->execute([$orgId,$eventId,(int)$tpl['id'],$idem,$channel,$recipientType,$recipientId,$recipient['name'],$recipient['address'],$subject,$body,$status,$mode,$when,$actor]);return(int)$pdo->lastInsertId();
}

function comm_step21_manual_url(array $row): string
{
    $channel=(string)$row['channel'];$addr=(string)$row['recipient_address_snapshot'];$body=(string)$row['body_snapshot'];$subject=(string)($row['subject_snapshot']??'');
    if($channel==='whatsapp')return 'https://wa.me/'.preg_replace('/\D+/','',$addr).'?text='.rawurlencode($body);
    if($channel==='email')return 'mailto:'.$addr.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    if($channel==='sms')return 'sms:'.$addr.'?body='.rawurlencode($body);
    return '#';
}

function comm_step21_outbox_action(PDO $pdo,int $orgId,int $id,string $action,?int $actor): void
{
    $s=$pdo->prepare("SELECT * FROM communication_outbox WHERE organization_id=? AND id=? LIMIT 1");$s->execute([$orgId,$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('Outbox item not found.');
    if($action==='mark_sent'){if((string)$r['status']!=='manual_ready')throw new RuntimeException('Only manual-ready messages can be marked sent.');$pdo->prepare("UPDATE communication_outbox SET status='sent',sent_at=NOW(),last_error=NULL WHERE organization_id=? AND id=?")->execute([$orgId,$id]);}
    elseif($action==='retry'){if(!in_array((string)$r['status'],['failed','waiting_provider'],true))throw new RuntimeException('Only failed/provider-waiting messages can be retried.');$setting=comm_step21_channel_setting($pdo,$orgId,(string)$r['channel']);$mode=(string)($setting['provider_mode']??'disabled');$status=$mode==='manual'?'manual_ready':($mode==='webhook'?'queued':'waiting_provider');$pdo->prepare("UPDATE communication_outbox SET status=?,provider_mode=?,last_error=NULL WHERE organization_id=? AND id=?")->execute([$status,$mode,$orgId,$id]);}
    elseif($action==='cancel'){if((string)$r['status']==='sent')throw new RuntimeException('Sent messages cannot be cancelled.');$pdo->prepare("UPDATE communication_outbox SET status='cancelled' WHERE organization_id=? AND id=?")->execute([$orgId,$id]);}
    else throw new RuntimeException('Invalid outbox action.');
    if(function_exists('security_step17_audit'))security_step17_audit($pdo,$actor,'communication_outbox_'.$action,'communication_outbox',$id,['channel'=>$r['channel'],'recipient_type'=>$r['recipient_type']]);
}

function comm_step21_dispatch(PDO $pdo,int $orgId,int $limit=25): array
{
    $limit=max(1,min(100,$limit));$s=$pdo->prepare("SELECT * FROM communication_outbox WHERE organization_id=? AND status='queued' AND scheduled_at<=NOW() AND attempts<max_attempts ORDER BY scheduled_at,id LIMIT $limit");$s->execute([$orgId]);$done=0;$failed=0;
    foreach($s->fetchAll() as $r){$setting=comm_step21_channel_setting($pdo,$orgId,(string)$r['channel']);$rt=comm_step21_channel_runtime($setting);$attempt=(int)$r['attempts']+1;$outcome='failed';$code=null;$excerpt=null;$err=null;
        try{
            if((string)($setting['provider_mode']??'')!=='webhook'||(int)($setting['is_enabled']??0)!==1)throw new RuntimeException('Webhook provider mode is not enabled.');
            if(!$rt['url_configured'])throw new RuntimeException('Webhook URL environment variable is not configured.');if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is not available.');
            $payload=['channel'=>$r['channel'],'recipient'=>$r['recipient_address_snapshot'],'recipient_name'=>$r['recipient_name_snapshot'],'subject'=>$r['subject_snapshot'],'body'=>$r['body_snapshot'],'idempotency_key'=>$r['idempotency_key'],'sender_label'=>$setting['sender_label']];
            $ch=curl_init($rt['url']);$headers=['Content-Type: application/json','Accept: application/json'];if($rt['token_configured'])$headers[]='Authorization: Bearer '.$rt['token'];curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$resp=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlErr=curl_error($ch);curl_close($ch);$code=(string)$http;$excerpt=substr((string)$resp,0,1000);
            if($resp===false||$curlErr!=='')throw new RuntimeException('Webhook transport failed: '.$curlErr);if($http<200||$http>=300)throw new RuntimeException('Webhook returned HTTP '.$http.'.');$outcome='sent';
        }catch(Throwable $e){$err=$e->getMessage();$excerpt=$excerpt?:substr($err,0,1000);}
        $pdo->prepare("INSERT INTO communication_attempts(organization_id,outbox_id,attempt_no,outcome,response_code,response_excerpt) VALUES(?,?,?,?,?,?)")->execute([$orgId,(int)$r['id'],$attempt,$outcome,$code,$excerpt]);
        if($outcome==='sent'){$pdo->prepare("UPDATE communication_outbox SET status='sent',attempts=?,sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$attempt,(int)$r['id']]);$done++;}
        else{$next=$attempt>=(int)$r['max_attempts']?'failed':'queued';$pdo->prepare("UPDATE communication_outbox SET status=?,attempts=?,last_error=? WHERE id=?")->execute([$next,$attempt,$err,(int)$r['id']]);$failed++;}
    }
    return ['sent'=>$done,'failed'=>$failed,'processed'=>$done+$failed];
}

function comm_step21_scheduler_run(PDO $pdo,int $orgId,bool $dispatch=true): array
{
    $s=$pdo->prepare("INSERT INTO communication_scheduler_runs(organization_id,run_type,status) VALUES(?,'sync_dispatch','running')");$s->execute([$orgId]);$runId=(int)$pdo->lastInsertId();
    try{$events=comm_step21_sync_events($pdo,$orgId);$d=$dispatch?comm_step21_dispatch($pdo,$orgId,50):['processed'=>0,'sent'=>0,'failed'=>0];$pdo->prepare("UPDATE communication_scheduler_runs SET status='success',events_synced=?,messages_processed=?,finished_at=NOW() WHERE id=?")->execute([$events,(int)$d['processed'],$runId]);return ['events'=>$events]+$d+['run_id'=>$runId];}
    catch(Throwable $e){$pdo->prepare("UPDATE communication_scheduler_runs SET status='failed',error_message=?,finished_at=NOW() WHERE id=?")->execute([$e->getMessage(),$runId]);throw $e;}
}
