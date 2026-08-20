<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function ria_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$organizationId = 0;
$legacySourceId = 0;
$manualSourceId = 0;
$legacy = ['total'=>0,'mapped'=>0,'pending'=>0];
$manualRaw = 0;
$manualFacts = [
    'New UMS Members' => 0,
    'UMS Records' => 0,
    'Volume Points' => 0,
    'Orders' => 0,
    'Renewals' => 0,
    'Income' => 0,
    'Royalty' => 0,
];
$runtimeChecks = [];
$reports = business_report_runtime_reports();
$pass = false;

try {
    $pdo = business_db();

    $orgStmt = $pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1");
    $organizationId = (int)$orgStmt->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $sourceStmt = $pdo->prepare("SELECT source_code, id FROM data_sources WHERE organization_id=? AND source_code IN ('LEGACY-XLSX','MANUAL')");
    $sourceStmt->execute([$organizationId]);
    foreach ($sourceStmt->fetchAll() as $row) {
        if ($row['source_code'] === 'LEGACY-XLSX') $legacySourceId = (int)$row['id'];
        if ($row['source_code'] === 'MANUAL') $manualSourceId = (int)$row['id'];
    }
    if ($legacySourceId <= 0 || $manualSourceId <= 0) {
        throw new RuntimeException('LEGACY-XLSX and MANUAL data sources must both be active.');
    }

    $legacyStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows,
                SUM(mapping_status='mapped') mapped_rows,
                SUM(mapping_status='pending') pending_rows
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $legacyStmt->execute([$organizationId, $legacySourceId]);
    $row = $legacyStmt->fetch() ?: [];
    $legacy = [
        'total'=>(int)($row['total_rows'] ?? 0),
        'mapped'=>(int)($row['mapped_rows'] ?? 0),
        'pending'=>(int)($row['pending_rows'] ?? 0),
    ];

    $manualRawStmt = $pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=?");
    $manualRawStmt->execute([$organizationId, $manualSourceId]);
    $manualRaw = (int)$manualRawStmt->fetchColumn();

    $factQueries = [
        'New UMS Members' => "SELECT COUNT(*) FROM members WHERE organization_id=? AND source_sheet='Manual Entry'",
        'UMS Records' => "SELECT COUNT(*) FROM ums_records WHERE organization_id=? AND source_sheet='Manual Entry'",
        'Volume Points' => "SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'Orders' => "SELECT COUNT(*) FROM orders WHERE organization_id=? AND source_sheet='Manual Entry'",
        'Renewals' => "SELECT COUNT(*) FROM renewals WHERE organization_id=? AND source_sheet='Manual Entry'",
        'Income' => "SELECT COUNT(*) FROM income_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'Royalty' => "SELECT COUNT(*) FROM royalty_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
    ];
    foreach ($factQueries as $label => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$organizationId]);
        $manualFacts[$label] = (int)$stmt->fetchColumn();
    }

    $testSql = [
        'master_tracking.php' => "SELECT raw_json FROM raw_source_records WHERE organization_id=? AND source_dataset='New UMS' AND mapping_status='mapped' ORDER BY source_row",
        'sp_house.php' => "SELECT v.id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label, v.volume_points, v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set, r.raw_json FROM volume_point_entries v LEFT JOIN raw_source_records r ON r.id=v.source_record_id WHERE v.organization_id=? AND v.source_sheet='Volume Points' ORDER BY v.entry_date, v.id",
        'name_wise_tracking.php' => "SELECT v.id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label, v.volume_points, v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set, r.raw_json FROM volume_point_entries v LEFT JOIN raw_source_records r ON r.id=v.source_record_id WHERE v.organization_id=? AND v.source_sheet='Volume Points' ORDER BY v.entry_date, v.id",
        'master_business_tracking.php' => "SELECT source_row, raw_json FROM raw_source_records WHERE organization_id=? AND source_dataset='New UMS' AND mapping_status='mapped' ORDER BY source_row",
        'ums_renewal.php' => "SELECT r.id raw_id, r.source_row, r.mapped_entity_id ums_id, r.raw_json, u.member_id, m.full_name FROM raw_source_records r LEFT JOIN ums_records u ON u.id=r.mapped_entity_id AND u.source_record_id=r.id LEFT JOIN members m ON m.id=u.member_id WHERE r.organization_id=? AND r.source_dataset='New UMS' AND r.mapping_status='mapped' ORDER BY r.source_row",
        'ums_active_duration.php' => "SELECT u.id ums_id, u.member_id, u.start_date, u.status normalized_status, u.notes ums_notes, m.full_name member_full_name, r.source_row, r.raw_json FROM ums_records u INNER JOIN raw_source_records r ON r.id=u.source_record_id LEFT JOIN members m ON m.id=u.member_id WHERE u.organization_id=? AND u.source_sheet='New UMS' ORDER BY u.start_date, u.id",
    ];

    $originalScript = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    foreach ($reports as $report) {
        $_SERVER['SCRIPT_NAME'] = '/Healthcare-Wellness-Club/business/' . $report;
        $before = $testSql[$report] ?? 'SELECT ?';
        $after = business_report_rewrite_sql($before);
        $placeholderStable = substr_count($before, '?') === substr_count($after, '?');
        $runtimeChecks[$report] = [
            'changed' => $after !== $before,
            'placeholder_stable' => $placeholderStable,
        ];
    }
    $_SERVER['SCRIPT_NAME'] = $originalScript;

    $runtimePass = count($runtimeChecks) === 6;
    foreach ($runtimeChecks as $check) {
        $runtimePass = $runtimePass && $check['changed'] && $check['placeholder_stable'];
    }

    $pass = $legacy['total'] === 757
        && $legacy['mapped'] === 757
        && $legacy['pending'] === 0
        && count($reports) === 6
        && $runtimePass;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$manualFactTotal = array_sum($manualFacts);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Report Integration Audit - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <style>
    .ria-wide{grid-column:span 12}.ria-half{grid-column:span 6}.ria-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.76rem}.ria-table th,.ria-table td{padding:10px;border-bottom:1px solid #e6ede9;text-align:left}.ria-table th{font-size:.64rem;text-transform:uppercase;color:#75837c}.ria-pass{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eaf7ef;color:#1d7147;font-size:.65rem;font-weight:850}.ria-warn{display:inline-flex;padding:5px 8px;border-radius:999px;background:#fff5dd;color:#805d17;font-size:.65rem;font-weight:850}.ria-note{margin-top:12px;padding:12px 13px;border:1px solid #dfe9e3;border-radius:12px;background:#f9fbfa;color:#5f7067;font-size:.74rem;line-height:1.55}@media(max-width:900px){.ria-half{grid-column:span 12}}
  </style>
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Report Integration Audit</small></span></a>
    <div class="os-top-actions"><a class="os-btn" href="data_entry_center.php">Data Entry</a><a class="os-btn" href="report_center.php">Report Center</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
  </div>
</header>
<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a><a href="report_center.php"><i class="dot"></i>Report Center</a><a class="active" href="report_integration_audit.php"><i class="dot"></i>Integration Audit</a></nav>
    <div class="os-sidebar-status"><b><?= $pass ? 'Report runtime ready' : 'Review required' ?></b><span>Legacy Excel remains isolated while MANUAL normalized facts are projected into the six live reports.</span></div>
  </aside>
  <main class="os-main">
    <section class="os-hero">
      <div class="os-kicker">Step 10G • Safe Report Integration</div>
      <h1>Manual Business OS entries can now flow into all six live reports without changing the 757-row Excel baseline.</h1>
      <p>The runtime adapter reads normalized MANUAL facts alongside legacy facts. It does not clone manual rows into the Excel source, so reconciliation remains a permanent legacy health check instead of a growing record counter.</p>
      <div class="os-status-row"><span class="os-chip <?= $pass ? 'good' : '' ?>"><?= $pass ? 'REPORT INTEGRATION PASS' : 'Review required' ?></span><span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span><span class="os-chip"><?= number_format($manualRaw) ?> manual raw entries</span><span class="os-chip">Runtime <?= ria_h(business_report_runtime_version()) ?></span></div>
    </section>

    <?php if ($error !== null): ?><div class="os-footer-note" style="background:#fff3f3;border-color:#efc7c7;color:#8b2c2c"><strong>Audit diagnostic:</strong> <?= ria_h($error) ?></div><?php endif; ?>

    <section class="os-grid">
      <article class="os-card os-kpi green"><small>Legacy Excel</small><strong><?= number_format($legacy['mapped']) ?>/757</strong><span><?= number_format($legacy['pending']) ?> pending • unchanged baseline</span></article>
      <article class="os-card os-kpi blue"><small>Manual Raw</small><strong><?= number_format($manualRaw) ?></strong><span>Trace-first Business OS entries</span></article>
      <article class="os-card os-kpi gold"><small>Manual Facts</small><strong><?= number_format($manualFactTotal) ?></strong><span>Normalized facts currently available</span></article>
      <article class="os-card os-kpi violet"><small>Live Reports</small><strong><?= count($reports) ?>/6</strong><span>Runtime adapter allowlist</span></article>

      <article class="os-card ria-half"><div class="os-title-row"><div><h2>Manual Fact Coverage</h2><p>Zero is valid until real daily data is entered.</p></div></div><table class="ria-table"><thead><tr><th>Fact Type</th><th>Manual Rows</th></tr></thead><tbody><?php foreach ($manualFacts as $label=>$count): ?><tr><td><?= ria_h($label) ?></td><td><strong><?= number_format($count) ?></strong></td></tr><?php endforeach; ?></tbody></table></article>

      <article class="os-card ria-half"><div class="os-title-row"><div><h2>Six-Report Runtime Check</h2><p>SQL is rewritten only for report reads; placeholder count must stay unchanged.</p></div></div><table class="ria-table"><thead><tr><th>Report</th><th>Adapter</th><th>Parameters</th></tr></thead><tbody><?php foreach ($runtimeChecks as $report=>$check): ?><tr><td><?= ria_h(str_replace(['_','.php'],[' ',''],$report)) ?></td><td><span class="<?= $check['changed'] ? 'ria-pass' : 'ria-warn' ?>"><?= $check['changed'] ? 'ACTIVE' : 'CHECK' ?></span></td><td><span class="<?= $check['placeholder_stable'] ? 'ria-pass' : 'ria-warn' ?>"><?= $check['placeholder_stable'] ? 'STABLE' : 'CHECK' ?></span></td></tr><?php endforeach; ?></tbody></table></article>

      <article class="os-card ria-wide"><div class="os-title-row"><div><h2>Integration Policy</h2><p>One source of truth, two provenance channels.</p></div></div><div class="os-list"><div class="os-list-row"><div><b>Legacy Excel health</b><span>Always checked only against LEGACY-XLSX operational rows.</span></div><strong>757 / 757</strong></div><div class="os-list-row"><div><b>Manual VP read model</b><span>Explicit VP plus VP carried by manual Orders/Renewals is available to VP-driven reports.</span></div><strong>LIVE</strong></div><div class="os-list-row"><div><b>Manual New UMS</b><span>UMS start date, Team, Sponsor and status are projected into New UMS/Active Duration report dimensions.</span></div><strong>LIVE</strong></div><div class="os-list-row"><div><b>Manual Income & Royalty</b><span>Organization-level facts join monthly report calculations without pretending to be Excel rows.</span></div><strong>LIVE</strong></div></div><div class="ria-note"><strong>Week rule for manual dated facts:</strong> when an explicit Week label is unavailable, days 1–7 map to Week-1, 8–14 to Week-2, 15–21 to Week-3 and day 22 onward to Week-4. This rule is runtime-only and does not rewrite stored data.</div></article>

      <article class="os-card ria-wide"><div class="os-title-row"><div><h2>Open Live Reports</h2><p>Use these links after PASS; no test/fake business entry is required.</p></div></div><div class="os-report-grid"><a class="os-report" href="master_tracking.php"><div class="os-report-head"><b>Master Tracking</b><em>LIVE</em></div><span>Legacy + manual VP/New UMS/Income/Royalty runtime.</span><small>Open →</small></a><a class="os-report" href="sp_house.php"><div class="os-report-head"><b>SP House</b><em>LIVE</em></div><span>Manual VP/order/renewal VP included.</span><small>Open →</small></a><a class="os-report" href="name_wise_tracking.php"><div class="os-report-head"><b>Name Wise Tracking</b><em>LIVE</em></div><span>Manual member-linked VP dimensions included.</span><small>Open →</small></a><a class="os-report" href="master_business_tracking.php"><div class="os-report-head"><b>Master Business</b><em>LIVE</em></div><span>Manual VP, New UMS, active UMS and royalty included.</span><small>Open →</small></a><a class="os-report" href="ums_renewal.php"><div class="os-report-head"><b>UMS Renewal</b><em>LIVE</em></div><span>Manual renewals and New UMS candidates included.</span><small>Open →</small></a><a class="os-report" href="ums_active_duration.php"><div class="os-report-head"><b>Active Duration</b><em>LIVE</em></div><span>Manual active UMS lifecycle included.</span><small>Open →</small></a></div></article>
    </section>
  </main>
</div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
