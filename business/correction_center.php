<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/data_entry_smart.php';

function cc_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cc_trim(mixed $value): string { return trim((string)$value); }
function cc_json(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Correction audit payload could not be encoded.');
    return $json;
}
function cc_json_array(mixed $value): array {
    $raw = cc_trim($value);
    if ($raw === '') return [];
    try { $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
    catch (Throwable) { return []; }
}
function cc_date(mixed $value): string {
    $raw = cc_trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if ($raw === '' || !$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $raw) {
        throw new RuntimeException('A valid date is required.');
    }
    return $raw;
}
function cc_decimal(mixed $value): float {
    $raw = str_replace([',','₹',' '], '', cc_trim($value));
    if ($raw === '' || !is_numeric($raw)) throw new RuntimeException('A numeric value is invalid.');
    return (float)$raw;
}
function cc_nonnegative(float $value, string $label): void { if ($value < 0) throw new RuntimeException($label . ' cannot be negative.'); }
function cc_mobile(mixed $value): ?string {
    $raw = cc_trim($value);
    if ($raw === '') return null;
    $digits = business_entry_smart_mobile_digits($raw);
    if (strlen($digits) < 10 || strlen($digits) > 15 || preg_match('/^0+$/', $digits)) throw new RuntimeException('Mobile number must contain 10–15 valid digits.');
    return str_starts_with($raw, '+') ? '+' . $digits : $digits;
}
function cc_member(PDO $pdo, int $organizationId, int $memberId): array {
    if ($memberId <= 0) throw new RuntimeException('Select a verified member.');
    $stmt = $pdo->prepare('SELECT id, full_name, mobile, sponsor_member_id FROM members WHERE organization_id=? AND id=? LIMIT 1');
    $stmt->execute([$organizationId,$memberId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Selected member does not exist in this organization.');
    return $row;
}
function cc_sponsor_cycle(PDO $pdo, int $organizationId, int $memberId, ?int $sponsorId): bool {
    if ($sponsorId === null || $sponsorId <= 0) return false;
    if ($sponsorId === $memberId) return true;
    $seen = [$memberId => true];
    $current = $sponsorId;
    $stmt = $pdo->prepare('SELECT sponsor_member_id FROM members WHERE organization_id=? AND id=? LIMIT 1');
    for ($i=0; $i<200 && $current > 0; $i++) {
        if (isset($seen[$current])) return true;
        $seen[$current] = true;
        $stmt->execute([$organizationId,$current]);
        $next = $stmt->fetchColumn();
        if ($next === false || $next === null) return false;
        $current = (int)$next;
    }
    return $current > 0;
}
function cc_audit(PDO $pdo, int $organizationId, int $clubId, string $eventType, string $entityType, int $entityId, array $details): void {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$organizationId,$clubId,$eventType,$entityType,$entityId,cc_json($details),substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)]);
}
function cc_manual_guard(PDO $pdo, int $organizationId, string $table, int $id): array {
    $allowed = ['members','volume_point_entries','orders','renewals','income_entries','royalty_entries'];
    if (!in_array($table,$allowed,true)) throw new RuntimeException('Correction target is invalid.');
    $stmt = $pdo->prepare("SELECT t.*, r.id raw_id, r.raw_json original_raw_json, ds.source_code FROM `{$table}` t LEFT JOIN raw_source_records r ON r.id=t.source_record_id LEFT JOIN data_sources ds ON ds.id=r.data_source_id WHERE t.organization_id=? AND t.id=? AND t.source_sheet='Manual Entry' LIMIT 1");
    $stmt->execute([$organizationId,$id]);
    $row = $stmt->fetch();
    if (!$row || ($row['source_code'] ?? '') !== 'MANUAL' || empty($row['raw_id'])) throw new RuntimeException('Only MANUAL Business OS entries can be corrected here. Legacy/imported rows are read-only.');
    return $row;
}
function cc_summary_money(mixed $amount): string { return '₹' . number_format((float)$amount,2,'.',','); }

if (empty($_SESSION['business_correction_csrf'])) $_SESSION['business_correction_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['business_correction_csrf'];

$moduleMap = [
    'new_ums'=>['label'=>'New UMS','table'=>'members','entity'=>'member'],
    'vp'=>['label'=>'Volume Points','table'=>'volume_point_entries','entity'=>'volume_point_entry'],
    'order'=>['label'=>'Order','table'=>'orders','entity'=>'order'],
    'renewal'=>['label'=>'Renewal','table'=>'renewals','entity'=>'renewal'],
    'income'=>['label'=>'Income','table'=>'income_entries','entity'=>'income_entry'],
    'royalty'=>['label'=>'Royalty','table'=>'royalty_entries','entity'=>'royalty_entry'],
];

$error=null; $success=null; $organizationId=0; $clubId=0; $manualSourceId=0; $members=[]; $entries=[]; $history=[]; $selected=null; $selectedUms=null;
$module = cc_trim($_GET['module'] ?? $_POST['module'] ?? '');
$entityId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['entity_id']) && is_numeric($_POST['entity_id']) ? (int)$_POST['entity_id'] : 0);
if ($module !== '' && !isset($moduleMap[$module])) { $module=''; $entityId=0; }

try {
    $pdo = business_db();
    foreach (['organizations','clubs','data_sources','raw_source_records','members','ums_records','volume_point_entries','orders','renewals','income_entries','royalty_entries','audit_logs'] as $table) {
        if (!business_table_exists($pdo,$table)) throw new RuntimeException("Required table {$table} is missing.");
    }
    $org = $pdo->query("SELECT id,timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    $organizationId=(int)$org['id']; @date_default_timezone_set((string)($org['timezone'] ?: 'Asia/Kolkata'));
    $stmt=$pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1"); $stmt->execute([$organizationId]); $clubId=(int)$stmt->fetchColumn();
    if ($clubId<=0) throw new RuntimeException('Ghazipur club was not found.');
    $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' LIMIT 1"); $stmt->execute([$organizationId]); $manualSourceId=(int)$stmt->fetchColumn();
    if ($manualSourceId<=0) throw new RuntimeException('MANUAL data source was not found.');

    $stmt=$pdo->prepare("SELECT id,full_name,mobile,status FROM members WHERE organization_id=? ORDER BY full_name,id"); $stmt->execute([$organizationId]); $members=$stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_correction'])) {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        if ($module==='' || $entityId<=0) throw new RuntimeException('Select a manual entry to correct.');
        $reason=preg_replace('/\s+/u',' ',cc_trim($_POST['correction_reason'] ?? '')) ?: '';
        if (mb_strlen($reason,'UTF-8') < 5 || mb_strlen($reason,'UTF-8') > 500) throw new RuntimeException('Correction reason must be between 5 and 500 characters.');
        $meta=$moduleMap[$module];
        $old=cc_manual_guard($pdo,$organizationId,$meta['table'],$entityId);
        $rawId=(int)$old['raw_id'];
        $before=[]; $after=[];

        $pdo->beginTransaction();
        try {
            if ($module==='new_ums') {
                $name=preg_replace('/\s+/u',' ',cc_trim($_POST['full_name'] ?? '')) ?: '';
                if (mb_strlen($name,'UTF-8')<2 || mb_strlen($name,'UTF-8')>180) throw new RuntimeException('Member name must be between 2 and 180 characters.');
                $mobile=cc_mobile($_POST['mobile'] ?? ''); $joinDate=cc_date($_POST['join_date'] ?? ''); $umsType=cc_trim($_POST['ums_type'] ?? '');
                $status=cc_trim($_POST['status'] ?? 'active'); if (!in_array($status,['active','inactive'],true)) throw new RuntimeException('Status is invalid.');
                $sponsorId=(isset($_POST['sponsor_member_id']) && is_numeric($_POST['sponsor_member_id']) && (int)$_POST['sponsor_member_id']>0) ? (int)$_POST['sponsor_member_id'] : null;
                if ($sponsorId!==null) cc_member($pdo,$organizationId,$sponsorId);
                if (cc_sponsor_cycle($pdo,$organizationId,$entityId,$sponsorId)) throw new RuntimeException('Sponsor correction would create a self-link or network cycle.');
                $umsStmt=$pdo->prepare("SELECT * FROM ums_records WHERE organization_id=? AND member_id=? AND source_record_id=? AND source_sheet='Manual Entry' ORDER BY id LIMIT 1");
                $umsStmt->execute([$organizationId,$entityId,$rawId]); $ums=$umsStmt->fetch();
                if (!$ums) throw new RuntimeException('Linked manual UMS lifecycle record was not found.');
                $before=['full_name'=>$old['full_name'],'mobile'=>$old['mobile'],'join_date'=>$old['join_date'],'member_type'=>$old['member_type'],'status'=>$old['status'],'sponsor_member_id'=>$old['sponsor_member_id'],'ums_id'=>(int)$ums['id'],'ums_start_date'=>$ums['start_date'],'ums_type'=>$ums['set_type'],'ums_status'=>$ums['status']];
                $memberNotes=cc_json_array($old['notes'] ?? null); $memberNotes['last_corrected_at']=date('c');
                $stmt=$pdo->prepare("UPDATE members SET full_name=?,mobile=?,sponsor_member_id=?,member_type=?,join_date=?,status=?,notes=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$name,$mobile,$sponsorId,$umsType!==''?$umsType:null,$joinDate,$status,cc_json($memberNotes),$organizationId,$entityId]);
                $stmt=$pdo->prepare("UPDATE ums_records SET set_type=?,start_date=?,status=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$umsType!==''?$umsType:null,$joinDate,$status,$organizationId,(int)$ums['id']]);
                $after=['full_name'=>$name,'mobile'=>$mobile,'join_date'=>$joinDate,'member_type'=>$umsType!==''?$umsType:null,'status'=>$status,'sponsor_member_id'=>$sponsorId,'ums_id'=>(int)$ums['id'],'ums_start_date'=>$joinDate,'ums_type'=>$umsType!==''?$umsType:null,'ums_status'=>$status];
            }
            if ($module==='vp') {
                $member=cc_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $date=cc_date($_POST['entry_date'] ?? ''); $vp=cc_decimal($_POST['volume_points'] ?? ''); cc_nonnegative($vp,'Volume Points');
                $orderType=cc_trim($_POST['order_type'] ?? ''); $vpFrom=cc_trim($_POST['vp_from'] ?? ''); $orderedBy=cc_trim($_POST['ordered_by'] ?? ''); $vpType=cc_trim($_POST['vp_type'] ?? ''); $level=cc_trim($_POST['level_label'] ?? ''); $week=cc_trim($_POST['week_label'] ?? ''); if ($week==='') $week=business_entry_smart_week_label($date);
                $before=['member_id'=>$old['member_id'],'member_name_snapshot'=>$old['member_name_snapshot'],'entry_date'=>$old['entry_date'],'volume_points'=>(float)$old['volume_points'],'order_type'=>$old['order_type'],'vp_from'=>$old['vp_from'],'ordered_by'=>$old['ordered_by'],'vp_type'=>$old['vp_type'],'level_label'=>$old['level_label'],'week_label'=>$old['week_label']];
                $stmt=$pdo->prepare("UPDATE volume_point_entries SET member_id=?,member_name_snapshot=?,entry_date=?,volume_points=?,order_type=?,vp_from=?,ordered_by=?,vp_type=?,level_label=?,week_label=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([(int)$member['id'],$member['full_name'],$date,$vp,$orderType!==''?$orderType:null,$vpFrom!==''?$vpFrom:null,$orderedBy!==''?$orderedBy:null,$vpType!==''?$vpType:null,$level!==''?$level:null,$week,$organizationId,$entityId]);
                $after=['member_id'=>(int)$member['id'],'member_name_snapshot'=>$member['full_name'],'entry_date'=>$date,'volume_points'=>$vp,'order_type'=>$orderType!==''?$orderType:null,'vp_from'=>$vpFrom!==''?$vpFrom:null,'ordered_by'=>$orderedBy!==''?$orderedBy:null,'vp_type'=>$vpType!==''?$vpType:null,'level_label'=>$level!==''?$level:null,'week_label'=>$week];
            }
            if ($module==='order') {
                $member=cc_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $date=cc_date($_POST['order_date'] ?? ''); $type=cc_trim($_POST['order_type'] ?? 'regular') ?: 'regular'; $desc=cc_trim($_POST['description'] ?? '');
                $gross=cc_decimal($_POST['gross_amount'] ?? ''); $discount=cc_decimal($_POST['discount_amount'] ?? '0'); $profit=cc_decimal($_POST['profit_amount'] ?? '0'); $vp=cc_decimal($_POST['volume_points'] ?? '0'); cc_nonnegative($gross,'Gross amount'); cc_nonnegative($discount,'Discount'); cc_nonnegative($vp,'Volume Points'); if ($discount>$gross) throw new RuntimeException('Discount cannot be greater than Gross Amount.'); $net=round($gross-$discount,2);
                $before=['member_id'=>$old['member_id'],'order_date'=>$old['order_date'],'order_type'=>$old['order_type'],'description'=>$old['description'],'gross_amount'=>(float)$old['gross_amount'],'discount_amount'=>(float)$old['discount_amount'],'net_amount'=>(float)$old['net_amount'],'profit_amount'=>(float)$old['profit_amount'],'volume_points'=>(float)$old['volume_points']];
                $notes=cc_json_array($old['notes'] ?? null); $notes['member_name_snapshot']=$member['full_name']; $notes['last_corrected_at']=date('c');
                $stmt=$pdo->prepare("UPDATE orders SET member_id=?,order_date=?,order_type=?,description=?,gross_amount=?,discount_amount=?,net_amount=?,profit_amount=?,volume_points=?,notes=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([(int)$member['id'],$date,$type,mb_substr($desc!==''?$desc:'Manual Business Order',0,255,'UTF-8'),$gross,$discount,$net,$profit,$vp,cc_json($notes),$organizationId,$entityId]);
                $after=['member_id'=>(int)$member['id'],'order_date'=>$date,'order_type'=>$type,'description'=>$desc!==''?$desc:'Manual Business Order','gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>$net,'profit_amount'=>$profit,'volume_points'=>$vp];
            }
            if ($module==='renewal') {
                $member=cc_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $date=cc_date($_POST['renewal_date'] ?? ''); $monthsRaw=cc_trim($_POST['period_months'] ?? ''); $months=null;
                if ($monthsRaw!=='') { if (!ctype_digit($monthsRaw) || (int)$monthsRaw<1 || (int)$monthsRaw>120) throw new RuntimeException('Renewal period must be 1–120 months.'); $months=(int)$monthsRaw; }
                $amount=cc_decimal($_POST['amount'] ?? '0'); $vp=cc_decimal($_POST['volume_points'] ?? '0'); cc_nonnegative($amount,'Renewal amount'); cc_nonnegative($vp,'Volume Points');
                $umsStmt=$pdo->prepare("SELECT id FROM ums_records WHERE organization_id=? AND member_id=? ORDER BY start_date DESC,id DESC LIMIT 1"); $umsStmt->execute([$organizationId,(int)$member['id']]); $umsId=$umsStmt->fetchColumn(); $umsId=$umsId!==false?(int)$umsId:null;
                $before=['member_id'=>$old['member_id'],'ums_record_id'=>$old['ums_record_id'],'renewal_date'=>$old['renewal_date'],'period_months'=>$old['period_months'],'amount'=>(float)$old['amount'],'volume_points'=>(float)$old['volume_points']];
                $notes=cc_json_array($old['notes'] ?? null); $notes['member_name_snapshot']=$member['full_name']; $notes['last_corrected_at']=date('c');
                $stmt=$pdo->prepare("UPDATE renewals SET member_id=?,ums_record_id=?,renewal_date=?,period_months=?,amount=?,volume_points=?,notes=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([(int)$member['id'],$umsId,$date,$months,$amount,$vp,cc_json($notes),$organizationId,$entityId]);
                if (business_column_exists($pdo,'renewals','member_name_snapshot')) { $stmt=$pdo->prepare("UPDATE renewals SET member_name_snapshot=? WHERE organization_id=? AND id=?"); $stmt->execute([$member['full_name'],$organizationId,$entityId]); }
                $after=['member_id'=>(int)$member['id'],'ums_record_id'=>$umsId,'renewal_date'=>$date,'period_months'=>$months,'amount'=>$amount,'volume_points'=>$vp];
            }
            if ($module==='income') {
                $date=cc_date($_POST['income_date'] ?? ''); $type=cc_trim($_POST['income_type'] ?? ''); if (!in_array($type,['retail','check','club','other'],true)) throw new RuntimeException('Income type is invalid.'); $amount=cc_decimal($_POST['amount'] ?? ''); cc_nonnegative($amount,'Income amount'); $userNotes=cc_trim($_POST['notes_text'] ?? ''); $periodKey=(new DateTimeImmutable($date))->format('Y-m');
                $before=['income_date'=>$old['income_date'],'income_type'=>$old['income_type'],'amount'=>(float)$old['amount'],'period_key'=>$old['period_key'],'notes'=>cc_json_array($old['notes'] ?? null)];
                $notes=cc_json_array($old['notes'] ?? null); $notes['user_notes']=$userNotes; $notes['last_corrected_at']=date('c');
                $stmt=$pdo->prepare("UPDATE income_entries SET income_date=?,income_type=?,amount=?,period_key=?,notes=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$date,$type,$amount,$periodKey,cc_json($notes),$organizationId,$entityId]);
                $after=['income_date'=>$date,'income_type'=>$type,'amount'=>$amount,'period_key'=>$periodKey,'user_notes'=>$userNotes];
            }
            if ($module==='royalty') {
                $date=cc_date($_POST['royalty_date'] ?? ''); $label=cc_trim($_POST['period_label'] ?? ''); if ($label==='') $label=business_entry_smart_week_label($date); $amount=cc_decimal($_POST['amount'] ?? ''); $vp=cc_decimal($_POST['volume_points'] ?? '0'); cc_nonnegative($amount,'Royalty amount'); cc_nonnegative($vp,'Volume Points'); $userNotes=cc_trim($_POST['notes_text'] ?? '');
                $before=['royalty_date'=>$old['royalty_date'],'period_label'=>$old['period_label'],'amount'=>(float)$old['amount'],'volume_points'=>(float)$old['volume_points'],'notes'=>cc_json_array($old['notes'] ?? null)];
                $notes=cc_json_array($old['notes'] ?? null); $notes['user_notes']=$userNotes; $notes['last_corrected_at']=date('c');
                $stmt=$pdo->prepare("UPDATE royalty_entries SET royalty_date=?,period_label=?,amount=?,volume_points=?,notes=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$date,$label,$amount,$vp,cc_json($notes),$organizationId,$entityId]);
                $after=['royalty_date'=>$date,'period_label'=>$label,'amount'=>$amount,'volume_points'=>$vp,'user_notes'=>$userNotes];
            }

            if ($before === $after) throw new RuntimeException('No business values changed. Nothing was corrected.');
            cc_audit($pdo,$organizationId,$clubId,'manual_' . $module . '_corrected',$meta['entity'],$entityId,[
                'source_record_id'=>$rawId,
                'correction_reason'=>$reason,
                'before'=>$before,
                'after'=>$after,
                'original_raw_immutable'=>true,
                'corrected_at'=>date('c'),
            ]);
            $pdo->commit();
            header('Location: correction_center.php?module='.rawurlencode($module).'&id='.$entityId.'&corrected=1'); exit;
        } catch (Throwable $writeError) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $writeError; }
    }

    if (isset($_GET['corrected']) && $_GET['corrected']==='1') $success='Correction saved. Original MANUAL raw evidence was not changed; before/after values and reason were written to Audit History.';

    $entrySql = "SELECT * FROM (
        SELECT 'new_ums' module,m.id entity_id,m.full_name label,m.join_date event_date,CONCAT('Member • ',COALESCE(m.member_type,'UMS')) summary,m.source_record_id raw_id,m.updated_at changed_at FROM members m WHERE m.organization_id=? AND m.source_sheet='Manual Entry'
        UNION ALL SELECT 'vp',v.id,COALESCE(v.member_name_snapshot,'Member'),v.entry_date,CONCAT(v.volume_points,' VP • ',COALESCE(v.order_type,'VP')),v.source_record_id,v.updated_at FROM volume_point_entries v WHERE v.organization_id=? AND v.source_sheet='Manual Entry'
        UNION ALL SELECT 'order',o.id,COALESCE(m.full_name,'Member'),o.order_date,CONCAT('Order • ',FORMAT(o.net_amount,2),' • ',o.volume_points,' VP'),o.source_record_id,o.updated_at FROM orders o LEFT JOIN members m ON m.id=o.member_id WHERE o.organization_id=? AND o.source_sheet='Manual Entry'
        UNION ALL SELECT 'renewal',n.id,COALESCE(m.full_name,'Member'),n.renewal_date,CONCAT('Renewal • ',FORMAT(n.amount,2),' • ',n.volume_points,' VP'),n.source_record_id,n.updated_at FROM renewals n LEFT JOIN members m ON m.id=n.member_id WHERE n.organization_id=? AND n.source_sheet='Manual Entry'
        UNION ALL SELECT 'income',i.id,UPPER(i.income_type),i.income_date,CONCAT('Income • ',FORMAT(i.amount,2)),i.source_record_id,i.updated_at FROM income_entries i WHERE i.organization_id=? AND i.source_sheet='Manual Entry'
        UNION ALL SELECT 'royalty',r.id,COALESCE(r.period_label,'Royalty'),r.royalty_date,CONCAT('Royalty • ',FORMAT(r.amount,2),' • ',r.volume_points,' VP'),r.source_record_id,r.updated_at FROM royalty_entries r WHERE r.organization_id=? AND r.source_sheet='Manual Entry'
    ) x ORDER BY COALESCE(event_date,'1900-01-01') DESC, changed_at DESC, entity_id DESC LIMIT 200";
    $stmt=$pdo->prepare($entrySql); $stmt->execute([$organizationId,$organizationId,$organizationId,$organizationId,$organizationId,$organizationId]); $entries=$stmt->fetchAll();

    if ($module!=='' && $entityId>0) {
        $meta=$moduleMap[$module]; $selected=cc_manual_guard($pdo,$organizationId,$meta['table'],$entityId);
        if ($module==='new_ums') { $stmt=$pdo->prepare("SELECT * FROM ums_records WHERE organization_id=? AND member_id=? AND source_record_id=? AND source_sheet='Manual Entry' ORDER BY id LIMIT 1"); $stmt->execute([$organizationId,$entityId,(int)$selected['raw_id']]); $selectedUms=$stmt->fetch() ?: null; }
    }

    $histStmt=$pdo->prepare("SELECT id,event_type,entity_type,entity_id,details_json,created_at FROM audit_logs WHERE organization_id=? AND event_type LIKE 'manual\\_%\\_corrected' ESCAPE '\\' ORDER BY id DESC LIMIT 80");
    $histStmt->execute([$organizationId]);
    foreach ($histStmt->fetchAll() as $row) { $row['details']=cc_json_array($row['details_json'] ?? null); $history[]=$row; }
} catch (Throwable $e) { $error=$e->getMessage(); }

function cc_member_options(array $members, mixed $selectedId, ?int $excludeId=null): string {
    $html='<option value="">No verified sponsor</option>';
    foreach ($members as $m) { $id=(int)$m['id']; if ($excludeId!==null && $id===$excludeId) continue; $sel=(string)$selectedId===(string)$id?' selected':''; $mobile=cc_trim($m['mobile'] ?? ''); $html.='<option value="'.$id.'"'.$sel.'>'.cc_h($m['full_name']).($mobile!==''?' • '.cc_h($mobile):'').' • #'.$id.'</option>'; }
    return $html;
}
function cc_value(array $row, string $key, mixed $fallback=''): string { return cc_h($row[$key] ?? $fallback); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Correction Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/correction_center.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Correction & Audit Center</small></span></a><div class="os-top-actions"><a class="os-btn" href="data_entry_center.php">+ Data Entry</a><a class="os-btn" href="operations_center.php">Operations</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout">
<aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a><a class="active" href="correction_center.php"><i class="dot"></i>Correction Center</a><a href="operations_center.php"><i class="dot"></i>Operations Center</a><a href="members.php"><i class="dot"></i>Members & Network</a><a href="report_center.php"><i class="dot"></i>Report Center</a></nav><div class="os-sidebar-status"><b>Audit-safe corrections</b><span>Only MANUAL entries can be changed here. Legacy Excel source remains read-only.</span></div></aside>
<main class="os-main">
<section class="os-hero cc-hero"><div class="os-kicker">Step 10I • Correction + Audit History</div><h1>Correct a daily manual entry without erasing what was originally submitted.</h1><p>The normalized business fact is corrected so Operations and Reports use the right value. The original MANUAL raw payload stays immutable, while Audit History stores the correction reason and complete before/after snapshot.</p><div class="os-status-row"><span class="os-chip good">CORRECTION CENTER LIVE</span><span class="os-chip good">Legacy Excel: READ ONLY</span><span class="os-chip good">Original Raw: IMMUTABLE</span><span class="os-chip"><?= number_format(count($history)) ?> recent correction audits</span></div></section>
<?php if ($success): ?><div class="cc-alert good"><strong>Saved:</strong> <?= cc_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cc-alert bad"><strong>Correction diagnostic:</strong> <?= cc_h($error) ?></div><?php endif; ?>
<?php if (!$error): ?>
<section class="cc-layout">
<aside class="os-card cc-entry-list"><div class="cc-head"><div><h2>Manual Entries</h2><p>Select the exact normalized fact you need to correct.</p></div><span><?= number_format(count($entries)) ?></span></div><input class="cc-search" id="ccSearch" placeholder="Search name, module, ID, date…" autocomplete="off"><div class="cc-rows" id="ccRows">
<?php if (!$entries): ?><div class="cc-empty">No manual entries yet. Nothing needs correction.</div><?php endif; ?>
<?php foreach ($entries as $entry): $active=$module===$entry['module'] && $entityId===(int)$entry['entity_id']; ?><a class="cc-entry <?= $active?'active':'' ?>" data-search="<?= cc_h(strtolower(implode(' ',[$entry['module'],$entry['entity_id'],$entry['label'],$entry['event_date'],$entry['summary']]))) ?>" href="?module=<?= cc_h($entry['module']) ?>&id=<?= (int)$entry['entity_id'] ?>"><div><b><?= cc_h($moduleMap[$entry['module']]['label']) ?> #<?= (int)$entry['entity_id'] ?></b><strong><?= cc_h($entry['label']) ?></strong><small><?= cc_h($entry['event_date']) ?> • <?= cc_h($entry['summary']) ?></small></div><em>RAW #<?= (int)$entry['raw_id'] ?></em></a><?php endforeach; ?>
</div></aside>
<div class="cc-main">
<?php if (!$selected): ?><article class="os-card cc-empty-main"><b>Select a manual entry</b><span>The correction form will appear here. Imported Excel records are intentionally unavailable for editing.</span></article><?php else: ?>
<article class="os-card cc-form-card"><div class="cc-head"><div><h2>Correct <?= cc_h($moduleMap[$module]['label']) ?> #<?= $entityId ?></h2><p>Original RAW #<?= (int)$selected['raw_id'] ?> will not be rewritten.</p></div><span class="cc-lock">RAW LOCKED</span></div>
<form method="post" class="cc-form"><input type="hidden" name="csrf" value="<?= cc_h($csrf) ?>"><input type="hidden" name="module" value="<?= cc_h($module) ?>"><input type="hidden" name="entity_id" value="<?= $entityId ?>"><input type="hidden" name="save_correction" value="1">
<?php if ($module==='new_ums'): ?>
<div class="cc-field wide"><label>Member Name</label><input name="full_name" required value="<?= cc_value($selected,'full_name') ?>"></div><div class="cc-field"><label>Mobile</label><input name="mobile" value="<?= cc_value($selected,'mobile') ?>"></div><div class="cc-field"><label>UMS Date</label><input type="date" name="join_date" required value="<?= cc_value($selected,'join_date',$selectedUms['start_date'] ?? '') ?>"></div><div class="cc-field"><label>UMS Type</label><input name="ums_type" value="<?= cc_h($selectedUms['set_type'] ?? $selected['member_type'] ?? '') ?>"></div><div class="cc-field"><label>Status</label><select name="status"><option value="active" <?= ($selected['status']??'')==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= ($selected['status']??'')==='inactive'?'selected':'' ?>>Inactive</option></select></div><div class="cc-field half"><label>Verified Sponsor</label><select name="sponsor_member_id"><?= cc_member_options($members,$selected['sponsor_member_id'] ?? null,$entityId) ?></select><small>Self-links and sponsor cycles are blocked.</small></div><div class="cc-note">Team/source labels are intentionally not edited here because they belong to the immutable original raw evidence.</div>
<?php endif; ?>
<?php if ($module==='vp'): ?>
<div class="cc-field half"><label>Member</label><select name="member_id" required><?= str_replace('No verified sponsor','Select verified member',cc_member_options($members,$selected['member_id'] ?? null)) ?></select></div><div class="cc-field"><label>Date</label><input type="date" name="entry_date" required value="<?= cc_value($selected,'entry_date') ?>"></div><div class="cc-field"><label>Volume Points</label><input type="number" step="0.001" min="0" name="volume_points" required value="<?= cc_value($selected,'volume_points') ?>"></div><div class="cc-field"><label>Order Type</label><input name="order_type" value="<?= cc_value($selected,'order_type') ?>"></div><div class="cc-field"><label>VP From</label><input name="vp_from" value="<?= cc_value($selected,'vp_from') ?>"></div><div class="cc-field"><label>Ordered By</label><input name="ordered_by" value="<?= cc_value($selected,'ordered_by') ?>"></div><div class="cc-field"><label>VP Type</label><input name="vp_type" value="<?= cc_value($selected,'vp_type') ?>"></div><div class="cc-field"><label>Level</label><input name="level_label" value="<?= cc_value($selected,'level_label') ?>"></div><div class="cc-field"><label>Week</label><input name="week_label" value="<?= cc_value($selected,'week_label') ?>" placeholder="Blank = auto"></div>
<?php endif; ?>
<?php if ($module==='order'): ?>
<div class="cc-field half"><label>Member</label><select name="member_id" required><?= str_replace('No verified sponsor','Select verified member',cc_member_options($members,$selected['member_id'] ?? null)) ?></select></div><div class="cc-field"><label>Order Date</label><input type="date" name="order_date" required value="<?= cc_value($selected,'order_date') ?>"></div><div class="cc-field"><label>Order Type</label><input name="order_type" value="<?= cc_value($selected,'order_type','regular') ?>"></div><div class="cc-field full"><label>Description</label><input name="description" value="<?= cc_value($selected,'description') ?>"></div><div class="cc-field"><label>Gross Amount</label><input type="number" step="0.01" min="0" name="gross_amount" required value="<?= cc_value($selected,'gross_amount') ?>"></div><div class="cc-field"><label>Discount</label><input type="number" step="0.01" min="0" name="discount_amount" required value="<?= cc_value($selected,'discount_amount') ?>"></div><div class="cc-field"><label>Net Amount</label><input readonly value="<?= cc_value($selected,'net_amount') ?>"><small>Recalculated as Gross − Discount.</small></div><div class="cc-field"><label>Profit</label><input type="number" step="0.01" name="profit_amount" required value="<?= cc_value($selected,'profit_amount') ?>"></div><div class="cc-field"><label>Volume Points</label><input type="number" step="0.001" min="0" name="volume_points" required value="<?= cc_value($selected,'volume_points') ?>"></div>
<?php endif; ?>
<?php if ($module==='renewal'): ?>
<div class="cc-field half"><label>Member</label><select name="member_id" required><?= str_replace('No verified sponsor','Select verified member',cc_member_options($members,$selected['member_id'] ?? null)) ?></select></div><div class="cc-field"><label>Renewal Date</label><input type="date" name="renewal_date" required value="<?= cc_value($selected,'renewal_date') ?>"></div><div class="cc-field"><label>Period Months</label><input type="number" min="1" max="120" name="period_months" value="<?= cc_value($selected,'period_months') ?>"></div><div class="cc-field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" required value="<?= cc_value($selected,'amount') ?>"></div><div class="cc-field"><label>Volume Points</label><input type="number" step="0.001" min="0" name="volume_points" required value="<?= cc_value($selected,'volume_points') ?>"></div>
<?php endif; ?>
<?php if ($module==='income'): $n=cc_json_array($selected['notes'] ?? null); ?>
<div class="cc-field"><label>Income Date</label><input type="date" name="income_date" required value="<?= cc_value($selected,'income_date') ?>"></div><div class="cc-field"><label>Income Type</label><select name="income_type"><option value="retail" <?= ($selected['income_type']??'')==='retail'?'selected':'' ?>>Retail</option><option value="check" <?= ($selected['income_type']??'')==='check'?'selected':'' ?>>Check</option><option value="club" <?= ($selected['income_type']??'')==='club'?'selected':'' ?>>Club</option><option value="other" <?= ($selected['income_type']??'')==='other'?'selected':'' ?>>Other</option></select></div><div class="cc-field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" required value="<?= cc_value($selected,'amount') ?>"></div><div class="cc-field full"><label>Notes</label><textarea name="notes_text"><?= cc_h($n['user_notes'] ?? '') ?></textarea></div>
<?php endif; ?>
<?php if ($module==='royalty'): $n=cc_json_array($selected['notes'] ?? null); ?>
<div class="cc-field"><label>Royalty Date</label><input type="date" name="royalty_date" required value="<?= cc_value($selected,'royalty_date') ?>"></div><div class="cc-field"><label>Period Label</label><input name="period_label" value="<?= cc_value($selected,'period_label') ?>"></div><div class="cc-field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" required value="<?= cc_value($selected,'amount') ?>"></div><div class="cc-field"><label>Volume Points</label><input type="number" step="0.001" min="0" name="volume_points" required value="<?= cc_value($selected,'volume_points') ?>"></div><div class="cc-field full"><label>Notes</label><textarea name="notes_text"><?= cc_h($n['user_notes'] ?? '') ?></textarea></div>
<?php endif; ?>
<div class="cc-reason"><label>Correction Reason <b>Required</b></label><textarea name="correction_reason" minlength="5" maxlength="500" required placeholder="Example: Order amount was entered incorrectly from the invoice."></textarea><small>This reason becomes permanent audit history.</small></div><div class="cc-actions"><span>Only normalized values change. RAW #<?= (int)$selected['raw_id'] ?> remains untouched.</span><button type="submit">Save Audited Correction →</button></div>
</form></article>
<?php endif; ?>
<article class="os-card cc-history"><div class="cc-head"><div><h2>Audit History</h2><p>Permanent correction log with reason and before/after snapshots.</p></div><span><?= number_format(count($history)) ?></span></div>
<?php if (!$history): ?><div class="cc-empty">No corrections have been made yet.</div><?php endif; ?>
<?php foreach ($history as $h): $d=$h['details']; ?><details class="cc-audit"><summary><div><b><?= cc_h(str_replace('_',' ',$h['event_type'])) ?> • #<?= (int)$h['entity_id'] ?></b><span><?= cc_h($h['created_at']) ?> • RAW #<?= (int)($d['source_record_id'] ?? 0) ?></span></div><em>View change</em></summary><div class="cc-audit-body"><div class="cc-reason-view"><b>Reason</b><span><?= cc_h($d['correction_reason'] ?? '—') ?></span></div><div class="cc-diff"><div><b>Before</b><pre><?= cc_h(json_encode($d['before'] ?? [],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></div><div><b>After</b><pre><?= cc_h(json_encode($d['after'] ?? [],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></div></div></div></details><?php endforeach; ?>
</article>
</div></section>
<?php endif; ?>
<div class="os-footer-note"><strong>Correction policy:</strong> imported/legacy facts are historical source evidence and stay locked. MANUAL normalized facts can be corrected only with an explicit reason; the original raw payload remains immutable and every change is auditable.</div>
</main></div>
<script>const s=document.getElementById('ccSearch'),rows=[...document.querySelectorAll('.cc-entry')];if(s){s.addEventListener('input',()=>{const q=s.value.trim().toLowerCase();rows.forEach(r=>r.hidden=q!==''&&!r.dataset.search.includes(q));});}</script>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
