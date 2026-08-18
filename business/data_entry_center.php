<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/data_entry_smart.php';

function de_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function de_trim(mixed $value): string { return trim((string)$value); }
function de_old(string $name, mixed $default=''): string { return de_h($_POST[$name] ?? $default); }
function de_selected(string $name, mixed $value): string { return (string)($_POST[$name] ?? '') === (string)$value ? 'selected' : ''; }
function de_json(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Entry payload could not be encoded.');
    return $json;
}
function de_decimal(mixed $value, bool $allowBlank=false): ?float {
    $raw = str_replace([',','₹',' '], '', de_trim($value));
    if ($raw === '') { if ($allowBlank) return null; throw new RuntimeException('A required numeric value is missing.'); }
    if (!is_numeric($raw)) throw new RuntimeException('A numeric field contains an invalid value.');
    return (float)$raw;
}
function de_date(mixed $value): string {
    $raw = de_trim($value);
    if ($raw === '') throw new RuntimeException('Date is required.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $raw) {
        throw new RuntimeException('Date is invalid.');
    }
    return $raw;
}
function de_nonnegative(float $value, string $label): void { if ($value < 0) throw new RuntimeException($label . ' cannot be negative.'); }
function de_mobile(mixed $value): ?string {
    $raw = de_trim($value);
    if ($raw === '') return null;
    $digits = business_entry_smart_mobile_digits($raw);
    if (strlen($digits) < 10 || strlen($digits) > 15 || preg_match('/^0+$/', $digits)) {
        throw new RuntimeException('Mobile number must contain 10–15 valid digits.');
    }
    return str_starts_with($raw, '+') ? '+' . $digits : $digits;
}
function de_cut(string $text, int $length): string { return function_exists('mb_substr') ? mb_substr($text,0,$length,'UTF-8') : substr($text,0,$length); }
function de_member(PDO $pdo, int $organizationId, int $memberId): array {
    if ($memberId <= 0) throw new RuntimeException('Select a verified member.');
    $stmt = $pdo->prepare('SELECT id, full_name, mobile FROM members WHERE organization_id=? AND id=? LIMIT 1');
    $stmt->execute([$organizationId,$memberId]);
    $member = $stmt->fetch();
    if (!$member) throw new RuntimeException('Selected member does not exist in this organization.');
    return $member;
}
function de_external_id(string $module): string {
    $safe = preg_replace('/[^a-z0-9_-]+/i','-',$module) ?: 'entry';
    return 'manual-' . strtolower($safe) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}
function de_create_raw(PDO $pdo,int $organizationId,int $clubId,int $sourceId,string $dataset,string $externalId,array $payload): int {
    $json = de_json($payload);
    $stmt = $pdo->prepare("INSERT INTO raw_source_records
        (organization_id,club_id,data_source_id,import_batch_id,source_dataset,external_record_id,source_row,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id,error_message)
        VALUES (?,?,?,NULL,?,?,NULL,NOW(),?,?,'pending',NULL,NULL,NULL)");
    $stmt->execute([$organizationId,$clubId,$sourceId,$dataset,$externalId,hash('sha256',$json),$json]);
    return (int)$pdo->lastInsertId();
}
function de_map_raw(PDO $pdo,int $rawId,string $entityType,int $entityId): void {
    $stmt = $pdo->prepare("UPDATE raw_source_records SET mapping_status='mapped',mapped_entity_type=?,mapped_entity_id=?,error_message=NULL,updated_at=NOW() WHERE id=? AND mapping_status='pending'");
    $stmt->execute([$entityType,$entityId,$rawId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Raw source trace changed unexpectedly while saving.');
}
function de_audit(PDO $pdo,int $organizationId,int $clubId,string $eventType,string $entityType,int $entityId,array $details): void {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$organizationId,$clubId,$eventType,$entityType,$entityId,de_json($details),substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)]);
}

if (empty($_SESSION['business_entry_csrf'])) $_SESSION['business_entry_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['business_entry_csrf'];
$modules = [
    'new_ums'=>['label'=>'New UMS','short'=>'UMS','desc'=>'Create a new member and UMS lifecycle together.'],
    'vp'=>['label'=>'Volume Points','short'=>'VP','desc'=>'Add verified member-linked Volume Points.'],
    'order'=>['label'=>'Order','short'=>'ORD','desc'=>'Add an order with automatic Net calculation, profit and VP.'],
    'renewal'=>['label'=>'Renewal','short'=>'REN','desc'=>'Record a verified member UMS renewal.'],
    'income'=>['label'=>'Income','short'=>'INC','desc'=>'Record Retail, Check, Club or Other income.'],
    'royalty'=>['label'=>'Royalty','short'=>'ROY','desc'=>'Record royalty amount and optional VP.'],
];
$tab = de_trim($_GET['tab'] ?? ($_POST['module'] ?? 'new_ums'));
if (!isset($modules[$tab])) $tab='new_ums';

$error=null; $success=null; $organizationId=0; $clubId=0; $manualSourceId=0; $currencyCode='INR';
$sourceTotal=0; $sourceMapped=0; $sourcePending=0; $members=[]; $recentManual=[];
$manualCounts=['members'=>0,'vp'=>0,'orders'=>0,'renewals'=>0,'income'=>0,'royalty'=>0];
$today=date('Y-m-d');

try {
    $pdo=business_db();
    foreach (['organizations','clubs','data_sources','raw_source_records','members','ums_records','volume_point_entries','orders','renewals','income_entries','royalty_entries','audit_logs'] as $table) {
        if (!business_table_exists($pdo,$table)) throw new RuntimeException("Required table {$table} is missing.");
    }
    $org=$pdo->query("SELECT id,default_currency_code,timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    $organizationId=(int)$org['id']; $currencyCode=(string)($org['default_currency_code'] ?: 'INR');
    @date_default_timezone_set((string)($org['timezone'] ?: 'Asia/Kolkata')); $today=date('Y-m-d');

    $stmt=$pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1"); $stmt->execute([$organizationId]); $clubId=(int)$stmt->fetchColumn();
    if ($clubId<=0) throw new RuntimeException('Ghazipur club was not found.');
    $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' AND is_active=1 LIMIT 1"); $stmt->execute([$organizationId]); $manualSourceId=(int)$stmt->fetchColumn();
    if ($manualSourceId<=0) throw new RuntimeException('MANUAL data source is not active.');

    $stmt=$pdo->prepare("SELECT COUNT(*) total_rows,SUM(mapping_status='mapped') mapped_rows,SUM(mapping_status='pending') pending_rows FROM raw_source_records WHERE organization_id=? AND source_dataset IN ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");
    $stmt->execute([$organizationId]); $state=$stmt->fetch() ?: [];
    $sourceTotal=(int)($state['total_rows'] ?? 0); $sourceMapped=(int)($state['mapped_rows'] ?? 0); $sourcePending=(int)($state['pending_rows'] ?? 0);
    if ($sourceTotal!==757 || $sourceMapped!==757 || $sourcePending!==0) throw new RuntimeException('Legacy operational source must remain reconciled at 757/757 before manual entry is enabled.');

    $stmt=$pdo->prepare("SELECT id,full_name,mobile,status FROM members WHERE organization_id=? ORDER BY full_name,id"); $stmt->execute([$organizationId]); $members=$stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        $module=de_trim($_POST['module'] ?? ''); if (!isset($modules[$module])) throw new RuntimeException('Unknown entry module.');
        $dup=business_entry_smart_duplicate($pdo,$organizationId,$module,$_POST);
        if ($dup['duplicate'] && ($_POST['confirm_duplicate'] ?? '')!=='yes') throw new RuntimeException($dup['message'] . ' Tick “Save anyway after review” only if this is genuinely a separate entry.');

        $externalId=de_external_id($module); $sourceKey=str_replace('manual-','manual:',$externalId); $dataset='Manual Business Entry • '.$modules[$module]['label'];
        $entityId=0; $entityType='';
        $pdo->beginTransaction();
        try {
            if ($module==='new_ums') {
                $name=preg_replace('/\s+/u',' ',de_trim($_POST['full_name'] ?? '')) ?: '';
                if (strlen($name)<2 || strlen($name)>180) throw new RuntimeException('Member name must be between 2 and 180 characters.');
                $mobile=de_mobile($_POST['mobile'] ?? ''); $umsDate=de_date($_POST['ums_date'] ?? ''); $umsType=de_trim($_POST['ums_type'] ?? ''); $team=de_trim($_POST['team'] ?? '');
                $status=de_trim($_POST['status'] ?? 'active'); if (!in_array($status,['active','inactive'],true)) throw new RuntimeException('UMS status is invalid.');
                $sponsorId=(isset($_POST['sponsor_member_id']) && is_numeric($_POST['sponsor_member_id'])) ? (int)$_POST['sponsor_member_id'] : 0; $sponsor=null;
                if ($sponsorId>0) $sponsor=de_member($pdo,$organizationId,$sponsorId);
                $payload=['channel'=>'business-os-manual','module'=>'new_ums','full_name'=>$name,'mobile'=>$mobile,'ums_date'=>$umsDate,'ums_type'=>$umsType,'team'=>$team,'status'=>$status,'sponsor_member_id'=>$sponsorId ?: null,'sponsor_name'=>$sponsor['full_name'] ?? null];
                $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO members (organization_id,primary_club_id,full_name,mobile,country_code,sponsor_member_id,member_type,join_date,status,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,'IN',?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,$name,$mobile,$sponsorId ?: null,$umsType ?: null,$umsDate,$status,de_json(['entry_channel'=>'Business OS Manual','team_source'=>$team,'identity_policy'=>'new-manual-member-row']),$rawId,$sourceKey.':member']);
                $memberId=(int)$pdo->lastInsertId();
                $insert=$pdo->prepare("INSERT INTO ums_records (organization_id,club_id,member_id,set_type,start_date,status,amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,?,?,0,?,0,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,$memberId,$umsType ?: null,$umsDate,$status,$currencyCode,de_json(['entry_channel'=>'Business OS Manual','team_source'=>$team]),$rawId,$sourceKey.':ums']);
                $umsId=(int)$pdo->lastInsertId(); $entityId=$memberId; $entityType='member'; de_map_raw($pdo,$rawId,$entityType,$entityId);
                de_audit($pdo,$organizationId,$clubId,'manual_new_ums_created',$entityType,$entityId,['source_record_id'=>$rawId,'ums_id'=>$umsId,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($module==='vp') {
                $member=de_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $entryDate=de_date($_POST['entry_date'] ?? ''); $vp=(float)de_decimal($_POST['volume_points'] ?? ''); de_nonnegative($vp,'Volume Points');
                $orderType=de_trim($_POST['order_type'] ?? ''); $vpFrom=de_trim($_POST['vp_from'] ?? ''); $orderedBy=de_trim($_POST['ordered_by'] ?? ''); $vpType=de_trim($_POST['vp_type'] ?? ''); $level=de_trim($_POST['level_label'] ?? '');
                $week=de_trim($_POST['week_label'] ?? ''); if ($week==='') $week=business_entry_smart_week_label($entryDate);
                $payload=['channel'=>'business-os-manual','module'=>'vp','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],'entry_date'=>$entryDate,'volume_points'=>$vp,'order_type'=>$orderType,'vp_from'=>$vpFrom,'ordered_by'=>$orderedBy,'vp_type'=>$vpType,'level_label'=>$level,'week_label'=>$week];
                $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO volume_point_entries (organization_id,club_id,member_id,member_name_snapshot,entry_date,level_label,week_label,volume_points,order_type,vp_from,ordered_by,vp_type,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,(int)$member['id'],$member['full_name'],$entryDate,$level ?: null,$week,$vp,$orderType ?: null,$vpFrom ?: null,$orderedBy ?: null,$vpType ?: null,de_json(['entry_channel'=>'Business OS Manual']),$rawId,$sourceKey]);
                $entityId=(int)$pdo->lastInsertId(); $entityType='volume_point_entry'; de_map_raw($pdo,$rawId,$entityType,$entityId); de_audit($pdo,$organizationId,$clubId,'manual_vp_created',$entityType,$entityId,['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'vp'=>$vp,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($module==='order') {
                $member=de_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $orderDate=de_date($_POST['order_date'] ?? ''); $orderType=de_trim($_POST['order_type'] ?? 'regular') ?: 'regular'; $description=de_trim($_POST['description'] ?? '');
                $gross=(float)de_decimal($_POST['gross_amount'] ?? ''); $discount=(float)de_decimal($_POST['discount_amount'] ?? '0'); $net=de_decimal($_POST['net_amount'] ?? '',true); $profit=(float)de_decimal($_POST['profit_amount'] ?? '0'); $vp=(float)de_decimal($_POST['volume_points'] ?? '0');
                de_nonnegative($gross,'Gross amount'); de_nonnegative($discount,'Discount'); de_nonnegative($vp,'Volume Points'); if ($discount>$gross) throw new RuntimeException('Discount cannot be greater than Gross Amount.');
                $calculatedNet=round($gross-$discount,2); if ($net===null) $net=$calculatedNet; if (abs($net-$calculatedNet)>0.01) throw new RuntimeException('Net Amount must equal Gross Amount − Discount.');
                $payload=['channel'=>'business-os-manual','module'=>'order','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],'order_date'=>$orderDate,'order_type'=>$orderType,'description'=>$description,'gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>$net,'profit_amount'=>$profit,'volume_points'=>$vp];
                $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO orders (organization_id,club_id,member_id,order_date,order_type,description,gross_amount,discount_amount,net_amount,profit_amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,(int)$member['id'],$orderDate,$orderType,de_cut($description ?: 'Manual Business Order',255),$gross,$discount,$net,$profit,$currencyCode,$vp,de_json(['entry_channel'=>'Business OS Manual','member_name_snapshot'=>$member['full_name']]),$rawId,$sourceKey]);
                $entityId=(int)$pdo->lastInsertId(); $entityType='order'; de_map_raw($pdo,$rawId,$entityType,$entityId); de_audit($pdo,$organizationId,$clubId,'manual_order_created',$entityType,$entityId,['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'net_amount'=>$net,'profit'=>$profit,'vp'=>$vp,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($module==='renewal') {
                $member=de_member($pdo,$organizationId,(int)($_POST['member_id'] ?? 0)); $renewalDate=de_date($_POST['renewal_date'] ?? ''); $monthsRaw=de_trim($_POST['period_months'] ?? ''); $periodMonths=null;
                if ($monthsRaw!=='') { if (!ctype_digit($monthsRaw) || (int)$monthsRaw<1 || (int)$monthsRaw>120) throw new RuntimeException('Renewal period must be 1–120 months.'); $periodMonths=(int)$monthsRaw; }
                $amount=(float)de_decimal($_POST['amount'] ?? '0'); $vp=(float)de_decimal($_POST['volume_points'] ?? '0'); de_nonnegative($amount,'Renewal amount'); de_nonnegative($vp,'Volume Points');
                $stmt=$pdo->prepare("SELECT id FROM ums_records WHERE organization_id=? AND member_id=? ORDER BY start_date DESC,id DESC LIMIT 1"); $stmt->execute([$organizationId,(int)$member['id']]); $umsId=$stmt->fetchColumn(); $umsId=$umsId!==false?(int)$umsId:null;
                $payload=['channel'=>'business-os-manual','module'=>'renewal','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],'renewal_date'=>$renewalDate,'period_months'=>$periodMonths,'amount'=>$amount,'volume_points'=>$vp,'ums_record_id'=>$umsId];
                $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO renewals (organization_id,club_id,member_id,ums_record_id,renewal_date,period_months,amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,(int)$member['id'],$umsId,$renewalDate,$periodMonths,$amount,$currencyCode,$vp,de_json(['entry_channel'=>'Business OS Manual','member_name_snapshot'=>$member['full_name']]),$rawId,$sourceKey]);
                $entityId=(int)$pdo->lastInsertId(); $entityType='renewal'; de_map_raw($pdo,$rawId,$entityType,$entityId); de_audit($pdo,$organizationId,$clubId,'manual_renewal_created',$entityType,$entityId,['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'amount'=>$amount,'vp'=>$vp,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($module==='income') {
                $incomeDate=de_date($_POST['income_date'] ?? ''); $incomeType=de_trim($_POST['income_type'] ?? ''); if (!in_array($incomeType,['retail','check','club','other'],true)) throw new RuntimeException('Income type is invalid.');
                $amount=(float)de_decimal($_POST['amount'] ?? ''); de_nonnegative($amount,'Income amount'); $notesText=de_trim($_POST['notes'] ?? ''); $periodKey=(new DateTimeImmutable($incomeDate))->format('Y-m');
                $payload=['channel'=>'business-os-manual','module'=>'income','income_date'=>$incomeDate,'income_type'=>$incomeType,'amount'=>$amount,'period_key'=>$periodKey,'notes'=>$notesText]; $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO income_entries (organization_id,club_id,income_date,member_id,income_type,amount,currency_code,period_key,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,NULL,?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,$incomeDate,$incomeType,$amount,$currencyCode,$periodKey,de_json(['entry_channel'=>'Business OS Manual','user_notes'=>$notesText]),$rawId,$sourceKey]);
                $entityId=(int)$pdo->lastInsertId(); $entityType='income_entry'; de_map_raw($pdo,$rawId,$entityType,$entityId); de_audit($pdo,$organizationId,$clubId,'manual_income_created',$entityType,$entityId,['source_record_id'=>$rawId,'income_type'=>$incomeType,'amount'=>$amount,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($module==='royalty') {
                $royaltyDate=de_date($_POST['royalty_date'] ?? ''); $periodLabel=de_trim($_POST['period_label'] ?? ''); if ($periodLabel==='') $periodLabel=business_entry_smart_week_label($royaltyDate);
                $amount=(float)de_decimal($_POST['amount'] ?? ''); $vp=(float)de_decimal($_POST['volume_points'] ?? '0'); de_nonnegative($amount,'Royalty amount'); de_nonnegative($vp,'Volume Points'); $notesText=de_trim($_POST['notes'] ?? '');
                $payload=['channel'=>'business-os-manual','module'=>'royalty','royalty_date'=>$royaltyDate,'period_label'=>$periodLabel,'amount'=>$amount,'volume_points'=>$vp,'notes'=>$notesText]; $rawId=de_create_raw($pdo,$organizationId,$clubId,$manualSourceId,$dataset,$externalId,$payload);
                $insert=$pdo->prepare("INSERT INTO royalty_entries (organization_id,club_id,royalty_date,period_label,amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key) VALUES (?,?,?,?,?,?,?,?,?,'Manual Entry',?)");
                $insert->execute([$organizationId,$clubId,$royaltyDate,$periodLabel,$amount,$currencyCode,$vp,de_json(['entry_channel'=>'Business OS Manual','user_notes'=>$notesText]),$rawId,$sourceKey]);
                $entityId=(int)$pdo->lastInsertId(); $entityType='royalty_entry'; de_map_raw($pdo,$rawId,$entityType,$entityId); de_audit($pdo,$organizationId,$clubId,'manual_royalty_created',$entityType,$entityId,['source_record_id'=>$rawId,'amount'=>$amount,'vp'=>$vp,'duplicate_override'=>$dup['duplicate']]);
            }
            if ($entityId<=0 || $entityType==='') throw new RuntimeException('Entry could not be mapped to a normalized entity.');
            $pdo->commit(); header('Location: data_entry_center.php?tab='.rawurlencode($module).'&saved='.rawurlencode($entityType).'&id='.$entityId); exit;
        } catch (Throwable $writeError) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $writeError; }
    }

    if (isset($_GET['saved'],$_GET['id']) && is_numeric($_GET['id'])) $success='Saved successfully: '.de_trim($_GET['saved']).' #'.(int)$_GET['id'].'. Raw source, normalized fact and audit were committed together.';
    $countQueries=[
        'members'=>"SELECT COUNT(*) FROM members WHERE organization_id=? AND source_sheet='Manual Entry'",
        'vp'=>"SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'orders'=>"SELECT COUNT(*) FROM orders WHERE organization_id=? AND source_sheet='Manual Entry'",
        'renewals'=>"SELECT COUNT(*) FROM renewals WHERE organization_id=? AND source_sheet='Manual Entry'",
        'income'=>"SELECT COUNT(*) FROM income_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'royalty'=>"SELECT COUNT(*) FROM royalty_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
    ];
    foreach ($countQueries as $key=>$sql) { $stmt=$pdo->prepare($sql); $stmt->execute([$organizationId]); $manualCounts[$key]=(int)$stmt->fetchColumn(); }
    $stmt=$pdo->prepare("SELECT id,source_dataset,mapped_entity_type,mapped_entity_id,captured_at FROM raw_source_records WHERE organization_id=? AND data_source_id=? ORDER BY id DESC LIMIT 12"); $stmt->execute([$organizationId,$manualSourceId]); $recentManual=$stmt->fetchAll();
} catch (Throwable $e) { $error=$e->getMessage(); }

$ready=$error===null && $sourceTotal===757 && $sourceMapped===757 && $sourcePending===0 && $manualSourceId>0;
$manualTotal=array_sum($manualCounts);
function de_member_options(array $members, string $name): string {
    $selected=(string)($_POST[$name] ?? ''); $html='<option value="">Select verified member</option>';
    foreach ($members as $m) {
        $id=(int)$m['id']; $mobile=de_trim($m['mobile'] ?? ''); $text=(string)$m['full_name'].($mobile!==''?' • '.$mobile:'').' • #'.$id;
        $search=(string)$m['full_name'].' '.$mobile.' '.$id.' '.(string)($m['status'] ?? '');
        $html.='<option value="'.$id.'" data-search="'.de_h($search).'"'.($selected===(string)$id?' selected':'').'>'.de_h($text).'</option>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Smart Data Entry 2.0 - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/data_entry.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Smart Data Entry 2.0</small></span></a><div class="os-top-actions"><a class="os-btn" href="operations_center.php">Operations</a><a class="os-btn" href="members.php">Members</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout">
<aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a class="active" href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a><a href="operations_center.php"><i class="dot"></i>Operations Center</a><a href="members.php"><i class="dot"></i>Members & Network</a><a href="member_profile.php"><i class="dot"></i>Member Profile 360°</a><a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a><a href="report_center.php"><i class="dot"></i>Report Center</a></nav><div class="os-sidebar-status"><b><?= $ready?'Smart entry ready':'Review required' ?></b><span><?= number_format($sourceMapped) ?> / 757 legacy source mapped • duplicate guard + raw trace active.</span></div></aside>
<main class="os-main">
<section class="os-hero de-hero"><div class="os-kicker">Step 10H • Smart Data Entry 2.0</div><h1>Faster forms, searchable members and duplicate protection before every save.</h1><p>Daily New UMS, VP, Orders, Renewals, Income and Royalty now use searchable member pickers, live calculations, automatic week labels, server validation and a duplicate preflight. Similar identities are warned — never silently merged.</p><div class="os-status-row"><span class="os-chip <?= $ready?'good':'' ?>"><?= $ready?'SMART ENTRY 2.0 LIVE':'Review required' ?></span><span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 legacy mapped</span><span class="os-chip"><?= number_format($manualTotal) ?> manual facts</span><span class="os-chip">Duplicate Guard ON</span></div></section>
<?php if ($success!==null): ?><div class="de-alert good"><strong>Saved:</strong> <?= de_h($success) ?></div><?php endif; ?>
<?php if ($error!==null): ?><div class="de-alert bad"><strong>Data Entry diagnostic:</strong> <?= de_h($error) ?></div><?php endif; ?>
<?php if ($error===null || $_SERVER['REQUEST_METHOD']==='POST'): ?>
<section class="os-grid de-kpis"><article class="os-card os-kpi green"><small>Manual New UMS</small><strong><?= number_format($manualCounts['members']) ?></strong><span>Identity-safe member rows</span></article><article class="os-card os-kpi blue"><small>Manual VP</small><strong><?= number_format($manualCounts['vp']) ?></strong><span>Verified member-linked facts</span></article><article class="os-card os-kpi gold"><small>Manual Orders</small><strong><?= number_format($manualCounts['orders']) ?></strong><span>Live Net + duplicate guard</span></article><article class="os-card os-kpi violet"><small>Other Facts</small><strong><?= number_format($manualCounts['renewals']+$manualCounts['income']+$manualCounts['royalty']) ?></strong><span>Renewal + Income + Royalty</span></article></section>
<section class="de-layout"><aside class="de-tabs"><h3>Choose Entry Type</h3><div class="de-tab-list"><?php foreach($modules as $key=>$module): ?><a class="de-tab <?= $tab===$key?'active':'' ?>" href="?tab=<?= de_h($key) ?>"><i><?= de_h($module['short']) ?></i><?= de_h($module['label']) ?></a><?php endforeach; ?></div><div class="de-safety"><strong>Smart Guard:</strong> browser preflight checks likely duplicates before submit; server checks them again before the transaction starts.</div></aside>
<div class="de-main"><article class="os-card de-form-card"><div class="de-form-head"><div><h2><?= de_h($modules[$tab]['label']) ?></h2><p><?= de_h($modules[$tab]['desc']) ?></p></div><span class="de-form-badge">SEARCH • CHECK • SAVE</span></div>
<?php if ($tab==='new_ums'): ?>
<form method="post" class="de-form" data-smart-entry><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="new_ums">
<div class="de-field wide"><label>Member Name</label><input name="full_name" value="<?= de_old('full_name') ?>" required autocomplete="off" maxlength="180" placeholder="Full member name"></div><div class="de-field"><label>Mobile</label><input name="mobile" value="<?= de_old('mobile') ?>" inputmode="tel" autocomplete="off" placeholder="Optional • 10–15 digits"></div><div class="de-field"><label>UMS Date</label><input type="date" name="ums_date" value="<?= de_old('ums_date',$today) ?>" required></div><div class="de-field"><label>UMS Type</label><input name="ums_type" value="<?= de_old('ums_type') ?>" placeholder="Type / set label"></div><div class="de-field"><label>Status</label><select name="status"><option value="active" <?= de_selected('status','active') ?>>Active</option><option value="inactive" <?= de_selected('status','inactive') ?>>Inactive</option></select></div><div class="de-field half"><label>Team</label><input name="team" value="<?= de_old('team') ?>" placeholder="Source team label"></div>
<div class="de-field half"><label>Verified Sponsor</label><div class="de-member-picker" data-member-picker><input class="de-member-search" type="search" data-member-search placeholder="Search sponsor by name, mobile or ID"><select name="sponsor_member_id"><?= de_member_options($members,'sponsor_member_id') ?></select><small data-member-count></small></div><span class="de-help">Selection creates an explicit verified sponsor link.</span></div>
<div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate / similar identity found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review — this is genuinely a separate member/UMS row.</label></div><div class="de-actions"><span data-preflight-status>Duplicate guard ready</span><button type="submit">Save New UMS →</button></div></form>
<?php endif; ?>
<?php if ($tab==='vp'): ?>
<form method="post" class="de-form" data-smart-entry><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="vp"><div class="de-field half"><label>Member</label><div class="de-member-picker" data-member-picker><input class="de-member-search" type="search" data-member-search placeholder="Type name, mobile or member ID"><select name="member_id" required><?= de_member_options($members,'member_id') ?></select><small data-member-count></small></div></div><div class="de-field"><label>Date</label><input type="date" name="entry_date" value="<?= de_old('entry_date',$today) ?>" required></div><div class="de-field"><label>Volume Points</label><input type="number" min="0" step="0.001" name="volume_points" value="<?= de_old('volume_points') ?>" required></div><div class="de-field"><label>Order Type</label><input name="order_type" value="<?= de_old('order_type') ?>" placeholder="New UMS / Renewal / Personal"></div><div class="de-field"><label>VP From</label><input name="vp_from" value="<?= de_old('vp_from') ?>" placeholder="UMS / 1st Line"></div><div class="de-field"><label>Ordered By</label><input name="ordered_by" value="<?= de_old('ordered_by') ?>" placeholder="PC / AS"></div><div class="de-field"><label>VP Type</label><input name="vp_type" value="<?= de_old('vp_type') ?>" placeholder="Personal / Team VP"></div><div class="de-field"><label>Level</label><input name="level_label" value="<?= de_old('level_label') ?>" placeholder="Optional"></div><div class="de-field"><label>Week</label><input name="week_label" value="<?= de_old('week_label') ?>" data-auto-week="entry_date" placeholder="Auto"></div><div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate VP found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review.</label></div><div class="de-actions"><span data-preflight-status>Duplicate guard ready • Week auto-calculated</span><button type="submit">Save VP →</button></div></form>
<?php endif; ?>
<?php if ($tab==='order'): ?>
<form method="post" class="de-form" data-smart-entry data-order-calculator><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="order"><div class="de-field half"><label>Member</label><div class="de-member-picker" data-member-picker><input class="de-member-search" type="search" data-member-search placeholder="Type name, mobile or member ID"><select name="member_id" required><?= de_member_options($members,'member_id') ?></select><small data-member-count></small></div></div><div class="de-field"><label>Order Date</label><input type="date" name="order_date" value="<?= de_old('order_date',$today) ?>" required></div><div class="de-field"><label>Order Type</label><input name="order_type" value="<?= de_old('order_type','regular') ?>"></div><div class="de-field full"><label>Description</label><input name="description" value="<?= de_old('description') ?>" maxlength="255" placeholder="Order note / set / purpose"></div><div class="de-field"><label>Gross Amount</label><input type="number" min="0" step="0.01" name="gross_amount" value="<?= de_old('gross_amount') ?>" required></div><div class="de-field"><label>Discount</label><input type="number" min="0" step="0.01" name="discount_amount" value="<?= de_old('discount_amount','0') ?>" required></div><div class="de-field"><label>Net Amount</label><input type="number" min="0" step="0.01" name="net_amount" value="<?= de_old('net_amount') ?>" placeholder="Auto"><span class="de-help" data-net-preview>Calculated Net: ₹0.00</span></div><div class="de-field"><label>Profit</label><input type="number" step="0.01" name="profit_amount" value="<?= de_old('profit_amount','0') ?>" required></div><div class="de-field"><label>Volume Points</label><input type="number" min="0" step="0.001" name="volume_points" value="<?= de_old('volume_points','0') ?>" required></div><div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate order found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review.</label></div><div class="de-actions"><span data-preflight-status>Net = Gross − Discount • Duplicate guard ready</span><button type="submit">Save Order →</button></div></form>
<?php endif; ?>
<?php if ($tab==='renewal'): ?>
<form method="post" class="de-form" data-smart-entry><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="renewal"><div class="de-field half"><label>Member</label><div class="de-member-picker" data-member-picker><input class="de-member-search" type="search" data-member-search placeholder="Type name, mobile or member ID"><select name="member_id" required><?= de_member_options($members,'member_id') ?></select><small data-member-count></small></div></div><div class="de-field"><label>Renewal Date</label><input type="date" name="renewal_date" value="<?= de_old('renewal_date',$today) ?>" required></div><div class="de-field"><label>Period Months</label><input type="number" min="1" max="120" name="period_months" value="<?= de_old('period_months') ?>" placeholder="Optional"></div><div class="de-field half"><label>Amount</label><input type="number" min="0" step="0.01" name="amount" value="<?= de_old('amount','0') ?>" required></div><div class="de-field half"><label>Volume Points</label><input type="number" min="0" step="0.001" name="volume_points" value="<?= de_old('volume_points','0') ?>" required></div><div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate renewal found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review.</label></div><div class="de-actions"><span data-preflight-status>Latest verified UMS will be linked • Duplicate guard ready</span><button type="submit">Save Renewal →</button></div></form>
<?php endif; ?>
<?php if ($tab==='income'): ?>
<form method="post" class="de-form" data-smart-entry><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="income"><div class="de-field"><label>Income Date</label><input type="date" name="income_date" value="<?= de_old('income_date',$today) ?>" required></div><div class="de-field"><label>Income Type</label><select name="income_type" required><option value="retail" <?= de_selected('income_type','retail') ?>>Retail</option><option value="check" <?= de_selected('income_type','check') ?>>Check</option><option value="club" <?= de_selected('income_type','club') ?>>Club</option><option value="other" <?= de_selected('income_type','other') ?>>Other</option></select></div><div class="de-field"><label>Amount</label><input type="number" min="0" step="0.01" name="amount" value="<?= de_old('amount') ?>" required></div><div class="de-field full"><label>Notes</label><textarea name="notes" placeholder="Optional note"><?= de_old('notes') ?></textarea></div><div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate income found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review.</label></div><div class="de-actions"><span data-preflight-status>Organization-level income • Duplicate guard ready</span><button type="submit">Save Income →</button></div></form>
<?php endif; ?>
<?php if ($tab==='royalty'): ?>
<form method="post" class="de-form" data-smart-entry><input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="royalty"><div class="de-field"><label>Royalty Date</label><input type="date" name="royalty_date" value="<?= de_old('royalty_date',$today) ?>" required></div><div class="de-field"><label>Period Label</label><input name="period_label" value="<?= de_old('period_label') ?>" data-auto-week="royalty_date" placeholder="Auto Week"></div><div class="de-field"><label>Amount</label><input type="number" min="0" step="0.01" name="amount" value="<?= de_old('amount') ?>" required></div><div class="de-field"><label>Volume Points</label><input type="number" min="0" step="0.001" name="volume_points" value="<?= de_old('volume_points','0') ?>" required></div><div class="de-field wide"><label>Notes</label><textarea name="notes" placeholder="Optional note"><?= de_old('notes') ?></textarea></div><div class="de-duplicate-panel" data-duplicate-panel hidden><strong>Possible duplicate royalty found</strong><p data-duplicate-message></p><ul data-duplicate-matches></ul><label><input type="checkbox" name="confirm_duplicate" value="yes"> Save anyway after review.</label></div><div class="de-actions"><span data-preflight-status>Period auto-calculated • Duplicate guard ready</span><button type="submit">Save Royalty →</button></div></form>
<?php endif; ?>
</article>
<article class="os-card de-recent"><div class="os-title-row"><div><h2>Recent Manual Source Trace</h2><p>Latest Business OS entries.</p></div><a class="os-btn" href="operations_center.php">Open Operations</a></div><div class="de-table-wrap"><table class="de-table"><thead><tr><th>Captured</th><th>Dataset</th><th>Mapped Entity</th><th>Entity ID</th><th>Trace</th></tr></thead><tbody><?php if(!$recentManual): ?><tr><td colspan="5" class="de-empty">No manual entries yet.</td></tr><?php else: foreach($recentManual as $row): ?><tr><td><?= de_h($row['captured_at']) ?></td><td><?= de_h($row['source_dataset']) ?></td><td class="de-entity"><?= de_h($row['mapped_entity_type'] ?: '—') ?></td><td><?= $row['mapped_entity_id']!==null?'#'.number_format((int)$row['mapped_entity_id']):'—' ?></td><td><span class="de-source">RAW #<?= number_format((int)$row['id']) ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div></article>
<article class="os-card de-recent"><div class="os-title-row"><div><h2>Smart Entry Safety</h2><p>Four protections run without changing the source-of-truth architecture.</p></div></div><div class="de-policy-grid"><div class="de-policy"><b>1. Searchable identity</b><span>Member selectors can be filtered by name, mobile or Member ID.</span></div><div class="de-policy"><b>2. Duplicate preflight</b><span>Likely duplicate facts are shown before save and require explicit override.</span></div><div class="de-policy"><b>3. Strong validation</b><span>Dates, amounts, mobile, order math and VP are checked again on the server.</span></div><div class="de-policy"><b>4. Raw → Fact → Audit</b><span>The transaction remains all-or-nothing and legacy 757/757 health stays separate.</span></div></div></article>
</div></section>
<?php endif; ?>
<div class="os-footer-note"><strong>Identity rule:</strong> duplicate warning never means automatic merge. Every genuinely separate New UMS row remains a separate identity unless a verified review explicitly links it later.</div>
</main></div><script src="assets/data_entry.js"></script></body></html>
