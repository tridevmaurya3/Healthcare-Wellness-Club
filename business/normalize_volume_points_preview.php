<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const VP_EXPECTED_ROWS = 282;

function vp_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vp_trim(?string $value): string
{
    return trim((string)$value);
}

function vp_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(vp_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function vp_excel_date(?string $value): array
{
    $raw = vp_trim($value);
    if ($raw === '') {
        return ['raw' => $raw, 'iso' => null, 'valid' => false];
    }

    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                return [
                    'raw' => $raw,
                    'iso' => (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d'),
                    'valid' => true,
                ];
            } catch (Throwable) {
                return ['raw' => $raw, 'iso' => null, 'valid' => false];
            }
        }
    }

    try {
        return ['raw' => $raw, 'iso' => (new DateTimeImmutable($raw))->format('Y-m-d'), 'valid' => true];
    } catch (Throwable) {
        return ['raw' => $raw, 'iso' => null, 'valid' => false];
    }
}

function vp_decimal(?string $value): array
{
    $raw = vp_trim($value);
    if ($raw === '') {
        return ['raw' => $raw, 'value' => null, 'valid' => false];
    }
    $clean = str_replace([',', ' '], '', $raw);
    if (!is_numeric($clean)) {
        return ['raw' => $raw, 'value' => null, 'valid' => false];
    }
    return ['raw' => $raw, 'value' => (float)$clean, 'valid' => true];
}

function vp_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A Volume Points raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

$error = null;
$batch = null;
$rows = [];
$checks = [];
$memberNames = [];
$summary = [
    'raw_rows' => 0,
    'pending_rows' => 0,
    'mapped_rows' => 0,
    'safe_member_links' => 0,
    'unmatched_member_names' => 0,
    'ambiguous_member_names' => 0,
    'missing_names' => 0,
    'invalid_dates' => 0,
    'invalid_vp' => 0,
    'zero_vp' => 0,
    'negative_vp' => 0,
    'duplicate_like_groups' => 0,
];
$unmatchedNames = [];
$ambiguousNames = [];
$orderTypeCounts = [];
$vpTypeCounts = [];

try {
    $pdo = business_db();

    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

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

    $memberStmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $memberStmt->execute([$organizationId]);
    foreach ($memberStmt->fetchAll() as $member) {
        $key = vp_name_key((string)$member['full_name']);
        if ($key !== '') {
            $memberNames[$key][] = $member;
        }
    }

    $rawStmt = $pdo->prepare(
        "SELECT id, source_row, external_record_id, mapping_status, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='Volume Points'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();
    $summary['raw_rows'] = count($rawRows);

    $businessSignatures = [];

    foreach ($rawRows as $rawRow) {
        $values = vp_decode_values((string)$rawRow['raw_json']);
        $name = vp_trim($values['B'] ?? null);
        $nameKey = vp_name_key($name);
        $date = vp_excel_date($values['G'] ?? null);
        $volume = vp_decimal($values['H'] ?? null);
        $orderType = vp_trim($values['I'] ?? null);
        $vpType = vp_trim($values['M'] ?? null);

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
            $issues[] = 'Member name missing';
        } elseif (!isset($memberNames[$nameKey])) {
            $summary['unmatched_member_names']++;
            $unmatchedNames[$name] = ($unmatchedNames[$name] ?? 0) + 1;
            $memberState = 'UNMATCHED';
        } elseif (count($memberNames[$nameKey]) === 1) {
            $summary['safe_member_links']++;
            $memberState = 'MATCH';
            $memberId = (int)$memberNames[$nameKey][0]['id'];
        } else {
            $summary['ambiguous_member_names']++;
            $ambiguousNames[$name] = ($ambiguousNames[$name] ?? 0) + 1;
            $memberState = 'AMBIGUOUS';
        }

        if (!$date['valid']) {
            $summary['invalid_dates']++;
            $issues[] = 'Invalid date';
        }
        if (!$volume['valid']) {
            $summary['invalid_vp']++;
            $issues[] = 'Invalid Volume Point value';
        } elseif ((float)$volume['value'] === 0.0) {
            $summary['zero_vp']++;
        } elseif ((float)$volume['value'] < 0) {
            $summary['negative_vp']++;
        }

        $orderTypeKey = $orderType !== '' ? $orderType : '(blank)';
        $vpTypeKey = $vpType !== '' ? $vpType : '(blank)';
        $orderTypeCounts[$orderTypeKey] = ($orderTypeCounts[$orderTypeKey] ?? 0) + 1;
        $vpTypeCounts[$vpTypeKey] = ($vpTypeCounts[$vpTypeKey] ?? 0) + 1;

        $signature = implode('|', [
            $nameKey,
            (string)($date['iso'] ?? ''),
            $volume['valid'] ? number_format((float)$volume['value'], 3, '.', '') : 'invalid',
            mb_strtolower($orderType, 'UTF-8'),
            mb_strtolower(vp_trim($values['K'] ?? null), 'UTF-8'),
            mb_strtolower(vp_trim($values['L'] ?? null), 'UTF-8'),
            mb_strtolower($vpType, 'UTF-8'),
            mb_strtolower(vp_trim($values['N'] ?? null), 'UTF-8'),
        ]);
        $businessSignatures[$signature] = ($businessSignatures[$signature] ?? 0) + 1;

        $rows[] = [
            'source_row' => (int)$rawRow['source_row'],
            'name' => $name,
            'member_state' => $memberState,
            'member_id' => $memberId,
            'date' => $date['iso'],
            'vp' => $volume['value'],
            'level' => vp_trim($values['C'] ?? null),
            'week' => vp_trim($values['F'] ?? null),
            'order_type' => $orderType,
            'note' => vp_trim($values['J'] ?? null),
            'vp_from' => vp_trim($values['K'] ?? null),
            'ordered_by' => vp_trim($values['L'] ?? null),
            'vp_type' => $vpType,
            'order_set' => vp_trim($values['N'] ?? null),
            'issues' => $issues,
        ];
    }

    $summary['duplicate_like_groups'] = count(array_filter(
        $businessSignatures,
        static fn(int $count): bool => $count > 1
    ));

    ksort($unmatchedNames, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($ambiguousNames, SORT_NATURAL | SORT_FLAG_CASE);
    arsort($orderTypeCounts);
    arsort($vpTypeCounts);

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'Volume Points raw rows = 282' => $summary['raw_rows'] === VP_EXPECTED_ROWS,
        'All Volume Points rows are still pending' => $summary['pending_rows'] === VP_EXPECTED_ROWS && $summary['mapped_rows'] === 0,
        'No missing member names' => $summary['missing_names'] === 0,
        'All dates are valid' => $summary['invalid_dates'] === 0,
        'All Volume Point values are numeric' => $summary['invalid_vp'] === 0,
        'Dedicated volume_point_entries table exists' => business_table_exists($pdo, 'volume_point_entries'),
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
  <title>Volume Points Normalization Preview - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .vp-wide{grid-column:span 12}.vp-main{grid-column:span 7}.vp-side{grid-column:span 5}.vp-tag{display:inline-flex;padding:5px 8px;border-radius:8px;background:#eef7f1;color:#32604b;font-size:.68rem;font-weight:800}.vp-tag.warn{background:#fff7e7;color:#815d19}.vp-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:.74rem}.vp-table th,.vp-table td{padding:9px 10px;border-bottom:1px solid #e9efeb;text-align:left}.vp-table th{color:#607169;font-size:.67rem;text-transform:uppercase}@media(max-width:900px){.vp-main,.vp-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Volume Points Preview</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="reconcile_new_ums.php">← New UMS Reconciliation</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8L • Volume Points normalization preview</div>
      <h1>Validate all 282 VP transactions before writing the dedicated fact table.</h1>
      <p>Member linking is conservative. Exact names link only when one Member exists. Unmatched or ambiguous names remain safe because the future Volume Point row will preserve the original member-name snapshot instead of guessing an identity.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">Volume Points only</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Normalization READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert vp-wide"><strong>Preview could not run:</strong> <?= vp_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="Volume Points preview summary">
        <article class="imp-kpi green"><small>Readiness</small><strong><?= $allPass ? 'READY' : 'REVIEW' ?></strong><span>Read-only validation</span></article>
        <article class="imp-kpi blue"><small>Raw VP Rows</small><strong><?= number_format($summary['raw_rows']) ?></strong><span>Expected 282</span></article>
        <article class="imp-kpi gold"><small>Safe Member Links</small><strong><?= number_format($summary['safe_member_links']) ?></strong><span>Exact unique-name matches</span></article>
        <article class="imp-kpi"><small>Link Later</small><strong><?= number_format($summary['unmatched_member_names'] + $summary['ambiguous_member_names']) ?></strong><span>Snapshot preserved; no guessing</span></article>
      </section>

      <article class="imp-card vp-main">
        <h2>Readiness checks</h2>
        <p>Only structural/data validity blocks normalization. Member-link uncertainty does not destroy the VP fact because source names are preserved separately.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row"><div><b><?= vp_h($label) ?></b><span><?= $pass ? 'Verified' : 'Must be fixed before write' ?></span></div><em><?= $pass ? 'PASS' : 'CHECK' ?></em></div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card vp-side">
        <h2>Identity-linking summary</h2>
        <p>Exact-name matching is used only as an optional foreign-key link.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Unique exact-name match</b><span>Safe Member ID link</span></div><em><?= number_format($summary['safe_member_links']) ?></em></div>
          <div class="imp-plan-row"><div><b>Unmatched source name</b><span>Keep member_name_snapshot</span></div><em><?= number_format($summary['unmatched_member_names']) ?></em></div>
          <div class="imp-plan-row"><div><b>Ambiguous exact name</b><span>Do not guess Member ID</span></div><em><?= number_format($summary['ambiguous_member_names']) ?></em></div>
          <div class="imp-plan-row"><div><b>Duplicate-like transaction groups</b><span>Informational; not auto-deleted</span></div><em><?= number_format($summary['duplicate_like_groups']) ?></em></div>
        </div>
      </aside>

      <article class="imp-card vp-main">
        <h2>Source-value observations</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Invalid dates</b><span>Blocking</span></div><em><?= number_format($summary['invalid_dates']) ?></em></div>
          <div class="imp-plan-row"><div><b>Invalid VP values</b><span>Blocking</span></div><em><?= number_format($summary['invalid_vp']) ?></em></div>
          <div class="imp-plan-row"><div><b>Zero VP rows</b><span>Preserved if source says zero</span></div><em><?= number_format($summary['zero_vp']) ?></em></div>
          <div class="imp-plan-row"><div><b>Negative VP rows</b><span>Preserved; review meaning later</span></div><em><?= number_format($summary['negative_vp']) ?></em></div>
        </div>
      </article>

      <aside class="imp-card vp-side">
        <h2>Order / VP labels</h2>
        <p>These source labels are preserved as text. They are not silently converted into new business meanings.</p>
        <div class="imp-tech">
          <?php foreach (array_slice($orderTypeCounts, 0, 8, true) as $label => $count): ?>
            <span><?= vp_h((string)$label) ?> · <?= number_format((int)$count) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="imp-tech">
          <?php foreach (array_slice($vpTypeCounts, 0, 8, true) as $label => $count): ?>
            <span><?= vp_h((string)$label) ?> · <?= number_format((int)$count) ?></span>
          <?php endforeach; ?>
        </div>
      </aside>

      <?php if ($unmatchedNames || $ambiguousNames): ?>
        <article class="imp-card vp-wide">
          <h2>Names that will not be auto-linked</h2>
          <p>This is safe. The normalized VP row can keep <code>member_id = NULL</code> while preserving <code>member_name_snapshot</code>. A later identity-reconciliation step can link them after verification.</p>
          <div class="imp-derived-list">
            <?php foreach ($unmatchedNames as $name => $count): ?>
              <div class="imp-derived-item"><b><?= vp_h((string)$name) ?></b><span>UNMATCHED • <?= number_format((int)$count) ?> VP row(s)</span></div>
            <?php endforeach; ?>
            <?php foreach ($ambiguousNames as $name => $count): ?>
              <div class="imp-derived-item"><b><?= vp_h((string)$name) ?></b><span>AMBIGUOUS • <?= number_format((int)$count) ?> VP row(s)</span></div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endif; ?>

      <article class="imp-card vp-wide">
        <h2>First 12 VP rows — preview only</h2>
        <div style="overflow:auto">
          <table class="vp-table">
            <thead><tr><th>Excel Row</th><th>Name</th><th>Member Link</th><th>Date</th><th>VP</th><th>Order Type</th><th>VP Type</th><th>Order Set</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($rows, 0, 12) as $row): ?>
                <tr>
                  <td><?= (int)$row['source_row'] ?></td>
                  <td><?= vp_h((string)$row['name']) ?></td>
                  <td><span class="vp-tag <?= $row['member_state'] === 'MATCH' ? '' : 'warn' ?>"><?= vp_h((string)$row['member_state']) ?></span></td>
                  <td><?= vp_h((string)($row['date'] ?? '—')) ?></td>
                  <td><?= $row['vp'] === null ? '—' : vp_h(rtrim(rtrim(number_format((float)$row['vp'], 3, '.', ''), '0'), '.')) ?></td>
                  <td><?= vp_h((string)$row['order_type']) ?></td>
                  <td><?= vp_h((string)$row['vp_type']) ?></td>
                  <td><?= vp_h((string)$row['order_set']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>

      <div class="imp-footer-note vp-wide"><strong>Step 8L safety rule:</strong> no data is written. When normalization is enabled, all 282 VP facts will preserve their raw source link and member-name snapshot. A Member ID is assigned only where the exact-name match is unique; unmatched/ambiguous names are never guessed.</div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
