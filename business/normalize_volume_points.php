<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const VP_WRITE_EXPECTED_ROWS = 282;

function vpw_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vpw_trim(?string $value): string
{
    return trim((string)$value);
}

function vpw_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(vpw_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function vpw_excel_date(?string $value): array
{
    $raw = vpw_trim($value);
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

function vpw_decimal(?string $value): array
{
    $raw = vpw_trim($value);
    if ($raw === '') {
        return ['value' => null, 'valid' => false];
    }
    $clean = str_replace([',', ' '], '', $raw);
    if (!is_numeric($clean)) {
        return ['value' => null, 'valid' => false];
    }
    return ['value' => (float)$clean, 'valid' => true];
}

function vpw_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A Volume Points raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function vpw_member_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $stmt->execute([$organizationId]);

    $byName = [];
    foreach ($stmt->fetchAll() as $member) {
        $key = vpw_name_key((string)$member['full_name']);
        if ($key !== '') {
            $byName[$key][] = $member;
        }
    }
    return $byName;
}

function vpw_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Volume Points audit details could not be encoded.');
    }
    return $json;
}

$error = null;
$success = null;
$result = null;
$rawRows = [];
$pendingRows = 0;
$mappedRows = 0;
$currentVpCount = 0;
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

    if (!business_table_exists($pdo, 'volume_point_entries')) {
        throw new RuntimeException('volume_point_entries table is missing. Run migration 002_source_support_tables.sql first.');
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
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='Volume Points'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();

    if (count($rawRows) !== VP_WRITE_EXPECTED_ROWS) {
        throw new RuntimeException('Volume Points raw row count is ' . count($rawRows) . '; expected 282. Normalization is blocked.');
    }

    foreach ($rawRows as $rawRow) {
        if ((string)$rawRow['mapping_status'] === 'pending') {
            $pendingRows++;
        } elseif ((string)$rawRow['mapping_status'] === 'mapped') {
            $mappedRows++;
        }
    }

    $vpCountStmt = $pdo->prepare('SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=?');
    $vpCountStmt->execute([$organizationId]);
    $currentVpCount = (int)$vpCountStmt->fetchColumn();

    $memberIndex = vpw_member_index($pdo, $organizationId);
    foreach ($rawRows as $rawRow) {
        $values = vpw_decode_values((string)$rawRow['raw_json']);
        $nameKey = vpw_name_key($values['B'] ?? null);
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
        if (($_POST['confirm_vp_write'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the Volume Points normalization write before continuing.');
        }

        if ($mappedRows === VP_WRITE_EXPECTED_ROWS && $pendingRows === 0) {
            $success = 'Volume Points has already been normalized. No duplicate VP entries were created.';
            $result = [
                'created_vp' => 0,
                'linked_members' => 0,
                'link_later' => 0,
                'mapped_raw' => VP_WRITE_EXPECTED_ROWS,
                'already_done' => true,
            ];
        } elseif ($pendingRows !== VP_WRITE_EXPECTED_ROWS || $mappedRows !== 0) {
            throw new RuntimeException('Volume Points is in a partial mapping state. Write is blocked to prevent mixed normalization.');
        } else {
            $prepared = [];
            foreach ($rawRows as $rawRow) {
                $values = vpw_decode_values((string)$rawRow['raw_json']);
                $name = vpw_trim($values['B'] ?? null);
                $nameKey = vpw_name_key($name);
                $date = vpw_excel_date($values['G'] ?? null);
                $volume = vpw_decimal($values['H'] ?? null);

                if ($name === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no member name.');
                }
                if (!$date['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid VP date.');
                }
                if (!$volume['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid Volume Point value.');
                }

                $externalId = (string)($rawRow['external_record_id'] ?? '');
                if ($externalId === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no external source key.');
                }

                $memberId = null;
                if ($nameKey !== '' && isset($memberIndex[$nameKey]) && count($memberIndex[$nameKey]) === 1) {
                    $memberId = (int)$memberIndex[$nameKey][0]['id'];
                }

                $prepared[] = [
                    'raw_id' => (int)$rawRow['id'],
                    'source_row' => (int)$rawRow['source_row'],
                    'external_id' => $externalId,
                    'member_id' => $memberId,
                    'member_name' => $name,
                    'entry_date' => $date['iso'],
                    'level' => vpw_trim($values['C'] ?? null),
                    'week' => vpw_trim($values['F'] ?? null),
                    'volume_points' => (float)$volume['value'],
                    'order_type' => vpw_trim($values['I'] ?? null),
                    'notes' => vpw_trim($values['J'] ?? null),
                    'vp_from' => vpw_trim($values['K'] ?? null),
                    'ordered_by' => vpw_trim($values['L'] ?? null),
                    'vp_type' => vpw_trim($values['M'] ?? null),
                    'order_set' => vpw_trim($values['N'] ?? null),
                ];
            }

            $pdo->beginTransaction();
            try {
                $vpInsert = $pdo->prepare(
                    "INSERT INTO volume_point_entries
                     (organization_id, club_id, member_id, member_name_snapshot, entry_date, level_label,
                      week_label, volume_points, order_type, vp_from, ordered_by, vp_type, order_set,
                      notes, source_record_id, source_sheet, source_row, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Volume Points', ?, ?)"
                );

                $rawUpdate = $pdo->prepare(
                    "UPDATE raw_source_records
                     SET mapping_status='mapped', mapped_entity_type='volume_point_entry', mapped_entity_id=?,
                         error_message=NULL, updated_at=NOW()
                     WHERE id=? AND mapping_status='pending'"
                );

                $createdVp = 0;
                $linkedMembers = 0;
                $linkLater = 0;

                foreach ($prepared as $row) {
                    $sourceKey = 'legacy-xlsx:volume-points:' . $row['external_id'];

                    $vpInsert->execute([
                        $organizationId,
                        $clubId,
                        $row['member_id'],
                        $row['member_name'],
                        $row['entry_date'],
                        $row['level'] !== '' ? $row['level'] : null,
                        $row['week'] !== '' ? $row['week'] : null,
                        $row['volume_points'],
                        $row['order_type'] !== '' ? $row['order_type'] : null,
                        $row['vp_from'] !== '' ? $row['vp_from'] : null,
                        $row['ordered_by'] !== '' ? $row['ordered_by'] : null,
                        $row['vp_type'] !== '' ? $row['vp_type'] : null,
                        $row['order_set'] !== '' ? $row['order_set'] : null,
                        $row['notes'] !== '' ? $row['notes'] : null,
                        $row['raw_id'],
                        $row['source_row'],
                        $sourceKey,
                    ]);
                    $vpId = (int)$pdo->lastInsertId();
                    $createdVp++;

                    if ($row['member_id'] !== null) {
                        $linkedMembers++;
                    } else {
                        $linkLater++;
                    }

                    $rawUpdate->execute([$vpId, $row['raw_id']]);
                    if ($rawUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Raw mapping state changed unexpectedly at source row ' . $row['source_row'] . '.');
                    }
                }

                $auditStmt = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
                     VALUES (?, ?, 'volume_points_normalization_completed', 'import_batch', ?, ?, ?, ?)"
                );
                $auditStmt->execute([
                    $organizationId,
                    $clubId,
                    (int)$batch['id'],
                    vpw_json([
                        'dataset' => 'Volume Points',
                        'raw_rows' => VP_WRITE_EXPECTED_ROWS,
                        'created_volume_point_entries' => $createdVp,
                        'safe_member_links' => $linkedMembers,
                        'member_link_later' => $linkLater,
                        'member_link_policy' => 'unique_exact_name_only',
                    ]),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);

                $pdo->commit();

                $mappedRows = VP_WRITE_EXPECTED_ROWS;
                $pendingRows = 0;
                $currentVpCount += $createdVp;
                $result = [
                    'created_vp' => $createdVp,
                    'linked_members' => $linkedMembers,
                    'link_later' => $linkLater,
                    'mapped_raw' => VP_WRITE_EXPECTED_ROWS,
                    'already_done' => false,
                ];
                $success = 'Volume Points normalization completed successfully inside one transaction.';
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

$readyToWrite = $error === null && $pendingRows === VP_WRITE_EXPECTED_ROWS && $mappedRows === 0;
$complete = $error === null && $mappedRows === VP_WRITE_EXPECTED_ROWS && $pendingRows === 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Volume Points Normalization - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .vpw-warning{padding:14px 16px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.8rem;line-height:1.6}.vpw-check{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px;border:1px solid #dce8e1;border-radius:12px;background:#fff}.vpw-check input{margin-top:3px}.vpw-main{grid-column:span 7}.vpw-side{grid-column:span 5}@media(max-width:900px){.vpw-main,.vpw-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Volume Points Safe Normalization</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_volume_points_preview.php">← Preview</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8M • Volume Points normalized write</div>
      <h1>Write all 282 verified VP facts without guessing uncertain Member identities.</h1>
      <p>Every source row becomes one source-key protected Volume Point fact. A Member ID is attached only when the exact source name resolves to one existing Member; otherwise the original member-name snapshot remains fully preserved for later reconciliation.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Transaction protected</span>
      <span class="imp-chip good">Volume Points only</span>
      <span class="imp-chip <?= ($readyToWrite || $complete) ? 'good' : '' ?>"><?= $complete ? 'Normalization COMPLETE' : ($readyToWrite ? 'Ready to write' : 'Blocked') ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Normalization blocked:</strong> <?= vpw_h($error) ?></div>
    <?php elseif ($success !== null): ?>
      <div class="imp-alert good" style="grid-column:span 12"><strong>Success:</strong> <?= vpw_h($success) ?></div>
    <?php endif; ?>

    <section class="imp-summary" aria-label="Volume Points normalization state">
      <article class="imp-kpi green"><small>Raw VP Rows</small><strong><?= number_format(count($rawRows)) ?></strong><span>Expected 282</span></article>
      <article class="imp-kpi blue"><small>Pending</small><strong><?= number_format($pendingRows) ?></strong><span>Waiting for normalization</span></article>
      <article class="imp-kpi gold"><small>Mapped</small><strong><?= number_format($mappedRows) ?></strong><span>Raw trace updated</span></article>
      <article class="imp-kpi"><small>VP Facts</small><strong><?= number_format($currentVpCount) ?></strong><span>Current organization total</span></article>
    </section>

    <article class="imp-card vpw-main">
      <h2>Normalize verified Volume Points rows</h2>
      <p>This write creates only dedicated VP facts. It does not create Members, Orders, Renewals, Income, Royalty or derived reports.</p>

      <?php if ($complete): ?>
        <div class="imp-alert good"><strong>Already complete:</strong> all 282 Volume Points raw rows are mapped. Running this page again will not create duplicate VP facts.</div>
      <?php elseif ($readyToWrite): ?>
        <div class="vpw-warning"><strong>Identity rule:</strong> <?= number_format($safeLinkCount) ?> row(s) currently have a safe unique exact-name Member link; <?= number_format($linkLaterCount) ?> row(s) will keep <code>member_id = NULL</code> plus the original member-name snapshot. Nothing is guessed or discarded.</div>
        <form method="post" class="imp-drop">
          <label class="vpw-check">
            <input type="checkbox" name="confirm_vp_write" value="yes" required>
            <span><strong>I confirm normalization of the verified Volume Points dataset.</strong><br><small>Only the 282 Volume Points source rows will be written to the dedicated fact table.</small></span>
          </label>
          <button class="imp-submit" type="submit">Normalize Volume Points Safely →</button>
        </form>
      <?php endif; ?>

      <?php if ($result !== null): ?>
        <div class="imp-derived-list" style="margin-top:14px">
          <div class="imp-derived-item"><b>VP facts created</b><span><?= number_format((int)$result['created_vp']) ?></span></div>
          <div class="imp-derived-item"><b>Safe Member links</b><span><?= number_format((int)$result['linked_members']) ?></span></div>
          <div class="imp-derived-item"><b>Member link later</b><span><?= number_format((int)$result['link_later']) ?></span></div>
          <div class="imp-derived-item"><b>Raw rows mapped</b><span><?= number_format((int)$result['mapped_raw']) ?></span></div>
        </div>
      <?php endif; ?>
    </article>

    <aside class="imp-card vpw-side">
      <h2>Write boundary</h2>
      <p>Step 8M keeps VP normalization isolated from the other source datasets.</p>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>volume_point_entries</b><span>One fact per source row</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>raw_source_records</b><span>pending → mapped</span></div><em>UPDATE</em></div>
        <div class="imp-plan-row"><div><b>audit_logs</b><span>Normalization audit event</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>members</b><span>No creation or merge</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Other source sheets</b><span>No normalization</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Derived sheets 1–6</b><span>Calculation layer</span></div><em>SKIP</em></div>
      </div>
    </aside>

    <div class="imp-footer-note"><strong>Traceability:</strong> each Volume Point fact retains its raw Excel source record, source sheet, original row number and immutable source key. Uncertain member identity is represented explicitly as an unlinked fact rather than a guessed Member relationship.</div>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
