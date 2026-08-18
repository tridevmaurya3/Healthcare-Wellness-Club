<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const NEW_UMS_EXPECTED = 78;

function nur_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$checks = [];
$metrics = [
    'raw_total' => 0,
    'raw_mapped' => 0,
    'raw_pending' => 0,
    'members' => 0,
    'ums' => 0,
    'raw_to_ums_exact' => 0,
    'member_to_raw_exact' => 0,
    'ums_member_links' => 0,
    'orphan_ums' => 0,
    'orphan_member_source' => 0,
    'duplicate_member_source_keys' => 0,
    'duplicate_ums_source_keys' => 0,
    'duplicate_member_source_rows' => 0,
    'duplicate_ums_source_rows' => 0,
    'raw_wrong_entity_type' => 0,
    'raw_missing_mapped_id' => 0,
];
$batch = null;
$allPass = false;

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
        "SELECT id, original_file_name, file_sha256, status, completed_at
         FROM import_batches
         WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture' AND status='completed'
         ORDER BY id DESC LIMIT 1"
    );
    $batchStmt->execute([$orgId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No completed raw Excel capture batch was found.');
    }
    $batchId = (int)$batch['id'];

    $rawCountStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS raw_total,
            SUM(CASE WHEN mapping_status='mapped' THEN 1 ELSE 0 END) AS raw_mapped,
            SUM(CASE WHEN mapping_status='pending' THEN 1 ELSE 0 END) AS raw_pending,
            SUM(CASE WHEN mapping_status='mapped' AND mapped_entity_type<>'ums_record' THEN 1 ELSE 0 END) AS wrong_entity,
            SUM(CASE WHEN mapping_status='mapped' AND (mapped_entity_id IS NULL OR mapped_entity_id=0) THEN 1 ELSE 0 END) AS missing_id
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='New UMS'"
    );
    $rawCountStmt->execute([$orgId, $sourceId, $batchId]);
    $raw = $rawCountStmt->fetch() ?: [];
    $metrics['raw_total'] = (int)($raw['raw_total'] ?? 0);
    $metrics['raw_mapped'] = (int)($raw['raw_mapped'] ?? 0);
    $metrics['raw_pending'] = (int)($raw['raw_pending'] ?? 0);
    $metrics['raw_wrong_entity_type'] = (int)($raw['wrong_entity'] ?? 0);
    $metrics['raw_missing_mapped_id'] = (int)($raw['missing_id'] ?? 0);

    $memberStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM members
         WHERE organization_id=? AND source_sheet='New UMS'"
    );
    $memberStmt->execute([$orgId]);
    $metrics['members'] = (int)$memberStmt->fetchColumn();

    $umsStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ums_records
         WHERE organization_id=? AND source_sheet='New UMS'"
    );
    $umsStmt->execute([$orgId]);
    $metrics['ums'] = (int)$umsStmt->fetchColumn();

    $rawToUmsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM raw_source_records r
         INNER JOIN ums_records u
           ON u.id=r.mapped_entity_id
          AND u.organization_id=r.organization_id
          AND u.source_record_id=r.id
          AND u.source_sheet='New UMS'
          AND u.source_row=r.source_row
         WHERE r.organization_id=? AND r.data_source_id=? AND r.import_batch_id=?
           AND r.source_dataset='New UMS' AND r.mapping_status='mapped'
           AND r.mapped_entity_type='ums_record'"
    );
    $rawToUmsStmt->execute([$orgId, $sourceId, $batchId]);
    $metrics['raw_to_ums_exact'] = (int)$rawToUmsStmt->fetchColumn();

    $memberRawStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM members m
         INNER JOIN raw_source_records r
           ON r.id=m.source_record_id
          AND r.organization_id=m.organization_id
          AND r.source_dataset='New UMS'
          AND r.source_row=m.source_row
         WHERE m.organization_id=? AND m.source_sheet='New UMS'"
    );
    $memberRawStmt->execute([$orgId]);
    $metrics['member_to_raw_exact'] = (int)$memberRawStmt->fetchColumn();

    $umsMemberStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM ums_records u
         INNER JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
         WHERE u.organization_id=? AND u.source_sheet='New UMS'"
    );
    $umsMemberStmt->execute([$orgId]);
    $metrics['ums_member_links'] = (int)$umsMemberStmt->fetchColumn();

    $orphanUmsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM ums_records u
         LEFT JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
         WHERE u.organization_id=? AND u.source_sheet='New UMS' AND m.id IS NULL"
    );
    $orphanUmsStmt->execute([$orgId]);
    $metrics['orphan_ums'] = (int)$orphanUmsStmt->fetchColumn();

    $orphanMemberStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM members m
         LEFT JOIN raw_source_records r ON r.id=m.source_record_id AND r.organization_id=m.organization_id
         WHERE m.organization_id=? AND m.source_sheet='New UMS' AND r.id IS NULL"
    );
    $orphanMemberStmt->execute([$orgId]);
    $metrics['orphan_member_source'] = (int)$orphanMemberStmt->fetchColumn();

    $dupMemberKeyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_key
            FROM members
            WHERE organization_id=? AND source_sheet='New UMS' AND source_key IS NOT NULL
            GROUP BY source_key HAVING COUNT(*)>1
         ) x"
    );
    $dupMemberKeyStmt->execute([$orgId]);
    $metrics['duplicate_member_source_keys'] = (int)$dupMemberKeyStmt->fetchColumn();

    $dupUmsKeyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_key
            FROM ums_records
            WHERE organization_id=? AND source_sheet='New UMS' AND source_key IS NOT NULL
            GROUP BY source_key HAVING COUNT(*)>1
         ) x"
    );
    $dupUmsKeyStmt->execute([$orgId]);
    $metrics['duplicate_ums_source_keys'] = (int)$dupUmsKeyStmt->fetchColumn();

    $dupMemberRowStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_row
            FROM members
            WHERE organization_id=? AND source_sheet='New UMS'
            GROUP BY source_row HAVING COUNT(*)>1
         ) x"
    );
    $dupMemberRowStmt->execute([$orgId]);
    $metrics['duplicate_member_source_rows'] = (int)$dupMemberRowStmt->fetchColumn();

    $dupUmsRowStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_row
            FROM ums_records
            WHERE organization_id=? AND source_sheet='New UMS'
            GROUP BY source_row HAVING COUNT(*)>1
         ) x"
    );
    $dupUmsRowStmt->execute([$orgId]);
    $metrics['duplicate_ums_source_rows'] = (int)$dupUmsRowStmt->fetchColumn();

    $checks = [
        'Raw New UMS rows remain exactly 78' => $metrics['raw_total'] === NEW_UMS_EXPECTED,
        'All 78 raw rows are mapped' => $metrics['raw_mapped'] === NEW_UMS_EXPECTED && $metrics['raw_pending'] === 0,
        'Every mapped raw row points to a UMS record' => $metrics['raw_wrong_entity_type'] === 0 && $metrics['raw_missing_mapped_id'] === 0,
        '78 Members were created from New UMS' => $metrics['members'] === NEW_UMS_EXPECTED,
        '78 UMS records were created from New UMS' => $metrics['ums'] === NEW_UMS_EXPECTED,
        'Raw → UMS trace is exact for all 78 rows' => $metrics['raw_to_ums_exact'] === NEW_UMS_EXPECTED,
        'Member → raw source trace is exact for all 78 rows' => $metrics['member_to_raw_exact'] === NEW_UMS_EXPECTED,
        'Every UMS record links to a Member' => $metrics['ums_member_links'] === NEW_UMS_EXPECTED && $metrics['orphan_ums'] === 0,
        'No Member has a missing raw source record' => $metrics['orphan_member_source'] === 0,
        'No duplicate Member source keys' => $metrics['duplicate_member_source_keys'] === 0,
        'No duplicate UMS source keys' => $metrics['duplicate_ums_source_keys'] === 0,
        'No source row was normalized twice' => $metrics['duplicate_member_source_rows'] === 0 && $metrics['duplicate_ums_source_rows'] === 0,
    ];

    $allPass = !in_array(false, $checks, true);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>New UMS Reconciliation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Members + UMS Reconciliation</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_new_ums.php">← New UMS</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8K • Post-write reconciliation</div>
      <h1>Prove the first normalized write is complete, traceable and non-duplicated.</h1>
      <p>This page is read-only. It verifies the complete New UMS chain from the original raw Excel row to the normalized Member and UMS record, including source keys, source row numbers and orphan protection.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">New UMS only</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Reconciliation PASS' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Reconciliation could not run:</strong> <?= nur_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="New UMS reconciliation summary">
        <article class="imp-kpi green"><small>Integrity</small><strong><?= $allPass ? 'PASS' : 'REVIEW' ?></strong><span>Read-only post-write check</span></article>
        <article class="imp-kpi blue"><small>Raw Mapped</small><strong><?= number_format($metrics['raw_mapped']) ?> / 78</strong><span>Pending <?= number_format($metrics['raw_pending']) ?></span></article>
        <article class="imp-kpi gold"><small>Members / UMS</small><strong><?= number_format($metrics['members']) ?> / <?= number_format($metrics['ums']) ?></strong><span>Expected 78 / 78</span></article>
        <article class="imp-kpi"><small>Exact Raw → UMS Trace</small><strong><?= number_format($metrics['raw_to_ums_exact']) ?> / 78</strong><span>Source-linked records</span></article>
      </section>

      <article class="imp-card" style="grid-column:span 7">
        <h2>Normalization integrity checks</h2>
        <p>Every check must pass before the next workbook dataset is normalized.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row">
              <div><b><?= nur_h($label) ?></b><span><?= $pass ? 'Verified' : 'Needs review before continuing' ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card" style="grid-column:span 5">
        <h2>Safety metrics</h2>
        <p>These values should remain zero unless a normalization defect exists.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Orphan UMS records</b><span>UMS without Member</span></div><em><?= number_format($metrics['orphan_ums']) ?></em></div>
          <div class="imp-plan-row"><div><b>Members missing raw source</b><span>Broken source trace</span></div><em><?= number_format($metrics['orphan_member_source']) ?></em></div>
          <div class="imp-plan-row"><div><b>Duplicate Member source keys</b><span>Source identity collision</span></div><em><?= number_format($metrics['duplicate_member_source_keys']) ?></em></div>
          <div class="imp-plan-row"><div><b>Duplicate UMS source keys</b><span>Source identity collision</span></div><em><?= number_format($metrics['duplicate_ums_source_keys']) ?></em></div>
          <div class="imp-plan-row"><div><b>Repeated Member source rows</b><span>Normalized twice</span></div><em><?= number_format($metrics['duplicate_member_source_rows']) ?></em></div>
          <div class="imp-plan-row"><div><b>Repeated UMS source rows</b><span>Normalized twice</span></div><em><?= number_format($metrics['duplicate_ums_source_rows']) ?></em></div>
        </div>
      </aside>

      <article class="imp-card imp-derived">
        <h2>Traceability chain</h2>
        <div class="imp-derived-list">
          <div class="imp-derived-item"><b>Raw source rows</b><span><?= number_format($metrics['raw_total']) ?> New UMS rows preserved</span></div>
          <div class="imp-derived-item"><b>Raw → UMS exact</b><span><?= number_format($metrics['raw_to_ums_exact']) ?> records verified by mapped ID + source record + row</span></div>
          <div class="imp-derived-item"><b>Member → Raw exact</b><span><?= number_format($metrics['member_to_raw_exact']) ?> records verified by source record + row</span></div>
          <div class="imp-derived-item"><b>UMS → Member</b><span><?= number_format($metrics['ums_member_links']) ?> linked records</span></div>
          <div class="imp-derived-item"><b>Workbook</b><span><?= nur_h((string)($batch['original_file_name'] ?? '—')) ?></span></div>
          <div class="imp-derived-item"><b>Raw Batch</b><span>#<?= (int)($batch['id'] ?? 0) ?> • <?= nur_h((string)($batch['status'] ?? '—')) ?></span></div>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Gate for the next dataset:</strong> continue only when this page shows <strong>Reconciliation PASS</strong> and every integrity row shows PASS. Shared/placeholder mobiles and repeated names remain preserved as separate source identities; no automatic merge is performed here.</div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
