<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function rc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$stats = [
    'source_total' => 0,
    'source_mapped' => 0,
    'members' => 0,
    'vp' => 0,
    'orders' => 0,
    'renewals' => 0,
    'income' => 0,
    'royalty' => 0,
    'active_snapshots' => 0,
];

$reports = [
    ['num'=>'01','name'=>'Master Tracking','file'=>'master_tracking.php','formula'=>'130 formula cells','desc'=>'Weekly and monthly VP, personal/business buckets, order-type views and supporting income/royalty context.'],
    ['num'=>'02','name'=>'SP House','file'=>'sp_house.php','formula'=>'33 formula cells','desc'=>'Selected member SP view with personal/family, New UMS, Renewal UMS and first-line VP analysis.'],
    ['num'=>'03','name'=>'Name Wise Tracking','file'=>'name_wise_tracking.php','formula'=>'26 formula cells','desc'=>'Name-wise first-line PC, Associate, New UMS, personal consumption and total VP breakdown.'],
    ['num'=>'04','name'=>'Master Business Tracking','file'=>'master_business_tracking.php','formula'=>'37 formula cells','desc'=>'PPV, DVP, Total VP, royalty, active/new UMS, team counts and average customer VP.'],
    ['num'=>'05','name'=>'UMS Renewal','file'=>'ums_renewal.php','formula'=>'14 formula cells','desc'=>'Renewed, pending and identity-review renewal lists with Year, Month, Team and Supervisor filters.'],
    ['num'=>'06','name'=>'UMS Active Duration','file'=>'ums_active_duration.php','formula'=>'37 formula cells','desc'=>'Live UMS duration calculated on demand with Team, Sponsor, UMS Date, status and as-of-date filter.'],
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $stateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows, SUM(mapping_status='mapped') mapped_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $stateStmt->execute([$organizationId]);
    $state = $stateStmt->fetch() ?: [];
    $stats['source_total'] = (int)($state['total_rows'] ?? 0);
    $stats['source_mapped'] = (int)($state['mapped_rows'] ?? 0);

    $countQueries = [
        'members' => "SELECT COUNT(*) FROM members WHERE organization_id=?",
        'vp' => "SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=? AND source_sheet='Volume Points'",
        'orders' => "SELECT COUNT(*) FROM orders WHERE organization_id=?",
        'renewals' => "SELECT COUNT(*) FROM renewals WHERE organization_id=? AND source_sheet='Renewal UMS'",
        'income' => "SELECT COUNT(*) FROM income_entries WHERE organization_id=? AND source_sheet='Monthely_Income'",
        'royalty' => "SELECT COUNT(*) FROM royalty_entries WHERE organization_id=? AND source_sheet='Royalty_Tracking'",
        'active_snapshots' => "SELECT COUNT(*) FROM ums_activity_snapshots WHERE organization_id=? AND source_sheet='Active UMS Month_Wise'",
    ];

    foreach ($countQueries as $key => $sql) {
        if (business_table_exists($pdo, match ($key) {
            'members' => 'members',
            'vp' => 'volume_point_entries',
            'orders' => 'orders',
            'renewals' => 'renewals',
            'income' => 'income_entries',
            'royalty' => 'royalty_entries',
            'active_snapshots' => 'ums_activity_snapshots',
        })) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$organizationId]);
            $stats[$key] = (int)$stmt->fetchColumn();
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && $stats['source_total'] === 757 && $stats['source_mapped'] === 757;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Report Center - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Report Center</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="derived_reports_audit.php">Formula Audit</a>
      <a class="os-btn primary" href="index.php">Dashboard</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav">
      <a href="index.php"><i class="dot"></i>Dashboard</a>
      <a class="active" href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
      <a href="derived_reports_audit.php"><i class="dot"></i>Formula Audit</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Live Reports</div>
    <nav class="os-nav">
      <?php foreach ($reports as $report): ?>
        <a href="<?= rc_h($report['file']) ?>"><i class="dot"></i><?= rc_h($report['name']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Live report engine ready' : 'Report engine review required' ?></b>
      <span><?= number_format($stats['source_mapped']) ?> / <?= number_format($stats['source_total']) ?> operational source rows mapped.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero">
      <div class="os-kicker">Step 10A • Integrated Report Center</div>
      <h1>All six workbook reports, now live from one Business OS.</h1>
      <p>Sheets 1–6 are no longer copied outputs. Each report calculates from normalized operational facts while preserving the workbook’s business dimensions and keeping uncertain legacy rules explicit.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? '6 LIVE REPORTS READY' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($stats['source_mapped']) ?> / 757 source mapped</span>
        <span class="os-chip">280 formula cells inventoried</span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="os-footer-note" style="border-color:#efc7c7;background:#fff3f3;color:#8b2c2c"><strong>Report Center could not load:</strong> <?= rc_h($error) ?></div>
    <?php else: ?>
      <section class="os-grid">
        <article class="os-card os-kpi green"><small>Members</small><strong><?= number_format($stats['members']) ?></strong><span>Normalized member records</span></article>
        <article class="os-card os-kpi blue"><small>VP Facts</small><strong><?= number_format($stats['vp']) ?></strong><span>Volume Point entries</span></article>
        <article class="os-card os-kpi gold"><small>Orders</small><strong><?= number_format($stats['orders']) ?></strong><span>Normalized order facts</span></article>
        <article class="os-card os-kpi violet"><small>Renewals</small><strong><?= number_format($stats['renewals']) ?></strong><span>Renewal UMS facts</span></article>

        <article class="os-card" style="grid-column:span 12">
          <div class="os-title-row">
            <div><h2>Live Derived Reports</h2><p>Open any report directly. Every page remains read-only and database-powered.</p></div>
            <a class="os-btn" href="derived_reports_audit.php">View Formula Audit</a>
          </div>
          <div class="rc-report-grid">
            <?php foreach ($reports as $report): ?>
              <article class="rc-card">
                <span class="num"><?= rc_h($report['num']) ?></span>
                <h3><?= rc_h($report['name']) ?></h3>
                <p><?= rc_h($report['desc']) ?></p>
                <div class="meta"><?= rc_h($report['formula']) ?> • Live database calculation</div>
                <a href="<?= rc_h($report['file']) ?>">Open Live Report →</a>
              </article>
            <?php endforeach; ?>
          </div>
        </article>

        <article class="os-card os-section">
          <div class="os-title-row"><div><h2>Operational fact layer</h2><p>The live reports are backed by the normalized source facts below.</p></div></div>
          <div class="os-list">
            <div class="os-list-row"><div><b>Volume Points</b><span>Dedicated VP fact table</span></div><strong><?= number_format($stats['vp']) ?></strong></div>
            <div class="os-list-row"><div><b>Orders</b><span>First/Second Set + Extra Customer Orders</span></div><strong><?= number_format($stats['orders']) ?></strong></div>
            <div class="os-list-row"><div><b>Renewal UMS</b><span>Preservation-first renewal facts</span></div><strong><?= number_format($stats['renewals']) ?></strong></div>
            <div class="os-list-row"><div><b>Monthly Income</b><span>Retail + Check + Club components</span></div><strong><?= number_format($stats['income']) ?></strong></div>
            <div class="os-list-row"><div><b>Royalty Tracking</b><span>Period-based royalty facts</span></div><strong><?= number_format($stats['royalty']) ?></strong></div>
            <div class="os-list-row"><div><b>Active UMS Snapshots</b><span>Monthly active UMS facts</span></div><strong><?= number_format($stats['active_snapshots']) ?></strong></div>
          </div>
        </article>

        <aside class="os-card os-side">
          <h2>Engine status</h2>
          <p>One normalized source layer powers all six reports.</p>
          <div class="os-list">
            <div class="os-list-row"><div><b>Operational Source</b><span>Raw → normalized trace</span></div><strong><?= number_format($stats['source_mapped']) ?>/757</strong></div>
            <div class="os-list-row"><div><b>Derived Reports</b><span>Workbook Sheets 1–6</span></div><strong>6 / 6</strong></div>
            <div class="os-list-row"><div><b>Formula Inventory</b><span>Reviewed workbook cells</span></div><strong>280</strong></div>
          </div>
          <div class="os-progress"><i style="width:<?= $ready ? '100' : '0' ?>%"></i></div>
          <div class="os-footer-note"><strong>Safety:</strong> hard-coded owner names, external exchange-rate calls and legacy VP-adjustment constants are not silently guessed. They remain explicit/versioned rules.</div>
        </aside>

        <div class="rc-note"><strong>Report architecture complete:</strong> the Excel workbook remains the historical source/reference, while Business OS now uses normalized operational facts plus live derived calculations. Future website forms, Google Forms and APIs can feed the same fact layer without rebuilding these reports.</div>
      </section>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
