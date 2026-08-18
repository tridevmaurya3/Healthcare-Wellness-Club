<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

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
$expectedTotal = array_sum($expected);

function rec_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$batch = null;
$rowsBySheet = [];
$totalRows = 0;
$distinctExternalIds = 0;
$nullExternalIds = 0;
$duplicateHashGroups = 0;
$pendingRows = 0;
$unexpectedSheets = [];
$checks = [];

try {
    $pdo = business_db();

    $orgId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($orgId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
    $sourceStmt->execute([$orgId]);
    $sourceId = (int)$sourceStmt->fetchColumn();
    if ($sourceId <= 0) {
        throw new RuntimeException('LEGACY-XLSX data source was not found.');
    }

    $batchStmt = $pdo->prepare(
        "SELECT id, original_file_name, file_sha256, status, total_rows, imported_rows, skipped_rows,
                failed_rows, started_at, completed_at, notes
         FROM import_batches
         WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture'
         ORDER BY id DESC LIMIT 1"
    );
    $batchStmt->execute([$orgId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No raw Excel capture batch was found. Run Step 8G first.');
    }

    $batchId = (int)$batch['id'];

    $countStmt = $pdo->prepare(
        "SELECT source_dataset, COUNT(*) AS row_count
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=?
         GROUP BY source_dataset
         ORDER BY source_dataset"
    );
    $countStmt->execute([$orgId, $sourceId, $batchId]);
    foreach ($countStmt->fetchAll() as $row) {
        $rowsBySheet[(string)$row['source_dataset']] = (int)$row['row_count'];
    }
    $totalRows = array_sum($rowsBySheet);

    $metricsStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT external_record_id) AS distinct_external_ids,
            SUM(CASE WHEN external_record_id IS NULL OR external_record_id='' THEN 1 ELSE 0 END) AS null_external_ids,
            SUM(CASE WHEN mapping_status='pending' THEN 1 ELSE 0 END) AS pending_rows
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=?"
    );
    $metricsStmt->execute([$orgId, $sourceId, $batchId]);
    $metrics = $metricsStmt->fetch() ?: [];
    $distinctExternalIds = (int)($metrics['distinct_external_ids'] ?? 0);
    $nullExternalIds = (int)($metrics['null_external_ids'] ?? 0);
    $pendingRows = (int)($metrics['pending_rows'] ?? 0);

    $hashStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_dataset, record_hash
            FROM raw_source_records
            WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND record_hash IS NOT NULL
            GROUP BY source_dataset, record_hash
            HAVING COUNT(*) > 1
         ) AS duplicate_hashes"
    );
    $hashStmt->execute([$orgId, $sourceId, $batchId]);
    $duplicateHashGroups = (int)$hashStmt->fetchColumn();

    $unexpectedSheets = array_values(array_diff(array_keys($rowsBySheet), array_keys($expected)));
    $missingSheets = array_values(array_diff(array_keys($expected), array_keys($rowsBySheet)));

    $sheetMatches = true;
    foreach ($expected as $sheet => $expectedCount) {
        if (($rowsBySheet[$sheet] ?? 0) !== $expectedCount) {
            $sheetMatches = false;
            break;
        }
    }

    $checks = [
        'Batch completed' => (string)$batch['status'] === 'completed',
        '8 expected source sheets present' => count($rowsBySheet) === 8 && !$missingSheets && !$unexpectedSheets,
        'Sheet-wise row counts match reviewed workbook' => $sheetMatches,
        'Total raw rows = 757' => $totalRows === $expectedTotal,
        'Batch imported_rows = 757' => (int)$batch['imported_rows'] === $expectedTotal,
        'No failed raw rows' => (int)$batch['failed_rows'] === 0,
        'Every raw row has an external row key' => $nullExternalIds === 0,
        'External row keys are unique in this batch' => $distinctExternalIds === $totalRows,
        'All raw rows remain pending normalization' => $pendingRows === $totalRows,
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$allPass = $error === null && $checks && !in_array(false, $checks, true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Raw Data Reconciliation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Raw Data Integrity</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="raw_import.php">← Raw Import</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8H • Read-only reconciliation</div>
      <h1>Verify every captured source row before normalization begins.</h1>
      <p>This page does not write or modify business data. It compares the latest raw import batch against the reviewed eight-sheet workbook counts and validates row keys, batch totals and normalization state.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">Expected total: <?= number_format($expectedTotal) ?></span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Integrity PASS' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Reconciliation could not run:</strong> <?= rec_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="Raw import reconciliation summary">
        <article class="imp-kpi green"><small>Integrity</small><strong><?= $allPass ? 'PASS' : 'REVIEW' ?></strong><span>Latest raw capture batch #<?= (int)$batch['id'] ?></span></article>
        <article class="imp-kpi blue"><small>Captured Rows</small><strong><?= number_format($totalRows) ?></strong><span>Expected <?= number_format($expectedTotal) ?></span></article>
        <article class="imp-kpi gold"><small>Source Sheets</small><strong><?= count($rowsBySheet) ?> / 8</strong><span>Operational datasets</span></article>
        <article class="imp-kpi"><small>Pending Normalization</small><strong><?= number_format($pendingRows) ?></strong><span>No normalized writes yet</span></article>
      </section>

      <article class="imp-card" style="grid-column:span 7">
        <h2>Sheet-by-sheet reconciliation</h2>
        <p>Each database count must exactly match the reviewed workbook source count.</p>
        <div class="imp-plan-list">
          <?php foreach ($expected as $sheet => $expectedCount):
              $actual = (int)($rowsBySheet[$sheet] ?? 0);
              $pass = $actual === $expectedCount;
          ?>
            <div class="imp-plan-row">
              <div><b><?= rec_h($sheet) ?></b><span>Database <?= number_format($actual) ?> • Expected <?= number_format($expectedCount) ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card" style="grid-column:span 5">
        <h2>Integrity checks</h2>
        <p>These checks protect the next normalization phase from incomplete or duplicated raw capture.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row">
              <div><b><?= rec_h($label) ?></b><span><?= $pass ? 'Verified' : 'Needs review before normalization' ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </aside>

      <article class="imp-card imp-derived">
        <h2>Latest raw capture batch</h2>
        <div class="imp-derived-list">
          <div class="imp-derived-item"><b>Batch ID</b><span>#<?= (int)$batch['id'] ?></span></div>
          <div class="imp-derived-item"><b>Workbook</b><span><?= rec_h((string)$batch['original_file_name']) ?></span></div>
          <div class="imp-derived-item"><b>Status</b><span><?= rec_h((string)$batch['status']) ?></span></div>
          <div class="imp-derived-item"><b>Imported / Failed</b><span><?= number_format((int)$batch['imported_rows']) ?> / <?= number_format((int)$batch['failed_rows']) ?></span></div>
          <div class="imp-derived-item"><b>External row keys</b><span><?= number_format($distinctExternalIds) ?> distinct • <?= number_format($nullExternalIds) ?> missing</span></div>
          <div class="imp-derived-item"><b>Identical-content groups</b><span><?= number_format($duplicateHashGroups) ?> group(s) • informational only</span></div>
        </div>
      </article>

      <div class="imp-footer-note">
        <strong>Normalization gate:</strong> proceed only when this page shows <strong>Integrity PASS</strong>. Identical-content hash groups are informational because two legitimate source rows can contain the same values; the authoritative duplicate protection is the workbook fingerprint plus unique source sheet/row key.
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
