<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
$mapping = require __DIR__ . '/config/derived_report_mapping.php';

function dra_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$summary = [
    'raw_total' => 0,
    'raw_mapped' => 0,
    'raw_pending' => 0,
    'report_count' => count($mapping['reports'] ?? []),
    'formula_cells' => (int)($mapping['formula_cell_count'] ?? 0),
    'db_report_definitions' => 0,
];
$sourceStates = [];
$checks = [];

$expected = [
    'New UMS' => 78,
    'Volume Points' => 282,
    'First & Second Set' => 94,
    'Active UMS Month_Wise' => 25,
    'Renewal UMS' => 141,
    'Monthely_Income' => 26,
    'Royalty_Tracking' => 97,
    'Extra Order for Customer' => 14,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
    $sourceStmt->execute([$organizationId]);
    $sourceId = (int)$sourceStmt->fetchColumn();
    if ($sourceId <= 0) {
        throw new RuntimeException('LEGACY-XLSX data source was not found.');
    }

    $batchStmt = $pdo->prepare(
        "SELECT id, original_file_name, status, imported_rows, failed_rows, completed_at
         FROM import_batches
         WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture' AND status='completed'
         ORDER BY id DESC LIMIT 1"
    );
    $batchStmt->execute([$organizationId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No completed raw Excel capture batch was found.');
    }
    $batchId = (int)$batch['id'];

    foreach ($expected as $dataset => $expectedRows) {
        $stateStmt = $pdo->prepare(
            "SELECT COUNT(*) total_rows,
                    SUM(mapping_status='mapped') mapped_rows,
                    SUM(mapping_status='pending') pending_rows
             FROM raw_source_records
             WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset=?"
        );
        $stateStmt->execute([$organizationId, $sourceId, $batchId, $dataset]);
        $state = $stateStmt->fetch() ?: [];
        $total = (int)($state['total_rows'] ?? 0);
        $mapped = (int)($state['mapped_rows'] ?? 0);
        $pending = (int)($state['pending_rows'] ?? 0);
        $sourceStates[$dataset] = [
            'expected' => $expectedRows,
            'total' => $total,
            'mapped' => $mapped,
            'pending' => $pending,
            'pass' => $total === $expectedRows && $mapped === $expectedRows && $pending === 0,
        ];
        $summary['raw_total'] += $total;
        $summary['raw_mapped'] += $mapped;
        $summary['raw_pending'] += $pending;
    }

    if (business_table_exists($pdo, 'report_definitions')) {
        $reportStmt = $pdo->prepare('SELECT COUNT(*) FROM report_definitions WHERE organization_id=?');
        $reportStmt->execute([$organizationId]);
        $summary['db_report_definitions'] = (int)$reportStmt->fetchColumn();
    }

    $checks = [
        'All 757 operational source rows are present' => $summary['raw_total'] === 757,
        'All 757 operational source rows are mapped' => $summary['raw_mapped'] === 757,
        'No operational source rows remain pending' => $summary['raw_pending'] === 0,
        'Exactly six derived workbook reports are mapped' => $summary['report_count'] === 6,
        'All 280 formula cells from Sheets 1-6 are covered by the reviewed formula inventory' => $summary['formula_cells'] === 280,
        'Database contains six derived report definitions' => $summary['db_report_definitions'] === 6,
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$allSourcePass = $sourceStates && !in_array(false, array_column($sourceStates, 'pass'), true);
$allPass = $error === null && $allSourcePass && $checks && !in_array(false, $checks, true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Derived Report Formula Audit - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .dra-wide{grid-column:span 12}.dra-main{grid-column:span 8}.dra-side{grid-column:span 4}
    .dra-report{padding:15px;border:1px solid #e1ebe5;border-radius:14px;background:#fff;margin-top:12px}
    .dra-report-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.dra-report h3{margin:0 0 4px}.dra-report p{margin:5px 0;color:#5d6b64;line-height:1.55}
    .dra-chip{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#236441;font-size:.68rem;font-weight:800;white-space:nowrap}.dra-chip.warn{background:#fff5db;color:#825d12}
    .dra-list{margin:9px 0 0;padding-left:18px;color:#4f6057;line-height:1.55;font-size:.78rem}.dra-source{display:grid;grid-template-columns:1.5fr .6fr .6fr .6fr .5fr;gap:8px;padding:9px 0;border-bottom:1px solid #e9efeb;font-size:.75rem}.dra-source b{font-size:.76rem}.dra-ok{color:#26704a;font-weight:800}.dra-bad{color:#a24a31;font-weight:800}
    @media(max-width:900px){.dra-main,.dra-side{grid-column:span 12}.dra-source{grid-template-columns:1fr 1fr}.dra-source span:first-child{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Derived Report Engine Audit</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="final_excel_seeding.php">Excel Seeding</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9A • Formula engine audit</div>
      <h1>Sheets 1–6 are now mapped as live calculations, not duplicate source tables.</h1>
      <p>The original workbook formula logic has been inventoried and translated into six report specifications. This page is read-only: it verifies that the source layer is complete and exposes the legacy rules that must become versioned calculation rules before the live reports are activated.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">Sheets 1–6 derived only</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'FORMULA MAP READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert dra-wide"><strong>Audit could not run:</strong> <?= dra_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="Formula audit summary">
        <article class="imp-kpi green"><small>Operational Source</small><strong><?= number_format($summary['raw_mapped']) ?>/757</strong><span>Mapped raw rows</span></article>
        <article class="imp-kpi blue"><small>Derived Reports</small><strong><?= number_format($summary['report_count']) ?></strong><span>Workbook Sheets 1–6</span></article>
        <article class="imp-kpi gold"><small>Formula Inventory</small><strong><?= number_format($summary['formula_cells']) ?></strong><span>Formula cells reviewed</span></article>
        <article class="imp-kpi"><small>Pending Source</small><strong><?= number_format($summary['raw_pending']) ?></strong><span>Must stay zero</span></article>
      </section>

      <article class="imp-card dra-main">
        <h2>Six derived report specifications</h2>
        <p>These are the exact workbook responsibilities we will reproduce from normalized database facts.</p>
        <?php foreach (($mapping['reports'] ?? []) as $report): ?>
          <?php $warn = str_contains((string)$report['status'], 'legacy') || str_contains((string)$report['status'], 'external'); ?>
          <section class="dra-report">
            <div class="dra-report-head">
              <div>
                <h3><?= dra_h($report['sheet']) ?></h3>
                <p><?= number_format((int)$report['formula_cells']) ?> formula cells • Inputs: <?= dra_h(implode(', ', $report['inputs'])) ?></p>
              </div>
              <span class="dra-chip <?= $warn ? 'warn' : '' ?>"><?= $warn ? 'RULE REVIEWED' : 'ENGINE READY' ?></span>
            </div>
            <p><strong>Normalized sources:</strong> <?= dra_h(implode(', ', $report['sources'])) ?></p>
            <ul class="dra-list">
              <?php foreach ($report['logic'] as $line): ?><li><?= dra_h($line) ?></li><?php endforeach; ?>
            </ul>
            <?php if (!empty($report['legacy_rules'])): ?>
              <p><strong>Legacy controls:</strong></p>
              <ul class="dra-list">
                <?php foreach ($report['legacy_rules'] as $line): ?><li><?= dra_h($line) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </article>

      <aside class="imp-card dra-side">
        <h2>Source completeness</h2>
        <p>Every operational sheet must remain fully mapped before report calculations can be trusted.</p>
        <?php foreach ($sourceStates as $dataset => $state): ?>
          <div class="dra-source">
            <span><b><?= dra_h($dataset) ?></b></span>
            <span>Raw <?= number_format($state['total']) ?></span>
            <span>Mapped <?= number_format($state['mapped']) ?></span>
            <span>Pending <?= number_format($state['pending']) ?></span>
            <span class="<?= $state['pass'] ? 'dra-ok' : 'dra-bad' ?>"><?= $state['pass'] ? 'PASS' : 'CHECK' ?></span>
          </div>
        <?php endforeach; ?>

        <h2 style="margin-top:24px">Architecture checks</h2>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row"><div><b><?= dra_h($label) ?></b><span><?= $pass ? 'Verified' : 'Must be resolved' ?></span></div><em><?= $pass ? 'PASS' : 'CHECK' ?></em></div>
          <?php endforeach; ?>
        </div>

        <div class="imp-alert" style="margin-top:16px;background:#fff9e9;border-color:#ecd9a8;color:#725313">
          <strong>Important formula rules:</strong><br>
          Hard-coded owner names will become parameters. GOOGLEFINANCE currency conversion will become a configurable rate rule. SP_House and Name_Wise legacy VP constants will be versioned instead of hidden in SQL/PHP.
        </div>
      </aside>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
