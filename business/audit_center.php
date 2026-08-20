<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function ua_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ua_trim(mixed $value): string
{
    return trim((string)$value);
}

function ua_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', ua_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function ua_json(mixed $value): array
{
    $raw = ua_trim($value);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function ua_action(string $eventType): string
{
    if (preg_match('/^manual_.*_created$/', $eventType)) return 'created';
    if (preg_match('/^manual_.*_corrected$/', $eventType)) return 'corrected';
    if (preg_match('/^manual_.*_reversed$/', $eventType)) return 'reversed';
    if (preg_match('/^manual_.*_restored$/', $eventType)) return 'restored';
    return 'system';
}

function ua_module(string $eventType): string
{
    if (preg_match('/^manual_(new_ums|vp|order|renewal|income|royalty)_(created|corrected|reversed|restored)$/', $eventType, $m)) {
        return (string)$m[1];
    }
    return 'system';
}

function ua_module_label(string $module): string
{
    return match ($module) {
        'new_ums' => 'New UMS',
        'vp' => 'Volume Points',
        'order' => 'Order',
        'renewal' => 'Renewal',
        'income' => 'Income',
        'royalty' => 'Royalty',
        default => 'System',
    };
}

function ua_action_label(string $action): string
{
    return match ($action) {
        'created' => 'Created',
        'corrected' => 'Corrected',
        'reversed' => 'Reversed',
        'restored' => 'Restored',
        default => 'System Event',
    };
}

function ua_reason(string $action, array $details): string
{
    $reason = match ($action) {
        'corrected' => ua_trim($details['correction_reason'] ?? ''),
        'reversed' => ua_trim($details['reversal_reason'] ?? ''),
        'restored' => ua_trim($details['restore_reason'] ?? ''),
        'created' => 'Created through Business OS manual entry flow.',
        default => '',
    };
    return $reason !== '' ? $reason : 'No reason text was stored for this event.';
}

function ua_scalar(mixed $value): string
{
    if ($value === null) return '—';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '—' : $json;
    }
    $text = trim((string)$value);
    return $text === '' ? '—' : $text;
}

function ua_event_title(string $eventType, string $module, string $action): string
{
    if ($action === 'system') {
        return ucwords(str_replace('_', ' ', $eventType));
    }
    return ua_module_label($module) . ' ' . ua_action_label($action);
}

function ua_date_display(?string $value): string
{
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->format('d M Y • h:i A');
    } catch (Throwable) {
        return (string)$value;
    }
}

function ua_entity_target(PDO $pdo, int $organizationId, string $entityType, int $entityId): array
{
    if ($entityId <= 0) {
        return ['label'=>'No entity link','url'=>null,'state'=>'none'];
    }

    $map = [
        'member' => ['table'=>'members','module'=>'new_ums'],
        'volume_point_entry' => ['table'=>'volume_point_entries','module'=>'vp'],
        'order' => ['table'=>'orders','module'=>'order'],
        'renewal' => ['table'=>'renewals','module'=>'renewal'],
        'income_entry' => ['table'=>'income_entries','module'=>'income'],
        'royalty_entry' => ['table'=>'royalty_entries','module'=>'royalty'],
    ];

    if (!isset($map[$entityType])) {
        return ['label'=>$entityType . ' #' . $entityId,'url'=>null,'state'=>'system'];
    }

    $meta = $map[$entityType];
    $stmt = $pdo->prepare("SELECT source_sheet FROM `{$meta['table']}` WHERE organization_id=? AND id=? LIMIT 1");
    $stmt->execute([$organizationId,$entityId]);
    $sheet = $stmt->fetchColumn();
    if ($sheet === false) {
        return ['label'=>$entityType . ' #' . $entityId . ' • not found','url'=>null,'state'=>'missing'];
    }

    $sheet = (string)$sheet;
    if ($entityType === 'member' && $sheet !== BUSINESS_REVERSED_SOURCE_SHEET) {
        return ['label'=>'Open Member Profile 360°','url'=>'member_profile.php?member=' . $entityId,'state'=>'active'];
    }
    if ($sheet === BUSINESS_REVERSED_SOURCE_SHEET) {
        return ['label'=>'Open Restore Center','url'=>'restore_center.php?module=' . rawurlencode($meta['module']) . '&id=' . $entityId,'state'=>'reversed'];
    }
    if ($sheet === 'Manual Entry') {
        return ['label'=>'Open Correction Center','url'=>'correction_center.php?module=' . rawurlencode($meta['module']) . '&id=' . $entityId,'state'=>'active'];
    }

    return ['label'=>$entityType . ' #' . $entityId . ' • source locked','url'=>null,'state'=>'locked'];
}

$error = null;
$organizationId = 0;
$timezoneName = 'Asia/Kolkata';
$summary = ['all'=>0,'manual'=>0,'created'=>0,'corrected'=>0,'reversed'=>0,'restored'=>0,'system'=>0];
$rows = [];
$selected = null;
$selectedDetails = [];
$selectedRaw = null;
$selectedTarget = ['label'=>'No entity link','url'=>null,'state'=>'none'];

$scope = ua_trim($_GET['scope'] ?? 'manual');
if (!in_array($scope, ['manual','all'], true)) $scope = 'manual';
$actionFilter = ua_trim($_GET['action'] ?? 'ALL');
if (!in_array($actionFilter, ['ALL','created','corrected','reversed','restored','system'], true)) $actionFilter = 'ALL';
$moduleFilter = ua_trim($_GET['module'] ?? 'ALL');
if (!in_array($moduleFilter, ['ALL','new_ums','vp','order','renewal','income','royalty','system'], true)) $moduleFilter = 'ALL';
$q = ua_trim($_GET['q'] ?? '');
$from = ua_trim($_GET['from'] ?? '');
$to = ua_trim($_GET['to'] ?? '');
$selectedAuditId = isset($_GET['audit']) && is_numeric($_GET['audit']) ? (int)$_GET['audit'] : 0;

try {
    $pdo = business_db();
    foreach (['organizations','audit_logs','raw_source_records','data_sources'] as $table) {
        if (!business_table_exists($pdo,$table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    $org = $pdo->query("SELECT id,timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    $organizationId = (int)$org['id'];
    $timezoneName = ua_trim($org['timezone'] ?? '') ?: 'Asia/Kolkata';
    @date_default_timezone_set($timezoneName);

    $sumStmt = $pdo->prepare(
        "SELECT COUNT(*) all_count,
                SUM(event_type REGEXP '^manual_.*_(created|corrected|reversed|restored)$') manual_count,
                SUM(event_type REGEXP '^manual_.*_created$') created_count,
                SUM(event_type REGEXP '^manual_.*_corrected$') corrected_count,
                SUM(event_type REGEXP '^manual_.*_reversed$') reversed_count,
                SUM(event_type REGEXP '^manual_.*_restored$') restored_count
         FROM audit_logs WHERE organization_id=?"
    );
    $sumStmt->execute([$organizationId]);
    $s = $sumStmt->fetch() ?: [];
    $summary['all'] = (int)($s['all_count'] ?? 0);
    $summary['manual'] = (int)($s['manual_count'] ?? 0);
    $summary['created'] = (int)($s['created_count'] ?? 0);
    $summary['corrected'] = (int)($s['corrected_count'] ?? 0);
    $summary['reversed'] = (int)($s['reversed_count'] ?? 0);
    $summary['restored'] = (int)($s['restored_count'] ?? 0);
    $summary['system'] = max(0, $summary['all'] - $summary['manual']);

    $where = ['organization_id=?'];
    $params = [$organizationId];
    if ($scope === 'manual') {
        $where[] = "event_type REGEXP '^manual_.*_(created|corrected|reversed|restored)$'";
    }
    if ($from !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        if (!$d || $d->format('Y-m-d') !== $from) throw new RuntimeException('From date is invalid.');
        $where[] = 'created_at>=?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if (!$d || $d->format('Y-m-d') !== $to) throw new RuntimeException('To date is invalid.');
        $where[] = 'created_at<=?';
        $params[] = $to . ' 23:59:59';
    }
    if ($q !== '') {
        $where[] = '(event_type LIKE ? OR entity_type LIKE ? OR CAST(entity_id AS CHAR) LIKE ? OR details_json LIKE ?)';
        $needle = '%' . $q . '%';
        array_push($params,$needle,$needle,$needle,$needle);
    }

    $sql = "SELECT id,user_id,event_type,entity_type,entity_id,details_json,ip_address,created_at
            FROM audit_logs WHERE " . implode(' AND ',$where) . " ORDER BY id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $eventType = (string)$row['event_type'];
        $action = ua_action($eventType);
        $module = ua_module($eventType);
        if ($actionFilter !== 'ALL' && $action !== $actionFilter) continue;
        if ($moduleFilter !== 'ALL' && $module !== $moduleFilter) continue;
        $details = ua_json($row['details_json'] ?? null);
        $prepared = $row + [
            'action'=>$action,
            'module'=>$module,
            'title'=>ua_event_title($eventType,$module,$action),
            'reason'=>ua_reason($action,$details),
            'details'=>$details,
            'source_record_id'=>(int)($details['source_record_id'] ?? 0),
        ];
        $rows[] = $prepared;
    }

    if ($selectedAuditId > 0) {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $selectedAuditId) {
                $selected = $row;
                break;
            }
        }
    }
    if ($selected === null && $rows) $selected = $rows[0];

    if ($selected !== null) {
        $selectedDetails = is_array($selected['details'] ?? null) ? $selected['details'] : [];
        $selectedTarget = ua_entity_target($pdo,$organizationId,(string)($selected['entity_type'] ?? ''),(int)($selected['entity_id'] ?? 0));
        $rawId = (int)($selected['source_record_id'] ?? 0);
        if ($rawId > 0) {
            $rawStmt = $pdo->prepare(
                "SELECT r.id,r.source_dataset,r.external_record_id,r.mapping_status,r.mapped_entity_type,r.mapped_entity_id,r.captured_at,
                        ds.source_code,ds.source_name
                 FROM raw_source_records r
                 LEFT JOIN data_sources ds ON ds.id=r.data_source_id
                 WHERE r.organization_id=? AND r.id=? LIMIT 1"
            );
            $rawStmt->execute([$organizationId,$rawId]);
            $selectedRaw = $rawStmt->fetch() ?: null;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function ua_query(array $changes = []): string
{
    $base = $_GET;
    unset($base['audit']);
    foreach ($changes as $key=>$value) {
        if ($value === null) unset($base[$key]); else $base[$key] = $value;
    }
    return '?' . http_build_query($base);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Unified Audit & Activity Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/audit_center.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner">
<a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Unified Audit & Activity</small></span></a>
<div class="os-top-actions"><a class="os-btn" href="correction_center.php">Corrections</a><a class="os-btn" href="reversal_center.php">Reverse</a><a class="os-btn" href="restore_center.php">Restore</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
</div></header>

<div class="os-layout">
<aside class="os-sidebar">
<div class="os-nav-label">Business OS</div><nav class="os-nav">
<a href="index.php"><i class="dot"></i>Dashboard</a>
<a href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a>
<a href="correction_center.php"><i class="dot"></i>Correction Center</a>
<a href="reversal_center.php"><i class="dot"></i>Reverse / Cancel</a>
<a href="restore_center.php"><i class="dot"></i>Restore Center</a>
<a class="active" href="audit_center.php"><i class="dot"></i>Audit & Activity</a>
<a href="operations_center.php"><i class="dot"></i>Operations Center</a>
<a href="members.php"><i class="dot"></i>Members & Network</a>
<a href="report_center.php"><i class="dot"></i>Report Center</a>
</nav>
<div class="os-sidebar-status"><b>Read-only evidence center</b><span>Create, correction, reversal and restore history is inspected here. This page performs no business-data writes.</span></div>
</aside>

<main class="os-main">
<section class="os-hero ua-hero"><div class="os-kicker">Step 10L • Unified Audit + Activity Timeline</div>
<h1>Every important Business OS change is traceable from one read-only timeline.</h1>
<p>Follow a manual fact from creation through correction, reversal and restore. Reasons, before/after snapshots, entity identity and raw-source trace stay connected without changing the historical evidence.</p>
<div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'AUDIT CENTER LIVE':'Review required' ?></span><span class="os-chip good">READ ONLY</span><span class="os-chip"><?= number_format($summary['manual']) ?> manual lifecycle events</span><span class="os-chip"><?= number_format($summary['all']) ?> total audit events</span><span class="os-chip">Timezone: <?= ua_h($timezoneName) ?></span></div>
</section>

<?php if ($error!==null): ?><div class="ua-alert bad"><strong>Audit diagnostic:</strong> <?= ua_h($error) ?></div><?php endif; ?>

<?php if ($error===null): ?>
<section class="os-grid ua-kpis">
<article class="os-card os-kpi green"><small>Created</small><strong><?= number_format($summary['created']) ?></strong><span>Manual facts created</span></article>
<article class="os-card os-kpi blue"><small>Corrected</small><strong><?= number_format($summary['corrected']) ?></strong><span>Audited corrections</span></article>
<article class="os-card os-kpi gold"><small>Reversed</small><strong><?= number_format($summary['reversed']) ?></strong><span>Safe cancellations</span></article>
<article class="os-card os-kpi violet"><small>Restored</small><strong><?= number_format($summary['restored']) ?></strong><span>Audited recoveries</span></article>
</section>

<section class="os-card ua-filter-card">
<form method="get" class="ua-filters">
<div><label>Scope</label><select name="scope"><option value="manual" <?= $scope==='manual'?'selected':'' ?>>Manual Lifecycle</option><option value="all" <?= $scope==='all'?'selected':'' ?>>All System Activity</option></select></div>
<div><label>Action</label><select name="action"><option value="ALL">All Actions</option><?php foreach (['created'=>'Created','corrected'=>'Corrected','reversed'=>'Reversed','restored'=>'Restored','system'=>'System'] as $key=>$label): ?><option value="<?= ua_h($key) ?>" <?= $actionFilter===$key?'selected':'' ?>><?= ua_h($label) ?></option><?php endforeach; ?></select></div>
<div><label>Module</label><select name="module"><option value="ALL">All Modules</option><?php foreach (['new_ums'=>'New UMS','vp'=>'Volume Points','order'=>'Order','renewal'=>'Renewal','income'=>'Income','royalty'=>'Royalty','system'=>'System'] as $key=>$label): ?><option value="<?= ua_h($key) ?>" <?= $moduleFilter===$key?'selected':'' ?>><?= ua_h($label) ?></option><?php endforeach; ?></select></div>
<div><label>From</label><input type="date" name="from" value="<?= ua_h($from) ?>"></div>
<div><label>To</label><input type="date" name="to" value="<?= ua_h($to) ?>"></div>
<div class="ua-search"><label>Search</label><input name="q" value="<?= ua_h($q) ?>" placeholder="Event, entity, ID, reason, raw trace…"></div>
<button type="submit">Apply Filters</button><a href="audit_center.php">Reset</a>
</form>
</section>

<section class="ua-layout">
<article class="os-card ua-list-card">
<div class="ua-head"><div><h2>Activity Timeline</h2><p><?= number_format(count($rows)) ?> event(s) in the current view. Latest first.</p></div><span class="ua-readonly">READ ONLY</span></div>
<div class="ua-timeline">
<?php if (!$rows): ?><div class="ua-empty">No audit events match these filters.</div><?php else: ?>
<?php foreach ($rows as $row): $active=$selected!==null && (int)$selected['id']===(int)$row['id']; ?>
<a class="ua-event <?= ua_h((string)$row['action']) ?> <?= $active?'active':'' ?>" href="<?= ua_h(ua_query(['audit'=>(int)$row['id']])) ?>">
<span class="ua-dot"></span><div class="ua-event-main"><div class="ua-event-top"><b><?= ua_h((string)$row['title']) ?></b><em>#<?= number_format((int)$row['id']) ?></em></div>
<span><?= ua_h(ua_date_display((string)$row['created_at'])) ?></span><small><?= ua_h((string)($row['entity_type'] ?: 'system')) ?><?= $row['entity_id']!==null?' • #'.number_format((int)$row['entity_id']):'' ?><?= (int)$row['source_record_id']>0?' • RAW #'.number_format((int)$row['source_record_id']):'' ?></small></div>
<span class="ua-action-chip"><?= ua_h(ua_action_label((string)$row['action'])) ?></span>
</a>
<?php endforeach; ?>
<?php endif; ?>
</div>
</article>

<aside class="os-card ua-detail-card">
<?php if ($selected===null): ?>
<div class="ua-empty">Select an event from the timeline to inspect its evidence.</div>
<?php else: ?>
<div class="ua-head"><div><h2><?= ua_h((string)$selected['title']) ?></h2><p>Audit #<?= number_format((int)$selected['id']) ?> • <?= ua_h(ua_date_display((string)$selected['created_at'])) ?></p></div><span class="ua-action-chip <?= ua_h((string)$selected['action']) ?>"><?= ua_h(ua_action_label((string)$selected['action'])) ?></span></div>

<div class="ua-detail-grid">
<div><small>Module</small><b><?= ua_h(ua_module_label((string)$selected['module'])) ?></b></div>
<div><small>Entity</small><b><?= ua_h((string)($selected['entity_type'] ?: 'system')) ?><?= $selected['entity_id']!==null?' #'.number_format((int)$selected['entity_id']):'' ?></b></div>
<div><small>User</small><b><?= $selected['user_id']!==null?'User #'.number_format((int)$selected['user_id']):'System / local session' ?></b></div>
<div><small>IP Evidence</small><b><?= ua_h((string)($selected['ip_address'] ?: '—')) ?></b></div>
</div>

<div class="ua-reason"><small>Reason / Context</small><p><?= ua_h((string)$selected['reason']) ?></p></div>

<?php if ($selectedTarget['url']!==null): ?><a class="ua-open" href="<?= ua_h((string)$selectedTarget['url']) ?>"><?= ua_h((string)$selectedTarget['label']) ?> →</a><?php else: ?><div class="ua-lock"><?= ua_h((string)$selectedTarget['label']) ?></div><?php endif; ?>

<div class="ua-source-box"><div class="ua-subhead"><h3>Raw Source Trace</h3><span>IMMUTABLE EVIDENCE</span></div>
<?php if ($selectedRaw===null): ?><p class="ua-muted">This audit event does not carry a raw-source ID, or the referenced raw record is unavailable.</p><?php else: ?>
<div class="ua-detail-grid compact"><div><small>Raw ID</small><b>#<?= number_format((int)$selectedRaw['id']) ?></b></div><div><small>Source</small><b><?= ua_h((string)($selectedRaw['source_code'] ?: '—')) ?></b></div><div><small>Dataset</small><b><?= ua_h((string)($selectedRaw['source_dataset'] ?: '—')) ?></b></div><div><small>Mapping</small><b><?= ua_h((string)($selectedRaw['mapping_status'] ?: '—')) ?></b></div></div>
<p class="ua-muted">Captured <?= ua_h(ua_date_display((string)$selectedRaw['captured_at'])) ?><?= $selectedRaw['external_record_id']?' • External ID '.ua_h((string)$selectedRaw['external_record_id']):'' ?>.</p>
<?php endif; ?></div>

<?php $before=is_array($selectedDetails['before'] ?? null)?$selectedDetails['before']:[]; $after=is_array($selectedDetails['after'] ?? null)?$selectedDetails['after']:[]; ?>
<?php if ($before || $after): ?>
<div class="ua-compare"><div class="ua-subhead"><h3>Before / After Evidence</h3><span>SNAPSHOT</span></div>
<div class="ua-compare-grid">
<div><h4>Before</h4><?php if (!$before): ?><p class="ua-muted">No before snapshot stored.</p><?php else: ?><?php foreach ($before as $key=>$value): ?><div class="ua-kv"><span><?= ua_h(str_replace('_',' ',ucwords((string)$key,'_'))) ?></span><b><?= ua_h(ua_scalar($value)) ?></b></div><?php endforeach; ?><?php endif; ?></div>
<div><h4>After</h4><?php if (!$after): ?><p class="ua-muted">No after snapshot stored.</p><?php else: ?><?php foreach ($after as $key=>$value): ?><div class="ua-kv"><span><?= ua_h(str_replace('_',' ',ucwords((string)$key,'_'))) ?></span><b><?= ua_h(ua_scalar($value)) ?></b></div><?php endforeach; ?><?php endif; ?></div>
</div></div>
<?php endif; ?>

<details class="ua-details"><summary>Technical audit details</summary><div class="ua-kv-list"><?php if (!$selectedDetails): ?><p class="ua-muted">No details_json payload stored.</p><?php else: ?><?php foreach ($selectedDetails as $key=>$value): if (in_array($key,['before','after'],true)) continue; ?><div class="ua-kv"><span><?= ua_h(str_replace('_',' ',ucwords((string)$key,'_'))) ?></span><b><?= ua_h(ua_scalar($value)) ?></b></div><?php endforeach; ?><?php endif; ?></div></details>
<?php endif; ?>
</aside>
</section>

<div class="os-footer-note"><strong>Audit policy:</strong> this center is observational only. Manual facts are changed only through Data Entry, Correction, Reverse and Restore workflows. Legacy Excel/source evidence remains locked.</div>
<?php endif; ?>
</main></div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
