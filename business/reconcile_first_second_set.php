<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const FSS_RECON_EXPECTED_ROWS = 94;

function fssr_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fssr_trim(?string $value): string
{
    return trim((string)$value);
}

function fssr_decimal_nullable(?string $value): array
{
    $raw = fssr_trim($value);
    if ($raw === '') {
        return ['value' => null, 'valid' => true, 'blank' => true];
    }

    $clean = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $raw);
    if (!is_numeric($clean)) {
        return ['value' => null, 'valid' => false, 'blank' => false];
    }

    return ['value' => (float)$clean, 'valid' => true, 'blank' => false];
}

function fssr_equal(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return true;
    }
    return abs($a - $b) < 0.005;
}

function fssr_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A First & Second Set raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

$error = null;
$batch = null;
$checks = [];
$metrics = [
    'raw_rows' => 0,
    'mapped_rows' => 0,
    'pending_rows' => 0,
    'mapped_as_order' => 0,
    'orders' => 0,
    'exact_trace' => 0,
    'linked_members' => 0,
    'link_later' => 0,
    'orphan_member_links' => 0,
    'duplicate_source_keys' => 0,
    'duplicate_source_records' => 0,
    'source_row_mismatches' => 0,
    'missing_metadata' => 0,
    'mirror_amount_mismatches' => 0,
    'mirror_profit_mismatches' => 0,
    'invalid_financial_rows' => 0,
];
$rawAmountTotal = 0.0;
$rawProfitTotal = 0.0;
$orderNetTotal = 0.0;
$orderGrossTotal = 0.0;
$orderProfitTotal = 0.0;
$amountDifference = 0.0;
$profitDifference = 0.0;

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

    $rawStmt = $pdo->prepare(
        "SELECT id, source_row, mapping_status, mapped_entity_type, mapped_entity_id, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='First & Second Set'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();
    $metrics['raw_rows'] = count($rawRows);

    foreach ($rawRows as $rawRow) {
        $status = (string)$rawRow['mapping_status'];
        if ($status === 'mapped') {
            $metrics['mapped_rows']++;
        } elseif ($status === 'pending') {
            $metrics['pending_rows']++;
        }
        if ($status === 'mapped' && (string)$rawRow['mapped_entity_type'] === 'order' && (int)$rawRow['mapped_entity_id'] > 0) {
            $metrics['mapped_as_order']++;
        }

        $values = fssr_values((string)$rawRow['raw_json']);
        $amountPrimary = fssr_decimal_nullable($values['R'] ?? null);
        $profitPrimary = fssr_decimal_nullable($values['S'] ?? null);
        $amountMirror = fssr_decimal_nullable($values['U'] ?? null);
        $profitMirror = fssr_decimal_nullable($values['V'] ?? null);

        if (!$amountPrimary['valid'] || !$profitPrimary['valid'] || !$amountMirror['valid'] || !$profitMirror['valid']) {
            $metrics['invalid_financial_rows']++;
            continue;
        }

        $rawAmountTotal += (float)($amountPrimary['value'] ?? 0.0);
        $rawProfitTotal += (float)($profitPrimary['value'] ?? 0.0);

        if (!$amountPrimary['blank'] && !$amountMirror['blank'] && !fssr_equal($amountPrimary['value'], $amountMirror['value'])) {
            $metrics['mirror_amount_mismatches']++;
        }
        if (!$profitPrimary['blank'] && !$profitMirror['blank'] && !fssr_equal($profitPrimary['value'], $profitMirror['value'])) {
            $metrics['mirror_profit_mismatches']++;
        }
    }

    $ordersStmt = $pdo->prepare(
        "SELECT id, member_id, gross_amount, net_amount, profit_amount, notes, source_record_id, source_row, source_key
         FROM orders
         WHERE organization_id=? AND source_sheet='First & Second Set'
         ORDER BY source_row"
    );
    $ordersStmt->execute([$organizationId]);
    $orders = $ordersStmt->fetchAll();
    $metrics['orders'] = count($orders);

    foreach ($orders as $order) {
        $orderGrossTotal += (float)$order['gross_amount'];
        $orderNetTotal += (float)$order['net_amount'];
        $orderProfitTotal += (float)$order['profit_amount'];

        if ($order['member_id'] === null) {
            $metrics['link_later']++;
        } else {
            $metrics['linked_members']++;
        }

        $notes = json_decode((string)($order['notes'] ?? ''), true);
        if (!is_array($notes)
            || ($notes['dataset'] ?? '') !== 'First & Second Set'
            || !isset($notes['products_raw'])
            || !isset($notes['financial_source'])
            || ($notes['product_item_mapping'] ?? '') !== 'deferred_until_product_catalog') {
            $metrics['missing_metadata']++;
        }
    }

    $traceStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM raw_source_records r
         JOIN orders o
           ON o.organization_id=r.organization_id
          AND o.id=r.mapped_entity_id
          AND o.source_record_id=r.id
          AND o.source_sheet='First & Second Set'
          AND o.source_row=r.source_row
         WHERE r.organization_id=?
           AND r.data_source_id=?
           AND r.import_batch_id=?
           AND r.source_dataset='First & Second Set'
           AND r.mapping_status='mapped'
           AND r.mapped_entity_type='order'"
    );
    $traceStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $metrics['exact_trace'] = (int)$traceStmt->fetchColumn();

    $orphanStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
         WHERE o.organization_id=?
           AND o.source_sheet='First & Second Set'
           AND o.member_id IS NOT NULL
           AND m.id IS NULL"
    );
    $orphanStmt->execute([$organizationId]);
    $metrics['orphan_member_links'] = (int)$orphanStmt->fetchColumn();

    $dupKeyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_key
            FROM orders
            WHERE organization_id=? AND source_sheet='First & Second Set'
            GROUP BY source_key
            HAVING COUNT(*) > 1
         ) d"
    );
    $dupKeyStmt->execute([$organizationId]);
    $metrics['duplicate_source_keys'] = (int)$dupKeyStmt->fetchColumn();

    $dupSourceStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT source_record_id
            FROM orders
            WHERE organization_id=? AND source_sheet='First & Second Set'
            GROUP BY source_record_id
            HAVING COUNT(*) > 1
         ) d"
    );
    $dupSourceStmt->execute([$organizationId]);
    $metrics['duplicate_source_records'] = (int)$dupSourceStmt->fetchColumn();

    $rowMismatchStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         JOIN raw_source_records r ON r.id=o.source_record_id
         WHERE o.organization_id=?
           AND o.source_sheet='First & Second Set'
           AND (r.source_dataset<>'First & Second Set' OR o.source_row<>r.source_row)"
    );
    $rowMismatchStmt->execute([$organizationId]);
    $metrics['source_row_mismatches'] = (int)$rowMismatchStmt->fetchColumn();

    $amountDifference = $orderNetTotal - $rawAmountTotal;
    $profitDifference = $orderProfitTotal - $rawProfitTotal;

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'Raw First & Second Set rows = 94' => $metrics['raw_rows'] === FSS_RECON_EXPECTED_ROWS,
        'All 94 raw rows are mapped' => $metrics['mapped_rows'] === FSS_RECON_EXPECTED_ROWS && $metrics['pending_rows'] === 0,
        'Every mapped row points to an Order' => $metrics['mapped_as_order'] === FSS_RECON_EXPECTED_ROWS,
        'Exactly 94 normalized Orders exist' => $metrics['orders'] === FSS_RECON_EXPECTED_ROWS,
        'Exact Raw → Order trace = 94' => $metrics['exact_trace'] === FSS_RECON_EXPECTED_ROWS,
        'Linked + link-later Orders = 94' => ($metrics['linked_members'] + $metrics['link_later']) === FSS_RECON_EXPECTED_ROWS,
        'No orphan Member links' => $metrics['orphan_member_links'] === 0,
        'No duplicate Order source keys' => $metrics['duplicate_source_keys'] === 0,
        'No raw row normalized twice' => $metrics['duplicate_source_records'] === 0,
        'Source row trace has no mismatch' => $metrics['source_row_mismatches'] === 0,
        'Product + financial source metadata preserved' => $metrics['missing_metadata'] === 0,
        'Raw financial values remain numeric' => $metrics['invalid_financial_rows'] === 0,
        'R/U mirrored Order Amounts still reconcile' => $metrics['mirror_amount_mismatches'] === 0,
        'S/V mirrored Profit values still reconcile' => $metrics['mirror_profit_mismatches'] === 0,
        'Raw R total = normalized net total' => abs($amountDifference) < 0.005,
        'Raw S total = normalized profit total' => abs($profitDifference) < 0.005,
        'Normalized gross = normalized net total' => abs($orderGrossTotal - $orderNetTotal) < 0.005,
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
  <title>First & Second Set Reconciliation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .fssr-wide{grid-column:span 12}.fssr-main{grid-column:span 7}.fssr-side{grid-column:span 5}
    .fssr-money{font-variant-numeric:tabular-nums}
    @media(max-width:900px){.fssr-main,.fssr-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • First & Second Set Reconciliation</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_first_second_set.php">← First & Second Set Write</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8Q • Read-only order reconciliation</div>
      <h1>Prove all 94 First & Second Set rows survived normalization exactly.</h1>
      <p>This page performs no writes. It reconciles raw-to-order traceability, R/S financial totals, U/V mirror checks, member links, source keys and deferred product metadata.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">94 source rows</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Reconciliation PASS' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert fssr-wide"><strong>Reconciliation could not run:</strong> <?= fssr_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="First and Second Set reconciliation summary">
        <article class="imp-kpi green"><small>Integrity</small><strong><?= $allPass ? 'PASS' : 'REVIEW' ?></strong><span>Read-only verification</span></article>
        <article class="imp-kpi blue"><small>Raw Mapped</small><strong><?= number_format($metrics['mapped_rows']) ?> / 94</strong><span>Pending <?= number_format($metrics['pending_rows']) ?></span></article>
        <article class="imp-kpi gold"><small>Orders</small><strong><?= number_format($metrics['orders']) ?></strong><span>Exact trace <?= number_format($metrics['exact_trace']) ?> / 94</span></article>
        <article class="imp-kpi"><small>Member Links</small><strong><?= number_format($metrics['linked_members']) ?></strong><span>Link later <?= number_format($metrics['link_later']) ?></span></article>
      </section>

      <article class="imp-card fssr-main">
        <h2>Integrity checks</h2>
        <p>Every check below must pass before the next source dataset is normalized.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row">
              <div><b><?= fssr_h($label) ?></b><span><?= $pass ? 'Verified' : 'Must be reviewed before continuing' ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card fssr-side">
        <h2>Financial reconciliation</h2>
        <p>R/S remain the authoritative source values. U/V are comparison-only mirrors.</p>
        <div class="imp-plan-list fssr-money">
          <div class="imp-plan-row"><div><b>Raw R total</b><span>Primary Order Amount</span></div><em>₹<?= number_format($rawAmountTotal, 2) ?></em></div>
          <div class="imp-plan-row"><div><b>Normalized net total</b><span>orders.net_amount</span></div><em>₹<?= number_format($orderNetTotal, 2) ?></em></div>
          <div class="imp-plan-row"><div><b>Amount difference</b><span>Must be zero</span></div><em><?= number_format($amountDifference, 2) ?></em></div>
          <div class="imp-plan-row"><div><b>Raw S total</b><span>Primary Profit</span></div><em>₹<?= number_format($rawProfitTotal, 2) ?></em></div>
          <div class="imp-plan-row"><div><b>Normalized profit</b><span>orders.profit_amount</span></div><em>₹<?= number_format($orderProfitTotal, 2) ?></em></div>
          <div class="imp-plan-row"><div><b>Profit difference</b><span>Must be zero</span></div><em><?= number_format($profitDifference, 2) ?></em></div>
        </div>
      </aside>

      <article class="imp-card fssr-wide">
        <h2>Safety metrics</h2>
        <div class="imp-derived-list">
          <div class="imp-derived-item"><b>Orphan Member links</b><span><?= number_format($metrics['orphan_member_links']) ?></span></div>
          <div class="imp-derived-item"><b>Duplicate source keys</b><span><?= number_format($metrics['duplicate_source_keys']) ?></span></div>
          <div class="imp-derived-item"><b>Duplicate source records</b><span><?= number_format($metrics['duplicate_source_records']) ?></span></div>
          <div class="imp-derived-item"><b>Source-row mismatches</b><span><?= number_format($metrics['source_row_mismatches']) ?></span></div>
          <div class="imp-derived-item"><b>Missing deferred metadata</b><span><?= number_format($metrics['missing_metadata']) ?></span></div>
          <div class="imp-derived-item"><b>R/U mismatches</b><span><?= number_format($metrics['mirror_amount_mismatches']) ?></span></div>
          <div class="imp-derived-item"><b>S/V mismatches</b><span><?= number_format($metrics['mirror_profit_mismatches']) ?></span></div>
          <div class="imp-derived-item"><b>Invalid financial rows</b><span><?= number_format($metrics['invalid_financial_rows']) ?></span></div>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Product boundary:</strong> Formula 1, Afresh and Shaker quantities are preserved inside source metadata but are intentionally not product-line facts yet. They will be mapped only after the authoritative Product & Price catalog exists.</div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
