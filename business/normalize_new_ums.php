<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const NEW_UMS_EXPECTED_ROWS = 78;

function nuw_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nuw_trim(?string $value): string
{
    return trim((string)$value);
}

function nuw_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(nuw_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

/**
 * A mobile number is contact data, not a unique member identity.
 * Placeholder/invalid values are preserved in notes but are not written as mobile.
 *
 * @return array{raw:string,key:string,canonical:?string,state:string}
 */
function nuw_phone(?string $value): array
{
    $raw = nuw_trim($value);
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if ($digits === '') {
        return ['raw' => $raw, 'key' => '', 'canonical' => null, 'state' => 'missing'];
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, -10);
    }

    if ($digits === '0000000000') {
        return ['raw' => $raw, 'key' => '', 'canonical' => null, 'state' => 'placeholder'];
    }

    if (strlen($digits) === 10) {
        return ['raw' => $raw, 'key' => $digits, 'canonical' => '+91' . $digits, 'state' => 'valid'];
    }

    return ['raw' => $raw, 'key' => '', 'canonical' => null, 'state' => 'invalid'];
}

/** @return array{iso:?string,valid:bool} */
function nuw_excel_date(?string $value): array
{
    $raw = nuw_trim($value);
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

function nuw_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A New UMS raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function nuw_status(?string $umsType, ?string $activeFlag): string
{
    $type = mb_strtolower(nuw_trim($umsType), 'UTF-8');
    $flag = mb_strtolower(nuw_trim($activeFlag), 'UTF-8');

    if (str_contains($type, 'not active') || str_contains($type, 'inactive')) {
        return 'inactive';
    }
    if (in_array($flag, ['no', 'false', '0', 'inactive', 'not active'], true)) {
        return 'inactive';
    }
    if (str_contains($type, 'active') || in_array($flag, ['yes', 'true', '1', 'active'], true)) {
        return 'active';
    }

    return 'active';
}

function nuw_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Normalization audit details could not be encoded.');
    }
    return $json;
}

function nuw_member_source_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, source_key FROM members
         WHERE organization_id=? AND source_sheet='New UMS' AND source_key IS NOT NULL"
    );
    $stmt->execute([$organizationId]);

    $index = [];
    foreach ($stmt->fetchAll() as $row) {
        $index[(string)$row['source_key']] = (int)$row['id'];
    }
    return $index;
}

$error = null;
$success = null;
$result = null;
$context = null;
$rawRows = [];
$alreadyMapped = 0;
$pendingRows = 0;
$currentMemberCount = 0;
$currentUmsCount = 0;
$analysis = [
    'missing_names' => 0,
    'invalid_dates' => 0,
    'valid_phones' => 0,
    'placeholder_phones' => 0,
    'missing_phones' => 0,
    'invalid_phones' => 0,
    'shared_phone_groups' => 0,
    'duplicate_name_groups' => 0,
];
$phoneGroups = [];
$nameGroups = [];

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

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
    $sourceStmt->execute([$organizationId]);
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
    $batchStmt->execute([$organizationId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No completed raw Excel capture batch was found.');
    }

    $rawStmt = $pdo->prepare(
        "SELECT id, source_row, external_record_id, mapping_status, mapped_entity_type, mapped_entity_id, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='New UMS'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();

    if (count($rawRows) !== NEW_UMS_EXPECTED_ROWS) {
        throw new RuntimeException('New UMS raw row count is ' . count($rawRows) . '; expected 78. Normalization is blocked.');
    }

    foreach ($rawRows as $rawRow) {
        if ((string)$rawRow['mapping_status'] === 'mapped') {
            $alreadyMapped++;
        } elseif ((string)$rawRow['mapping_status'] === 'pending') {
            $pendingRows++;
        }

        $values = nuw_decode_values((string)$rawRow['raw_json']);
        $name = nuw_trim($values['F'] ?? null);
        $nameKey = nuw_name_key($name);
        $phone = nuw_phone($values['I'] ?? null);
        $date = nuw_excel_date($values['H'] ?? null);

        if ($name === '' || $nameKey === '') {
            $analysis['missing_names']++;
        } else {
            $nameGroups[$nameKey][] = (int)$rawRow['source_row'];
        }

        if (!$date['valid']) {
            $analysis['invalid_dates']++;
        }

        if ($phone['state'] === 'valid') {
            $analysis['valid_phones']++;
            $phoneGroups[$phone['key']][] = (int)$rawRow['source_row'];
        } elseif ($phone['state'] === 'placeholder') {
            $analysis['placeholder_phones']++;
        } elseif ($phone['state'] === 'missing') {
            $analysis['missing_phones']++;
        } else {
            $analysis['invalid_phones']++;
        }
    }

    $analysis['shared_phone_groups'] = count(array_filter($phoneGroups, static fn(array $rows): bool => count($rows) > 1));
    $analysis['duplicate_name_groups'] = count(array_filter($nameGroups, static fn(array $rows): bool => count($rows) > 1));

    $memberCountStmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE organization_id=?');
    $memberCountStmt->execute([$organizationId]);
    $currentMemberCount = (int)$memberCountStmt->fetchColumn();

    $umsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM ums_records WHERE organization_id=?');
    $umsCountStmt->execute([$organizationId]);
    $currentUmsCount = (int)$umsCountStmt->fetchColumn();

    $context = [
        'organization_id' => $organizationId,
        'organization_name' => (string)$org['organization_name'],
        'club_id' => $clubId,
        'club_name' => (string)$club['club_name'],
        'source_id' => $sourceId,
        'batch_id' => (int)$batch['id'],
        'workbook' => (string)$batch['original_file_name'],
    ];

    $hardBlockers = $analysis['missing_names'] + $analysis['invalid_dates'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['confirm_new_ums_write'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the New UMS normalization write before continuing.');
        }

        if ($hardBlockers > 0) {
            throw new RuntimeException('New UMS contains missing names or invalid UMS dates. Normalization is blocked.');
        }

        if ($alreadyMapped === NEW_UMS_EXPECTED_ROWS && $pendingRows === 0) {
            $success = 'New UMS has already been normalized. No duplicate Members or UMS records were created.';
            $result = [
                'created_members' => 0,
                'matched_members' => NEW_UMS_EXPECTED_ROWS,
                'created_ums' => 0,
                'mapped_raw' => NEW_UMS_EXPECTED_ROWS,
                'already_done' => true,
            ];
        } elseif ($pendingRows !== NEW_UMS_EXPECTED_ROWS || $alreadyMapped !== 0) {
            throw new RuntimeException('New UMS is in a partial mapping state. Write is blocked to prevent mixed normalization.');
        } else {
            $existingSourceMembers = nuw_member_source_index($pdo, $organizationId);
            $prepared = [];

            foreach ($rawRows as $rawRow) {
                $values = nuw_decode_values((string)$rawRow['raw_json']);
                $name = nuw_trim($values['F'] ?? null);
                $nameKey = nuw_name_key($name);
                $phone = nuw_phone($values['I'] ?? null);
                $date = nuw_excel_date($values['H'] ?? null);
                $externalId = (string)($rawRow['external_record_id'] ?? '');

                if ($name === '' || $nameKey === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no safe member name.');
                }
                if (!$date['valid']) {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has an invalid UMS date.');
                }
                if ($externalId === '') {
                    throw new RuntimeException('Source row ' . (int)$rawRow['source_row'] . ' has no external source key.');
                }

                $sourceBase = 'legacy-xlsx:new-ums:' . $externalId;
                $memberSourceKey = $sourceBase . ':member';

                $prepared[] = [
                    'raw_id' => (int)$rawRow['id'],
                    'source_row' => (int)$rawRow['source_row'],
                    'external_id' => $externalId,
                    'source_base' => $sourceBase,
                    'member_source_key' => $memberSourceKey,
                    'name' => $name,
                    'phone' => $phone['canonical'],
                    'phone_raw' => $phone['raw'],
                    'phone_state' => $phone['state'],
                    'ums_date' => $date['iso'],
                    'team_of' => nuw_trim($values['E'] ?? null),
                    'sponsor' => nuw_trim($values['G'] ?? null),
                    'duration' => nuw_trim($values['J'] ?? null),
                    'active_flag' => nuw_trim($values['K'] ?? null),
                    'active_supervisor' => nuw_trim($values['L'] ?? null),
                    'ums_type' => nuw_trim($values['M'] ?? null),
                    'matched_member_id' => $existingSourceMembers[$memberSourceKey] ?? null,
                ];
            }

            $pdo->beginTransaction();
            try {
                $memberInsert = $pdo->prepare(
                    "INSERT INTO members
                     (organization_id, primary_club_id, full_name, mobile, country_code, member_type,
                      join_date, status, notes, source_record_id, source_sheet, source_row, source_key)
                     VALUES (?, ?, ?, ?, 'IN', 'ums_member', ?, 'active', ?, ?, 'New UMS', ?, ?)"
                );

                $umsInsert = $pdo->prepare(
                    "INSERT INTO ums_records
                     (organization_id, club_id, member_id, set_type, start_date, status, amount,
                      currency_code, volume_points, notes, source_record_id, source_sheet, source_row, source_key)
                     VALUES (?, ?, ?, NULL, ?, ?, 0, 'INR', 0, ?, ?, 'New UMS', ?, ?)"
                );

                $rawUpdate = $pdo->prepare(
                    "UPDATE raw_source_records
                     SET mapping_status='mapped', mapped_entity_type='ums_record', mapped_entity_id=?,
                         error_message=NULL, updated_at=NOW()
                     WHERE id=? AND mapping_status='pending'"
                );

                $createdMembers = 0;
                $matchedMembers = 0;
                $createdUms = 0;

                foreach ($prepared as $row) {
                    $memberId = $row['matched_member_id'];

                    if (!$memberId) {
                        $memberNotes = [
                            'Normalized from New UMS raw source',
                            'IdentityPolicy=source-row-preserved',
                            'Sponsor/team/supervisor relationship linking deferred',
                        ];
                        if ($row['phone_state'] !== 'valid') {
                            $memberNotes[] = 'SourceMobileState=' . $row['phone_state'];
                            if ($row['phone_raw'] !== '') {
                                $memberNotes[] = 'SourceMobileRaw=' . $row['phone_raw'];
                            }
                        }

                        $memberInsert->execute([
                            $organizationId,
                            $clubId,
                            $row['name'],
                            $row['phone'],
                            $row['ums_date'],
                            implode(' | ', $memberNotes),
                            $row['raw_id'],
                            $row['source_row'],
                            $row['member_source_key'],
                        ]);
                        $memberId = (int)$pdo->lastInsertId();
                        $createdMembers++;
                    } else {
                        $matchedMembers++;
                    }

                    if (!$memberId) {
                        throw new RuntimeException('Member identity could not be resolved for source row ' . $row['source_row'] . '.');
                    }

                    $umsSourceKey = $row['source_base'] . ':ums';
                    $existingUmsStmt = $pdo->prepare('SELECT id FROM ums_records WHERE organization_id=? AND source_key=? LIMIT 1');
                    $existingUmsStmt->execute([$organizationId, $umsSourceKey]);
                    $existingUmsId = (int)$existingUmsStmt->fetchColumn();
                    if ($existingUmsId > 0) {
                        throw new RuntimeException('A UMS source record already exists for source row ' . $row['source_row'] . ' while raw mapping is pending.');
                    }

                    $umsNotesParts = [
                        'Legacy New UMS normalization',
                        'IdentityPolicy=source-row-preserved',
                        'Duration=' . ($row['duration'] !== '' ? $row['duration'] : 'blank'),
                        'Sponsor=' . ($row['sponsor'] !== '' ? $row['sponsor'] : 'blank'),
                        'Team=' . ($row['team_of'] !== '' ? $row['team_of'] : 'blank'),
                        'ActiveSupervisor=' . ($row['active_supervisor'] !== '' ? $row['active_supervisor'] : 'blank'),
                        'SourceActiveFlag=' . ($row['active_flag'] !== '' ? $row['active_flag'] : 'blank'),
                        'SourceUmsType=' . ($row['ums_type'] !== '' ? $row['ums_type'] : 'blank'),
                    ];

                    $umsInsert->execute([
                        $organizationId,
                        $clubId,
                        $memberId,
                        $row['ums_date'],
                        nuw_status($row['ums_type'], $row['active_flag']),
                        implode(' | ', $umsNotesParts),
                        $row['raw_id'],
                        $row['source_row'],
                        $umsSourceKey,
                    ]);
                    $umsId = (int)$pdo->lastInsertId();
                    $createdUms++;

                    $rawUpdate->execute([$umsId, $row['raw_id']]);
                    if ($rawUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Raw mapping state changed unexpectedly at source row ' . $row['source_row'] . '.');
                    }
                }

                $auditStmt = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
                     VALUES (?, ?, 'new_ums_normalization_completed', 'import_batch', ?, ?, ?, ?)"
                );
                $auditStmt->execute([
                    $organizationId,
                    $clubId,
                    (int)$batch['id'],
                    nuw_json([
                        'dataset' => 'New UMS',
                        'raw_rows' => NEW_UMS_EXPECTED_ROWS,
                        'created_members' => $createdMembers,
                        'matched_source_members' => $matchedMembers,
                        'created_ums_records' => $createdUms,
                        'identity_policy' => 'source-row-preserved; name/mobile not unique',
                        'valid_mobile_rows' => $analysis['valid_phones'],
                        'placeholder_mobile_rows' => $analysis['placeholder_phones'],
                        'missing_mobile_rows' => $analysis['missing_phones'],
                        'invalid_mobile_rows' => $analysis['invalid_phones'],
                        'shared_valid_mobile_groups' => $analysis['shared_phone_groups'],
                        'duplicate_name_groups' => $analysis['duplicate_name_groups'],
                        'relationship_linking' => 'deferred',
                    ]),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);

                $pdo->commit();

                $result = [
                    'created_members' => $createdMembers,
                    'matched_members' => $matchedMembers,
                    'created_ums' => $createdUms,
                    'mapped_raw' => NEW_UMS_EXPECTED_ROWS,
                    'already_done' => false,
                ];
                $success = 'New UMS normalization completed successfully using source-row identity preservation.';
                $alreadyMapped = NEW_UMS_EXPECTED_ROWS;
                $pendingRows = 0;

                $memberCountStmt->execute([$organizationId]);
                $currentMemberCount = (int)$memberCountStmt->fetchColumn();
                $umsCountStmt->execute([$organizationId]);
                $currentUmsCount = (int)$umsCountStmt->fetchColumn();
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

$hardBlockers = $analysis['missing_names'] + $analysis['invalid_dates'];
$readyToWrite = $error === null && $hardBlockers === 0 && $pendingRows === NEW_UMS_EXPECTED_ROWS && $alreadyMapped === 0;
$complete = $error === null && $alreadyMapped === NEW_UMS_EXPECTED_ROWS && $pendingRows === 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>New UMS Normalization - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .nuw-warning{padding:14px 16px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.8rem;line-height:1.6}.nuw-info{padding:14px 16px;border:1px solid #cfe1f5;border-radius:13px;background:#f3f8ff;color:#315b84;font-size:.8rem;line-height:1.6}.nuw-check{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px;border:1px solid #dce8e1;border-radius:12px;background:#fff}.nuw-check input{margin-top:3px}.nuw-boundary{grid-column:span 5}.nuw-write{grid-column:span 7}@media(max-width:900px){.nuw-boundary,.nuw-write{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • New UMS Safe Normalization</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="normalize_new_ums_preview.php">← Preview</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8J • First normalized write</div>
      <h1>Preserve every verified New UMS source identity without guessing merges.</h1>
      <p>Each of the 78 reviewed source rows is treated as its own source identity. Names and mobile numbers are contact/matching clues, not global unique keys. Shared numbers, placeholder numbers and same-name records therefore remain separate unless a later verified merge explicitly links them.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Transaction protected</span>
      <span class="imp-chip good">Source-row identity</span>
      <span class="imp-chip <?= ($readyToWrite || $complete) ? 'good' : '' ?>"><?= $complete ? 'Normalization COMPLETE' : ($readyToWrite ? 'Ready to write' : 'Blocked') ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Normalization blocked:</strong> <?= nuw_h($error) ?></div>
    <?php elseif ($success !== null): ?>
      <div class="imp-alert good" style="grid-column:span 12"><strong>Success:</strong> <?= nuw_h($success) ?></div>
    <?php endif; ?>

    <section class="imp-summary" aria-label="New UMS normalization state">
      <article class="imp-kpi green"><small>Raw New UMS</small><strong><?= number_format(count($rawRows)) ?></strong><span>Expected 78</span></article>
      <article class="imp-kpi blue"><small>Pending</small><strong><?= number_format($pendingRows) ?></strong><span>Waiting for normalization</span></article>
      <article class="imp-kpi gold"><small>Mapped</small><strong><?= number_format($alreadyMapped) ?></strong><span>Raw trace updated</span></article>
      <article class="imp-kpi"><small>Members / UMS</small><strong><?= number_format($currentMemberCount) ?> / <?= number_format($currentUmsCount) ?></strong><span>Current organization totals</span></article>
    </section>

    <article class="imp-card nuw-write">
      <h2>Normalize verified New UMS rows</h2>
      <p>This first normalized write remains limited to Members + UMS for the New UMS source. The other seven source sheets stay untouched.</p>

      <div class="nuw-info" style="margin-top:14px"><strong>Identity policy corrected:</strong> shared mobile numbers and repeated names are not proof that records are duplicates. Placeholder/invalid mobile values are retained in raw trace/notes but are not stored as a usable contact number.</div>

      <div class="imp-derived-list" style="margin-top:14px">
        <div class="imp-derived-item"><b>Valid mobile rows</b><span><?= number_format($analysis['valid_phones']) ?></span></div>
        <div class="imp-derived-item"><b>Placeholder mobile rows</b><span><?= number_format($analysis['placeholder_phones']) ?></span></div>
        <div class="imp-derived-item"><b>Shared valid mobile groups</b><span><?= number_format($analysis['shared_phone_groups']) ?> • warning only</span></div>
        <div class="imp-derived-item"><b>Repeated exact-name groups</b><span><?= number_format($analysis['duplicate_name_groups']) ?> • warning only</span></div>
        <div class="imp-derived-item"><b>Missing names</b><span><?= number_format($analysis['missing_names']) ?> • blocker</span></div>
        <div class="imp-derived-item"><b>Invalid UMS dates</b><span><?= number_format($analysis['invalid_dates']) ?> • blocker</span></div>
      </div>

      <?php if ($complete): ?>
        <div class="imp-alert good"><strong>Already complete:</strong> all 78 New UMS raw rows are mapped. Running this page again will not create duplicate records.</div>
      <?php elseif ($readyToWrite): ?>
        <div class="nuw-warning" style="margin-top:14px"><strong>Write boundary:</strong> each source row gets a source-key protected member identity and one UMS record. No name/mobile-based auto-merge will occur in this step.</div>
        <form method="post" class="imp-drop">
          <label class="nuw-check">
            <input type="checkbox" name="confirm_new_ums_write" value="yes" required>
            <span><strong>I confirm source-preserving normalization of the New UMS dataset.</strong><br><small>Shared phones/repeated names remain separate; verified merging can be done later.</small></span>
          </label>
          <button class="imp-submit" type="submit">Normalize New UMS Safely →</button>
        </form>
      <?php endif; ?>

      <?php if ($result !== null): ?>
        <div class="imp-derived-list" style="margin-top:14px">
          <div class="imp-derived-item"><b>Members created</b><span><?= number_format((int)$result['created_members']) ?></span></div>
          <div class="imp-derived-item"><b>Source members matched</b><span><?= number_format((int)$result['matched_members']) ?></span></div>
          <div class="imp-derived-item"><b>UMS records created</b><span><?= number_format((int)$result['created_ums']) ?></span></div>
          <div class="imp-derived-item"><b>Raw rows mapped</b><span><?= number_format((int)$result['mapped_raw']) ?></span></div>
        </div>
      <?php endif; ?>
    </article>

    <aside class="imp-card nuw-boundary">
      <h2>Write boundary</h2>
      <p>Step 8J remains transaction-safe and deliberately avoids inferred identity merges.</p>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>members</b><span>One source identity per New UMS row</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>ums_records</b><span>One source-linked UMS record</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>raw_source_records</b><span>pending → mapped</span></div><em>UPDATE</em></div>
        <div class="imp-plan-row"><div><b>audit_logs</b><span>Policy + normalization audit</span></div><em>WRITE</em></div>
        <div class="imp-plan-row"><div><b>Name/mobile auto-merge</b><span>Unsafe without stronger identity proof</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Other 7 source sheets</b><span>No normalization yet</span></div><em>OFF</em></div>
      </div>
    </aside>

    <div class="imp-footer-note"><strong>Traceability:</strong> each Member and UMS record retains its original New UMS raw source row. Sponsor, Team and Active Supervisor relationship IDs remain deferred. A later reconciliation module can merge duplicate identities only after stronger evidence is available.</div>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
