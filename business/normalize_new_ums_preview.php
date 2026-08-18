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
    $value = preg_replace('/\s+/u', ' ', mb_strtolower(nup_trim($value), 'UTF-8'));
    return $value === null ? '' : $value;
}

function nup_phone(?string $value): array
{
    $raw = nup_trim($value);
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return ['raw' => $raw, 'digits' => $digits, 'key' => substr($digits, -10), 'valid' => true];
    }
    if (strlen($digits) === 10) {
        return ['raw' => $raw, 'digits' => $digits, 'key' => $digits, 'valid' => true];
    }
    if ($digits === '') {
        return ['raw' => $raw, 'digits' => '', 'key' => '', 'valid' => false];
    }

    return ['raw' => $raw, 'digits' => $digits, 'key' => '', 'valid' => false];
}

function nup_excel_date(?string $value): array
{
    $raw = nup_trim($value);
    if ($raw === '') {
        return ['raw' => $raw, 'iso' => null, 'valid' => false];
    }

    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days');
                return ['raw' => $raw, 'iso' => $date->format('Y-m-d'), 'valid' => true];
            } catch (Throwable) {
                return ['raw' => $raw, 'iso' => null, 'valid' => false];
            }
        }
    }

    try {
        $date = new DateTimeImmutable($raw);
        return ['raw' => $raw, 'iso' => $date->format('Y-m-d'), 'valid' => true];
    } catch (Throwable) {
        return ['raw' => $raw, 'iso' => null, 'valid' => false];
    }
}

function nup_decode_row(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A New UMS raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function nup_existing_member_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, mobile, source_key FROM members WHERE organization_id=? ORDER BY id');
    $stmt->execute([$organizationId]);

    $byPhone = [];
    $byName = [];
    foreach ($stmt->fetchAll() as $member) {
        $phone = nup_phone((string)($member['mobile'] ?? ''));
        $nameKey = nup_name_key((string)($member['full_name'] ?? ''));
        if ($phone['valid']) {
            $byPhone[$phone['key']][] = $member;
        }
        if ($nameKey !== '') {
            $byName[$nameKey][] = $member;
        }
    }

    return ['by_phone' => $byPhone, 'by_name' => $byName];
}

$error = null;
$batch = null;
$rows = [];
$checks = [];
$summary = [
    'raw_rows' => 0,
    'safe_create' => 0,
    'safe_match' => 0,
    'review' => 0,
    'missing_name' => 0,
    'invalid_phone' => 0,
    'invalid_date' => 0,
    'duplicate_phone_groups' => 0,
    'duplicate_name_groups' => 0,
];
$statusCounts = [];
$activeFlagCounts = [];

try {
    $pdo = business_db();

    $orgStmt = $pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1");
    $organizationId = (int)$orgStmt->fetchColumn();
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
        "SELECT id, original_file_name, file_sha256, status, imported_rows, failed_rows, completed_at
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
        "SELECT id, source_row, external_record_id, record_hash, mapping_status, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='New UMS'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();
    $summary['raw_rows'] = count($rawRows);

    $existing = nup_existing_member_index($pdo, $organizationId);
    $sourcePhoneGroups = [];
    $sourceNameGroups = [];

    foreach ($rawRows as $rawRow) {
        $values = nup_decode_row((string)$rawRow['raw_json']);
        $name = nup_trim($values['F'] ?? null);
        $nameKey = nup_name_key($name);
        $phone = nup_phone($values['I'] ?? null);
        $date = nup_excel_date($values['H'] ?? null);

        if ($phone['valid']) {
            $sourcePhoneGroups[$phone['key']][] = (int)$rawRow['id'];
        }
        if ($nameKey !== '') {
            $sourceNameGroups[$nameKey][] = (int)$rawRow['id'];
        }

        $statusLabel = nup_trim($values['M'] ?? null);
        $activeFlag = nup_trim($values['K'] ?? null);
        $statusCounts[$statusLabel !== '' ? $statusLabel : '(blank)'] = ($statusCounts[$statusLabel !== '' ? $statusLabel : '(blank)'] ?? 0) + 1;
        $activeFlagCounts[$activeFlag !== '' ? $activeFlag : '(blank)'] = ($activeFlagCounts[$activeFlag !== '' ? $activeFlag : '(blank)'] ?? 0) + 1;

        $issues = [];
        if ($name === '') {
            $issues[] = 'Missing member name';
            $summary['missing_name']++;
        }
        if (!$phone['valid']) {
            $issues[] = $phone['digits'] === '' ? 'Mobile number missing' : 'Mobile format needs review';
            $summary['invalid_phone']++;
        }
        if (!$date['valid']) {
            $issues[] = 'UMS date needs review';
            $summary['invalid_date']++;
        }

        $action = 'CREATE';
        $matchedMemberId = null;

        if ($phone['valid'] && isset($existing['by_phone'][$phone['key']])) {
            $candidates = $existing['by_phone'][$phone['key']];
            if (count($candidates) === 1) {
                $candidate = $candidates[0];
                $candidateNameKey = nup_name_key((string)$candidate['full_name']);
                if ($nameKey !== '' && $candidateNameKey !== $nameKey) {
                    $issues[] = 'Existing mobile belongs to a different member name';
                    $action = 'REVIEW';
                } else {
                    $action = 'MATCH';
                    $matchedMemberId = (int)$candidate['id'];
                }
            } else {
                $issues[] = 'Mobile matches multiple existing members';
                $action = 'REVIEW';
            }
        } elseif ($nameKey !== '' && isset($existing['by_name'][$nameKey])) {
            $candidates = $existing['by_name'][$nameKey];
            if (count($candidates) === 1) {
                $candidate = $candidates[0];
                $candidatePhone = nup_phone((string)($candidate['mobile'] ?? ''));
                if ($phone['valid'] && $candidatePhone['valid'] && $candidatePhone['key'] !== $phone['key']) {
                    $issues[] = 'Existing exact name has a different mobile number';
                    $action = 'REVIEW';
                } else {
                    $action = 'MATCH';
                    $matchedMemberId = (int)$candidate['id'];
                }
            } else {
                $issues[] = 'Exact name matches multiple existing members';
                $action = 'REVIEW';
            }
        }

        if ($issues) {
            $action = 'REVIEW';
        }

        $rows[] = [
            'raw_id' => (int)$rawRow['id'],
            'source_row' => (int)$rawRow['source_row'],
            'external_record_id' => (string)($rawRow['external_record_id'] ?? ''),
            'mapping_status' => (string)$rawRow['mapping_status'],
            'name' => $name,
            'name_key' => $nameKey,
            'mobile_raw' => $phone['raw'],
            'mobile_key' => $phone['key'],
            'mobile_valid' => (bool)$phone['valid'],
            'ums_date' => $date['iso'],
            'ums_date_valid' => (bool)$date['valid'],
            'sponsor' => nup_trim($values['G'] ?? null),
            'team_of' => nup_trim($values['E'] ?? null),
            'active_supervisor' => nup_trim($values['L'] ?? null),
            'ums_type' => $statusLabel,
            'active_flag' => $activeFlag,
            'duration' => nup_trim($values['J'] ?? null),
            'action' => $action,
            'matched_member_id' => $matchedMemberId,
            'issues' => $issues,
        ];
    }

    $duplicatePhones = array_filter($sourcePhoneGroups, static fn(array $ids): bool => count($ids) > 1);
    $duplicateNames = array_filter($sourceNameGroups, static fn(array $ids): bool => count($ids) > 1);
    $summary['duplicate_phone_groups'] = count($duplicatePhones);
    $summary['duplicate_name_groups'] = count($duplicateNames);

    if ($duplicatePhones || $duplicateNames) {
        foreach ($rows as &$row) {
            if ($row['mobile_key'] !== '' && isset($duplicatePhones[$row['mobile_key']])) {
                $row['issues'][] = 'Same mobile appears more than once in New UMS source';
                $row['action'] = 'REVIEW';
            }
            if ($row['name_key'] !== '' && isset($duplicateNames[$row['name_key']])) {
                $row['issues'][] = 'Same member name appears more than once in New UMS source';
                $row['action'] = 'REVIEW';
            }
        }
        unset($row);
    }

    foreach ($rows as $row) {
        if ($row['action'] === 'CREATE') {
            $summary['safe_create']++;
        } elseif ($row['action'] === 'MATCH') {
            $summary['safe_match']++;
        } else {
            $summary['review']++;
        }
    }

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'New UMS raw rows = 78' => $summary['raw_rows'] === EXPECTED_NEW_UMS_ROWS,
        'Every New UMS row still pending normalization' => count(array_filter($rows, static fn(array $row): bool => $row['mapping_status'] !== 'pending')) === 0,
        'No missing member names' => $summary['missing_name'] === 0,
        'All mobile numbers are safely comparable' => $summary['invalid_phone'] === 0,
        'All UMS dates are valid' => $summary['invalid_date'] === 0,
        'No duplicate mobile groups in New UMS source' => $summary['duplicate_phone_groups'] === 0,
        'No duplicate exact-name groups in New UMS source' => $summary['duplicate_name_groups'] === 0,
        'No rows require identity review' => $summary['review'] === 0,
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
  <title>New UMS Normalization Preview - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • New UMS Normalization Preview</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="reconcile_raw.php">← Raw Integrity</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8I • New UMS identity preview</div>
      <h1>Resolve member identity before creating Members and UMS records.</h1>
      <p>This is a read-only normalization preview for the 78 New UMS source rows. Mobile numbers, exact names and UMS dates are checked conservatively. Sponsor and supervisor names are preserved for a later relationship-linking pass and are not guessed here.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">New UMS only</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Normalization READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert" style="grid-column:span 12"><strong>Preview could not run:</strong> <?= nup_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="New UMS normalization summary">
        <article class="imp-kpi green"><small>Readiness</small><strong><?= $allPass ? 'READY' : 'REVIEW' ?></strong><span>Read-only identity analysis</span></article>
        <article class="imp-kpi blue"><small>New UMS Rows</small><strong><?= number_format($summary['raw_rows']) ?></strong><span>Expected <?= EXPECTED_NEW_UMS_ROWS ?></span></article>
        <article class="imp-kpi gold"><small>Create / Match</small><strong><?= number_format($summary['safe_create']) ?> / <?= number_format($summary['safe_match']) ?></strong><span>Safe identity actions</span></article>
        <article class="imp-kpi"><small>Needs Review</small><strong><?= number_format($summary['review']) ?></strong><span>Must be zero before write</span></article>
      </section>

      <article class="imp-card" style="grid-column:span 7">
        <h2>Readiness checks</h2>
        <p>Normalization remains locked if any identity or source-quality check fails.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row">
              <div><b><?= nup_h($label) ?></b><span><?= $pass ? 'Verified' : 'Needs review before Member/UMS write' ?></span></div>
              <em><?= $pass ? 'PASS' : 'CHECK' ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card" style="grid-column:span 5">
        <h2>Source quality</h2>
        <div class="imp-derived-list" style="grid-template-columns:1fr 1fr">
          <div class="imp-derived-item"><b>Missing names</b><span><?= number_format($summary['missing_name']) ?></span></div>
          <div class="imp-derived-item"><b>Mobile review</b><span><?= number_format($summary['invalid_phone']) ?></span></div>
          <div class="imp-derived-item"><b>Date review</b><span><?= number_format($summary['invalid_date']) ?></span></div>
          <div class="imp-derived-item"><b>Duplicate mobile groups</b><span><?= number_format($summary['duplicate_phone_groups']) ?></span></div>
          <div class="imp-derived-item"><b>Duplicate name groups</b><span><?= number_format($summary['duplicate_name_groups']) ?></span></div>
          <div class="imp-derived-item"><b>Raw batch</b><span>#<?= (int)$batch['id'] ?> • <?= nup_h((string)$batch['original_file_name']) ?></span></div>
        </div>
      </aside>

      <article class="imp-card imp-derived">
        <h2>UMS source labels preserved</h2>
        <p>These values are shown exactly as source categories. No business meaning is being invented during identity normalization.</p>
        <div class="imp-derived-list">
          <?php foreach ($statusCounts as $label => $count): ?>
            <div class="imp-derived-item"><b><?= nup_h($label) ?></b><span>UMS Type • <?= number_format($count) ?> row(s)</span></div>
          <?php endforeach; ?>
          <?php foreach ($activeFlagCounts as $label => $count): ?>
            <div class="imp-derived-item"><b><?= nup_h($label) ?></b><span>Active/Inactive • <?= number_format($count) ?> row(s)</span></div>
          <?php endforeach; ?>
        </div>
      </article>

      <?php if (!$allPass): ?>
        <article class="imp-card imp-derived">
          <h2>Rows requiring review</h2>
          <p>Only rows with a conservative identity/data-quality concern are shown here.</p>
          <div class="imp-table-wrap">
            <table class="imp-table">
              <thead><tr><th>Excel Row</th><th>Name</th><th>Mobile</th><th>UMS Date</th><th>Action</th><th>Reason</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $row): if ($row['action'] !== 'REVIEW') continue; ?>
                <tr>
                  <td><?= (int)$row['source_row'] ?></td>
                  <td><?= nup_h($row['name']) ?></td>
                  <td><?= nup_h($row['mobile_raw']) ?></td>
                  <td><?= nup_h($row['ums_date'] ?? '—') ?></td>
                  <td>REVIEW</td>
                  <td><?= nup_h(implode('; ', $row['issues'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endif; ?>

      <div class="imp-footer-note">
        <strong>Safety rule:</strong> this page performs no INSERT, UPDATE or DELETE. A later step will create Members and UMS records only after this preview is clean. Sponsor, Team of and Active Supervisor fields remain source metadata until member identities exist and relationship linking can be verified separately.
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
