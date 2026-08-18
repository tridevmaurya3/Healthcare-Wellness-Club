<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/step10_services.php';

function al_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if (empty($_SESSION['alerts_csrf'])) $_SESSION['alerts_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['alerts_csrf'];

$error = null;
$success = null;
$alerts = [];
$followups = [];
$members = [];
$ctx = ['organization_id'=>0,'club_id'=>0,'timezone'=>'Asia/Kolkata'];
$legacy = ['total'=>0,'mapped'=>0,'pending'=>0];
$summary = ['critical'=>0,'review'=>0,'info'=>0,'open'=>0,'due_today'=>0,'overdue'=>0,'done'=>0];
$rev = defined('BUSINESS_REVERSED_SOURCE_SHEET') ? BUSINESS_REVERSED_SOURCE_SHEET : 'Manual Entry • Reversed';

try {
    $pdo = business_db();
    foreach (['organizations','clubs','members','raw_source_records','data_sources','audit_logs'] as $table) {
        if (!business_table_exists($pdo,$table)) throw new RuntimeException("Required table {$table} is missing.");
    }
    $ctx = step10_org_context($pdo);
    step10_ensure_tables($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];
    $today = date('Y-m-d');
    $legacy = step10_legacy_state($pdo,$orgId);

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch. Refresh and try again.');
        $action = step10_trim($_POST['action'] ?? '');
        if ($action === 'create_followup') {
            $title = preg_replace('/\s+/u',' ',step10_trim($_POST['title'] ?? '')) ?: '';
            $description = step10_trim($_POST['description'] ?? '');
            $dueDate = step10_trim($_POST['due_date'] ?? '');
            $priority = step10_key($_POST['priority'] ?? 'normal');
            $type = step10_key($_POST['followup_type'] ?? 'general');
            $memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) && (int)$_POST['member_id']>0 ? (int)$_POST['member_id'] : null;
            if (al_len($title)<3 || al_len($title)>190) throw new RuntimeException('Follow-up title must be 3–190 characters.');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d',$dueDate);
            if (!$d || $d->format('Y-m-d')!==$dueDate) throw new RuntimeException('Choose a valid follow-up date.');
            if (!in_array($priority,['low','normal','high','urgent'],true)) $priority='normal';
            if (!in_array($type,['general','member','renewal','identity','order','vp','income','royalty','system'],true)) $type='general';
            if ($memberId !== null) {
                $stmt=$pdo->prepare("SELECT COUNT(*) FROM members WHERE organization_id=? AND id=? AND COALESCE(source_sheet,'')<>?");
                $stmt->execute([$orgId,$memberId,$rev]);
                if ((int)$stmt->fetchColumn()!==1) throw new RuntimeException('Selected member is not an active member record.');
            }
            $stmt=$pdo->prepare("INSERT INTO business_followups
                (organization_id,club_id,member_id,followup_type,title,description,due_date,priority,status)
                VALUES (?,?,?,?,?,?,?,?,'open')");
            $stmt->execute([$orgId,$clubId,$memberId,$type,$title,$description!==''?$description:null,$dueDate,$priority]);
            $id=(int)$pdo->lastInsertId();
            step10_audit($pdo,$orgId,$clubId,'business_followup_created','business_followup',$id,[
                'title'=>$title,'due_date'=>$dueDate,'priority'=>$priority,'member_id'=>$memberId
            ]);
            $success='Follow-up created successfully.';
        }
        if (in_array($action,['complete_followup','reopen_followup'],true)) {
            $id = isset($_POST['followup_id']) && is_numeric($_POST['followup_id']) ? (int)$_POST['followup_id'] : 0;
            if ($id<=0) throw new RuntimeException('Follow-up target is invalid.');
            $newStatus = $action==='complete_followup' ? 'done' : 'open';
            $stmt=$pdo->prepare("UPDATE business_followups SET status=?,completed_at=? WHERE organization_id=? AND id=?");
            $stmt->execute([$newStatus,$newStatus==='done'?date('Y-m-d H:i:s'):null,$orgId,$id]);
            if ($stmt->rowCount()!==1) throw new RuntimeException('Follow-up state changed unexpectedly.');
            step10_audit($pdo,$orgId,$clubId,'business_followup_'.$newStatus,'business_followup',$id,['status'=>$newStatus]);
            $success=$newStatus==='done'?'Follow-up completed.':'Follow-up reopened.';
        }
    }

    $stmt=$pdo->prepare("SELECT id,full_name,mobile FROM members WHERE organization_id=? AND COALESCE(source_sheet,'')<>? ORDER BY full_name,id");
    $stmt->execute([$orgId,$rev]);
    $members=$stmt->fetchAll();

    if ($legacy['total']!==757 || $legacy['mapped']!==757 || $legacy['pending']!==0) {
        $alerts[]=['level'=>'critical','title'=>'Legacy source reconciliation needs attention','detail'=>$legacy['mapped'].' / '.$legacy['total'].' mapped • '.$legacy['pending'].' pending','url'=>'reconcile_raw.php'];
    }

    $manualStmt=$pdo->prepare("SELECT ds.id FROM data_sources ds WHERE ds.organization_id=? AND ds.source_code='MANUAL' LIMIT 1");
    $manualStmt->execute([$orgId]);
    $manualSourceId=(int)$manualStmt->fetchColumn();
    if ($manualSourceId>0) {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=? AND mapping_status<>'mapped'");
        $stmt->execute([$orgId,$manualSourceId]);
        $manualPending=(int)$stmt->fetchColumn();
        if ($manualPending>0) $alerts[]=['level'=>'critical','title'=>'Manual source mapping is incomplete','detail'=>$manualPending.' MANUAL raw record(s) are not mapped.','url'=>'data_management.php'];
    }

    $stmt=$pdo->prepare("SELECT LOWER(TRIM(full_name)) k,MIN(full_name) label,COUNT(*) c FROM members WHERE organization_id=? AND COALESCE(source_sheet,'')<>? GROUP BY LOWER(TRIM(full_name)) HAVING COUNT(*)>1 ORDER BY c DESC,label LIMIT 20");
    $stmt->execute([$orgId,$rev]);
    $duplicateGroups=$stmt->fetchAll();
    if ($duplicateGroups) $alerts[]=['level'=>'review','title'=>'Duplicate-name identity review','detail'=>count($duplicateGroups).' duplicate-name group(s) need identity-aware review; no automatic merge is performed.','url'=>'members.php'];

    $mobileCounts=[];
    foreach ($members as $m) {
        $digits=preg_replace('/\D+/','',(string)($m['mobile'] ?? '')) ?? '';
        if ($digits==='' || preg_match('/^0+$/',$digits)) continue;
        $mobileCounts[$digits]=($mobileCounts[$digits] ?? 0)+1;
    }
    $sharedMobiles=count(array_filter($mobileCounts,static fn(int $c):bool=>$c>1));
    if ($sharedMobiles>0) $alerts[]=['level'=>'review','title'=>'Shared mobile identity review','detail'=>$sharedMobiles.' mobile value(s) are linked to more than one active member record.','url'=>'members.php'];

    $unresolvedSponsor=0;
    $stmt=$pdo->prepare("SELECT m.id,r.raw_json FROM members m LEFT JOIN raw_source_records r ON r.id=m.source_record_id WHERE m.organization_id=? AND m.sponsor_member_id IS NULL AND COALESCE(m.source_sheet,'')<>?");
    $stmt->execute([$orgId,$rev]);
    foreach ($stmt->fetchAll() as $row) {
        $payload=step10_json_array($row['raw_json'] ?? null);
        $sourceSponsor=step10_trim($payload['values']['G'] ?? '');
        if ($sourceSponsor!=='') $unresolvedSponsor++;
    }
    if ($unresolvedSponsor>0) $alerts[]=['level'=>'review','title'=>'Sponsor links still pending','detail'=>$unresolvedSponsor.' member record(s) have a source sponsor name but no verified sponsor_member_id.','url'=>'sponsor_network.php'];

    $reversedTotal=0;
    foreach (['members','volume_point_entries','orders','renewals','income_entries','royalty_entries'] as $table) {
        if (!business_table_exists($pdo,$table)) continue;
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_sheet=?");
        $stmt->execute([$orgId,$rev]);
        $reversedTotal+=(int)$stmt->fetchColumn();
    }
    if ($reversedTotal>0) $alerts[]=['level'=>'info','title'=>'Reversed manual facts are preserved','detail'=>$reversedTotal.' reversed fact(s) are excluded from normal business effect and can be reviewed/restored safely.','url'=>'restore_center.php'];

    $stmt=$pdo->prepare("SELECT f.*,m.full_name member_name FROM business_followups f LEFT JOIN members m ON m.id=f.member_id AND m.organization_id=f.organization_id WHERE f.organization_id=? ORDER BY (f.status='open') DESC,f.due_date ASC,f.priority='urgent' DESC,f.id DESC LIMIT 200");
    $stmt->execute([$orgId]);
    $followups=$stmt->fetchAll();
    foreach ($followups as $f) {
        if ($f['status']==='done') { $summary['done']++; continue; }
        $summary['open']++;
        if ((string)$f['due_date']<$today) $summary['overdue']++;
        elseif ((string)$f['due_date']===$today) $summary['due_today']++;
    }

    foreach ($alerts as $a) $summary[$a['level']]++;
} catch (Throwable $e) {
    $error=$e->getMessage();
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Alerts & Follow-ups - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner">
<a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Smart Alerts & Follow-ups</small></span></a>
<div class="os-top-actions"><a class="os-btn" href="today_center.php">Today</a><a class="os-btn" href="data_management.php">Data Management</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
</div></header>
<div class="os-layout">
<aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav">
<a href="index.php"><i class="dot"></i>Dashboard</a><a href="global_search.php"><i class="dot"></i>Global Search</a><a class="active" href="alerts_center.php"><i class="dot"></i>Alerts & Follow-ups</a><a href="today_center.php"><i class="dot"></i>Today Center</a><a href="insights_center.php"><i class="dot"></i>Insights</a><a href="export_center.php"><i class="dot"></i>Export Center</a><a href="data_quality.php"><i class="dot"></i>Data Quality</a><a href="health_center.php"><i class="dot"></i>System Health</a><a href="data_management.php"><i class="dot"></i>Data Management</a><a href="report_center.php"><i class="dot"></i>Report Center</a></nav>
<div class="os-sidebar-status"><b>Fact-safe alerts</b><span>Only source-supported issues are surfaced. No automatic identity merge or business assumption.</span></div></aside>
<main class="os-main">
<section class="os-hero s10-hero"><div class="os-kicker">Step 10O–P • Smart Alerts & Follow-ups</div><h1>Turn verified data issues and daily commitments into one actionable queue.</h1><p>Alerts are derived from source/reconciliation/identity state. Follow-ups are explicit user tasks with due date, priority, optional member link and full audit trail.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'ALERT CENTER LIVE':'Review required' ?></span><span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span><span class="os-chip"><?= number_format($summary['open']) ?> open follow-ups</span><span class="os-chip"><?= number_format($summary['overdue']) ?> overdue</span></div></section>
<?php if ($success): ?><div class="s10-alert good"><?= step10_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="s10-alert bad"><strong>Alert diagnostic:</strong> <?= step10_h($error) ?></div><?php endif; ?>
<?php if (!$error): ?>
<section class="s10-kpis"><div class="s10-kpi"><small>Critical</small><strong><?= number_format($summary['critical']) ?></strong><span>System/source issues</span></div><div class="s10-kpi"><small>Identity Review</small><strong><?= number_format($summary['review']) ?></strong><span>Human verification required</span></div><div class="s10-kpi"><small>Due Today</small><strong><?= number_format($summary['due_today']) ?></strong><span>Open follow-ups</span></div><div class="s10-kpi"><small>Overdue</small><strong><?= number_format($summary['overdue']) ?></strong><span>Needs attention</span></div></section>
<section class="s10-grid">
<article class="s10-card s10-span-7"><h2>Smart Alerts</h2><p>Derived from facts already present in Business OS.</p><div class="s10-list">
<?php if (!$alerts): ?><div class="s10-row"><div><b>No current system alerts</b><small>Source, identity and reversal checks are clear.</small></div><span class="s10-badge">CLEAR</span></div><?php endif; ?>
<?php foreach ($alerts as $a): ?><div class="s10-row"><div><b><?= step10_h($a['title']) ?></b><small><?= step10_h($a['detail']) ?></small></div><div class="s10-actions"><span class="s10-badge <?= $a['level']==='critical'?'bad':($a['level']==='review'?'warn':'blue') ?>"><?= strtoupper(step10_h($a['level'])) ?></span><a href="<?= step10_h($a['url']) ?>">Review →</a></div></div><?php endforeach; ?>
</div></article>
<aside class="s10-card s10-span-5"><h2>Create Follow-up</h2><p>Add an explicit reminder without changing the underlying business fact.</p><form method="post" class="s10-form-grid"><input type="hidden" name="csrf" value="<?= step10_h($csrf) ?>"><input type="hidden" name="action" value="create_followup"><div class="s10-field full"><label>Title</label><input name="title" maxlength="190" required placeholder="e.g. Verify sponsor identity"></div><div class="s10-field"><label>Due date</label><input type="date" name="due_date" value="<?= step10_h(date('Y-m-d')) ?>" required></div><div class="s10-field"><label>Priority</label><select name="priority"><option>normal</option><option>high</option><option>urgent</option><option>low</option></select></div><div class="s10-field"><label>Type</label><select name="followup_type"><option value="general">General</option><option value="member">Member</option><option value="renewal">Renewal</option><option value="identity">Identity</option><option value="order">Order</option><option value="vp">VP</option><option value="income">Income</option><option value="royalty">Royalty</option><option value="system">System</option></select></div><div class="s10-field"><label>Member (optional)</label><select name="member_id"><option value="">— None —</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= step10_h($m['full_name']) ?><?= !empty($m['mobile'])?' • '.step10_h($m['mobile']):'' ?></option><?php endforeach; ?></select></div><div class="s10-field full"><label>Description</label><textarea name="description" placeholder="What should be checked or completed?"></textarea></div><div class="s10-field full"><button class="os-btn primary" type="submit">Create Follow-up</button></div></form></aside>
<article class="s10-card s10-span-12"><h2>Follow-up Queue</h2><p>Open, overdue and completed reminders with optional member context.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Status</th><th>Due</th><th>Priority</th><th>Follow-up</th><th>Member</th><th>Action</th></tr></thead><tbody><?php if (!$followups): ?><tr><td colspan="6" class="s10-empty">No follow-ups yet.</td></tr><?php endif; ?><?php foreach ($followups as $f): $isDone=$f['status']==='done'; $isOver=!$isDone && (string)$f['due_date']<date('Y-m-d'); ?><tr><td><span class="s10-badge <?= $isDone?'':($isOver?'bad':'blue') ?>"><?= step10_h($isDone?'DONE':($isOver?'OVERDUE':'OPEN')) ?></span></td><td><?= step10_h(step10_date((string)$f['due_date'])) ?></td><td><?= step10_h(strtoupper((string)$f['priority'])) ?></td><td><b><?= step10_h($f['title']) ?></b><small style="display:block;color:#75827b;margin-top:3px"><?= step10_h($f['description'] ?? '') ?></small></td><td><?= step10_h($f['member_name'] ?? '—') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= step10_h($csrf) ?>"><input type="hidden" name="followup_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="<?= $isDone?'reopen_followup':'complete_followup' ?>"><button class="s10-link" type="submit"><?= $isDone?'Reopen':'Complete' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></article>
</section><?php endif; ?>
</main></div></body></html>
