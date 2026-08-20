<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

function rv_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rv_trim(mixed $value): string { return trim((string)$value); }
function rv_json(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Reversal audit payload could not be encoded.');
    return $json;
}
function rv_json_array(mixed $value): array {
    $raw = rv_trim($value);
    if ($raw === '') return [];
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); return is_array($data) ? $data : []; }
    catch (Throwable) { return []; }
}
function rv_len(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
function rv_money(mixed $value): string { return '₹' . number_format((float)$value, 2, '.', ','); }
function rv_num(mixed $value): string {
    $text = number_format((float)$value, 3, '.', ',');
    return rtrim(rtrim($text, '0'), '.');
}
function rv_audit(PDO $pdo, int $organizationId, int $clubId, string $eventType, string $entityType, int $entityId, array $details): void {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $organizationId,$clubId,$eventType,$entityType,$entityId,rv_json($details),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500),
    ]);
}
function rv_guard(PDO $pdo, int $organizationId, string $table, int $id): array {
    $allowed = ['members','volume_point_entries','orders','renewals','income_entries','royalty_entries'];
    if (!in_array($table,$allowed,true)) throw new RuntimeException('Reversal target is invalid.');
    $stmt = $pdo->prepare("SELECT t.*,r.id raw_id,r.raw_json original_raw_json,ds.source_code
        FROM `{$table}` t
        LEFT JOIN raw_source_records r ON r.id=t.source_record_id
        LEFT JOIN data_sources ds ON ds.id=r.data_source_id
        WHERE t.organization_id=? AND t.id=? AND t.source_sheet='Manual Entry' LIMIT 1");
    $stmt->execute([$organizationId,$id]);
    $row = $stmt->fetch();
    if (!$row || ($row['source_code'] ?? '') !== 'MANUAL' || empty($row['raw_id'])) {
        throw new RuntimeException('Only active MANUAL Business OS entries can be reversed. Imported/legacy or already reversed rows stay locked.');
    }
    return $row;
}
function rv_label(string $module, array $row): string {
    return match ($module) {
        'new_ums' => (string)($row['full_name'] ?? 'Manual member'),
        'vp' => (string)($row['display_name'] ?? $row['member_name_snapshot'] ?? 'VP entry'),
        'order','renewal' => (string)($row['display_name'] ?? 'Member entry'),
        'income' => ucfirst((string)($row['income_type'] ?? 'Income')),
        'royalty' => (string)($row['period_label'] ?: 'Royalty'),
        default => 'Manual entry',
    };
}
function rv_value(string $module, array $row): string {
    return match ($module) {
        'new_ums' => (string)($row['status'] ?? '—'),
        'vp' => rv_num($row['volume_points'] ?? 0) . ' VP',
        'order' => rv_money($row['net_amount'] ?? 0) . ' • ' . rv_num($row['volume_points'] ?? 0) . ' VP',
        'renewal' => rv_money($row['amount'] ?? 0) . ' • ' . rv_num($row['volume_points'] ?? 0) . ' VP',
        'income' => rv_money($row['amount'] ?? 0),
        'royalty' => rv_money($row['amount'] ?? 0) . ' • ' . rv_num($row['volume_points'] ?? 0) . ' VP',
        default => '—',
    };
}

if (empty($_SESSION['business_reversal_csrf'])) $_SESSION['business_reversal_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['business_reversal_csrf'];
$reversedSheet = BUSINESS_REVERSED_SOURCE_SHEET;
$modules = [
    'new_ums'=>['label'=>'New UMS','table'=>'members','entity'=>'member'],
    'vp'=>['label'=>'Volume Points','table'=>'volume_point_entries','entity'=>'volume_point_entry'],
    'order'=>['label'=>'Order','table'=>'orders','entity'=>'order'],
    'renewal'=>['label'=>'Renewal','table'=>'renewals','entity'=>'renewal'],
    'income'=>['label'=>'Income','table'=>'income_entries','entity'=>'income_entry'],
    'royalty'=>['label'=>'Royalty','table'=>'royalty_entries','entity'=>'royalty_entry'],
];

$error=null; $success=null; $organizationId=0; $clubId=0; $manualSourceId=0; $entries=[]; $history=[]; $selected=null;
$filterModule = rv_trim($_GET['filter_module'] ?? 'ALL');
$q = rv_trim($_GET['q'] ?? '');
$module = rv_trim($_GET['module'] ?? $_POST['module'] ?? '');
$entityId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['entity_id']) && is_numeric($_POST['entity_id']) ? (int)$_POST['entity_id'] : 0);
if ($module !== '' && !isset($modules[$module])) { $module=''; $entityId=0; }
if (isset($_GET['reversed']) && $_GET['reversed']==='1') $success='Manual entry reversed successfully. Original raw payload and normalized values were preserved for audit.';

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

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reverse_entry'])) {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        if ($module==='' || $entityId<=0) throw new RuntimeException('Select a manual entry to reverse.');
        if (($_POST['confirm_reverse'] ?? '')!=='yes') throw new RuntimeException('Confirm that this entry should be reversed.');
        $reason=preg_replace('/\s+/u',' ',rv_trim($_POST['reversal_reason'] ?? '')) ?: '';
        if (rv_len($reason)<5 || rv_len($reason)>500) throw new RuntimeException('Reversal reason must be between 5 and 500 characters.');

        $meta=$modules[$module];
        $old=rv_guard($pdo,$organizationId,$meta['table'],$entityId);
        $rawId=(int)$old['raw_id'];
        $before=$old;
        unset($before['original_raw_json'],$before['source_code']);

        $pdo->beginTransaction();
        try {
            $dependencySummary=[];
            if ($module==='new_ums') {
                $checks=[
                    'orders'=>['orders','member_id'],
                    'vp_facts'=>['volume_point_entries','member_id'],
                    'renewals'=>['renewals','member_id'],
                    'direct_downline'=>['members','sponsor_member_id'],
                ];
                foreach ($checks as $key=>[$table,$field]) {
                    $stmt=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND `{$field}`=? AND COALESCE(source_sheet,'') <> ?");
                    $stmt->execute([$organizationId,$entityId,$reversedSheet]);
                    $dependencySummary[$key]=(int)$stmt->fetchColumn();
                }
                if (array_sum($dependencySummary)>0) {
                    throw new RuntimeException('This New UMS member still has linked business/network records. Reverse those dependent manual facts first; member cancellation is blocked to protect identity history.');
                }

                $stmt=$pdo->prepare("SELECT id,status,source_sheet FROM ums_records WHERE organization_id=? AND member_id=? AND source_record_id=? AND source_sheet='Manual Entry' ORDER BY id LIMIT 1");
                $stmt->execute([$organizationId,$entityId,$rawId]);
                $ums=$stmt->fetch();
                if (!$ums) throw new RuntimeException('Linked manual UMS lifecycle record was not found.');

                $stmt=$pdo->prepare("UPDATE members SET source_sheet=?,status='inactive' WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$reversedSheet,$organizationId,$entityId]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('Member reversal state changed unexpectedly.');
                $stmt=$pdo->prepare("UPDATE ums_records SET source_sheet=?,status='inactive' WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$reversedSheet,$organizationId,(int)$ums['id']]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('UMS reversal state changed unexpectedly.');
            } else {
                $stmt=$pdo->prepare("UPDATE `{$meta['table']}` SET source_sheet=? WHERE organization_id=? AND id=? AND source_sheet='Manual Entry'");
                $stmt->execute([$reversedSheet,$organizationId,$entityId]);
                if ($stmt->rowCount()!==1) throw new RuntimeException('Entry reversal state changed unexpectedly.');
            }

            rv_audit($pdo,$organizationId,$clubId,'manual_'.$module.'_reversed',$meta['entity'],$entityId,[
                'source_record_id'=>$rawId,
                'reversal_reason'=>$reason,
                'before'=>$before,
                'reversed_source_sheet'=>$reversedSheet,
                'original_raw_immutable'=>true,
                'normalized_values_preserved'=>true,
                'legacy_excel_untouched'=>true,
                'dependencies_checked'=>$dependencySummary,
            ]);
            $pdo->commit();
            header('Location: reversal_center.php?reversed=1'); exit;
        } catch (Throwable $tx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $tx;
        }
    }

    $queries=[
        'new_ums'=>"SELECT m.id,m.full_name,m.mobile,m.join_date fact_date,m.status,m.source_record_id,m.source_sheet,r.id raw_id FROM members m JOIN raw_source_records r ON r.id=m.source_record_id WHERE m.organization_id=? AND r.data_source_id=? AND m.source_sheet='Manual Entry' ORDER BY m.id DESC LIMIT 100",
        'vp'=>"SELECT v.id,COALESCE(m.full_name,v.member_name_snapshot,'Manual member') display_name,v.entry_date fact_date,v.volume_points,v.order_type,v.source_record_id,v.source_sheet,r.id raw_id FROM volume_point_entries v JOIN raw_source_records r ON r.id=v.source_record_id LEFT JOIN members m ON m.id=v.member_id WHERE v.organization_id=? AND r.data_source_id=? AND v.source_sheet='Manual Entry' ORDER BY v.id DESC LIMIT 100",
        'order'=>"SELECT o.id,COALESCE(m.full_name,'Manual member') display_name,o.order_date fact_date,o.net_amount,o.volume_points,o.order_type,o.source_record_id,o.source_sheet,r.id raw_id FROM orders o JOIN raw_source_records r ON r.id=o.source_record_id LEFT JOIN members m ON m.id=o.member_id WHERE o.organization_id=? AND r.data_source_id=? AND o.source_sheet='Manual Entry' ORDER BY o.id DESC LIMIT 100",
        'renewal'=>"SELECT n.id,COALESCE(m.full_name,n.member_name_snapshot,'Manual member') display_name,n.renewal_date fact_date,n.amount,n.volume_points,n.source_record_id,n.source_sheet,r.id raw_id FROM renewals n JOIN raw_source_records r ON r.id=n.source_record_id LEFT JOIN members m ON m.id=n.member_id WHERE n.organization_id=? AND r.data_source_id=? AND n.source_sheet='Manual Entry' ORDER BY n.id DESC LIMIT 100",
        'income'=>"SELECT i.id,i.income_date fact_date,i.income_type,i.amount,i.source_record_id,i.source_sheet,r.id raw_id FROM income_entries i JOIN raw_source_records r ON r.id=i.source_record_id WHERE i.organization_id=? AND r.data_source_id=? AND i.source_sheet='Manual Entry' ORDER BY i.id DESC LIMIT 100",
        'royalty'=>"SELECT y.id,y.royalty_date fact_date,y.period_label,y.amount,y.volume_points,y.source_record_id,y.source_sheet,r.id raw_id FROM royalty_entries y JOIN raw_source_records r ON r.id=y.source_record_id WHERE y.organization_id=? AND r.data_source_id=? AND y.source_sheet='Manual Entry' ORDER BY y.id DESC LIMIT 100",
    ];
    foreach ($queries as $key=>$sql) {
        if ($filterModule!=='ALL' && $filterModule!==$key) continue;
        $stmt=$pdo->prepare($sql); $stmt->execute([$organizationId,$manualSourceId]);
        foreach ($stmt->fetchAll() as $row) {
            $item=$row + ['module'=>$key,'module_label'=>$modules[$key]['label']];
            $item['label']=rv_label($key,$item); $item['value']=rv_value($key,$item);
            if ($q!=='' && !str_contains(strtolower(implode(' ',array_map('strval',$item))),strtolower($q))) continue;
            $entries[]=$item;
        }
    }
    usort($entries,static fn(array $a,array $b):int=>((int)$b['raw_id'])<=>((int)$a['raw_id']));

    if ($module!=='' && $entityId>0) {
        $meta=$modules[$module]; $selected=rv_guard($pdo,$organizationId,$meta['table'],$entityId);
        $selected['module']=$module; $selected['module_label']=$meta['label']; $selected['label']=rv_label($module,$selected); $selected['value']=rv_value($module,$selected);
    }

    $hist=$pdo->prepare("SELECT id,event_type,entity_type,entity_id,details_json,created_at FROM audit_logs WHERE organization_id=? AND event_type REGEXP '^manual_.*_reversed$' ORDER BY id DESC LIMIT 80");
    $hist->execute([$organizationId]);
    foreach ($hist->fetchAll() as $row) { $row['details']=rv_json_array($row['details_json'] ?? null); $history[]=$row; }
} catch (Throwable $e) { $error=$e->getMessage(); }

$activeCount=count($entries); $reversalCount=count($history);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Safe Reverse / Cancel Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/reversal.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner">
<a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Safe Reverse / Cancel Center</small></span></a>
<div class="os-top-actions"><a class="os-btn" href="data_entry_center.php">+ Data Entry</a><a class="os-btn" href="correction_center.php">Corrections</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
</div></header>
<div class="os-layout">
<aside class="os-sidebar">
<div class="os-nav-label">Business OS</div><nav class="os-nav">
<a href="index.php"><i class="dot"></i>Dashboard</a><a href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a><a href="correction_center.php"><i class="dot"></i>Correction Center</a><a class="active" href="reversal_center.php"><i class="dot"></i>Reverse / Cancel</a><a href="operations_center.php"><i class="dot"></i>Operations Center</a><a href="members.php"><i class="dot"></i>Members & Network</a><a href="report_center.php"><i class="dot"></i>Report Center</a>
</nav><div class="os-sidebar-status"><b>No hard delete</b><span>Original raw data and normalized values remain preserved. Reversed rows are removed only from active business calculations.</span></div>
</aside>
<main class="os-main">
<section class="os-hero rv-hero"><div class="os-kicker">Step 10J • Safe Reverse + Cancel</div><h1>Cancel a wrong manual entry without deleting its evidence or rewriting history.</h1><p>Reversal changes only the active state of a MANUAL normalized fact. The original raw payload stays immutable, normalized values stay stored, and an audit event records who/when/why the record stopped affecting Business OS.</p><div class="os-status-row"><span class="os-chip good">REVERSAL CENTER LIVE</span><span class="os-chip good">Legacy Excel: READ ONLY</span><span class="os-chip">Original Raw: IMMUTABLE</span><span class="os-chip"><?= number_format($reversalCount) ?> reversal audit(s)</span></div></section>
<?php if ($success): ?><div class="rv-alert good"><strong>Done:</strong> <?= rv_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="rv-alert bad"><strong>Reversal diagnostic:</strong> <?= rv_h($error) ?></div><?php endif; ?>
<?php if (!$error): ?>
<section class="os-grid" style="margin-top:14px"><article class="os-card os-kpi green"><small>Active Manual Entries</small><strong><?= number_format($activeCount) ?></strong><span>Eligible for correction/reversal</span></article><article class="os-card os-kpi blue"><small>Reversal Audits</small><strong><?= number_format($reversalCount) ?></strong><span>History retained permanently</span></article><article class="os-card os-kpi gold"><small>Raw Payload</small><strong>100%</strong><span>Never changed by reversal</span></article><article class="os-card os-kpi violet"><small>Hard Deletes</small><strong>0</strong><span>Audit-safe policy</span></article></section>
<section class="rv-layout">
<article class="os-card rv-card"><div class="rv-head"><div><h2>Active Manual Entries</h2><p>Select only a genuinely wrong entry. Reversal is for cancellation, while normal value mistakes should use Correction Center.</p></div><span class="rv-badge">ACTIVE FACTS ONLY</span></div>
<form class="rv-filters" method="get"><select name="filter_module"><option value="ALL">All entry types</option><?php foreach ($modules as $key=>$meta): ?><option value="<?= rv_h($key) ?>" <?= $filterModule===$key?'selected':'' ?>><?= rv_h($meta['label']) ?></option><?php endforeach; ?></select><input name="q" value="<?= rv_h($q) ?>" placeholder="Search member, type or value"><button type="submit">Apply</button></form>
<div class="rv-table-wrap"><table class="rv-table"><thead><tr><th>Type</th><th>Entry</th><th>Date</th><th>Value</th><th>Trace</th><th></th></tr></thead><tbody>
<?php if (!$entries): ?><tr><td colspan="6" class="rv-empty">No active manual entries match this view.</td></tr><?php else: ?><?php foreach ($entries as $row): ?><tr><td><?= rv_h($row['module_label']) ?></td><td><strong><?= rv_h($row['label']) ?></strong></td><td><?= rv_h((string)($row['fact_date'] ?? '—')) ?></td><td><?= rv_h($row['value']) ?></td><td><span class="rv-source">RAW #<?= (int)$row['raw_id'] ?></span></td><td><a href="?module=<?= rv_h($row['module']) ?>&id=<?= (int)$row['id'] ?>">Review →</a></td></tr><?php endforeach; ?><?php endif; ?>
</tbody></table></div></article>
<aside class="os-card rv-card"><div class="rv-head"><div><h2><?= $selected ? 'Review Reversal' : 'Select an Entry' ?></h2><p><?= $selected ? 'The values below will remain stored; only active business participation will stop.' : 'Choose Review → from the active entry list.' ?></p></div><?php if ($selected): ?><span class="rv-badge">NO DELETE</span><?php endif; ?></div>
<?php if ($selected): ?><div class="rv-form"><div class="rv-fact"><div><small>Entry Type</small><b><?= rv_h($selected['module_label']) ?></b></div><div><small>Entity ID</small><b>#<?= (int)$entityId ?></b></div><div><small>Entry</small><b><?= rv_h($selected['label']) ?></b></div><div><small>Current Value</small><b><?= rv_h($selected['value']) ?></b></div><div><small>Raw Source</small><b>#<?= (int)$selected['raw_id'] ?></b></div><div><small>After Reverse</small><b><?= rv_h($reversedSheet) ?></b></div></div>
<div class="rv-warning"><strong>Important:</strong> This is not a delete. Reports and normal operations will ignore this record, while the original raw payload, current normalized values and audit evidence remain available. New UMS reversal is blocked while linked business/network dependencies still exist.</div>
<form method="post"><input type="hidden" name="csrf" value="<?= rv_h($csrf) ?>"><input type="hidden" name="module" value="<?= rv_h($module) ?>"><input type="hidden" name="entity_id" value="<?= (int)$entityId ?>"><div class="rv-field"><label>Reversal Reason</label><textarea name="reversal_reason" required minlength="5" maxlength="500" placeholder="Why should this entry stop affecting Business OS?"></textarea></div><label class="rv-confirm"><input type="checkbox" name="confirm_reverse" value="yes" required><span>I reviewed the entry and confirm this is a cancellation/reversal, not a normal correction.</span></label><div class="rv-actions"><button type="submit" name="reverse_entry" value="1">Reverse / Cancel Entry →</button></div></form></div><?php else: ?><div class="rv-policy"><strong>Use the right tool:</strong><br>Wrong amount/date/name → Correction Center.<br>Entry should never have counted at all → Reverse / Cancel Center.</div><?php endif; ?>
</aside></section>
<article class="os-card rv-card rv-history"><div class="rv-head"><div><h2>Reversal Audit History</h2><p>Recent cancellations with preserved reason and source trace.</p></div><span class="rv-badge">AUDIT TRAIL</span></div><div class="rv-history-list"><?php if (!$history): ?><div class="rv-history-item"><b>No reversals yet</b><span>History will appear here after the first real cancellation.</span></div><?php else: ?><?php foreach ($history as $row): $d=$row['details']; ?><div class="rv-history-item"><b><?= rv_h(str_replace('_',' ',(string)$row['event_type'])) ?> • #<?= (int)$row['entity_id'] ?></b><span><?= rv_h((string)$row['created_at']) ?> • Raw #<?= (int)($d['source_record_id'] ?? 0) ?> • <?= rv_h((string)($d['reversal_reason'] ?? 'Reason preserved in audit')) ?></span></div><?php endforeach; ?><?php endif; ?></div></article>
<?php endif; ?>
<div class="os-footer-note"><strong>Reversal policy:</strong> Legacy/imported data is never changed here. MANUAL raw payloads are immutable. Reversal marks the normalized fact as inactive for normal Business OS calculations and records a permanent audit event.</div>
</main></div><script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
