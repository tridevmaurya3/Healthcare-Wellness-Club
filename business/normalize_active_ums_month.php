<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const AUM_WRITE_EXPECTED_ROWS = 25;

function aumw_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aumw_trim(?string $value): string
{
    return trim((string)$value);
}

function aumw_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(aumw_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function aumw_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('An Active UMS Month_Wise raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function aumw_year(?string $value): array
{
    $raw = aumw_trim($value);
    if ($raw === '' || !is_numeric($raw)) {
        return ['year' => null, 'valid' => false];
    }

    $year = (int)$raw;
    if ($year < 2000 || $year > 2100) {
        return ['year' => $year, 'valid' => false];
    }

    return ['year' => $year, 'valid' => true];
}

function aumw_month(?string $value): array
{
    $raw = aumw_trim($value);
    if ($raw === '') {
        return ['name' => null, 'number' => null, 'valid' => false];
    }

    $lookup = [
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    $key = mb_strtolower($raw, 'UTF-8');
    $number = null;
    if (isset($lookup[$key])) {
        $number = $lookup[$key];
    } elseif (is_numeric($raw)) {
        $candidate = (int)$raw;
        if ($candidate >= 1 && $candidate <= 12) {
            $number = $candidate;
        }
    }

    if ($number === null) {
        return ['name' => null, 'number' => null, 'valid' => false];
    }

    $date = DateTimeImmutable::createFromFormat('!m', (string)$number);
    return [
        'name' => $date ? $date->format('F') : $raw,
        'number' => $number,
        'valid' => true,
    ];
}

function aumw_member_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $stmt->execute([$organizationId]);

    $byName = [];
    foreach ($stmt->fetchAll() as $member) {
        $key = aumw_name_key((string)$member['full_name']);
        if ($key !== '') {
            $byName[$key][] = $member;
        }
    }
    return $byName;
}

function aumw_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Active UMS normalization audit details could not be encoded.');
    }
    return $json;
}

$error = null;
$success = null;
$result = null;
$rawRows = [];
$pendingRows = 0;
$mappedRows = 0;
$currentSnapshotCount = 0;
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

    if (!business_table_exists($pdo, 'ums_activity_snapshots')) {
        throw new RuntimeException('ums_activity_snapshots table is missing. Run migration 002_source_support_tables.sql first.');
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
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='Active UMS Month_Wise'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();

    if (count($rawRows) !== AUM_WRITE_EXPECTED_ROWS) {
        throw new RuntimeException('Active UMS Month_Wise raw row count is ' . count($rawRows) . '; expected 25. Normalization is blocked.');
    }

    foreach ($rawRows as $rawRow) {
        if ((string)$rawRow['mapping_status'] === 'pending') {
            $pendingRows++;
        } elseif ((string)$rawRow['mapping_status'] === 'mapped') {
            $mappedRows++;
        }
    }

    $snapshotCountStmt = $pdo->prepare("SELECT COUNT(*) FROM ums_activity_snapshots WHERE organization_id=? AND source_sheet='Active UMS Month_Wise'");
    $snapshotCountStmt->execute([$organizationId]);
    $currentSnapshotCount = (int)$snapshotCountStmt->fetchColumn();

    $memberIndex = aumw_member_index($pdo, $organizationId);
    foreach ($rawRows as $rawRow) {
        $values = aumw_decode_values((string)$rawRow['raw_json']);
        $nameKey = aumw_name_key($values['D'] ?? null);
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
        if (($_POST['confirm_aum_write'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the Active UMS Month_Wise normalization write before continuing.');
        }

        if ($mappedRows === AUM_WRITE_EXPECTED_ROWS && $pendingRows === 0 && $currentSnapshotCount === AUM_WRITE_EXPECTED_ROWS) {
            $success = 'Active UMS Month_Wise has already been normalized. No duplicate snapshots were created.';
            $result = [
                'created_snapshots' => 0,
                'linked_members' => 0,
                'link_later' => 0,
                'mapped_raw' => AUM_WRITE_EXPECTED_ROWS,
                'already_done' => true,
            ];
        } elseif ($pendingRows !== AUM_WRITE_EXPECTED_ROWS || $mappedRows !== 0 || $currentSnapshotCount !== 0) {
            throw new RuntimeException('Active UMS Month_Wise is in a partial or pre-existing normalized state. Write is blocked to prevent mixed normalization.');
        } else {
            $prepared = [];
            $snapshotSignatures = [];

            foreach ($rawRows as $rawRow) {
                $values = aumw_decode_values((string)$rawRow['raw_json']);
                $name = aumw_trim($values['D'] ?? null);
                $nameKey = aumw_name_key($name);
                $year = aumw_year($values['B'] ?? null);
                $month = aumw_month($values['C'] ?? null);

                if ($name === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no customer name.');
                }
                if (!$year['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid snapshot year.');
                }
                if (!$month['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid snapshot month.');
                }

                $externalId = (string)($rawRow['external_record_id'] ?? '');
                if ($externalId === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no external source key.');
                }

                $periodKey = sprintf('%04d-%02d', (int)$year['year'], (int)$month['number']);
                $signature = $nameKey . '|' . $periodKey;
                if (isset($snapshotSignatures[$signature])) {
                    throw new RuntimeException('Duplicate customer-period snapshot detected inside Active UMS Month_Wise. Normalization is blocked.');
                }
                $snapshotSignatures[$signature] = true;

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
                    'year' => (int)$year['year'],
                    'month' => (string)$month['name'],
                    'month_number' => (int)$month['number'],
                    'snapshot_date' => sprintf('%04d-%02d-01', (int)$year['year'], (int)$month['number']),
                ];
            }

            $pdo->beginTransaction();
            try {
                $snapshotInsert = $pdo->prepare(
                    "INSERT INTO ums_activity_snapshots
                     (organization_id, club_id, member_id, member_name_snapshot, snapshot_year,
                      snapshot_month, snapshot_month_number, snapshot_date, is_active,
                      source_record_id, source_sheet, source_row, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'Active UMS Month_Wise', ?, ?)"
                );

                $rawUpdate = $pdo->prepare(
                    "UPDATE raw_source_records
                     SET mapping_status='mapped', mapped_entity_type='ums_activity_snapshot', mapped_entity_id=?,
                         error_message=NULL, updated_at=NOW()
                     WHERE id=? AND mapping_status='pending'"
                );

                $createdSnapshots = 0;
                $linkedMembers = 0;
                $linkLater = 0;

                foreach ($prepared as $row) {
                    $sourceKey = 'legacy-xlsx:active-ums-month:' . $row['external_id'];

                    $snapshotInsert->execute([
                        $organizationId,
                        $clubId,
                        $row['member_id'],
                        $row['member_name'],
                        $row['year'],
                        $row['month'],
                        $row['month_number'],
                        $row['snapshot_date'],
                        $row['raw_id'],
                        $row['source_row'],
                        $sourceKey,
                    ]);
                    $snapshotId = (int)$pdo->lastInsertId();
                    $createdSnapshots++;

                    if ($row['member_id'] !== null) {
                        $linkedMembers++;
                    } else {
                        $linkLater++;
                    }

                    $rawUpdate->execute([$snapshotId, $row['raw_id']]);
                    if ($rawUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Raw mapping state changed unexpectedly at source row ' . $row['source_row'] . '.');
                    }
                }

                $auditStmt = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
                     VALUES (?, ?, 'active_ums_month_normalization_completed', 'import_batch', ?, ?, ?, ?)"
                );
                $auditStmt->execute([
                    $organizationId,
                    $clubId,
                    (int)$batch['id'],
                    aumw_json([
                        'dataset' => 'Active UMS Month_Wise',
                        'raw_rows' => AUM_WRITE_EXPECTED_ROWS,
                        'created_snapshots' => $createdSnapshots,
                        'safe_member_links' => $linkedMembers,
                        'member_link_later' => $linkLater,
                        'member_link_policy' => 'unique_exact_name_only',
                        'snapshot_semantics' => 'active in year-month',
                    ]),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);

                $pdo->commit();

                $mappedRows = AUM_WRITE_EXPECTED_ROWS;
                $pendingRows = 0;
                $currentSnapshotCount += $createdSnapshots;
                $result = [
                    'created_snapshots' => $createdSnapshots,
                    'linked_members' => $linkedMembers,
                    'link_later' => $linkLater,
                    'mapped_raw' => AUM_WRITE_EXPECTED_ROWS,
                    'already_done' => false,
                ];
                $success = 'Active UMS Month_Wise normalization completed successfully inside one transaction.';
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

$readyToWrite = $error === null
    && $pendingRows === AUM_WRITE_EXPECTED_ROWS
    && $mappedRows === 0
    && $currentSnapshotCount === 0;
$complete = $error === null
    && $mappedRows === AUM_WRITE_EXPECTED_ROWS
    && $pendingRows === 0
    && $currentSnapshotCount === AUM_WRITE_EXPECTED_ROWS;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Active UMS Month_Wise Normalization - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .aumw-warning{padding:14px 16px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.8rem;line-height:1.6}.aumw-check{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px;border:1px solid #dce8e1;border-radius:12px;background:#fff}.aumw-check input{margin-top:3px}.aumw-main{grid-column:span 7}.aumw-side{grid-column:span 5}@media(max-width:900px){.aumw-main,.aumw-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Active UMS Monthly Safe Write</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_active_ums_month_preview.php">← Preview</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8S • Active UMS Month_Wise safe write</div>
      <h1>Write only the 25 verified active-member month snapshots.</h1>
      <p>Each source row becomes one traceable activity snapshot. Year and month are re-validated before writing. Unique exact-name matches receive a Member ID; uncertain names remain unlinked while the original customer-name snapshot is preserved.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Transaction protected</span>
      <span class="imp-chip good">25 snapshots only</span>
      <span class="imp-chip <?= ($readyToWrite || $complete) ? 'good' : '' ?>"><?= $complete ? 'Normalization COMPLETE' : ($readyToWrite ? 'Ready to write' : 'Blocked') ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Normalization blocked:</strong> <?= aumw_h($error) ?></div>
    <?php elseif ($success !== null): ?>
      <div class="imp-alert good" style="grid-column:span 12"><strong>Success:</strong> <?= aumw_h($success) ?></div>
    <?php endif; ?>

    <section class="imp-summary" aria-label="Active UMS normalization state">
      <article class="imp-kpi green"><small>Raw Snapshots</small><strong><?= number_format(count($rawRows)) ?></strong><span>Expected 25</span></article>
      <article class="imp-kpi blue"><small>Pending</small><strong><?= number_format($pendingRows) ?></strong><span>Waiting for normalization</span></article>
      <article class="imp-kpi gold"><small>Mapped</small><strong><?= number_format($mappedRows) ?></strong><span>Raw trace updated</span></article>
      <article class="imp-kpi"><small>Activity Facts</small><strong><?= number_format($currentSnapshotCount) ?></strong><span>Active UMS Month_Wise rows</span></article>
    </section>

    <article class="imp-card aumw-main">
      <h2>Normalize verified monthly snapshots</h2>
      <p>This write is limited to Active UMS Month_Wise. Renewal, income, royalty and extra-order datasets remain untouched.</p>

      <?php if ($complete): ?>
        <div class="imp-alert good"><strong>Already complete:</strong> all 25 raw rows are mapped and 25 activity snapshots exist. Reloading this page will not create duplicates.</div>
      <?php elseif ($readyToWrite): ?>
        <div class="aumw-warning"><strong>Write boundary:</strong> each row records only the source fact that the named customer was active in that Year + Month. No renewal date, expiry date or relationship is inferred from this snapshot.</div>
        <form method="post" class="imp-drop">
          <label class="aumw-check">
            <input type="checkbox" name="confirm_aum_write" value="yes" required>
            <span><strong>I confirm normalization of the verified Active UMS Month_Wise dataset.</strong><br><small>Only these 25 source rows will be written to ums_activity_snapshots.</small></span>
          </label>
          <button class="imp-submit" type="submit">Normalize Active UMS Snapshots Safely →</button>
        </form>
      <?php endif; ?>

      <?php if ($result !== null): ?>
        <div class="imp-derived-list" style="margin-top:14px">
          <div class="imp-derived-item"><b>Snapshots created</b><span><?= number_format((int)$result['created_snapshots']) ?></span></div>
          <div class="imp-derived-item"><b>Safe Member links</b><span><?= number_format((int)$result['linked_members']) ?></span></div>
          <div class="imp-derived-item"><b>Member link later</b><span><?= number_format((int)$result['link_later']) ?></span></div>
          <div class="imp-derived-item"><b>Raw rows mapped</b><span><?= number_format((int)$result['mapped_raw']) ?></span></div>
        </div>
      <?php endif; ?>
    </article>

    <aside class="imp-card aumw-side">
      <h2>Write boundary</h2>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>ums_activity_snapshots</b><span>One fact per customer + month</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>Member linking</b><span>Unique exact-name only</span></div><em>SAFE</em></div>
        <div class="imp-plan-row"><div><b>Uncertain identity</b><span>Name snapshot preserved</span></div><em>LINK LATER</em></div>
        <div class="imp-plan-row"><div><b>raw_source_records</b><span>pending → mapped</span></div><em>UPDATE</em></div>
        <div class="imp-plan-row"><div><b>audit_logs</b><span>Normalization event</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>Other source datasets</b><span>No normalization in this step</span></div><em>OFF</em></div>
      </div>
    </aside>

    <div class="imp-footer-note"><strong>Traceability:</strong> every activity snapshot points back to its exact raw Excel source row. Snapshot date is stored as the first day of the source Year + Month only as a normalized period anchor; it does not claim the member became active on that specific day.</div>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
