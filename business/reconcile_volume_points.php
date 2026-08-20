<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const VP_RECON_EXPECTED_ROWS = 282;

function vpr_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vpr_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A Volume Points raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function vpr_decimal(?string $value): float
{
    $raw = trim((string)$value);
    $clean = str_replace([',', ' '], '', $raw);
    if ($clean === '' || !is_numeric($clean)) {
        throw new RuntimeException('A raw Volume Point value is not numeric during reconciliation.');
    }
    return (float)$clean;
}

$error = null;
$batch = null;
$checks = [];
$metrics = [
    'raw_rows' => 0,
    'mapped_rows' => 0,
    'pending_rows' => 0,
    'vp_rows' => 0,
    'exact_trace_rows' => 0,
    'linked_members' => 0,
    'link_later' => 0,
    'orphan_member_links' => 0,
    'duplicate_source_key_groups' => 0,
    'duplicate_source_record_groups' => 0,
    'raw_total_vp' => 0.0,
    'normalized_total_vp' => 0.0,
];

try {
    $pdo = business_db();

    if (!business_table_exists($pdo, 'volume_point_entries')) {
        throw new RuntimeException('volume_point_entries table is missing.');
    }

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
        "SELECT id, original_file_name, status, completed_at
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

    $rawStmt = $pdo->prepare(
        "SELECT id, mapping_status, mapped_entity_type, mapped_entity_id, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='Volume Points'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, $batchId]);
    $rawRows = $rawStmt->fetchAll();
    $metrics['raw_rows'] = count($rawRows);

    foreach ($rawRows as $rawRow) {
        if ((string)$rawRow['mapping_status'] === 'mapped') {
            $metrics['mapped_rows']++;
        } elseif ((string)$rawRow['mapping_status'] === 'pending') {
            $metrics['pending_rows']++;
        }
        $values = vpr_decode_values((string)$rawRow['raw_json']);
        $metrics['raw_total_vp'] += vpr_decimal($values['H'] ?? null);
    }

    $vpStmt = $pdo->prepare(
        "SELECT v.id, v.member_id, v.volume_points, v.source_record_id, v.source_key
         FROM volume_point_entries v
         INNER JOIN raw_source_records r ON r.id=v.source_record_id
         WHERE v.organization_id=?
           AND v.source_sheet='Volume Points'
           AND r.organization_id=?
           AND r.data_source_id=?
           AND r.import_batch_id=?
           AND r.source_dataset='Volume Points'"
    );
    $vpStmt->execute([$organizationId, $organizationId, $sourceId, $batchId]);
    $vpRows = $vpStmt->fetchAll();
    $metrics['vp_rows'] = count($vpRows);
    foreach ($vpRows as $vpRow) {
        $metrics['normalized_total_vp'] += (float)$vpRow['volume_points'];
        if ($vpRow['member_id'] === null) {
            $metrics['link_later']++;
        } else {
            $metrics['linked_members']++;
        }
    }

    $traceStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM raw_source_records r
         INNER JOIN volume_point_entries v
           ON v.id=r.mapped_entity_id
          AND v.source_record_id=r.id
          AND v.organization_id=r.organization_id
         WHERE r.organization_id=?
           AND r.data_source_id=?
           AND r.import_batch_id=?
           AND r.source_dataset='Volume Points'
           AND r.mapping_status='mapped'
           AND r.mapped_entity_type='volume_point_entry'"
    );
    $traceStmt->execute([$organizationId, $sourceId, $batchId]);
    $metrics['exact_trace_rows'] = (int)$traceStmt->fetchColumn();

    $orphanStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM volume_point_entries v
         INNER JOIN raw_source_records r ON r.id=v.source_record_id
         LEFT JOIN members m ON m.id=v.member_id AND m.organization_id=v.organization_id
         WHERE v.organization_id=?
           AND r.import_batch_id=?
           AND r.source_dataset='Volume Points'
           AND v.member_id IS NOT NULL
           AND m.id IS NULL"
    );
    $orphanStmt->execute([$organizationId, $batchId]);
    $metrics['orphan_member_links'] = (int)$orphanStmt->fetchColumn();

    $dupKeyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_key
            FROM volume_point_entries v
            INNER JOIN raw_source_records r ON r.id=v.source_record_id
            WHERE v.organization_id=? AND r.import_batch_id=? AND r.source_dataset='Volume Points'
            GROUP BY source_key
            HAVING COUNT(*) > 1
        ) d"
    );
    $dupKeyStmt->execute([$organizationId, $batchId]);
    $metrics['duplicate_source_key_groups'] = (int)$dupKeyStmt->fetchColumn();

    $dupSourceStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_record_id
            FROM volume_point_entries v
            INNER JOIN raw_source_records r ON r.id=v.source_record_id
            WHERE v.organization_id=? AND r.import_batch_id=? AND r.source_dataset='Volume Points'
            GROUP BY source_record_id
            HAVING COUNT(*) > 1
        ) d"
    );
    $dupSourceStmt->execute([$organizationId, $batchId]);
    $metrics['duplicate_source_record_groups'] = (int)$dupSourceStmt->fetchColumn();

    $vpDifference = abs($metrics['raw_total_vp'] - $metrics['normalized_total_vp']);

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'Raw Volume Points rows = 282' => $metrics['raw_rows'] === VP_RECON_EXPECTED_ROWS,
        'All 282 raw rows are mapped' => $metrics['mapped_rows'] === VP_RECON_EXPECTED_ROWS && $metrics['pending_rows'] === 0,
        '282 normalized VP facts exist for this batch' => $metrics['vp_rows'] === VP_RECON_EXPECTED_ROWS,
        'Every raw row has an exact Raw → VP trace' => $metrics['exact_trace_rows'] === VP_RECON_EXPECTED_ROWS,
        'No orphan Member foreign-key links' => $metrics['orphan_member_links'] === 0,
        'No duplicate VP source keys' => $metrics['duplicate_source_key_groups'] === 0,
        'No raw source row normalized twice' => $metrics['duplicate_source_record_groups'] === 0,
        'Total Volume Points matches raw source' => $vpDifference < 0.0005,
        'Linked + Link Later = 282' => ($metrics['linked_members'] + $metrics['link_later']) === VP_RECON_EXPECTED_ROWS,
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
  <title>Volume Points Reconciliation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .vpr-wide{grid-column:span 12}.vpr-main{grid-column:span 7}.vpr-side{grid-column:span 5}.vpr-number{font-variant-numeric:tabular-nums}@media(max-width:900px){.vpr-main,.vpr-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Volume Points Reconciliation</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_volume_points.php">← Volume Points Write</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8N • Read-only VP reconciliation</div>
      <h1>Prove that all 282 Volume Point facts remain traceable to their raw Excel rows.</h1>
      <p>This page performs no writes. It verifies raw mapping state, dedicated VP fact count, exact source links, optional Member links, duplicate protection and total VP preservation.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">Expected rows: 282</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Reconciliation PASS' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert vpr-wide"><strong>Reconciliation could not run:</strong> <?= vpr_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="Volume Points reconciliation summary">
        <article class="imp-kpi green"><small>Integrity</small><strong><?= $allPass ? 'PASS' : 'REVIEW' ?></strong><span>Latest raw batch #<?= (int)$batch['id'] ?></span></article>
        <article class="imp-kpi blue"><small>Raw Mapped</small><strong><?= number_format($metrics['mapped_rows']) ?> / 282</strong><span>Pending <?= number_format($metrics['pending_rows']) ?></span></article>
        <article class="imp-kpi gold"><small>VP Facts</small><strong><?= number_format($metrics['vp_rows']) ?></strong><span>Exact trace <?= number_format($metrics['exact_trace_rows']) ?> / 282</span></article>
        <article class="imp-kpi"><small>Member Links</small><strong><?= number_format($metrics['linked_members']) ?></strong><span><?= number_format($metrics['link_later']) ?> intentionally link later</span></article>
      </section>

      <article class="imp-card vpr-main">
        <h2>Reconciliation checks</h2>
        <p>Every blocking condition must pass before the next source dataset is normalized.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row">
              <div><b><?= vpr_h($label) ?></b><span><?= $pass ? 'Verified' : 'Needs review before continuing' ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card vpr-side">
        <h2>Safety metrics</h2>
        <p>Zero values below confirm no broken or duplicate traceability.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Orphan Member links</b><span>Member ID points to missing Member</span></div><em><?= number_format($metrics['orphan_member_links']) ?></em></div>
          <div class="imp-plan-row"><div><b>Duplicate source-key groups</b><span>Same normalized source key more than once</span></div><em><?= number_format($metrics['duplicate_source_key_groups']) ?></em></div>
          <div class="imp-plan-row"><div><b>Duplicate source-row groups</b><span>Same raw row normalized more than once</span></div><em><?= number_format($metrics['duplicate_source_record_groups']) ?></em></div>
        </div>
      </aside>

      <article class="imp-card vpr-wide">
        <h2>Volume Point preservation</h2>
        <p>The normalized fact total must equal the sum stored in the raw workbook rows.</p>
        <div class="imp-derived-list">
          <div class="imp-derived-item"><b>Raw source VP total</b><span class="vpr-number"><?= number_format($metrics['raw_total_vp'], 3) ?></span></div>
          <div class="imp-derived-item"><b>Normalized VP total</b><span class="vpr-number"><?= number_format($metrics['normalized_total_vp'], 3) ?></span></div>
          <div class="imp-derived-item"><b>Difference</b><span class="vpr-number"><?= number_format(abs($metrics['raw_total_vp'] - $metrics['normalized_total_vp']), 3) ?></span></div>
          <div class="imp-derived-item"><b>Safe Member links</b><span><?= number_format($metrics['linked_members']) ?></span></div>
          <div class="imp-derived-item"><b>Link later</b><span><?= number_format($metrics['link_later']) ?> • source names preserved</span></div>
          <div class="imp-derived-item"><b>Workbook</b><span><?= vpr_h((string)$batch['original_file_name']) ?></span></div>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Read-only boundary:</strong> this reconciliation page does not update Member links or alter VP facts. Uncertain Member identities remain intentionally unlinked until a dedicated identity-resolution pass.</div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
