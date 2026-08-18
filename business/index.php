<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function os_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$status = business_db_status();
$error = null;
$organizationId = 0;
$metrics = [
    'source_total' => 0,
    'source_mapped' => 0,
    'source_pending' => 0,
    'members' => 0,
    'vp_facts' => 0,
    'orders' => 0,
    'renewals' => 0,
    'income_facts' => 0,
    'royalty_facts' => 0,
    'active_snapshots' => 0,
    'manual_raw' => 0,
    'reports' => 0,
    'rules' => 0,
];
$recentAudits = [];

try {
    if (!$status['connected']) {
        throw new RuntimeException('Database connection is not ready.');
    }

    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
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
    $metrics['source_total'] = (int)($state['total_rows'] ?? 0);
    $metrics['source_mapped'] = (int)($state['mapped_rows'] ?? 0);
    $metrics['source_pending'] = (int)($state['pending_rows'] ?? 0);

    $queries = [
        'members' => ['members', "SELECT COUNT(*) FROM members WHERE organization_id=?"],
        'vp_facts' => ['volume_point_entries', "SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=?"],
        'orders' => ['orders', "SELECT COUNT(*) FROM orders WHERE organization_id=?"],
        'renewals' => ['renewals', "SELECT COUNT(*) FROM renewals WHERE organization_id=?"],
        'income_facts' => ['income_entries', "SELECT COUNT(*) FROM income_entries WHERE organization_id=?"],
        'royalty_facts' => ['royalty_entries', "SELECT COUNT(*) FROM royalty_entries WHERE organization_id=?"],
        'active_snapshots' => ['ums_activity_snapshots', "SELECT COUNT(*) FROM ums_activity_snapshots WHERE organization_id=?"],
        'reports' => ['report_definitions', "SELECT COUNT(*) FROM report_definitions WHERE organization_id=? AND is_active=1"],
        'rules' => ['calculation_rules', "SELECT COUNT(*) FROM calculation_rules WHERE organization_id=? AND is_active=1"],
    ];

    foreach ($queries as $key => [$table, $sql]) {
        if (!business_table_exists($pdo, $table)) {
            continue;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$organizationId]);
        $metrics[$key] = (int)$stmt->fetchColumn();
    }

    $manualSourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' LIMIT 1");
    $manualSourceStmt->execute([$organizationId]);
    $manualSourceId = (int)$manualSourceStmt->fetchColumn();
    if ($manualSourceId > 0) {
        $manualStmt = $pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=? AND mapping_status='mapped'");
        $manualStmt->execute([$organizationId,$manualSourceId]);
        $metrics['manual_raw'] = (int)$manualStmt->fetchColumn();
    }

    if (business_table_exists($pdo, 'audit_logs')) {
        $auditStmt = $pdo->prepare(
            "SELECT event_type, entity_type, created_at
             FROM audit_logs
             WHERE organization_id=?
             ORDER BY id DESC LIMIT 6"
        );
        $auditStmt->execute([$organizationId]);
        $recentAudits = $auditStmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sourceReady = $metrics['source_total'] === 757 && $metrics['source_mapped'] === 757 && $metrics['source_pending'] === 0;
$reportsReady = $metrics['reports'] === 6;
$businessReady = $error === null && $sourceReady && $reportsReady;

$liveReports = [
    ['name'=>'Master Tracking','file'=>'master_tracking.php','desc'=>'Weekly/monthly VP and business tracking'],
    ['name'=>'SP House','file'=>'sp_house.php','desc'=>'Member SP and first-line VP view'],
    ['name'=>'Name Wise Tracking','file'=>'name_wise_tracking.php','desc'=>'Name-wise PC/Associate/UMS VP'],
    ['name'=>'Master Business','file'=>'master_business_tracking.php','desc'=>'PPV, DVP, royalty and UMS metrics'],
    ['name'=>'UMS Renewal','file'=>'ums_renewal.php','desc'=>'Renewed, pending and identity review'],
    ['name'=>'Active Duration','file'=>'ums_active_duration.php','desc'=>'Live UMS duration and lifecycle view'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Business OS Dashboard - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="../index.html">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Live Dashboard</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="../index.html">Public Website</a>
      <a class="os-btn" href="data_management.php">Data Management</a>
      <a class="os-btn" href="operations_center.php">Operations</a>
      <a class="os-btn primary" href="report_center.php">Report Center</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav">
      <a class="active" href="index.php"><i class="dot"></i>Dashboard</a>
      <a href="data_management.php"><i class="dot"></i>Data Management</a>
      <a href="operations_center.php"><i class="dot"></i>Operations Center</a>
      <a href="members.php"><i class="dot"></i>Members & Network</a>
      <a href="member_profile.php"><i class="dot"></i>Member Profile 360°</a>
      <a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
      <a href="derived_reports_audit.php"><i class="dot"></i>Formula Audit</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Live Reports</div>
    <nav class="os-nav">
      <?php foreach ($liveReports as $report): ?>
        <a href="<?= os_h($report['file']) ?>"><i class="dot"></i><?= os_h($report['name']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $businessReady ? 'Business OS operational' : 'Setup review required' ?></b>
      <span><?= number_format($metrics['source_mapped']) ?> / 757 legacy source rows mapped • <?= number_format($metrics['manual_raw']) ?> manual entries.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero">
      <div class="os-kicker">Step 10M • Professional Business OS</div>
      <h1>Historical Excel evidence, daily operations and every manual data change now follow one connected workflow.</h1>
      <p>The reconciled workbook remains traceable historical evidence. New records and later corrections, reversals, restores and audits are organized through one compact Data Management control center.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $businessReady ? 'good' : '' ?>"><?= $businessReady ? 'BUSINESS OS LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($metrics['source_mapped']) ?> / 757 legacy source mapped</span>
        <span class="os-chip good">6 / 6 live reports</span>
        <span class="os-chip"><?= number_format($metrics['manual_raw']) ?> manual entries</span>
        <span class="os-chip">Schema <?= os_h((string)$status['schema_version']) ?></span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="os-footer-note" style="background:#fff3f3;border-color:#efc7c7;color:#8b2c2c"><strong>Dashboard diagnostic:</strong> <?= os_h($error) ?></div>
    <?php endif; ?>

    <section class="os-grid">
      <article class="os-card os-kpi green"><small>Members</small><strong><?= number_format($metrics['members']) ?></strong><span>Imported + active manual member records</span></article>
      <article class="os-card os-kpi blue"><small>Volume Point Facts</small><strong><?= number_format($metrics['vp_facts']) ?></strong><span>All normalized VP sources</span></article>
      <article class="os-card os-kpi gold"><small>Orders</small><strong><?= number_format($metrics['orders']) ?></strong><span>Active normalized order facts</span></article>
      <article class="os-card os-kpi violet"><small>Renewal Facts</small><strong><?= number_format($metrics['renewals']) ?></strong><span>Imported + active manual renewals</span></article>

      <article class="os-card os-section">
        <div class="os-title-row">
          <div><h2>Data Management</h2><p>One central workflow for creating, correcting, reversing, restoring and auditing daily MANUAL business facts.</p></div>
          <a class="os-btn primary" href="data_management.php">Open Data Management</a>
        </div>
        <div class="os-report-grid">
          <a class="os-report" href="data_entry_center.php"><div class="os-report-head"><b>Smart Data Entry</b><em>WRITE</em></div><span>Create New UMS, VP, Orders, Renewals, Income and Royalty.</span><small>Create / Add →</small></a>
          <a class="os-report" href="correction_center.php"><div class="os-report-head"><b>Correction Center</b><em>SAFE EDIT</em></div><span>Correct MANUAL values while preserving before/after evidence.</span><small>Correct →</small></a>
          <a class="os-report" href="reversal_center.php"><div class="os-report-head"><b>Reverse / Cancel</b><em>NO DELETE</em></div><span>Remove business effect without deleting raw or normalized evidence.</span><small>Reverse →</small></a>
          <a class="os-report" href="restore_center.php"><div class="os-report-head"><b>Restore Center</b><em>RECOVER</em></div><span>Recover a reversed fact after duplicate/conflict safety checks.</span><small>Restore →</small></a>
          <a class="os-report" href="audit_center.php"><div class="os-report-head"><b>Unified Audit</b><em>READ ONLY</em></div><span>Review Create → Correct → Reverse → Restore history.</span><small>Open Timeline →</small></a>
        </div>
      </article>

      <aside class="os-card os-side">
        <h2>Source & Engine Health</h2>
        <p>Historical import remains frozen/reconciled while new entries use the MANUAL source channel.</p>
        <div class="os-list">
          <div class="os-list-row"><div><b>Legacy operational rows</b><span>Excel Sheets 7–14</span></div><strong><?= number_format($metrics['source_mapped']) ?>/<?= number_format($metrics['source_total']) ?></strong></div>
          <div class="os-list-row"><div><b>Legacy pending</b><span>Must remain zero</span></div><strong><?= number_format($metrics['source_pending']) ?></strong></div>
          <div class="os-list-row"><div><b>Manual raw entries</b><span>Business OS write channel</span></div><strong><?= number_format($metrics['manual_raw']) ?></strong></div>
          <div class="os-list-row"><div><b>Derived reports</b><span>Workbook Sheets 1–6</span></div><strong><?= number_format($metrics['reports']) ?>/6</strong></div>
        </div>
        <div class="os-progress"><i style="width:<?= $businessReady ? '100' : '0' ?>%"></i></div>
      </aside>

      <article class="os-card os-section">
        <div class="os-title-row"><div><h2>Business Fact Snapshot</h2><p>Normalized facts available to operations, member profiles and reporting engines.</p></div><a class="os-btn" href="operations_center.php">Open Operations</a></div>
        <div class="os-list">
          <div class="os-list-row"><div><b>Income Facts</b><span>Retail, Check, Club and future manual entries</span></div><strong><?= number_format($metrics['income_facts']) ?></strong></div>
          <div class="os-list-row"><div><b>Royalty Facts</b><span>Historical + manual royalty facts</span></div><strong><?= number_format($metrics['royalty_facts']) ?></strong></div>
          <div class="os-list-row"><div><b>Active UMS Snapshots</b><span>Monthly active-member snapshots</span></div><strong><?= number_format($metrics['active_snapshots']) ?></strong></div>
          <div class="os-list-row"><div><b>Trace Architecture</b><span>Raw source → normalized fact → audit/report</span></div><strong><?= $sourceReady ? 'PASS' : 'CHECK' ?></strong></div>
        </div>
      </article>

      <aside class="os-card os-side">
        <div class="os-title-row"><div><h2>Recent System Activity</h2><p>Latest imports, links and manual lifecycle audit events.</p></div><a class="os-btn" href="audit_center.php">Audit</a></div>
        <div class="os-list">
          <?php if (!$recentAudits): ?>
            <div class="os-list-row"><div><b>No recent audit entries</b><span>Activity will appear here when recorded.</span></div></div>
          <?php else: ?>
            <?php foreach ($recentAudits as $audit): ?>
              <div class="os-list-row"><div><b><?= os_h(str_replace('_', ' ', (string)$audit['event_type'])) ?></b><span><?= os_h((string)($audit['entity_type'] ?? 'system')) ?></span></div><strong><?= os_h(substr((string)$audit['created_at'], 0, 16)) ?></strong></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </aside>

      <article class="os-card" style="grid-column:span 12">
        <div class="os-title-row"><div><h2>Quick Actions</h2><p>Open the main operational workspaces without a crowded navigation menu.</p></div></div>
        <div class="os-links">
          <a class="os-link" href="data_management.php"><div><b>Data Management</b><span>Create, Correct, Reverse, Restore and Audit from one workflow hub</span></div><span>→</span></a>
          <a class="os-link" href="operations_center.php"><div><b>Operations Center</b><span>Orders, VP, Income and Royalty with period/member filters and source trace</span></div><span>→</span></a>
          <a class="os-link" href="members.php"><div><b>Members & Network</b><span>Search members and inspect identity-safe UMS/network context</span></div><span>→</span></a>
          <a class="os-link" href="member_profile.php"><div><b>Member Profile 360°</b><span>Complete verified member business timeline and lifecycle detail</span></div><span>→</span></a>
          <a class="os-link" href="sponsor_network.php"><div><b>Sponsor Network</b><span>Verified sponsor links and interactive network tree</span></div><span>→</span></a>
          <a class="os-link" href="report_center.php"><div><b>Report Center</b><span>Open all six live derived reports</span></div><span>→</span></a>
          <a class="os-link" href="final_excel_seeding.php"><div><b>Excel Data Center</b><span>Historical source reconciliation and normalized fact counts</span></div><span>→</span></a>
        </div>
      </article>
    </section>

    <div class="os-footer-note"><strong>Source-of-truth status:</strong> the 757-row legacy workbook import remains isolated and reconciled. Daily MANUAL facts now use one consolidated Create → Correct → Reverse → Restore → Audit workflow without changing historical raw rows.</div>
  </main>
</div>
</body>
</html>