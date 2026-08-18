<?php
declare(strict_types=1);

require_once __DIR__.'/communications_step21.php';
require_once __DIR__.'/finance_step16.php';

const BI_STEP22_VERSION='1.0-complete';

function bi_step22_tables(): array{return ['bi_targets','bi_signal_actions','bi_executive_notes','bi_export_runs'];}
function bi_step22_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function bi_step22_money(mixed $v):string{return '₹'.number_format((float)$v,2,'.',',');}
function bi_step22_num(mixed $v,int $d=0):string{return number_format((float)$v,$d,'.',',');}

function bi_step22_run_migration(PDO $pdo):void{
    $file=dirname(__DIR__,2).'/database/migrations/018_step22_executive_bi.sql';
    if(!is_file($file))throw new RuntimeException('STEP 22 migration is missing.');
    $sql=(string)file_get_contents($file);
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){$statement=trim($statement);if($statement==='')continue;$statement=preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement)??$statement;$statement=trim($statement);if($statement===''||preg_match('/^USE\s+/i',$statement))continue;$pdo->exec($statement);}
}

function bi_step22_context(PDO $pdo):array{
    $r=$pdo->query("SELECT o.id organization_id,c.id club_id,o.default_currency_code FROM organizations o LEFT JOIN clubs c ON c.organization_id=o.id AND c.club_code='GHAZIPUR-001' WHERE o.organization_code='HWC-001' LIMIT 1")->fetch();
    if(!$r)throw new RuntimeException('Executive BI context unavailable.');return ['organization_id'=>(int)$r['organization_id'],'club_id'=>$r['club_id']!==null?(int)$r['club_id']:null,'currency_code'=>(string)$r['default_currency_code']];
}

function bi_step22_seed_permissions(PDO $pdo,int $orgId):void{
    if(!business_table_exists($pdo,'security_permissions')||!business_table_exists($pdo,'security_role_permissions'))return;
    $defs=[
        ['bi.view','Executive BI: View','bi','restricted','View cross-module executive KPIs, trends and action signals.'],
        ['bi.manage','Executive BI: Manage','bi','restricted','Manage targets, signal acknowledgements and executive notes.'],
        ['bi.export','Executive BI: Export','bi','restricted','Export management KPI and analytics reports.'],
    ];
    $p=$pdo->prepare("INSERT INTO security_permissions(permission_code,permission_name,module_code,risk_level,description,is_active) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_code=VALUES(module_code),risk_level=VALUES(risk_level),description=VALUES(description),is_active=1");foreach($defs as $d)$p->execute($d);
    $admin=$pdo->prepare("INSERT INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,'admin',?,1) ON DUPLICATE KEY UPDATE is_allowed=1");foreach($defs as $d)$admin->execute([$orgId,$d[0]]);
    $ins=$pdo->prepare("INSERT IGNORE INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,?,?,1)");foreach(['bi.view','bi.manage','bi.export'] as $pc)$ins->execute([$orgId,'manager',$pc]);$ins->execute([$orgId,'viewer','bi.view']);
}

function bi_step22_ensure(PDO $pdo):void{
    comm_step21_ensure($pdo);finance_step16_ensure($pdo);
    foreach(bi_step22_tables() as $t){if(!business_table_exists($pdo,$t)){bi_step22_run_migration($pdo);break;}}
    foreach(bi_step22_tables() as $t)if(!business_table_exists($pdo,$t))throw new RuntimeException('STEP 22 table missing: '.$t);
    $ctx=bi_step22_context($pdo);bi_step22_seed_permissions($pdo,(int)$ctx['organization_id']);
    if(business_table_exists($pdo,'schema_meta')){$s=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('bi_step22_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$s->execute([BI_STEP22_VERSION]);}
}

function bi_step22_guard(PDO $pdo,string $permission):array{
    $user=security_step17_current_user($pdo);if(!security_step17_has_permission($pdo,$permission,$user)){http_response_code(403);throw new RuntimeException('Executive BI permission required: '.$permission);}
    return $user;
}

function bi_step22_period(?string $month=null):array{
    $month=trim((string)$month);if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01');if(!$start)$start=new DateTimeImmutable(date('Y-m-01'));
    $end=$start->modify('last day of this month');$prevStart=$start->modify('-1 month');$prevEnd=$prevStart->modify('last day of this month');
    return ['key'=>$start->format('Y-m'),'label'=>$start->format('F Y'),'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'prev_start'=>$prevStart->format('Y-m-d'),'prev_end'=>$prevEnd->format('Y-m-d'),'prev_key'=>$prevStart->format('Y-m')];
}

function bi_step22_scalar(PDO $pdo,string $sql,array $args=[]):float{$s=$pdo->prepare($sql);$s->execute($args);return(float)$s->fetchColumn();}
function bi_step22_row(PDO $pdo,string $sql,array $args=[]):array{$s=$pdo->prepare($sql);$s->execute($args);return$s->fetch()?:[];}

function bi_step22_metrics(PDO $pdo,int $orgId,string $start,string $end):array{
    $m=['sales_count'=>0,'sales_revenue'=>0.0,'sales_vp'=>0.0,'verified_profit'=>0.0,'profit_complete_sales'=>0,'profit_deferred_sales'=>0,'collections'=>0.0,'purchase_spend'=>0.0,'new_leads'=>0,'lead_conversions'=>0,'cohort_converted'=>0,'conversion_rate'=>null,'new_customers'=>0,'customer_receivable'=>0.0,'customer_credit_due'=>0.0,'supplier_payable'=>0.0,'overdue_supplier_bills'=>0,'inventory_units'=>0.0,'inventory_value_known'=>0.0,'inventory_cost_coverage'=>null,'low_stock'=>0,'out_of_stock'=>0,'expiry_risk'=>0,'finance_income'=>0.0,'finance_expense'=>0.0,'finance_net'=>0.0,'cash_net_movement'=>0.0,'open_comm_events'=>0,'critical_comm_events'=>0,'failed_outbox'=>0,'overdue_lead_followups'=>0];
    if(business_table_exists($pdo,'product_sale_ledger')){$r=bi_step22_row($pdo,"SELECT COUNT(*) sales_count,COALESCE(SUM(o.net_amount),0) revenue,COALESCE(SUM(o.volume_points),0) vp,COALESCE(SUM(CASE WHEN l.cost_status='complete' THEN l.profit_total ELSE 0 END),0) profit,SUM(CASE WHEN l.cost_status='complete' THEN 1 ELSE 0 END) complete_sales,SUM(CASE WHEN l.cost_status<>'complete' THEN 1 ELSE 0 END) deferred_sales FROM product_sale_ledger l JOIN orders o ON o.id=l.order_id AND o.organization_id=l.organization_id WHERE l.organization_id=? AND l.sale_status='active' AND o.order_date BETWEEN ? AND ?",[$orgId,$start,$end]);$m['sales_count']=(int)($r['sales_count']??0);$m['sales_revenue']=(float)($r['revenue']??0);$m['sales_vp']=(float)($r['vp']??0);$m['verified_profit']=(float)($r['profit']??0);$m['profit_complete_sales']=(int)($r['complete_sales']??0);$m['profit_deferred_sales']=(int)($r['deferred_sales']??0);}
    if(business_table_exists($pdo,'product_sale_payments'))$m['collections']=bi_step22_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM product_sale_payments WHERE organization_id=? AND status='active' AND payment_date BETWEEN ? AND ?",[$orgId,$start,$end]);
    if(business_table_exists($pdo,'purchase_bills')){$m['purchase_spend']=bi_step22_scalar($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM purchase_bills WHERE organization_id=? AND status='active' AND invoice_date BETWEEN ? AND ?",[$orgId,$start,$end]);$m['supplier_payable']=bi_step22_scalar($pdo,"SELECT COALESCE(SUM(GREATEST(total_amount-return_credit-paid_amount,0)),0) FROM purchase_bills WHERE organization_id=? AND status='active'",[$orgId]);$m['overdue_supplier_bills']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM purchase_bills WHERE organization_id=? AND status='active' AND due_date<CURDATE() AND GREATEST(total_amount-return_credit-paid_amount,0)>0.009",[$orgId]);}
    if(business_table_exists($pdo,'crm_leads')){$m['new_leads']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_leads WHERE organization_id=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$start,$end]);$m['overdue_lead_followups']=business_table_exists($pdo,'crm_lead_tasks')?(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_lead_tasks WHERE organization_id=? AND status='pending' AND due_at<NOW()",[$orgId]):0;}
    if(business_table_exists($pdo,'crm_lead_conversions'))$m['lead_conversions']=(int)bi_step22_scalar($pdo,"SELECT COUNT(DISTINCT lead_id) FROM crm_lead_conversions WHERE organization_id=? AND converted_at>=? AND converted_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$start,$end]);
    if(business_table_exists($pdo,'crm_leads'))$m['cohort_converted']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_leads WHERE organization_id=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY) AND stage='converted'",[$orgId,$start,$end]);
    if($m['new_leads']>0)$m['conversion_rate']=round($m['cohort_converted']/$m['new_leads']*100,2);
    if(business_table_exists($pdo,'crm_customers'))$m['new_customers']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$start,$end]);
    if(business_table_exists($pdo,'sales_fulfillment_ledger')){$r=bi_step22_row($pdo,"SELECT COALESCE(SUM(receivable_amount),0) receivable,COALESCE(SUM(customer_credit_due),0) credit_due FROM sales_fulfillment_ledger WHERE organization_id=?",[$orgId]);$m['customer_receivable']=(float)($r['receivable']??0);$m['customer_credit_due']=(float)($r['credit_due']??0);}
    if(business_table_exists($pdo,'inventory_batches')){$r=bi_step22_row($pdo,"SELECT COALESCE(SUM(CASE WHEN status='active' AND current_quantity>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) THEN current_quantity ELSE 0 END),0) qty,COALESCE(SUM(CASE WHEN status='active' AND current_quantity>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) AND unit_cost IS NOT NULL THEN current_quantity*unit_cost ELSE 0 END),0) value_known,COALESCE(SUM(CASE WHEN status='active' AND current_quantity>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) AND unit_cost IS NOT NULL THEN current_quantity ELSE 0 END),0) qty_costed FROM inventory_batches WHERE organization_id=?",[$orgId]);$m['inventory_units']=(float)($r['qty']??0);$m['inventory_value_known']=(float)($r['value_known']??0);$costed=(float)($r['qty_costed']??0);if($m['inventory_units']>0)$m['inventory_cost_coverage']=round($costed/$m['inventory_units']*100,2);$m['expiry_risk']=business_table_exists($pdo,'inventory_product_settings')?(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM inventory_batches b JOIN inventory_product_settings s ON s.organization_id=b.organization_id AND s.location_id=b.location_id AND s.listing_id=b.listing_id WHERE b.organization_id=? AND b.status='active' AND b.current_quantity>0 AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL s.expiry_alert_days DAY)",[$orgId]):0;}
    if(business_table_exists($pdo,'inventory_product_settings')){$base="FROM inventory_product_settings s LEFT JOIN (SELECT organization_id,location_id,listing_id,SUM(CASE WHEN status='active' AND current_quantity>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) THEN current_quantity ELSE 0 END) qty FROM inventory_batches WHERE organization_id=? GROUP BY organization_id,location_id,listing_id) b ON b.organization_id=s.organization_id AND b.location_id=s.location_id AND b.listing_id=s.listing_id WHERE s.organization_id=? AND s.track_stock=1";$m['out_of_stock']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) $base AND COALESCE(b.qty,0)<=0",[$orgId,$orgId]);$m['low_stock']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) $base AND s.reorder_level>0 AND COALESCE(b.qty,0)>0 AND COALESCE(b.qty,0)<=s.reorder_level",[$orgId,$orgId]);}
    if(business_table_exists($pdo,'finance_journals')){$r=bi_step22_row($pdo,"SELECT COALESCE(SUM(CASE WHEN a.account_class='income' THEN l.credit_amount-l.debit_amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN a.account_class='expense' THEN l.debit_amount-l.credit_amount ELSE 0 END),0) expense FROM finance_journal_lines l JOIN finance_journals j ON j.id=l.journal_id AND j.organization_id=l.organization_id JOIN finance_ledger_accounts a ON a.id=l.ledger_account_id AND a.organization_id=l.organization_id WHERE l.organization_id=? AND j.status='posted' AND j.journal_date BETWEEN ? AND ?",[$orgId,$start,$end]);$m['finance_income']=(float)($r['income']??0);$m['finance_expense']=(float)($r['expense']??0);$m['finance_net']=round($m['finance_income']-$m['finance_expense'],2);$m['cash_net_movement']=bi_step22_scalar($pdo,"SELECT COALESCE(SUM(l.debit_amount-l.credit_amount),0) FROM finance_journal_lines l JOIN finance_journals j ON j.id=l.journal_id AND j.organization_id=l.organization_id JOIN finance_cash_accounts c ON c.id=l.cash_account_id AND c.organization_id=l.organization_id WHERE l.organization_id=? AND j.status='posted' AND j.journal_date BETWEEN ? AND ?",[$orgId,$start,$end]);}
    if(business_table_exists($pdo,'communication_events')){$m['open_comm_events']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM communication_events WHERE organization_id=? AND event_status='open'",[$orgId]);$m['critical_comm_events']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM communication_events WHERE organization_id=? AND event_status='open' AND severity='critical'",[$orgId]);}
    if(business_table_exists($pdo,'communication_outbox'))$m['failed_outbox']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM communication_outbox WHERE organization_id=? AND status='failed'",[$orgId]);
    return $m;
}

function bi_step22_change(float|int|null $current,float|int|null $previous):?float{if($current===null||$previous===null)return null;$p=(float)$previous;$c=(float)$current;if(abs($p)<0.000001)return abs($c)<0.000001?0:null;return round(($c-$p)/abs($p)*100,2);}

function bi_step22_monthly_trends(PDO $pdo,int $orgId,int $months=12):array{
    $months=max(3,min(24,$months));$start=(new DateTimeImmutable('first day of this month'))->modify('-'.($months-1).' months');$out=[];for($i=0;$i<$months;$i++){$d=$start->modify('+'.$i.' months');$p=bi_step22_period($d->format('Y-m'));$m=bi_step22_metrics($pdo,$orgId,$p['start'],$p['end']);$out[]=['month'=>$p['key'],'label'=>$d->format('M Y')]+$m;}return $out;
}

function bi_step22_top_products(PDO $pdo,int $orgId,string $start,string $end,int $limit=10):array{
    if(!business_table_exists($pdo,'product_order_items')||!business_table_exists($pdo,'product_sale_ledger'))return[];$limit=max(1,min(50,$limit));$s=$pdo->prepare("SELECT poi.product_id,poi.stock_no,poi.product_name_snapshot,SUM(poi.quantity) qty,SUM(poi.line_price) revenue,SUM(poi.line_vp) vp,COALESCE(SUM(CASE WHEN poi.profit_status='explicit_cost' THEN poi.line_profit ELSE 0 END),0) verified_profit,SUM(CASE WHEN poi.profit_status='explicit_cost' THEN 1 ELSE 0 END) costed_lines,COUNT(*) line_count FROM product_order_items poi JOIN orders o ON o.id=poi.order_id AND o.organization_id=poi.organization_id JOIN product_sale_ledger sl ON sl.organization_id=o.organization_id AND sl.order_id=o.id AND sl.sale_status='active' WHERE poi.organization_id=? AND o.order_date BETWEEN ? AND ? GROUP BY poi.product_id,poi.stock_no,poi.product_name_snapshot ORDER BY revenue DESC LIMIT $limit");$s->execute([$orgId,$start,$end]);return$s->fetchAll();
}
function bi_step22_top_customers(PDO $pdo,int $orgId,string $start,string $end,int $limit=10):array{
    if(!business_table_exists($pdo,'sales_fulfillment_ledger'))return[];$limit=max(1,min(50,$limit));$s=$pdo->prepare("SELECT COALESCE(c.customer_name,l.customer_name_snapshot,'Unlinked customer') customer_name,COUNT(*) orders,COALESCE(SUM(o.net_amount),0) revenue,COALESCE(SUM(f.net_collected),0) collected,COALESCE(SUM(f.receivable_amount),0) receivable FROM sales_fulfillment_ledger f JOIN orders o ON o.id=f.order_id AND o.organization_id=f.organization_id LEFT JOIN crm_customers c ON c.id=f.customer_id AND c.organization_id=f.organization_id LEFT JOIN sales_customer_links l ON l.organization_id=f.organization_id AND l.order_id=f.order_id WHERE f.organization_id=? AND o.order_date BETWEEN ? AND ? GROUP BY f.customer_id,c.customer_name,l.customer_name_snapshot ORDER BY revenue DESC LIMIT $limit");$s->execute([$orgId,$start,$end]);return$s->fetchAll();
}
function bi_step22_top_suppliers(PDO $pdo,int $orgId,string $start,string $end,int $limit=10):array{
    if(!business_table_exists($pdo,'purchase_bills'))return[];$limit=max(1,min(50,$limit));$s=$pdo->prepare("SELECT s.supplier_name,COUNT(*) bills,COALESCE(SUM(b.total_amount),0) billed,COALESCE(SUM(b.paid_amount),0) paid,COALESCE(SUM(GREATEST(b.total_amount-b.return_credit-b.paid_amount,0)),0) outstanding FROM purchase_bills b JOIN purchase_suppliers s ON s.id=b.supplier_id AND s.organization_id=b.organization_id WHERE b.organization_id=? AND b.status='active' AND b.invoice_date BETWEEN ? AND ? GROUP BY b.supplier_id,s.supplier_name ORDER BY billed DESC LIMIT $limit");$s->execute([$orgId,$start,$end]);return$s->fetchAll();
}

function bi_step22_funnel(PDO $pdo,int $orgId,string $start,string $end):array{
    $out=['submissions'=>0,'leads'=>0,'contacted'=>0,'qualified'=>0,'appointment'=>0,'converted'=>0,'lost'=>0];if(!business_table_exists($pdo,'crm_leads'))return$out;
    if(business_table_exists($pdo,'crm_lead_submissions'))$out['submissions']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_lead_submissions WHERE organization_id=? AND spam_status='accepted' AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$start,$end]);
    $out['leads']=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_leads WHERE organization_id=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$start,$end]);
    foreach(['contacted','qualified','appointment','converted','lost'] as $st)$out[$st]=(int)bi_step22_scalar($pdo,"SELECT COUNT(*) FROM crm_leads WHERE organization_id=? AND stage=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)",[$orgId,$st,$start,$end]);
    return$out;
}

function bi_step22_metric_catalog():array{return[
    'sales_revenue'=>['Sales Revenue','currency','higher_better'],'sales_vp'=>['Sales VP','vp','higher_better'],'verified_profit'=>['Verified Profit','currency','higher_better'],'collections'=>['Customer Collections','currency','higher_better'],'purchase_spend'=>['Purchase Spend','currency','lower_better'],'new_leads'=>['New Leads','count','higher_better'],'lead_conversions'=>['Lead Conversions','count','higher_better'],'conversion_rate'=>['Lead Conversion Rate','percent','higher_better'],'new_customers'=>['New Customers','count','higher_better'],'customer_receivable'=>['Customer Receivable','currency','lower_better'],'supplier_payable'=>['Supplier Payable','currency','lower_better'],'finance_net'=>['Finance Net Result','currency','higher_better'],'cash_net_movement'=>['Net Cash Movement','currency','higher_better']];}

function bi_step22_targets(PDO $pdo,int $orgId,string $start,string $end,array $metrics):array{
    $s=$pdo->prepare("SELECT * FROM bi_targets WHERE organization_id=? AND status='active' AND period_start<=? AND period_end>=? ORDER BY metric_code,target_name");$s->execute([$orgId,$end,$start]);$cat=bi_step22_metric_catalog();$out=[];foreach($s->fetchAll() as $r){$code=(string)$r['metric_code'];$actual=array_key_exists($code,$metrics)?$metrics[$code]:null;if(in_array($code,['customer_receivable','supplier_payable'],true)&&!($start<=date('Y-m-d')&&$end>=date('Y-m-d')))$actual=null;$target=(float)$r['target_value'];$direction=(string)$r['direction_code'];$pct=null;$met=null;if($actual!==null&&abs($target)>0.000001){$av=(float)$actual;$pct=$direction==='lower_better'?($av<=0?100.0:round($target/$av*100,1)):round($av/$target*100,1);$met=$direction==='lower_better'?$av<=$target:$av>=$target;}$out[]=$r+['metric_name'=>$cat[$code][0]??$code,'actual_value'=>$actual,'achievement_pct'=>$pct,'met'=>$met];}return$out;
}

function bi_step22_save_target(PDO $pdo,int $orgId,array $data,int $userId):int{
    $cat=bi_step22_metric_catalog();$metric=(string)($data['metric_code']??'');if(!isset($cat[$metric]))throw new RuntimeException('Choose a supported KPI metric.');$name=trim((string)($data['target_name']??''));if($name==='')$name=$cat[$metric][0].' Target';$start=(string)($data['period_start']??'');$end=(string)($data['period_end']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$end<$start)throw new RuntimeException('Choose a valid target period.');$value=(float)($data['target_value']??0);if($value<0)throw new RuntimeException('Target value cannot be negative.');$code='TGT-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));$unit=$cat[$metric][1];$dir=$cat[$metric][2];$s=$pdo->prepare("INSERT INTO bi_targets(organization_id,target_code,metric_code,target_name,period_start,period_end,target_value,unit_code,direction_code,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'active',?)");$s->execute([$orgId,$code,$metric,$name,$start,$end,$value,$unit,$dir,trim((string)($data['notes']??''))?:null,$userId]);return(int)$pdo->lastInsertId();
}
function bi_step22_target_status(PDO $pdo,int $orgId,int $id,string $status):void{if(!in_array($status,['active','archived'],true))throw new RuntimeException('Invalid target status.');$s=$pdo->prepare("UPDATE bi_targets SET status=? WHERE organization_id=? AND id=?");$s->execute([$status,$orgId,$id]);}

function bi_step22_signals(PDO $pdo,int $orgId,array $m):array{
    $raw=[];$add=function(string $key,string $type,string $severity,string $title,string $detail,string $url)use(&$raw):void{$raw[]=['key'=>$key,'type'=>$type,'severity'=>$severity,'title'=>$title,'detail'=>$detail,'url'=>$url];};
    if($m['customer_receivable']>0.009)$add('receivable:open','receivable','high','Customer receivables need collection',bi_step22_money($m['customer_receivable']).' currently outstanding.','sales_receivables.php');
    if($m['overdue_supplier_bills']>0)$add('supplier:overdue','supplier_payable','high','Supplier bills are overdue',$m['overdue_supplier_bills'].' overdue bill(s).','supplier_payments.php');
    if($m['out_of_stock']>0)$add('inventory:out','inventory','critical','Tracked products are out of stock',$m['out_of_stock'].' tracked product setting(s) have zero sellable stock.','inventory_center.php');
    if($m['low_stock']>0)$add('inventory:low','inventory','high','Products are below reorder level',$m['low_stock'].' product setting(s) below explicit reorder threshold.','inventory_center.php');
    if($m['expiry_risk']>0)$add('inventory:expiry','inventory','high','Inventory is approaching expiry',$m['expiry_risk'].' batch(es) inside configured expiry-alert window.','inventory_analytics.php');
    if($m['profit_deferred_sales']>0)$add('profit:deferred','profit','normal','Some sales have deferred profit',$m['profit_deferred_sales'].' sale(s) lack complete explicit cost basis in selected period.','product_cost_basis.php');
    if($m['overdue_lead_followups']>0)$add('lead:followup','lead','high','Lead follow-ups are overdue',$m['overdue_lead_followups'].' follow-up(s) need action.','lead_followups.php');
    if($m['critical_comm_events']>0)$add('communication:critical','communication','critical','Critical operational notifications are open',$m['critical_comm_events'].' critical event(s).','notification_inbox.php');
    if($m['failed_outbox']>0)$add('communication:failed','communication','high','External messages failed',$m['failed_outbox'].' failed outbox item(s).','communication_outbox.php');
    $state=[];$s=$pdo->prepare("SELECT signal_key,signal_status,note,action_at FROM bi_signal_actions WHERE organization_id=?");$s->execute([$orgId]);foreach($s->fetchAll() as $r)$state[(string)$r['signal_key']]=$r;foreach($raw as &$r){$stored=$state[$r['key']]['signal_status']??'open';$r['workflow_status']=$stored==='resolved'?'active_after_resolution':$stored;$r['workflow_note']=$state[$r['key']]['note']??null;$r['action_at']=$state[$r['key']]['action_at']??null;}unset($r);return$raw;
}
function bi_step22_signal_action(PDO $pdo,int $orgId,string $key,string $type,string $status,string $note,int $userId):void{if(!in_array($status,['acknowledged','resolved'],true))throw new RuntimeException('Invalid signal action.');$s=$pdo->prepare("INSERT INTO bi_signal_actions(organization_id,signal_key,signal_type,signal_status,note,action_by,action_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE signal_type=VALUES(signal_type),signal_status=VALUES(signal_status),note=VALUES(note),action_by=VALUES(action_by),action_at=NOW()");$s->execute([$orgId,$key,$type,$status,trim($note)?:null,$userId]);}

function bi_step22_notes(PDO $pdo,int $orgId,int $limit=20):array{$limit=max(1,min(100,$limit));$s=$pdo->prepare("SELECT n.*,u.full_name FROM bi_executive_notes n LEFT JOIN system_users u ON u.id=n.created_by WHERE n.organization_id=? AND n.status='active' ORDER BY n.note_date DESC,n.id DESC LIMIT $limit");$s->execute([$orgId]);return$s->fetchAll();}
function bi_step22_add_note(PDO $pdo,int $orgId,string $title,string $text,string $date,int $userId):int{$title=trim($title);$text=trim($text);if($title===''||$text==='')throw new RuntimeException('Note title and text are required.');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');$s=$pdo->prepare("INSERT INTO bi_executive_notes(organization_id,note_date,title,note_text,created_by) VALUES(?,?,?,?,?)");$s->execute([$orgId,$date,$title,$text,$userId]);return(int)$pdo->lastInsertId();}

function bi_step22_log_export(PDO $pdo,int $orgId,string $type,string $start,string $end,int $rows,int $userId):void{$s=$pdo->prepare("INSERT INTO bi_export_runs(organization_id,export_type,period_start,period_end,row_count,exported_by) VALUES(?,?,?,?,?,?)");$s->execute([$orgId,$type,$start,$end,$rows,$userId]);}
