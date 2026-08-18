<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
$status = business_db_status();
$isReady = (bool)$status['connected'] && (string)$status['schema_version'] === '2.0-world-ready';
$operationalTotal = 0;
$operationalMapped = 0;
$operationalPending = 0;
$sourceSeeded = false;

if ($status['connected']) {
    try {
        $pdo = business_db();
        if (business_table_exists($pdo, 'raw_source_records')) {
            $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
            $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
            $sourceStmt->execute([$organizationId]);
            $sourceId = (int)$sourceStmt->fetchColumn();
            $batchStmt = $pdo->prepare(
                "SELECT id FROM import_batches
                 WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture' AND status='completed'
                 ORDER BY id DESC LIMIT 1"
            );
            $batchStmt->execute([$organizationId, $sourceId]);
            $batchId = (int)$batchStmt->fetchColumn();
            if ($organizationId > 0 && $sourceId > 0 && $batchId > 0) {
                $stateStmt = $pdo->prepare(
                    "SELECT COUNT(*) total_rows,
                            SUM(mapping_status='mapped') mapped_rows,
                            SUM(mapping_status='pending') pending_rows
                     FROM raw_source_records
                     WHERE organization_id=? AND data_source_id=? AND import_batch_id=?"
                );
                $stateStmt->execute([$organizationId, $sourceId, $batchId]);
                $state = $stateStmt->fetch() ?: [];
                $operationalTotal = (int)($state['total_rows'] ?? 0);
                $operationalMapped = (int)($state['mapped_rows'] ?? 0);
                $operationalPending = (int)($state['pending_rows'] ?? 0);
                $sourceSeeded = $operationalTotal === 757 && $operationalMapped === 757 && $operationalPending === 0;
            }
        }
    } catch (Throwable) {
        // Foundation page stays usable even if source-status diagnostics fail.
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Business OS Foundation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/business.css">
</head>
<body>
  <header class="biz-topbar">
    <div class="biz-topbar-inner">
      <a class="biz-brand" href="../index.html">
        <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
        <span><strong>Healthcare Wellness Club</strong><small>Business OS • World-Ready Foundation</small></span>
      </a>
      <a class="biz-back" href="../index.html">← Public Website</a>
    </div>
  </header>

  <main class="biz-shell">
    <section class="biz-hero">
      <div>
        <div class="biz-kicker">World-Ready Business Architecture</div>
        <h1>One source of business truth, with live calculations instead of duplicate formula sheets.</h1>
        <p>The operational Excel layer is now normalized into traceable database facts. The first six workbook sheets remain derived calculations and are being rebuilt as live Business OS reports.</p>
      </div>
      <div style="display:flex;flex-direction:column;align-items:stretch;gap:10px">
        <div class="biz-status <?= ($isReady && $sourceSeeded) ? 'ready' : '' ?>">
          <i></i><?= htmlspecialchars($sourceSeeded ? 'Operational Source Seeded' : ($isReady ? 'World-Ready Schema Active' : 'Database Setup Pending'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <a class="biz-back" href="derived_reports_audit.php" style="background:#19764a;border-color:#19764a;color:#fff">Derived Formula Audit →</a>
        <a class="biz-back" href="final_excel_seeding.php">Excel Seeding Center</a>
      </div>
    </section>

    <section class="biz-grid" aria-label="Business system status">
      <article class="biz-card biz-kpi green"><small>Database</small><strong><?= $status['connected'] ? 'Online' : 'Pending' ?></strong><span><?= htmlspecialchars((string)$status['database'], ENT_QUOTES, 'UTF-8') ?></span></article>
      <article class="biz-card biz-kpi blue"><small>Operational Source</small><strong><?= number_format($operationalMapped) ?>/757</strong><span><?= $operationalPending ?> pending rows</span></article>
      <article class="biz-card biz-kpi gold"><small>Organizations / Clubs</small><strong><?= (int)$status['organization_count'] ?> / <?= (int)$status['club_count'] ?></strong><span>Multi-tenant structure</span></article>
      <article class="biz-card biz-kpi"><small>Derived Reports</small><strong><?= (int)$status['derived_report_count'] ?></strong><span>Workbook sheets 1-6 become live calculations</span></article>

      <article class="biz-card biz-section">
        <h2>How the workbook now works inside Business OS</h2>
        <p>The workbook is no longer treated as fourteen isolated sheets. Operational source data and calculated reporting are intentionally separated.</p>
        <div class="biz-module-grid">
          <div class="biz-module"><b>Source Data Layer</b><span>All 757 operational workbook rows are normalized from New UMS, VP, orders, activity, renewal, income and royalty sources.</span></div>
          <div class="biz-module"><b>Members & Sponsor Network</b><span>Member identity connects UMS, sponsor/upline, orders, VP, renewals and business history while uncertain identities remain traceable.</span></div>
          <div class="biz-module"><b>UMS Lifecycle</b><span>New UMS, active duration, monthly activity and renewals are generated from real records instead of copied formula output.</span></div>
          <div class="biz-module"><b>Orders, VP & Income</b><span>Operational transactions are reusable database facts for dashboards, filters, trends and exports.</span></div>
          <div class="biz-module"><b>Six Derived Workbook Reports</b><span>Master Tracking, SP House, Name Wise, Master Business, UMS Renewal and Active Duration are calculated live.</span></div>
          <div class="biz-module"><b>Global Tenant Safety</b><span>Every business record is organization-scoped so future clubs/countries can stay isolated.</span></div>
        </div>
      </article>

      <aside class="biz-card biz-side">
        <h2>Architecture status</h2>
        <p>The source foundation is complete. The current phase is the live calculation/report engine.</p>
        <div class="biz-steps">
          <div class="biz-step"><div class="biz-step-num">01</div><div><b>World-ready MySQL schema</b><span>Multi-tenant database foundation is active locally in XAMPP.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">02</div><div><b>Operational Excel seeding</b><span><?= $sourceSeeded ? 'All 757 source rows are mapped and reconciled.' : 'Source seeding/reconciliation still requires review.' ?></span></div></div>
          <div class="biz-step"><div class="biz-step-num">03</div><div><b>Formula engine audit</b><span>Translate workbook sheets 1-6 formulas into explicit, versioned calculation rules.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">04</div><div><b>Live Business Dashboard</b><span>KPIs, filters, trends, renewals, VP and income from one database.</span></div></div>
        </div>
        <div class="biz-tech"><span>Multi-Tenant</span><span>757 Source Rows</span><span>Currency Ready</span><span>Raw Trace</span><span>6 Derived Reports</span></div>
      </aside>
    </section>

    <div class="biz-note"><strong>Current status:</strong> <?= htmlspecialchars((string)$status['message'], ENT_QUOTES, 'UTF-8') ?> Operational raw source: <?= number_format($operationalTotal) ?> • Mapped: <?= number_format($operationalMapped) ?> • Pending: <?= number_format($operationalPending) ?> • Members: <?= (int)$status['member_count'] ?> • Derived reports: <?= (int)$status['derived_report_count'] ?>.</div>
  </main>
</body>
</html>
