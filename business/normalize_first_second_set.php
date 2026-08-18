<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const FSS_WRITE_EXPECTED_ROWS = 94;

function fssw_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fssw_trim(?string $value): string
{
    return trim((string)$value);
}

function fssw_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(fssw_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function fssw_excel_date(?string $value): array
{
    $raw = fssw_trim($value);
    if ($raw === '') {
        return ['iso' => null, 'valid' => false];
    }

    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                return [
                    'iso' => (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d'),
                    'valid' => true,
                ];
            } catch (Throwable) {
                return ['iso' => null, 'valid' => false];
            }
        }
    }

    try {
        return ['iso' => (new DateTimeImmutable($raw))->format('Y-m-d'), 'valid' => true];
    } catch (Throwable) {
        return ['iso' => null, 'valid' => false];
    }
}

function fssw_decimal_nullable(?string $value): array
{
    $raw = fssw_trim($value);
    if ($raw === '') {
        return ['value' => null, 'valid' => true, 'blank' => true];
    }

    $clean = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $raw);
    if (!is_numeric($clean)) {
        return ['value' => null, 'valid' => false, 'blank' => false];
    }

    return ['value' => (float)$clean, 'valid' => true, 'blank' => false];
}

function fssw_equal_decimal(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return true;
    }
    return abs($a - $b) < 0.005;
}

function fssw_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A First & Second Set raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function fssw_member_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $stmt->execute([$organizationId]);

    $byName = [];
    foreach ($stmt->fetchAll() as $member) {
        $key = fssw_name_key((string)$member['full_name']);
        if ($key !== '') {
            $byName[$key][] = $member;
        }
    }
    return $byName;
}

function fssw_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('First & Second Set metadata could not be encoded.');
    }
    return $json;
}

$error = null;
$success = null;
$result = null;
$rawRows = [];
$pendingRows = 0;
$mappedRows = 0;
$currentDatasetOrders = 0;
$safeLinkCount = 0;
$linkLaterCount = 0;
$context = null;

try {
    $pdo = business_db();

    $org = $pdo->query("SELECT id, organization_name FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }
    $organizationId = (int)$org['id'];

    $clubStmt = $pdo->prepare("SELECT id, club_name FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $clubStmt->execute([$organizationId]);
    $club = $clubStmt->fetch();
    if (!$club) {
        throw new RuntimeException('Ghazipur club was not found.');
    }
    $clubId = (int)$club['id'];

    if (!business_table_exists($pdo, 'orders')) {
        throw new RuntimeException('orders table is missing. Run the world-ready schema first.');
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
        "SELECT id, source_row, external_record_id, mapping_status, mapped_entity_type, mapped_entity_id, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='First & Second Set'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();

    if (count($rawRows) !== FSS_WRITE_EXPECTED_ROWS) {
        throw new RuntimeException('First & Second Set raw row count is ' . count($rawRows) . '; expected 94. Normalization is blocked.');
    }

    foreach ($rawRows as $rawRow) {
        if ((string)$rawRow['mapping_status'] === 'pending') {
            $pendingRows++;
        } elseif ((string)$rawRow['mapping_status'] === 'mapped') {
            $mappedRows++;
        }
    }

    $datasetOrderStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE organization_id=? AND source_sheet='First & Second Set'");
    $datasetOrderStmt->execute([$organizationId]);
    $currentDatasetOrders = (int)$datasetOrderStmt->fetchColumn();

    $memberIndex = fssw_member_index($pdo, $organizationId);
    foreach ($rawRows as $rawRow) {
        $values = fssw_decode_values((string)$rawRow['raw_json']);
        $nameKey = fssw_name_key($values['I'] ?? null);
        if ($nameKey !== '' && isset($memberIndex[$nameKey]) && count($memberIndex[$nameKey]) === 1) {
            $safeLinkCount++;
        } else {
            $linkLaterCount++;
        }
    }

    $context = [
        'organization_id' => $organizationId,
        'organization_name' => (string)$org['organization_name'],
        'club_id' => $clubId,
        'club_name' => (string)$club['club_name'],
        'source_id' => $sourceId,
        'batch_id' => (int)$batch['id'],
        'workbook' => (string)$batch['original_file_name'],
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['confirm_fss_write'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the First & Second Set normalization write before continuing.');
        }

        if ($mappedRows === FSS_WRITE_EXPECTED_ROWS && $pendingRows === 0) {
            $success = 'First & Second Set has already been normalized. No duplicate orders were created.';
            $result = [
                'created_orders' => 0,
                'linked_members' => 0,
                'link_later' => 0,
                'mapped_raw' => FSS_WRITE_EXPECTED_ROWS,
                'already_done' => true,
            ];
        } elseif ($pendingRows !== FSS_WRITE_EXPECTED_ROWS || $mappedRows !== 0) {
            throw new RuntimeException('First & Second Set is in a partial mapping state. Write is blocked to prevent mixed normalization.');
        } else {
            $prepared = [];

            foreach ($rawRows as $rawRow) {
                $values = fssw_decode_values((string)$rawRow['raw_json']);
                $name = fssw_trim($values['I'] ?? null);
                $nameKey = fssw_name_key($name);
                $date = fssw_excel_date($values['G'] ?? null);
                $amountPrimary = fssw_decimal_nullable($values['R'] ?? null);
                $profitPrimary = fssw_decimal_nullable($values['S'] ?? null);
                $amountMirror = fssw_decimal_nullable($values['U'] ?? null);
                $profitMirror = fssw_decimal_nullable($values['V'] ?? null);

                if ($name === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no member name.');
                }
                if (!$date['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid order date.');
                }
                if (!$amountPrimary['valid'] || !$profitPrimary['valid'] || !$amountMirror['valid'] || !$profitMirror['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' contains a non-numeric financial value.');
                }
                if (!$amountPrimary['blank'] && !$amountMirror['blank'] && !fssw_equal_decimal($amountPrimary['value'], $amountMirror['value'])) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has mismatched R/U Order Amount values.');
                }
                if (!$profitPrimary['blank'] && !$profitMirror['blank'] && !fssw_equal_decimal($profitPrimary['value'], $profitMirror['value'])) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has mismatched S/V Profit values.');
                }

                $externalId = (string)($rawRow['external_record_id'] ?? '');
                if ($externalId === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no external source key.');
                }

                $memberId = null;
                if ($nameKey !== '' && isset($memberIndex[$nameKey]) && count($memberIndex[$nameKey]) === 1) {
                    $memberId = (int)$memberIndex[$nameKey][0]['id'];
                }

                $orderSet = fssw_trim($values['H'] ?? null);
                $orderLevel = fssw_trim($values['B'] ?? null);
                $primaryAmount = $amountPrimary['value'] ?? 0.0;
                $primaryProfit = $profitPrimary['value'] ?? 0.0;

                $sourceMetadata = [
                    'dataset' => 'First & Second Set',
                    'member_name_snapshot' => $name,
                    'order_level' => $orderLevel,
                    'ordered_by' => fssw_trim($values['C'] ?? null),
                    'year' => fssw_trim($values['D'] ?? null),
                    'month' => fssw_trim($values['E'] ?? null),
                    'week' => fssw_trim($values['F'] ?? null),
                    'order_set' => $orderSet,
                    'sponsor_name_snapshot' => fssw_trim($values['J'] ?? null),
                    'ums_amount_source' => fssw_trim($values['K'] ?? null),
                    'ums_type_source' => fssw_trim($values['L'] ?? null),
                    'products_raw' => [
                        'formula_1_first' => fssw_trim($values['M'] ?? null),
                        'afresh_first' => fssw_trim($values['N'] ?? null),
                        'shaker_cup' => fssw_trim($values['O'] ?? null),
                        'formula_1_second' => fssw_trim($values['P'] ?? null),
                        'afresh_second' => fssw_trim($values['Q'] ?? null),
                    ],
                    'financial_source' => [
                        'R_order_amount_primary' => $amountPrimary['value'],
                        'S_profit_primary' => $profitPrimary['value'],
                        'U_order_amount_mirror' => $amountMirror['value'],
                        'V_profit_mirror' => $profitMirror['value'],
                    ],
                    'legacy_W_raw' => fssw_trim($values['W'] ?? null),
                    'product_item_mapping' => 'deferred_until_product_catalog',
                ];

                $prepared[] = [
                    'raw_id' => (int)$rawRow['id'],
                    'source_row' => (int)$rawRow['source_row'],
                    'external_id' => $externalId,
                    'member_id' => $memberId,
                    'member_name' => $name,
                    'order_date' => $date['iso'],
                    'order_type' => $orderSet !== '' ? $orderSet : 'set_order',
                    'order_level' => $orderLevel,
                    'net_amount' => (float)$primaryAmount,
                    'profit_amount' => (float)$primaryProfit,
                    'notes' => fssw_json($sourceMetadata),
                ];
            }

            $pdo->beginTransaction();
            try {
                $orderInsert = $pdo->prepare(
                    "INSERT INTO orders
                     (organization_id, club_id, member_id, order_date, order_type, description,
                      gross_amount, discount_amount, net_amount, profit_amount, currency_code, volume_points,
                      notes, source_record_id, source_sheet, source_row, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'INR', 0, ?, ?, 'First & Second Set', ?, ?)"
                );

                $rawUpdate = $pdo->prepare(
                    "UPDATE raw_source_records
                     SET mapping_status='mapped', mapped_entity_type='order', mapped_entity_id=?,
                         error_message=NULL, updated_at=NOW()
                     WHERE id=? AND mapping_status='pending'"
                );

                $createdOrders = 0;
                $linkedMembers = 0;
                $linkLater = 0;

                foreach ($prepared as $row) {
                    $sourceKey = 'legacy-xlsx:first-second-set:' . $row['external_id'];
                    $description = 'First & Second Set';
                    if ($row['order_level'] !== '') {
                        $description .= ' | Level: ' . $row['order_level'];
                    }
                    $description .= ' | Member: ' . $row['member_name'];

                    $orderInsert->execute([
                        $organizationId,
                        $clubId,
                        $row['member_id'],
                        $row['order_date'],
                        $row['order_type'],
                        mb_substr($description, 0, 255, 'UTF-8'),
                        $row['net_amount'],
                        $row['net_amount'],
                        $row['profit_amount'],
                        $row['notes'],
                        $row['raw_id'],
                        $row['source_row'],
                        $sourceKey,
                    ]);
                    $orderId = (int)$pdo->lastInsertId();
                    $createdOrders++;

                    if ($row['member_id'] !== null) {
                        $linkedMembers++;
                    } else {
                        $linkLater++;
                    }

                    $rawUpdate->execute([$orderId, $row['raw_id']]);
                    if ($rawUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Raw mapping state changed unexpectedly at source row ' . $row['source_row'] . '.');
                    }
                }

                $auditStmt = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
                     VALUES (?, ?, 'first_second_set_normalization_completed', 'import_batch', ?, ?, ?, ?)"
                );
                $auditStmt->execute([
                    $organizationId,
                    $clubId,
                    (int)$batch['id'],
                    fssw_json([
                        'dataset' => 'First & Second Set',
                        'raw_rows' => FSS_WRITE_EXPECTED_ROWS,
                        'created_orders' => $createdOrders,
                        'safe_member_links' => $linkedMembers,
                        'member_link_later' => $linkLater,
                        'financial_policy' => 'R/S primary; U/V mirror validation only',
                        'product_item_mapping' => 'deferred',
                    ]),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);

                $pdo->commit();

                $mappedRows = FSS_WRITE_EXPECTED_ROWS;
                $pendingRows = 0;
                $currentDatasetOrders += $createdOrders;
                $result = [
                    'created_orders' => $createdOrders,
                    'linked_members' => $linkedMembers,
                    'link_later' => $linkLater,
                    'mapped_raw' => FSS_WRITE_EXPECTED_ROWS,
                    'already_done' => false,
                ];
                $success = 'First & Second Set normalization completed successfully inside one transaction.';
            } catch (Throwable $transactionError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $transactionError;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$readyToWrite = $error === null && $pendingRows === FSS_WRITE_EXPECTED_ROWS && $mappedRows === 0;
$complete = $error === null && $mappedRows === FSS_WRITE_EXPECTED_ROWS && $pendingRows === 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>First & Second Set Normalization - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .fssw-warning{padding:14px 16px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.8rem;line-height:1.6}.fssw-check{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px;border:1px solid #dce8e1;border-radius:12px;background:#fff}.fssw-check input{margin-top:3px}.fssw-main{grid-column:span 7}.fssw-side{grid-column:span 5}@media(max-width:900px){.fssw-main,.fssw-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • First & Second Set Safe Write</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_first_second_set_preview.php">← Preview</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8P • First & Second Set normalized write</div>
      <h1>Create 94 source-traceable order facts without guessing product items.</h1>
      <p>R/S are the authoritative Order Amount and Profit facts. U/V are rechecked only as mirrors. Product quantities M–Q, sponsor, UMS fields and source period labels remain preserved in notes until the Product & Price catalog is built.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Transaction protected</span>
      <span class="imp-chip good">R/S primary • U/V validation</span>
      <span class="imp-chip <?= ($readyToWrite || $complete) ? 'good' : '' ?>"><?= $complete ? 'Normalization COMPLETE' : ($readyToWrite ? 'Ready to write' : 'Blocked') ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Normalization blocked:</strong> <?= fssw_h($error) ?></div>
    <?php elseif ($success !== null): ?>
      <div class="imp-alert good" style="grid-column:span 12"><strong>Success:</strong> <?= fssw_h($success) ?></div>
    <?php endif; ?>

    <section class="imp-summary" aria-label="First & Second Set normalization state">
      <article class="imp-kpi green"><small>Raw Rows</small><strong><?= number_format(count($rawRows)) ?></strong><span>Expected 94</span></article>
      <article class="imp-kpi blue"><small>Pending</small><strong><?= number_format($pendingRows) ?></strong><span>Waiting for normalization</span></article>
      <article class="imp-kpi gold"><small>Mapped</small><strong><?= number_format($mappedRows) ?></strong><span>Raw trace updated</span></article>
      <article class="imp-kpi"><small>Dataset Orders</small><strong><?= number_format($currentDatasetOrders) ?></strong><span>First & Second Set only</span></article>
    </section>

    <article class="imp-card fssw-main">
      <h2>Normalize verified First & Second Set rows</h2>
      <p>Member IDs are linked only for a unique exact-name match. Unmatched/ambiguous member names stay preserved in the order description and source metadata.</p>

      <?php if ($complete): ?>
        <div class="imp-alert good"><strong>Already complete:</strong> all 94 First & Second Set raw rows are mapped. Running this page again will not create duplicate orders.</div>
      <?php elseif ($readyToWrite): ?>
        <div class="fssw-warning"><strong>Financial boundary:</strong> R is stored as the source Order Amount; S is stored as Profit. Because this workbook does not provide a separate gross/discount/net breakdown, the source Order Amount is carried into both gross and net with discount = 0. This is a preservation choice, not a claim that no commercial discount existed.</div>
        <form method="post" class="imp-drop">
          <label class="fssw-check">
            <input type="checkbox" name="confirm_fss_write" value="yes" required>
            <span><strong>I confirm normalization of the verified First & Second Set dataset.</strong><br><small>Only 94 order facts + raw mapping trace + audit event will be written.</small></span>
          </label>
          <button class="imp-submit" type="submit">Normalize First & Second Set Safely →</button>
        </form>
      <?php endif; ?>

      <?php if ($result !== null): ?>
        <div class="imp-derived-list" style="margin-top:14px">
          <div class="imp-derived-item"><b>Orders created</b><span><?= number_format((int)$result['created_orders']) ?></span></div>
          <div class="imp-derived-item"><b>Members linked</b><span><?= number_format((int)$result['linked_members']) ?></span></div>
          <div class="imp-derived-item"><b>Member link later</b><span><?= number_format((int)$result['link_later']) ?></span></div>
          <div class="imp-derived-item"><b>Raw rows mapped</b><span><?= number_format((int)$result['mapped_raw']) ?></span></div>
        </div>
      <?php endif; ?>
    </article>

    <aside class="imp-card fssw-side">
      <h2>Write boundary</h2>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>orders</b><span>94 order-level facts</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>R / S</b><span>Primary amount + profit</span></div><em>FACT</em></div>
        <div class="imp-plan-row"><div><b>U / V</b><span>Mirror validation only</span></div><em>CHECK</em></div>
        <div class="imp-plan-row"><div><b>M–Q Products</b><span>Raw metadata preserved</span></div><em>DEFER</em></div>
        <div class="imp-plan-row"><div><b>raw_source_records</b><span>pending → mapped</span></div><em>UPDATE</em></div>
        <div class="imp-plan-row"><div><b>Other source sheets</b><span>No change</span></div><em>OFF</em></div>
      </div>
    </aside>

    <div class="imp-footer-note"><strong>Traceability:</strong> every created order keeps its original raw record ID, source sheet, row and source key. Product-level order_items remain deliberately deferred until the authoritative product catalog and pricing system exist.</div>
  </section>
</main>
</body>
</html>
