<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const ACTIVE_UMS_EXPECTED_ROWS = 25;

function aum_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aum_trim(?string $value): string
{
    return trim((string)$value);
}

function aum_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(aum_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function aum_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('An Active UMS Month_Wise raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function aum_year(?string $value): array
{
    $raw = aum_trim($value);
    if ($raw === '' || !is_numeric($raw)) {
        return ['raw' => $raw, 'year' => null, 'valid' => false];
    }

    $year = (int)$raw;
    if ($year < 2000 || $year > 2100) {
        return ['raw' => $raw, 'year' => $year, 'valid' => false];
    }

    return ['raw' => $raw, 'year' => $year, 'valid' => true];
}

function aum_month(?string $value): array
{
    $raw = aum_trim($value);
    if ($raw === '') {
        return ['raw' => '', 'name' => null, 'number' => null, 'valid' => false];
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
    if (isset($lookup[$key])) {
        $number = $lookup[$key];
        $date = DateTimeImmutable::createFromFormat('!m', (string)$number);
        return [
            'raw' => $raw,
            'name' => $date ? $date->format('F') : $raw,
            'number' => $number,
            'valid' => true,
        ];
    }

    if (is_numeric($raw)) {
        $number = (int)$raw;
        if ($number >= 1 && $number <= 12) {
            $date = DateTimeImmutable::createFromFormat('!m', (string)$number);
            return [
                'raw' => $raw,
                'name' => $date ? $date->format('F') : $raw,
                'number' => $number,
                'valid' => true,
            ];
        }
    }

    return ['raw' => $raw, 'name' => null, 'number' => null, 'valid' => false];
}

$error = null;
$batch = null;
$checks = [];
$rows = [];
$memberNames = [];
$unmatchedNames = [];
$ambiguousNames = [];
$periodCounts = [];
$summary = [
    'raw_rows' => 0,
    'pending_rows' => 0,
    'mapped_rows' => 0,
    'missing_names' => 0,
    'invalid_years' => 0,
    'invalid_months' => 0,
    'safe_member_links' => 0,
    'link_later' => 0,
    'duplicate_snapshot_groups' => 0,
    'existing_snapshot_rows' => 0,
];

try {
    $pdo = business_db();

    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

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

    $memberStmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $memberStmt->execute([$organizationId]);
    foreach ($memberStmt->fetchAll() as $member) {
        $key = aum_name_key((string)$member['full_name']);
        if ($key !== '') {
            $memberNames[$key][] = $member;
        }
    }

    $existingStmt = $pdo->prepare("SELECT COUNT(*) FROM ums_activity_snapshots WHERE organization_id=? AND source_sheet='Active UMS Month_Wise'");
    $existingStmt->execute([$organizationId]);
    $summary['existing_snapshot_rows'] = (int)$existingStmt->fetchColumn();

    $rawStmt = $pdo->prepare(
        "SELECT id, source_row, external_record_id, mapping_status, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='Active UMS Month_Wise'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();
    $summary['raw_rows'] = count($rawRows);

    $snapshotSignatures = [];

    foreach ($rawRows as $rawRow) {
        $values = aum_decode_values((string)$rawRow['raw_json']);
        $name = aum_trim($values['D'] ?? null);
        $nameKey = aum_name_key($name);
        $year = aum_year($values['B'] ?? null);
        $month = aum_month($values['C'] ?? null);

        if ((string)$rawRow['mapping_status'] === 'pending') {
            $summary['pending_rows']++;
        } elseif ((string)$rawRow['mapping_status'] === 'mapped') {
            $summary['mapped_rows']++;
        }

        $issues = [];
        $memberState = 'UNMATCHED';
        $memberId = null;

        if ($name === '') {
            $summary['missing_names']++;
            $issues[] = 'Customer name missing';
        } elseif (!isset($memberNames[$nameKey])) {
            $summary['link_later']++;
            $unmatchedNames[$name] = ($unmatchedNames[$name] ?? 0) + 1;
        } elseif (count($memberNames[$nameKey]) === 1) {
            $summary['safe_member_links']++;
            $memberState = 'MATCH';
            $memberId = (int)$memberNames[$nameKey][0]['id'];
        } else {
            $summary['link_later']++;
            $memberState = 'AMBIGUOUS';
            $ambiguousNames[$name] = ($ambiguousNames[$name] ?? 0) + 1;
        }

        if (!$year['valid']) {
            $summary['invalid_years']++;
            $issues[] = 'Invalid snapshot year';
        }
        if (!$month['valid']) {
            $summary['invalid_months']++;
            $issues[] = 'Invalid snapshot month';
        }

        $snapshotDate = null;
        if ($year['valid'] && $month['valid']) {
            $snapshotDate = sprintf('%04d-%02d-01', (int)$year['year'], (int)$month['number']);
            $periodKey = sprintf('%04d-%02d', (int)$year['year'], (int)$month['number']);
            $periodCounts[$periodKey] = ($periodCounts[$periodKey] ?? 0) + 1;

            if ($nameKey !== '') {
                $signature = $nameKey . '|' . $periodKey;
                $snapshotSignatures[$signature] = ($snapshotSignatures[$signature] ?? 0) + 1;
            }
        }

        $rows[] = [
            'source_row' => (int)$rawRow['source_row'],
            'name' => $name,
            'member_state' => $memberState,
            'member_id' => $memberId,
            'year' => $year['year'],
            'month' => $month['name'],
            'month_number' => $month['number'],
            'snapshot_date' => $snapshotDate,
            'issues' => $issues,
        ];
    }

    $summary['duplicate_snapshot_groups'] = count(array_filter(
        $snapshotSignatures,
        static fn(int $count): bool => $count > 1
    ));

    ksort($unmatchedNames, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($ambiguousNames, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($periodCounts);

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'Active UMS Month_Wise raw rows = 25' => $summary['raw_rows'] === ACTIVE_UMS_EXPECTED_ROWS,
        'All 25 rows are still pending normalization' => $summary['pending_rows'] === ACTIVE_UMS_EXPECTED_ROWS && $summary['mapped_rows'] === 0,
        'No missing customer names' => $summary['missing_names'] === 0,
        'All snapshot years are valid' => $summary['invalid_years'] === 0,
        'All snapshot months are valid' => $summary['invalid_months'] === 0,
        'No duplicate customer-period snapshots' => $summary['duplicate_snapshot_groups'] === 0,
        'Dedicated ums_activity_snapshots table exists' => business_table_exists($pdo, 'ums_activity_snapshots'),
        'No prior normalized rows exist for this dataset' => $summary['existing_snapshot_rows'] === 0,
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
  <title>Active UMS Month_Wise Preview - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .aum-wide{grid-column:span 12}.aum-main{grid-column:span 7}.aum-side{grid-column:span 5}.aum-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:.74rem}.aum-table th,.aum-table td{padding:9px 10px;border-bottom:1px solid #e9efeb;text-align:left}.aum-table th{color:#607169;font-size:.67rem;text-transform:uppercase}.aum-tag{display:inline-flex;padding:5px 8px;border-radius:8px;background:#eef7f1;color:#32604b;font-size:.68rem;font-weight:800}.aum-tag.warn{background:#fff7e7;color:#815d19}@media(max-width:900px){.aum-main,.aum-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Active UMS Monthly Preview</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="reconcile_first_second_set.php">← First & Second Set</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8R • Active UMS Month_Wise preview</div>
      <h1>Validate 25 monthly active-member snapshots before writing the activity fact table.</h1>
      <p>Each row represents that a named customer was active in a specific Year + Month. Member linking is conservative: only one exact-name match gets a Member ID; unmatched or ambiguous names keep their source-name snapshot without guessing.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">25 source snapshots</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Normalization READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert aum-wide"><strong>Preview could not run:</strong> <?= aum_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="Active UMS preview summary">
        <article class="imp-kpi green"><small>Readiness</small><strong><?= $allPass ? 'READY' : 'REVIEW' ?></strong><span>Read-only validation</span></article>
        <article class="imp-kpi blue"><small>Raw Snapshots</small><strong><?= number_format($summary['raw_rows']) ?></strong><span>Expected 25</span></article>
        <article class="imp-kpi gold"><small>Safe Member Links</small><strong><?= number_format($summary['safe_member_links']) ?></strong><span>Unique exact-name matches</span></article>
        <article class="imp-kpi"><small>Link Later</small><strong><?= number_format($summary['link_later']) ?></strong><span>Name snapshot preserved</span></article>
      </section>

      <article class="imp-card aum-main">
        <h2>Readiness checks</h2>
        <p>Structural period validity and duplicate customer-period snapshots are blockers. Member-link uncertainty is not a blocker because the original customer name remains preserved.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row"><div><b><?= aum_h($label) ?></b><span><?= $pass ? 'Verified' : 'Must be resolved before write' ?></span></div><em><?= $pass ? 'PASS' : 'CHECK' ?></em></div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card aum-side">
        <h2>Snapshot policy</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Year + Month</b><span>Converted to first-day snapshot date</span></div><em>FACT</em></div>
          <div class="imp-plan-row"><div><b>Customer Name</b><span>Always preserved as snapshot</span></div><em>KEEP</em></div>
          <div class="imp-plan-row"><div><b>Member ID</b><span>Unique exact-name match only</span></div><em>SAFE</em></div>
          <div class="imp-plan-row"><div><b>Activity flag</b><span>Each source row means active</span></div><em>TRUE</em></div>
          <div class="imp-plan-row"><div><b>Duplicate same customer/month</b><span>Would over-count active membership</span></div><em><?= number_format($summary['duplicate_snapshot_groups']) ?></em></div>
        </div>
      </aside>

      <article class="imp-card aum-main">
        <h2>Period distribution</h2>
        <p>Reviewed rows grouped by normalized Year-Month.</p>
        <div class="imp-plan-list">
          <?php foreach ($periodCounts as $period => $count): ?>
            <div class="imp-plan-row"><div><b><?= aum_h($period) ?></b><span>Active member snapshots</span></div><em><?= number_format($count) ?></em></div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card aum-side">
        <h2>Member-linking summary</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Safe exact-name links</b><span>Member ID can be stored</span></div><em><?= number_format($summary['safe_member_links']) ?></em></div>
          <div class="imp-plan-row"><div><b>Link later</b><span>Unmatched/ambiguous identity</span></div><em><?= number_format($summary['link_later']) ?></em></div>
          <div class="imp-plan-row"><div><b>Missing names</b><span>Normalization blocker</span></div><em><?= number_format($summary['missing_names']) ?></em></div>
          <div class="imp-plan-row"><div><b>Invalid periods</b><span>Year + month errors</span></div><em><?= number_format($summary['invalid_years'] + $summary['invalid_months']) ?></em></div>
        </div>
      </aside>

      <?php if ($unmatchedNames || $ambiguousNames): ?>
        <article class="imp-card aum-wide">
          <h2>Names to link later</h2>
          <p>These rows remain valid source facts. No identity is guessed.</p>
          <div style="overflow:auto">
            <table class="aum-table">
              <thead><tr><th>Name</th><th>State</th><th>Rows</th></tr></thead>
              <tbody>
              <?php foreach ($unmatchedNames as $name => $count): ?>
                <tr><td><?= aum_h($name) ?></td><td><span class="aum-tag warn">UNMATCHED</span></td><td><?= number_format($count) ?></td></tr>
              <?php endforeach; ?>
              <?php foreach ($ambiguousNames as $name => $count): ?>
                <tr><td><?= aum_h($name) ?></td><td><span class="aum-tag warn">AMBIGUOUS</span></td><td><?= number_format($count) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endif; ?>

      <article class="imp-card aum-wide">
        <h2>Row sample</h2>
        <p>First 25 normalized-preview rows. This page performs no writes.</p>
        <div style="overflow:auto">
          <table class="aum-table">
            <thead><tr><th>Row</th><th>Customer</th><th>Period</th><th>Snapshot Date</th><th>Member Link</th><th>Issues</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= (int)$row['source_row'] ?></td>
                <td><?= aum_h($row['name']) ?></td>
                <td><?= aum_h(($row['year'] ?? '—') . ' ' . ($row['month'] ?? '—')) ?></td>
                <td><?= aum_h($row['snapshot_date'] ?? '—') ?></td>
                <td><span class="aum-tag <?= $row['member_state'] === 'MATCH' ? '' : 'warn' ?>"><?= aum_h($row['member_state']) ?></span></td>
                <td><?= $row['issues'] ? aum_h(implode('; ', $row['issues'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Next boundary:</strong> only after this preview is READY will Step 8S write the 25 active-month snapshots into <code>ums_activity_snapshots</code> inside one transaction.</div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
