<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const EXPECTED_NEW_UMS_ROWS = 78;

function nup_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nup_trim(?string $value): string
{
    return trim((string)$value);
}

function nup_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(nup_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

/** @return array{raw:string,key:string,state:string} */
function nup_phone(?string $value): array
{
    $raw = nup_trim($value);
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if ($digits === '') {
        return ['raw' => $raw, 'key' => '', 'state' => 'missing'];
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, -10);
    }
    if ($digits === '0000000000') {
        return ['raw' => $raw, 'key' => '', 'state' => 'placeholder'];
    }
    if (strlen($digits) === 10) {
        return ['raw' => $raw, 'key' => $digits, 'state' => 'valid'];
    }
    return ['raw' => $raw, 'key' => '', 'state' => 'invalid'];
}

/** @return array{iso:?string,valid:bool} */
function nup_excel_date(?string $value): array
{
    $raw = nup_trim($value);
    if ($raw === '') {
        return ['iso' => null, 'valid' => false];
    }
    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                return ['iso' => (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d'), 'valid' => true];
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

function nup_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A New UMS raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

$error = null;
$rows = [];
$summary = [
    'raw_rows' => 0,
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
        "SELECT id, original_file_name, status FROM import_batches
         WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture' AND status='completed'
         ORDER BY id DESC LIMIT 1"
    );
    $batchStmt->execute([$organizationId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No completed raw Excel capture batch was found.');
    }

    $stmt = $pdo->prepare(
        "SELECT id, source_row, mapping_status, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='New UMS'
         ORDER BY source_row"
    );
    $stmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $stmt->fetchAll();
    $summary['raw_rows'] = count($rawRows);

    foreach ($rawRows as $rawRow) {
        $values = nup_values((string)$rawRow['raw_json']);
        $name = nup_trim($values['F'] ?? null);
        $nameKey = nup_name_key($name);
        $phone = nup_phone($values['I'] ?? null);
        $date = nup_excel_date($values['H'] ?? null);

        $issues = [];
        $warnings = [];

        if ($name === '' || $nameKey === '') {
            $summary['missing_names']++;
            $issues[] = 'Missing member name';
        } else {
            $nameGroups[$nameKey][] = (int)$rawRow['source_row'];
        }

        if (!$date['valid']) {
            $summary['invalid_dates']++;
            $issues[] = 'Invalid UMS date';
        }

        if ($phone['state'] === 'valid') {
            $summary['valid_phones']++;
            $phoneGroups[$phone['key']][] = (int)$rawRow['source_row'];
        } elseif ($phone['state'] === 'placeholder') {
            $summary['placeholder_phones']++;
            $warnings[] = 'Placeholder mobile retained in raw source only';
        } elseif ($phone['state'] === 'missing') {
            $summary['missing_phones']++;
            $warnings[] = 'Mobile missing; source identity remains valid';
        } else {
            $summary['invalid_phones']++;
            $warnings[] = 'Mobile format not used for identity; raw value preserved';
        }

        $rows[] = [
            'source_row' => (int)$rawRow['source_row'],
            'mapping_status' => (string)$rawRow['mapping_status'],
            'name' => $name,
            'mobile_state' => $phone['state'],
            'ums_date' => $date['iso'],
            'sponsor' => nup_trim($values['G'] ?? null),
            'ums_type' => nup_trim($values['M'] ?? null),
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    $sharedPhones = array_filter($phoneGroups, static fn(array $items): bool => count($items) > 1);
    $duplicateNames = array_filter($nameGroups, static fn(array $items): bool => count($items) > 1);
    $summary['shared_phone_groups'] = count($sharedPhones);
    $summary['duplicate_name_groups'] = count($duplicateNames);

    foreach ($rows as &$row) {
        $values = nup_values((string)($rawRows[array_search($row['source_row'], array_column($rawRows, 'source_row'), true)]['raw_json'] ?? '{}'));
        $phone = nup_phone($values['I'] ?? null);
        $nameKey = nup_name_key($row['name']);
        if ($phone['state'] === 'valid' && isset($sharedPhones[$phone['key']])) {
            $row['warnings'][] = 'Mobile is shared by more than one New UMS source row; no auto-merge';
        }
        if ($nameKey !== '' && isset($duplicateNames[$nameKey])) {
            $row['warnings'][] = 'Same name appears in more than one source row; no auto-merge';
        }
    }
    unset($row);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$blockers = $summary['missing_names'] + $summary['invalid_dates'];
$ready = $error === null && $summary['raw_rows'] === EXPECTED_NEW_UMS_ROWS && $blockers === 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>New UMS Normalization Preview - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • New UMS Source Identity Preview</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="reconcile_raw.php">← Raw Integrity</a>
      <a href="normalize_new_ums.php">Write Step →</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8I • Source identity preview</div>
      <h1>Preserve source identities; use names and mobiles as clues, not unique keys.</h1>
      <p>The preview now reflects the actual workbook structure. Shared contact numbers, placeholder numbers and repeated names are warnings only. They are never used to merge people automatically.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">Source-row identity</span>
      <span class="imp-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'Normalization READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Preview could not run:</strong> <?= nup_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary">
        <article class="imp-kpi green"><small>Readiness</small><strong><?= $ready ? 'READY' : 'REVIEW' ?></strong><span><?= number_format($blockers) ?> blocker(s)</span></article>
        <article class="imp-kpi blue"><small>New UMS Rows</small><strong><?= number_format($summary['raw_rows']) ?></strong><span>Expected 78</span></article>
        <article class="imp-kpi gold"><small>Placeholder Mobiles</small><strong><?= number_format($summary['placeholder_phones']) ?></strong><span>Raw-only contact value</span></article>
        <article class="imp-kpi"><small>Shared / Same-name Groups</small><strong><?= number_format($summary['shared_phone_groups']) ?> / <?= number_format($summary['duplicate_name_groups']) ?></strong><span>Warnings, not duplicates</span></article>
      </section>

      <article class="imp-card" style="grid-column:span 12">
        <h2>Readiness policy</h2>
        <p>Only a missing member name or invalid UMS date blocks normalization. Mobile quality and repeated names remain traceable warnings.</p>
        <div class="imp-derived-list">
          <div class="imp-derived-item"><b>Missing names</b><span><?= number_format($summary['missing_names']) ?> • blocker</span></div>
          <div class="imp-derived-item"><b>Invalid dates</b><span><?= number_format($summary['invalid_dates']) ?> • blocker</span></div>
          <div class="imp-derived-item"><b>Valid mobiles</b><span><?= number_format($summary['valid_phones']) ?></span></div>
          <div class="imp-derived-item"><b>Placeholder mobiles</b><span><?= number_format($summary['placeholder_phones']) ?> • warning</span></div>
          <div class="imp-derived-item"><b>Invalid/missing mobiles</b><span><?= number_format($summary['invalid_phones'] + $summary['missing_phones']) ?> • warning</span></div>
          <div class="imp-derived-item"><b>Auto-merge</b><span>OFF until verified reconciliation</span></div>
        </div>
      </article>

      <article class="imp-card" style="grid-column:span 12">
        <h2>Source rows</h2>
        <p>Only rows with warnings/issues are highlighted below; all raw data remains preserved.</p>
        <div class="imp-plan-list">
          <?php foreach ($rows as $row): ?>
            <?php if (!$row['issues'] && !$row['warnings']) continue; ?>
            <div class="imp-plan-row">
              <div>
                <b>Row <?= (int)$row['source_row'] ?> • <?= nup_h($row['name'] !== '' ? $row['name'] : '(missing name)') ?></b>
                <span><?= nup_h(implode(' • ', array_merge($row['issues'], $row['warnings']))) ?></span>
              </div>
              <em><?= $row['issues'] ? 'CHECK' : 'SAFE WARNING' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Identity rule:</strong> the original New UMS row is the import identity. A future Member Merge/Reconciliation step can combine records only after stronger evidence confirms they represent the same person.</div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
