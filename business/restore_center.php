<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

function rs_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rs_trim(mixed $value): string { return trim((string)$value); }
function rs_key(mixed $value): string {
    $text = preg_replace('/\s+/u', ' ', rs_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}
function rs_mobile_digits(mixed $value): string { return preg_replace('/\D+/', '', rs_trim($value)) ?? ''; }
function rs_len(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
function rs_json(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Restore audit payload could not be encoded.');
    return $json;
}
function rs_json_array(mixed $value): array {
    $raw = rs_trim($value);
    if ($raw === '') return [];
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); return is_array($data) ? $data : []; }
    catch (Throwable) { return []; }
}
function rs_money(mixed $value): string { return '₹' . number_format((float)$value, 2, '.', ','); }
function rs_num(mixed $value): string {
    $text = number_format((float)$value, 3, '.', ',');
    return rtrim(rtrim($text, '0'), '.');
}
function rs_audit(PDO $pdo, int $organizationId, int $clubId, string $eventType, string $entityType, int $entityId, array $details): void {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $organizationId,$clubId,$eventType,$entityType,$entityId,rs_json($details),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500),
    ]);
}
function rs_guard(PDO $pdo, int $organizationId, string $table, int $id, string $reversedSheet): array {
    $allowed = ['members','volume_point_entries','orders','renewals','income_entries','royalty_entries'];
    if (!in_array($table,$allowed,true)) throw new RuntimeException('Restore target is invalid.');
    $stmt = $pdo->prepare("SELECT t.*,r.id raw_id,r.raw_json original_raw_json,ds.source_code
        FROM `{$table}` t
        LEFT JOIN raw_source_records r ON r.id=t.source_record_id
        LEFT JOIN data_sources ds ON ds.id=r.data_source_id
        WHERE t.organization_id=? AND t.id=? AND t.source_sheet=? LIMIT 1");
    $stmt->execute([$organizationId,$id,$reversedSheet]);
    $row = $stmt->fetch();
    if (!$row || ($row['source_code'] ?? '') !== 'MANUAL' || empty($row['raw_id'])) {
        throw new RuntimeException('Only currently reversed MANUAL Business OS entries can be restored here.');
    }
    return $row;
}
function rs_label(string $module, array $row): string {
    return match ($module) {
        'new_ums' => (string)($row['full_name'] ?? 'Manual member'),
        'vp' => (string)($row['display_name'] ?? $row['member_name_snapshot'] ?? 'VP entry'),
        'order','renewal' => (string)($row['display_name'] ?? 'Member entry'),
        'income' => ucfirst((string)($row['income_type'] ?? 'Income')),
        'royalty' => (string)($row['period_label'] ?: 'Royalty'),
        default => 'Manual entry',
    };
}
function rs_value(string $module, array $row): string {
    return match ($module) {
        'new_ums' => 'Reversed member identity',
        'vp' => rs_num($row['volume_points'] ?? 0) . ' VP',
        'order' => rs_money($row['net_amount'] ?? 0) . ' • ' . rs_num($row['volume_points'] ?? 0) . ' VP',
        'renewal' => rs_money($row['amount'] ?? 0) . ' • ' . rs_num($row['volume_points'] ?? 0) . ' VP',
        'income' => rs_money($row['amount'] ?? 0),
        'royalty' => rs_money($row['amount'] ?? 0) . ' • ' . rs_num($row['volume_points'] ?? 0) . ' VP',
        default => '—',
    };
}
function rs_conflicts(PDO $pdo, int $organizationId, string $module, int $id, array $row, string $reversedSheet): array {
    $conflicts = [];

    if ($module === 'new_ums') {
        $stmt = $pdo->prepare("SELECT id,full_name,mobile,join_date FROM members WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? ORDER BY id DESC");
        $stmt->execute([$organizationId,$id,$reversedSheet]);
        $nameKey = rs_key($row['full_name'] ?? '');
        $mobile = rs_mobile_digits($row['mobile'] ?? '');
        $joinDate = (string)($row['join_date'] ?? '');
        foreach ($stmt->fetchAll() as $candidate) {
            $sameNameDate = $nameKey !== '' && rs_key($candidate['full_name'] ?? '') === $nameKey && $joinDate !== '' && (string)($candidate['join_date'] ?? '') === $joinDate;
            $candidateMobile = rs_mobile_digits($candidate['mobile'] ?? '');
            $sameMobile = $mobile !== '' && $candidateMobile !== '' && $candidateMobile === $mobile;
            if ($sameNameDate || $sameMobile) {
                $conflicts[] = 'Member #' . (int)$candidate['id'] . ' • ' . (string)$candidate['full_name'];
            }
            if (count($conflicts) >= 6) break;
        }
        return $conflicts;
    }

    if ($module === 'vp') {
        $stmt = $pdo->prepare("SELECT id FROM volume_point_entries WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? AND member_id <=> ? AND entry_date=? AND volume_points=? AND LOWER(COALESCE(order_type,''))=LOWER(COALESCE(?,'')) ORDER BY id DESC LIMIT 6");
        $stmt->execute([$organizationId,$id,$reversedSheet,$row['member_id'] ?? null,$row['entry_date'],$row['volume_points'],$row['order_type'] ?? '']);
    } elseif ($module === 'order') {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? AND member_id <=> ? AND order_date=? AND net_amount=? AND LOWER(COALESCE(order_type,''))=LOWER(COALESCE(?,'')) ORDER BY id DESC LIMIT 6");
        $stmt->execute([$organizationId,$id,$reversedSheet,$row['member_id'] ?? null,$row['order_date'],$row['net_amount'],$row['order_type'] ?? '']);
    } elseif ($module === 'renewal') {
        $stmt = $pdo->prepare("SELECT id FROM renewals WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? AND member_id <=> ? AND renewal_date=? AND amount=? AND volume_points=? ORDER BY id DESC LIMIT 6");
        $stmt->execute([$organizationId,$id,$reversedSheet,$row['member_id'] ?? null,$row['renewal_date'],$row['amount'],$row['volume_points']]);
    } elseif ($module === 'income') {
        $stmt = $pdo->prepare("SELECT id FROM income_entries WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? AND income_date=? AND amount=? AND LOWER(COALESCE(income_type,''))=LOWER(COALESCE(?,'')) ORDER BY id DESC LIMIT 6");
        $stmt->execute([$organizationId,$id,$reversedSheet,$row['income_date'],$row['amount'],$row['income_type'] ?? '']);
    } elseif ($module === 'royalty') {
        $stmt = $pdo->prepare("SELECT id FROM royalty_entries WHERE organization_id=? AND id<>? AND COALESCE(source_sheet,'')<>? AND royalty_date=? AND amount=? AND volume_points=? AND LOWER(COALESCE(period_label,''))=LOWER(COALESCE(?,'')) ORDER BY id DESC LIMIT 6");
        $stmt->execute([$organizationId,$id,$reversedSheet,$row['royalty_date'],$row['amount'],$row['volume_points'],$row['period_label'] ?? '']);
    } else {
        return [];
    }

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $conflictId) {
        $conflicts[] = ucfirst(str_replace('_',' ',$module)) . ' #' . (int)$conflictId;
    }
    return $conflicts;
}

if (empty($_SESSION['business_restore_csrf'])) $_SESSION['business_restore_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['business_restore_csrf'];
$reversedSheet = BUSINESS_REVERSED_SOURCE_SHEET;
$manualSheet = 'Manual Entry';
$modules = [
    'new_ums'=>['label'=>'New UMS','table'=>'members','entity'=>'member'],
    'vp'=>['label'=>'Volume Points','table'=>'volume_point_entries','entity'=>'volume_point_entry'],
    'order'=>['label'=>'Order','table'=>'orders','entity'=>'order'],
    'renewal'=>['label'=>'Renewal','table'=>'renewals','entity'=>'renewal'],
    'income'=>['label'=>'Income','table'=>'income_entries','entity'=>'income_entry'],
    'royalty'=>['label'=>'Royalty','table'=>'royalty_entries','entity'=>'royalty_entry'],
];

$error=null; $success=null; $organizationId=0; $clubId=0; $manualSourceId=0; $entries=[]; $history=[]; $selected=null; $selectedConflicts=[]; $selectedReversal=null;
$filterModule = rs_trim($_GET['filter_module'] ?? 'ALL');
$q = rs_trim($_GET['q'] ?? '');
$module = rs_trim($_GET['module'] ?? $_POST['module'] ?? '');
$entityId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['entity_id']) && is_numeric($_POST['entity_id']) ? (int)$_POST['entity_id'] : 0);
if ($module !== '' && !isset($modules[$module])) { $module=''; $entityId=0; }
if (isset($_GET['restored']) && $_GET['restored']==='1') $success='Reversed manual entry restored successfully. Reversal history and original raw payload remain intact.';

try {
    $pdo = business_db();
    foreach (['organizations','clubs','data_sources','raw_source_records','members','ums_records','volume_point_entries','orders','renewals','income_entries','royalty_entries','audit_logs'] as $table) {
        if (!business_table_exists($pdo,$table)) throw new RuntimeException("Required table {$table} is missing.");
    }

    $org=$pdo->query("SELECT id,timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    $organizationId=(int)$org['id']; @date_default_timezone_set((string)($org['timezone'] ?: 'Asia/Kolkata'));
    $stmt=$pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1"); $stmt->execute([$organizationId]); $clubId=(int)$stmt->fetchColumn();
    if ($clubId<=0) throw new RuntimeException('Ghazipur club was not found.');
    $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' AND is_active=1 LIMIT 1"); $stmt->execute([$organizationId]); $manualSourceId=(int)$stmt->fetchColumn();
    if ($manualSourceId<=0) throw new RuntimeException('MANUAL data source is not active.');

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['restore_entry'])) {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        if ($module==='' || $entityId<=0) throw new RuntimeException('Select a reversed entry to restore.');
        if (($_POST['confirm_restore'] ?? '')!=='yes') throw new RuntimeException('Confirm that this reversed entry should be restored.');
        $reason=preg_replace('/\s+/u',' ',rs_trim($_POST['restore_reason'] ?? '')) ?: '';
        if (rs_len($reason)<5 || rs_len($reason)>500) throw new RuntimeException('Restore reason must be between 5 and 500 characters.');

        $meta=$modules[$module];
        $old=rs_guard($pdo,$organizationId,$meta['table'],$entityId,$reversedSheet);
        $rawId=(int)$old['raw_id'];
        $conflicts=rs_conflicts($pdo,$organizationId,$module,$entityId,$old,$reversedSheet);
        if ($conflicts) throw new RuntimeException('Restore blocked because an active replacement/conflicting record exists: ' . implode(', ',$conflicts) . '. Review the duplicate/identity state before restoring.');

        $reversalStmt=$pdo->prepare("SELECT id,details_json,created_at FROM audit_logs WHERE organization_id=? AND event_type=? AND entity_id=? ORDER BY id DESC LIMIT 1");
        $reversalStmt->execute([$organizationId,'manual_'.$module.'_reversed',$entityId]);
        $reversal=$reversalStmt->fetch();
        if (!$reversal) throw new RuntimeException('Restore is blocked because the matching reversal audit event was not found.');
        $reversalDetails=rs_json_array($reversal['details_json'] ?? null);
        if ((int)($reversalDetails['source_record_id'] ?? 0) !== $rawId) throw new RuntimeException('Reversal audit source trace does not match this entry. Restore was stopped.');

        $pdo->beginTransaction();
        try {
            $restoredStatus=null;
            if ($module==='new_ums') {
                $before=is_array($reversalDetails['before'] ?? null) ? $reversalDetails['before'] : [];
                $restoredStatus=rs_trim($before['status'] ?? 'active');
                if (!in_array($restoredStatus,['active','inactive'],true)) $restoredStatus='active';

                $umsStmt=$pdo->prepare("SELECT id FROM ums_records WHERE organization_id=? AND member_id=? AND source_record_id=? AND source_sheet=? ORDER BY id LIMIT 1");
                $umsStmt->execute([$organizationId,$entityId,$rawId,$reversedSheet]);
                $umsId=(int)$umsStmt->fetchColumn();
                if ($umsId<=0) throw new RuntimeException('Linked reversed UMS lifecycle record was not found.');

                $stmt=$pdo->prepare("UPDATE members SET source_sheet=?,status=? WHERE organization_id=? AND id=? AND source_sheet=?");
                $stmt->execute([$manualSheet,$restoredStatus,$organizationId,$entityId,$reversedSheet]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('Member restore state changed unexpectedly.');
                $stmt=$pdo->prepare("UPDATE ums_records SET source_sheet=?,status=? WHERE organization_id=? AND id=? AND source_sheet=?");
                $stmt->execute([$manualSheet,$restoredStatus,$organizationId,$umsId,$reversedSheet]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('UMS restore state changed unexpectedly.');
            } else {
                $stmt=$pdo->prepare("UPDATE `{$meta['table']}` SET source_sheet=? WHERE organization_id=? AND id=? AND source_sheet=?");
                $stmt->execute([$manualSheet,$organizationId,$entityId,$reversedSheet]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('Entry restore state changed unexpectedly.');
            }

            rs_audit($pdo,$organizationId,$clubId,'manual_'.$module.'_restored',$meta['entity'],$entityId,[
                'source_record_id'=>$rawId,
                'restore_reason'=>$reason,
                'reversal_audit_id'=>(int)$reversal['id'],
                'reversal_at'=>(string)$reversal['created_at'],
                'restored_source_sheet'=>$manualSheet,
                'restored_status'=>$restoredStatus,
                'conflict_check'=>'pass',
                'original_raw_immutable'=>true,
                'normalized_values_preserved'=>true,
                'reversal_history_preserved'=>true,
                'legacy_excel_untouched'=>true,
            ]);
            $pdo->commit();
            header('Location: restore_center.php?restored=1'); exit;
        } catch (Throwable $tx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $tx;
        }
    }

    $queries=[
        'new_ums'=>"SELECT m.id,m.full_name,m.mobile,m.join_date fact_date,m.status,m.source_record_id,m.source_sheet,r.id raw_id FROM members m JOIN raw_source_records r ON r.id=m.source_record_id WHERE m.organization_id=? AND r.data_source_id=? AND m.source_sheet=? ORDER BY m.id DESC LIMIT 100",
        'vp'=>"SELECT v.id,COALESCE(m.full_name,v.member_name_snapshot,'Manual member') display_name,v.entry_date fact_date,v.member_id,v.volume_points,v.order_type,v.source_record_id,v.source_sheet,r.id raw_id FROM volume_point_entries v JOIN raw_source_records r ON r.id=v.source_record_id LEFT JOIN members m ON m.id=v.member_id WHERE v.organization_id=? AND r.data_source_id=? AND v.source_sheet=? ORDER BY v.id DESC LIMIT 100",
        'order'=>"SELECT o.id,COALESCE(m.full_name,'Manual member') display_name,o.order_date fact_date,o.member_id,o.net_amount,o.volume_points,o.order_type,o.source_record_id,o.source_sheet,r.id raw_id FROM orders o JOIN raw_source_records r ON r.id=o.source_record_id LEFT JOIN members m ON m.id=o.member_id WHERE o.organization_id=? AND r.data_source_id=? AND o.source_sheet=? ORDER BY o.id DESC LIMIT 100",
        'renewal'=>"SELECT n.id,COALESCE(m.full_name,n.member_name_snapshot,'Manual member') display_name,n.renewal_date fact_date,n.member_id,n.amount,n.volume_points,n.source_record_id,n.source_sheet,r.id raw_id FROM renewals n JOIN raw_source_records r ON r.id=n.source_record_id LEFT JOIN members m ON m.id=n.member_id WHERE n.organization_id=? AND r.data_source_id=? AND n.source_sheet=? ORDER BY n.id DESC LIMIT 100",
        'income'=>"SELECT i.id,i.income_date fact_date,i.income_type,i.amount,i.source_record_id,i.source_sheet,r.id raw_id FROM income_entries i JOIN raw_source_records r ON r.id=i.source_record_id WHERE i.organization_id=? AND r.data_source_id=? AND i.source_sheet=? ORDER BY i.id DESC LIMIT 100",
        'royalty'=>"SELECT y.id,y.royalty_date fact_date,y.period_label,y.amount,y.volume_points,y.source_record_id,y.source_sheet,r.id raw_id FROM royalty_entries y JOIN raw_source_records r ON r.id=y.source_record_id WHERE y.organization_id=? AND r.data_source_id=? AND y.source_sheet=? ORDER BY y.id DESC LIMIT 100",
    ];
    foreach ($queries as $key=>$sql) {
        if ($filterModule!=='ALL' && $filterModule!==$key) continue;
        $stmt=$pdo->prepare($sql); $stmt->execute([$organizationId,$manualSourceId,$reversedSheet]);
        foreach ($stmt->fetchAll() as $row) {
            $item=$row + ['module'=>$key,'module_label'=>$modules[$key]['label']];
            $item['label']=rs_label($key,$item); $item['value']=rs_value($key,$item);
            if ($q!=='' && !str_contains(rs_key(implode(' ',array_map('strval',$item))),rs_key($q))) continue;
            $entries[]=$item;
        }
    }
    usort($entries,static fn(array $a,array $b):int=>((int)$b['raw_id'])<=>((int)$a['raw_id']));

    if ($module!=='' && $entityId>0) {
        $meta=$modules[$module];
        $selected=rs_guard($pdo,$organizationId,$meta['table'],$entityId,$reversedSheet);
        $selected['module']=$module; $selected['module_label']=$meta['label']; $selected['label']=rs_label($module,$selected); $selected['value']=rs_value($module,$selected);
        $selectedConflicts=rs_conflicts($pdo,$organizationId,$module,$entityId,$selected,$reversedSheet);
        $stmt=$pdo->prepare("SELECT id,details_json,created_at FROM audit_logs WHERE organization_id=? AND event_type=? AND entity_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$organizationId,'manual_'.$module.'_reversed',$entityId]);
        $selectedReversal=$stmt->fetch() ?: null;
        if ($selectedReversal) $selectedReversal['details']=rs_json_array($selectedReversal['details_json'] ?? null);
    }

    $hist=$pdo->prepare("SELECT id,event_type,entity_type,entity_id,details_json,created_at FROM audit_logs WHERE organization_id=? AND event_type REGEXP '^manual_.*_(reversed|restored)$' ORDER BY id DESC LIMIT 100");
    $hist->execute([$organizationId]);
    foreach ($hist->fetchAll() as $row) { $row['details']=rs_json_array($row['details_json'] ?? null); $history[]=$row; }
} catch (Throwable $e) { $error=$e->getMessage(); }

$reversedCount=count($entries);
$recentRestores=count(array_filter($history,static fn(array $h):bool=>str_ends_with((string)$h['event_type'],'_restored')));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Restore Reversed Entry Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/restore.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner">
<a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Restore & Audit Recovery</small></span></a>
<div class="os-top-actions"><a class="os-btn" href="reversal_center.php">Reverse Center</a><a class="os-btn" href="correction_center.php">Corrections</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
</div></header>

<div class="os-layout">
<aside class="os-sidebar">
<div class="os-nav-label">Business OS</div><nav class="os-nav">
<a href="index.php"><i class="dot"></i>Dashboard</a>
<a href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a>
<a href="correction_center.php"><i class="dot"></i>Correction Center</a>
<a href="reversal_center.php"><i class="dot"></i>Reverse / Cancel</a>
<a class="active" href="restore_center.php"><i class="dot"></i>Restore Center</a>
<a href="operations_center.php"><i class="dot"></i>Operations Center</a>
<a href="report_center.php"><i class="dot"></i>Report Center</a>
</nav>
<div class="os-sidebar-status"><b>Recovery without deletion</b><span>Only reversed MANUAL entries are recoverable here. Raw source and reversal audit remain unchanged.</span></div>
</aside>

<main class="os-main">
<section class="os-hero rs-hero"><div class="os-kicker">Step 10K • Restore + Complete Audit Recovery</div>
<h1>Bring back a reversed manual fact without erasing why it was reversed.</h1>
<p>Restore changes only the operational state back to Manual Entry. Before restoring, Business OS checks for replacement/duplicate conflicts. Original raw payload, reversal reason and the new restore reason remain permanently traceable.</p>
<div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'RESTORE CENTER LIVE':'Review required' ?></span><span class="os-chip good">Original Raw: IMMUTABLE</span><span class="os-chip good">Reversal History: PRESERVED</span><span class="os-chip"><?= number_format($reversedCount) ?> currently reversed</span><span class="os-chip"><?= number_format($recentRestores) ?> recent restore audits</span></div>
</section>

<?php if ($success!==null): ?><div class="rs-alert good"><strong>Restored:</strong> <?= rs_h($success) ?></div><?php endif; ?>
<?php if ($error!==null): ?><div class="rs-alert bad"><strong>Restore diagnostic:</strong> <?= rs_h($error) ?></div><?php endif; ?>

<?php if ($error===null): ?>
<section class="rs-layout">
<article class="os-card rs-card"><div class="rs-head"><div><h2>Reversed Manual Entries</h2><p>Only records currently outside normal business calculations are listed.</p></div><span class="rs-badge">CONFLICT CHECK FIRST</span></div>
<form class="rs-filters" method="get"><select name="filter_module"><option value="ALL">All entry types</option><?php foreach($modules as $k=>$m): ?><option value="<?= rs_h($k) ?>" <?= $filterModule===$k?'selected':'' ?>><?= rs_h($m['label']) ?></option><?php endforeach; ?></select><input name="q" value="<?= rs_h($q) ?>" placeholder="Search name, type, value, raw ID"><button type="submit">Filter</button></form>
<div class="rs-table-wrap"><table class="rs-table"><thead><tr><th>Type</th><th>Entry</th><th>Date</th><th>Preserved Value</th><th>Trace</th><th></th></tr></thead><tbody>
<?php if(!$entries): ?><tr><td colspan="6" class="rs-empty">No reversed manual entries are waiting for restore.</td></tr><?php else: foreach($entries as $entry): ?><tr><td><?= rs_h($entry['module_label']) ?></td><td><strong><?= rs_h($entry['label']) ?></strong><br><small>#<?= (int)$entry['id'] ?></small></td><td><?= rs_h((string)($entry['fact_date'] ?? '—')) ?></td><td><?= rs_h($entry['value']) ?></td><td><span class="rs-source">RAW #<?= (int)$entry['raw_id'] ?></span></td><td><a href="?module=<?= rs_h($entry['module']) ?>&id=<?= (int)$entry['id'] ?>&filter_module=<?= rs_h($filterModule) ?>&q=<?= rawurlencode($q) ?>">Review Restore →</a></td></tr><?php endforeach; endif; ?>
</tbody></table></div></article>

<aside class="os-card rs-card"><div class="rs-head"><div><h2>Restore Review</h2><p>Recovery is allowed only after source/audit/conflict checks pass.</p></div><span class="rs-badge">AUDIT RECOVERY</span></div>
<?php if($selected===null): ?><div class="rs-empty">Choose a reversed entry from the table.</div><?php else: ?>
<div class="rs-form"><div class="rs-fact"><div><small>Entry Type</small><b><?= rs_h($selected['module_label']) ?></b></div><div><small>Entity</small><b><?= rs_h($selected['label']) ?> • #<?= (int)$selected['id'] ?></b></div><div><small>Preserved Value</small><b><?= rs_h($selected['value']) ?></b></div><div><small>Original Raw</small><b>RAW #<?= (int)$selected['raw_id'] ?> • unchanged</b></div><div><small>Reversal Audit</small><b><?= $selectedReversal ? '#'.(int)$selectedReversal['id'].' • '.rs_h((string)$selectedReversal['created_at']) : 'Missing' ?></b></div><div><small>Conflict Check</small><b><?= $selectedConflicts ? number_format(count($selectedConflicts)).' conflict(s)' : 'PASS' ?></b></div></div>
<?php if($selectedConflicts): ?><div class="rs-alert bad"><strong>Restore blocked:</strong> <?= rs_h(implode(', ',$selectedConflicts)) ?>. A replacement or identity conflict already exists.</div><?php elseif(!$selectedReversal): ?><div class="rs-alert bad"><strong>Restore blocked:</strong> matching reversal audit is missing.</div><?php else: ?>
<div class="rs-note"><strong>Safe recovery:</strong> restoring this record makes its existing normalized value operational again. It does not rewrite the original raw payload and does not delete the reversal event.</div>
<form method="post"><input type="hidden" name="csrf" value="<?= rs_h($csrf) ?>"><input type="hidden" name="module" value="<?= rs_h($module) ?>"><input type="hidden" name="entity_id" value="<?= (int)$entityId ?>">
<div class="rs-field"><label>Restore Reason</label><textarea name="restore_reason" required minlength="5" maxlength="500" placeholder="Why should this reversed entry become active again?"></textarea></div>
<label class="rs-confirm"><input type="checkbox" name="confirm_restore" value="yes" required><span>I reviewed the reversal and confirm this exact preserved manual entry should return to normal Business OS calculations.</span></label>
<div class="rs-actions"><button type="submit" name="restore_entry" value="1">Restore Entry Safely →</button></div></form>
<?php endif; ?></div><?php endif; ?></aside>
</section>

<article class="os-card rs-card rs-history"><div class="rs-head"><div><h2>Reversal + Restore Audit Trail</h2><p>Both sides of the recovery lifecycle remain visible.</p></div><span class="rs-badge">NO HISTORY ERASED</span></div>
<div class="rs-history-list"><?php if(!$history): ?><div class="rs-empty">No reversal/restore audit events yet.</div><?php else: foreach($history as $h): $d=$h['details']; ?><div class="rs-history-item"><b><?= rs_h((string)$h['event_type']) ?> • <?= rs_h((string)$h['entity_type']) ?> #<?= (int)$h['entity_id'] ?></b><span><?= rs_h((string)$h['created_at']) ?><?php if(!empty($d['reversal_reason'])): ?> • Reverse reason: <?= rs_h((string)$d['reversal_reason']) ?><?php endif; ?><?php if(!empty($d['restore_reason'])): ?> • Restore reason: <?= rs_h((string)$d['restore_reason']) ?><?php endif; ?><?php if(!empty($d['source_record_id'])): ?> • RAW #<?= (int)$d['source_record_id'] ?><?php endif; ?></span></div><?php endforeach; endif; ?></div>
<div class="rs-policy"><strong>Recovery policy:</strong> restore never hard-deletes or recreates the fact. It reactivates the same preserved normalized record only when no active replacement conflict exists. Legacy Excel data remains read-only.</div></article>
<?php endif; ?>
</main></div>
</body></html>