<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function dm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dm_event_label(string $event): string
{
    return ucwords(str_replace('_', ' ', $event));
}

$error = null;
$organizationId = 0;
$manualSourceId = 0;
$legacy = ['total'=>0,'mapped'=>0,'pending'=>0];
$manualRaw = 0;
$activeManual = ['members'=>0,'vp'=>0,'orders'=>0,'renewals'=>0,'income'=>0,'royalty'=>0];
$reversedManual = ['members'=>0,'vp'=>0,'orders'=>0,'renewals'=>0,'income'=>0,'royalty'=>0];
$auditCounts = ['created'=>0,'corrected'=>0,'reversed'=>0,'restored'=>0];
$recent = [];

try {
    $pdo = business_db();
    foreach (['organizations','data_sources','raw_source_records','members','volume_point_entries','orders','renewals','income_entries','royalty_entries','audit_logs'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' LIMIT 1");
    $sourceStmt->execute([$organizationId]);
    $manualSourceId = (int)$sourceStmt->fetchColumn();
    if ($manualSourceId <= 0) {
        throw new RuntimeException('MANUAL data source was not found.');
    }

    $stateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows,
                SUM(mapping_status='mapped') mapped_rows,
                SUM(mapping_status='pending') pending_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $stateStmt->execute([$organizationId]);
    $state = $stateStmt->fetch() ?: [];
    $legacy = [
        'total'=>(int)($state['total_rows'] ?? 0),
        'mapped'=>(int)($state['mapped_rows'] ?? 0),
        'pending'=>(int)($state['pending_rows'] ?? 0),
    ];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=?");
    $stmt->execute([$organizationId,$manualSourceId]);
    $manualRaw = (int)$stmt->fetchColumn();

    $tables = [
        'members'=>'members',
        'vp'=>'volume_point_entries',
        'orders'=>'orders',
        'renewals'=>'renewals',
        'income'=>'income_entries',
        'royalty'=>'royalty_entries',
    ];
    foreach ($tables as $key=>$table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_sheet='Manual Entry'");
        $stmt->execute([$organizationId]);
        $activeManual[$key] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_sheet=?");
        $stmt->execute([$organizationId,BUSINESS_REVERSED_SOURCE_SHEET]);
        $reversedManual[$key] = (int)$stmt->fetchColumn();
    }

    $auditMap = [
        'created'=>'^manual_.*_created$',
        'corrected'=>'^manual_.*_corrected$',
        'reversed'=>'^manual_.*_reversed$',
        'restored'=>'^manual_.*_restored$',
    ];
    foreach ($auditMap as $key=>$pattern) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE organization_id=? AND event_type REGEXP ?");
        $stmt->execute([$organizationId,$pattern]);
        $auditCounts[$key] = (int)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT id,event_type,entity_type,entity_id,details_json,created_at
         FROM audit_logs
         WHERE organization_id=? AND event_type REGEXP '^manual_.*_(created|corrected|reversed|restored)$'
         ORDER BY id DESC LIMIT 8"
    );
    $stmt->execute([$organizationId]);
    $recent = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$legacyReady = $legacy['total'] === 757 && $legacy['mapped'] === 757 && $legacy['pending'] === 0;
$activeTotal = array_sum($activeManual);
$reversedTotal = array_sum($reversedManual);
$ready = $error === null && $legacyReady && $manualSourceId > 0;

$workflow = [
    ['step'=>'01','title'=>'Smart Data Entry','desc'=>'Create New UMS, VP, Orders, Renewals, Income and Royalty with duplicate guard and raw trace.','file'=>'data_entry_center.php','action'=>'Create / Add','tone'=>'green'],
    ['step'=>'02','title'=>'Correction Center','desc'=>'Correct an active MANUAL fact while preserving the original raw payload and complete before/after audit.','file'=>'correction_center.php','action'=>'Correct','tone'=>'blue'],
    ['step'=>'03','title'=>'Reverse / Cancel','desc'=>'Remove a wrong MANUAL fact from normal business effect without deleting its source or normalized values.','file'=>'reversal_center.php','action'=>'Reverse','tone'=>'orange'],
    ['step'=>'04','title'=>'Restore Center','desc'=>'Bring back a reversed fact only after duplicate/conflict checks pass. Reversal history remains preserved.','file'=>'restore_center.php','action'=>'Restore','tone'=>'violet'],
    ['step'=>'05','title'=>'Unified Audit Center','desc'=>'Read the full Create → Correct → Reverse → Restore evidence timeline from one place.','file'=>'audit_center.php','action'=>'Review History','tone'=>'slate'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Data Management - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/data_management.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Data Management</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="data_entry_center.php">+ New Entry</a>
      <a class="os-btn" href="audit_center.php">Audit</a>
      <a class="os-btn primary" href="index.php">Dashboard</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav">
      <a href="index.php"><i class="dot"></i>Dashboard</a>
      <a class="active" href="data_management.php"><i class="dot"></i>Data Management</a>
      <a href="operations_center.php"><i class="dot"></i>Operations Center</a>
      <a href="members.php"><i class="dot"></i>Members & Network</a>
      <a href="member_profile.php"><i class="dot"></i>Member Profile 360°</a>
      <a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
      <a href="derived_reports_audit.php"><i class="dot"></i>Formula Audit</a>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Management workflow ready' : 'Review required' ?></b>
      <span><?= number_format($legacy['mapped']) ?> / 757 legacy mapped • <?= number_format($manualRaw) ?> MANUAL raw records.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero dm-hero">
      <div class="os-kicker">Step 10M • Data Management Workflow</div>
      <h1>One compact control center for every daily data change.</h1>
      <p>Use one predictable workflow: create new facts, correct mistakes, reverse unwanted effects, restore when needed, and verify every change in the audit timeline. Historical Excel evidence remains read-only throughout.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'DATA MANAGEMENT LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span>
        <span class="os-chip"><?= number_format($manualRaw) ?> manual raw records</span>
        <span class="os-chip"><?= number_format($activeTotal) ?> active manual facts</span>
        <span class="os-chip"><?= number_format($reversedTotal) ?> reversed facts</span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="dm-alert bad"><strong>Data Management diagnostic:</strong> <?= dm_h($error) ?></div>
    <?php endif; ?>

    <?php if ($error === null): ?>
      <section class="dm-flow" aria-label="Data management workflow">
        <?php foreach ($workflow as $i=>$item): ?>
          <a class="dm-flow-card <?= dm_h($item['tone']) ?>" href="<?= dm_h($item['file']) ?>">
            <span class="dm-step"><?= dm_h($item['step']) ?></span>
            <div><b><?= dm_h($item['title']) ?></b><p><?= dm_h($item['desc']) ?></p><small><?= dm_h($item['action']) ?> →</small></div>
          </a>
          <?php if ($i < count($workflow)-1): ?><span class="dm-arrow">→</span><?php endif; ?>
        <?php endforeach; ?>
      </section>

      <section class="os-grid dm-kpis">
        <article class="os-card os-kpi green"><small>Active Manual Facts</small><strong><?= number_format($activeTotal) ?></strong><span>Currently included in Business OS</span></article>
        <article class="os-card os-kpi blue"><small>Corrections</small><strong><?= number_format($auditCounts['corrected']) ?></strong><span>Before/after history preserved</span></article>
        <article class="os-card os-kpi gold"><small>Reversed</small><strong><?= number_format($reversedTotal) ?></strong><span>Preserved but excluded from effect</span></article>
        <article class="os-card os-kpi violet"><small>Restores</small><strong><?= number_format($auditCounts['restored']) ?></strong><span>Conflict-checked recoveries</span></article>
      </section>

      <section class="dm-layout">
        <article class="os-card dm-card">
          <div class="os-title-row"><div><h2>Manual Data State</h2><p>Current normalized MANUAL facts by module.</p></div><a class="os-btn" href="data_entry_center.php">Add Data</a></div>
          <div class="dm-state-grid">
            <?php
              $labels=['members'=>'New UMS / Members','vp'=>'Volume Points','orders'=>'Orders','renewals'=>'Renewals','income'=>'Income','royalty'=>'Royalty'];
              foreach ($labels as $key=>$label):
            ?>
              <div class="dm-state-row">
                <div><b><?= dm_h($label) ?></b><span>Active + safely preserved reversed state</span></div>
                <div class="dm-counts"><span><strong><?= number_format($activeManual[$key]) ?></strong> Active</span><span class="rev"><strong><?= number_format($reversedManual[$key]) ?></strong> Reversed</span></div>
              </div>
            <?php endforeach; ?>
          </div>
        </article>

        <aside class="os-card dm-card">
          <div class="os-title-row"><div><h2>Lifecycle Audit</h2><p>Manual workflow event totals.</p></div><a class="os-btn" href="audit_center.php">Open Audit</a></div>
          <div class="dm-audit-grid">
            <div><small>Created</small><strong><?= number_format($auditCounts['created']) ?></strong></div>
            <div><small>Corrected</small><strong><?= number_format($auditCounts['corrected']) ?></strong></div>
            <div><small>Reversed</small><strong><?= number_format($auditCounts['reversed']) ?></strong></div>
            <div><small>Restored</small><strong><?= number_format($auditCounts['restored']) ?></strong></div>
          </div>
          <div class="dm-policy"><strong>Protection:</strong> imported/legacy rows are not part of this write workflow. Original MANUAL raw payloads stay immutable through correction, reversal and restore.</div>
        </aside>

        <article class="os-card dm-card dm-recent">
          <div class="os-title-row"><div><h2>Recent Data Activity</h2><p>Latest MANUAL lifecycle events across all management tools.</p></div></div>
          <div class="os-list">
            <?php if (!$recent): ?>
              <div class="os-list-row"><div><b>No manual lifecycle events yet</b><span>New activity will appear after a MANUAL entry is created.</span></div></div>
            <?php else: ?>
              <?php foreach ($recent as $row): ?>
                <div class="os-list-row">
                  <div><b><?= dm_h(dm_event_label((string)$row['event_type'])) ?></b><span><?= dm_h((string)($row['entity_type'] ?: 'system')) ?><?= $row['entity_id'] !== null ? ' • #' . number_format((int)$row['entity_id']) : '' ?></span></div>
                  <strong><?= dm_h(substr((string)$row['created_at'],0,16)) ?></strong>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </article>
      </section>
    <?php endif; ?>

    <div class="os-footer-note"><strong>Workflow rule:</strong> Create for new facts, Correct for wrong values, Reverse to remove a fact from business effect without deletion, Restore only when recovery is genuinely needed, and Audit whenever you need the complete evidence trail.</div>
  </main>
</div>
</body>
</html>