<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const FSS_EXPECTED_ROWS = 94;

function fss_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fss_trim(?string $value): string
{
    return trim((string)$value);
}

function fss_name_key(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(fss_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function fss_excel_date(?string $value): array
{
    $raw = fss_trim($value);
    if ($raw === '') {
        return ['raw' => '', 'iso' => null, 'valid' => false];
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

function fss_decimal_nullable(?string $value): array
{
    $raw = fss_trim($value);
    if ($raw === '') {
        return ['raw' => '', 'value' => null, 'valid' => true, 'blank' => true];
    }

    $clean = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $raw);
    if (!is_numeric($clean)) {
        return ['raw' => $raw, 'value' => null, 'valid' => false, 'blank' => false];
    }

    return ['raw' => $raw, 'value' => (float)$clean, 'valid' => true, 'blank' => false];
}

function fss_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A First & Second Set raw row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function fss_equal_decimal(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return true;
    }
    return abs($a - $b) < 0.005;
}

$error = null;
$batch = null;
$checks = [];
$rows = [];
$memberNames = [];
$unmatchedNames = [];
$ambiguousNames = [];
$orderSetCounts = [];
$orderLevelCounts = [];
$summary = [
    'raw_rows' => 0,
    'pending_rows' => 0,
    'mapped_rows' => 0,
    'missing_names' => 0,
    'invalid_dates' => 0,
    'safe_member_links' => 0,
    'link_later' => 0,
    'invalid_primary_amounts' => 0,
    'invalid_primary_profits' => 0,
    'invalid_mirror_amounts' => 0,
    'invalid_mirror_profits' => 0,
    'amount_mismatches' => 0,
    'profit_mismatches' => 0,
    'mirror_amount_pairs' => 0,
    'mirror_profit_pairs' => 0,
    'legacy_w_nonblank' => 0,
];

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

    $memberStmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $memberStmt->execute([$organizationId]);
    foreach ($memberStmt->fetchAll() as $member) {
        $key = fss_name_key((string)$member['full_name']);
        if ($key !== '') {
            $memberNames[$key][] = $member;
        }
    }

    $rawStmt = $pdo->prepare(
        "SELECT id, source_row, external_record_id, mapping_status, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset='First & Second Set'
         ORDER BY source_row"
    );
    $rawStmt->execute([$organizationId, $sourceId, (int)$batch['id']]);
    $rawRows = $rawStmt->fetchAll();
    $summary['raw_rows'] = count($rawRows);

    foreach ($rawRows as $rawRow) {
        $values = fss_decode_values((string)$rawRow['raw_json']);
        $name = fss_trim($values['I'] ?? null);
        $nameKey = fss_name_key($name);
        $date = fss_excel_date($values['G'] ?? null);

        $amountPrimary = fss_decimal_nullable($values['R'] ?? null);
        $profitPrimary = fss_decimal_nullable($values['S'] ?? null);
        $amountMirror = fss_decimal_nullable($values['U'] ?? null);
        $profitMirror = fss_decimal_nullable($values['V'] ?? null);

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

        if (!$date['valid']) {
            $summary['invalid_dates']++;
            $issues[] = 'Invalid order date';
        }

        if (!$amountPrimary['valid']) {
            $summary['invalid_primary_amounts']++;
            $issues[] = 'R · Order Amount is not numeric';
        }
        if (!$profitPrimary['valid']) {
            $summary['invalid_primary_profits']++;
            $issues[] = 'S · Profit is not numeric';
        }
        if (!$amountMirror['valid']) {
            $summary['invalid_mirror_amounts']++;
            $issues[] = 'U · mirrored Order Amount is not numeric';
        }
        if (!$profitMirror['valid']) {
            $summary['invalid_mirror_profits']++;
            $issues[] = 'V · mirrored Profit is not numeric';
        }

        if (!$amountPrimary['blank'] && !$amountMirror['blank'] && $amountPrimary['valid'] && $amountMirror['valid']) {
            $summary['mirror_amount_pairs']++;
            if (!fss_equal_decimal($amountPrimary['value'], $amountMirror['value'])) {
                $summary['amount_mismatches']++;
                $issues[] = 'R and U Order Amount values differ';
            }
        }
        if (!$profitPrimary['blank'] && !$profitMirror['blank'] && $profitPrimary['valid'] && $profitMirror['valid']) {
            $summary['mirror_profit_pairs']++;
            if (!fss_equal_decimal($profitPrimary['value'], $profitMirror['value'])) {
                $summary['profit_mismatches']++;
                $issues[] = 'S and V Profit values differ';
            }
        }

        if (fss_trim($values['W'] ?? null) !== '') {
            $summary['legacy_w_nonblank']++;
        }

        $orderSet = fss_trim($values['H'] ?? null);
        $orderLevel = fss_trim($values['B'] ?? null);
        $orderSetCounts[$orderSet !== '' ? $orderSet : '(blank)'] = ($orderSetCounts[$orderSet !== '' ? $orderSet : '(blank)'] ?? 0) + 1;
        $orderLevelCounts[$orderLevel !== '' ? $orderLevel : '(blank)'] = ($orderLevelCounts[$orderLevel !== '' ? $orderLevel : '(blank)'] ?? 0) + 1;

        $rows[] = [
            'source_row' => (int)$rawRow['source_row'],
            'name' => $name,
            'member_state' => $memberState,
            'member_id' => $memberId,
            'date' => $date['iso'],
            'order_set' => $orderSet,
            'order_level' => $orderLevel,
            'ordered_by' => fss_trim($values['C'] ?? null),
            'sponsor' => fss_trim($values['J'] ?? null),
            'ums_type' => fss_trim($values['L'] ?? null),
            'amount_primary' => $amountPrimary['value'],
            'profit_primary' => $profitPrimary['value'],
            'amount_mirror' => $amountMirror['value'],
            'profit_mirror' => $profitMirror['value'],
            'formula1_first' => fss_trim($values['M'] ?? null),
            'afresh_first' => fss_trim($values['N'] ?? null),
            'shaker' => fss_trim($values['O'] ?? null),
            'formula1_second' => fss_trim($values['P'] ?? null),
            'afresh_second' => fss_trim($values['Q'] ?? null),
            'legacy_w' => fss_trim($values['W'] ?? null),
            'issues' => $issues,
        ];
    }

    arsort($orderSetCounts);
    arsort($orderLevelCounts);
    ksort($unmatchedNames, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($ambiguousNames, SORT_NATURAL | SORT_FLAG_CASE);

    $financialErrors =
        $summary['invalid_primary_amounts'] +
        $summary['invalid_primary_profits'] +
        $summary['invalid_mirror_amounts'] +
        $summary['invalid_mirror_profits'] +
        $summary['amount_mismatches'] +
        $summary['profit_mismatches'];

    $checks = [
        'Latest raw batch is completed' => (string)$batch['status'] === 'completed',
        'First & Second Set raw rows = 94' => $summary['raw_rows'] === FSS_EXPECTED_ROWS,
        'All 94 rows are still pending normalization' => $summary['pending_rows'] === FSS_EXPECTED_ROWS && $summary['mapped_rows'] === 0,
        'No missing member names' => $summary['missing_names'] === 0,
        'All order dates are valid' => $summary['invalid_dates'] === 0,
        'R/S primary financial values are numeric when present' => ($summary['invalid_primary_amounts'] + $summary['invalid_primary_profits']) === 0,
        'U/V mirror financial values are numeric when present' => ($summary['invalid_mirror_amounts'] + $summary['invalid_mirror_profits']) === 0,
        'R/S and U/V mirrored financial values agree where both are present' => ($summary['amount_mismatches'] + $summary['profit_mismatches']) === 0,
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
    $financialErrors = 0;
}

$allPass = $error === null && $checks && !in_array(false, $checks, true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>First & Second Set Preview - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .fss-wide{grid-column:span 12}.fss-main{grid-column:span 7}.fss-side{grid-column:span 5}.fss-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:.73rem}.fss-table th,.fss-table td{padding:9px 10px;border-bottom:1px solid #e9efeb;text-align:left;vertical-align:top}.fss-table th{color:#607169;font-size:.66rem;text-transform:uppercase}.fss-tag{display:inline-flex;padding:5px 8px;border-radius:8px;background:#eef7f1;color:#32604b;font-size:.67rem;font-weight:800}.fss-tag.warn{background:#fff7e7;color:#805c18}.fss-tag.bad{background:#fff1f2;color:#9d2c23}@media(max-width:900px){.fss-main,.fss-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • First & Second Set Preview</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="reconcile_volume_points.php">← VP Reconciliation</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8O • First & Second Set normalization preview</div>
      <h1>Verify the 94 order/set rows without trusting duplicate financial headings.</h1>
      <p>R/S are treated as the reviewed primary Order Amount / Profit fields. U/V remain comparison mirrors only. Product columns M–Q and legacy helper W are preserved as source evidence and are not converted into product facts yet.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good">94 source rows only</span>
      <span class="imp-chip <?= $allPass ? 'good' : '' ?>"><?= $allPass ? 'Normalization READY' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert fss-wide"><strong>Preview could not run:</strong> <?= fss_h($error) ?></div>
    <?php else: ?>
      <section class="imp-summary" aria-label="First and Second Set preview summary">
        <article class="imp-kpi green"><small>Readiness</small><strong><?= $allPass ? 'READY' : 'REVIEW' ?></strong><span>Read-only validation</span></article>
        <article class="imp-kpi blue"><small>Raw Rows</small><strong><?= number_format($summary['raw_rows']) ?></strong><span>Expected 94</span></article>
        <article class="imp-kpi gold"><small>Safe Member Links</small><strong><?= number_format($summary['safe_member_links']) ?></strong><span>Unique exact-name match</span></article>
        <article class="imp-kpi"><small>Financial Issues</small><strong><?= number_format($financialErrors) ?></strong><span>Invalid or mirror mismatch</span></article>
      </section>

      <article class="imp-card fss-main">
        <h2>Readiness checks</h2>
        <p>Financial mirror disagreement is a blocker because the system will not silently choose between conflicting values.</p>
        <div class="imp-plan-list">
          <?php foreach ($checks as $label => $pass): ?>
            <div class="imp-plan-row"><div><b><?= fss_h($label) ?></b><span><?= $pass ? 'Verified' : 'Must be reviewed before write' ?></span></div><em><?= $pass ? 'PASS' : 'CHECK' ?></em></div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="imp-card fss-side">
        <h2>Financial mirror audit</h2>
        <p>R/S are primary. U/V are never used as a second transaction; they only validate the source.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>R ↔ U amount pairs</b><span>Both sides populated</span></div><em><?= number_format($summary['mirror_amount_pairs']) ?></em></div>
          <div class="imp-plan-row"><div><b>Amount mismatches</b><span>Must be zero before write</span></div><em><?= number_format($summary['amount_mismatches']) ?></em></div>
          <div class="imp-plan-row"><div><b>S ↔ V profit pairs</b><span>Both sides populated</span></div><em><?= number_format($summary['mirror_profit_pairs']) ?></em></div>
          <div class="imp-plan-row"><div><b>Profit mismatches</b><span>Must be zero before write</span></div><em><?= number_format($summary['profit_mismatches']) ?></em></div>
          <div class="imp-plan-row"><div><b>Legacy W values</b><span>Raw-only; never drives normalization</span></div><em><?= number_format($summary['legacy_w_nonblank']) ?></em></div>
        </div>
      </aside>

      <article class="imp-card fss-main">
        <h2>Member linking</h2>
        <p>A VP-style conservative rule is retained: only one exact-name Member is linked automatically.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Safe exact-name links</b><span>Member ID may be attached</span></div><em><?= number_format($summary['safe_member_links']) ?></em></div>
          <div class="imp-plan-row"><div><b>Link later</b><span>Unmatched / ambiguous identity stays as source-name snapshot</span></div><em><?= number_format($summary['link_later']) ?></em></div>
        </div>
        <?php if ($unmatchedNames): ?>
          <div class="imp-hint" style="margin-top:12px"><strong>Unmatched names:</strong> <?= fss_h(implode(', ', array_slice(array_keys($unmatchedNames), 0, 12))) ?><?= count($unmatchedNames) > 12 ? '…' : '' ?></div>
        <?php endif; ?>
        <?php if ($ambiguousNames): ?>
          <div class="imp-hint" style="margin-top:8px"><strong>Ambiguous names:</strong> <?= fss_h(implode(', ', array_slice(array_keys($ambiguousNames), 0, 12))) ?><?= count($ambiguousNames) > 12 ? '…' : '' ?></div>
        <?php endif; ?>
      </article>

      <aside class="imp-card fss-side">
        <h2>Product/set boundary</h2>
        <p>These legacy product columns are preserved but are not yet converted into Product & Price order items.</p>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>M · Formula-1</b><span>First-set source value</span></div><em>RAW</em></div>
          <div class="imp-plan-row"><div><b>N · Afresh</b><span>First-set source value</span></div><em>RAW</em></div>
          <div class="imp-plan-row"><div><b>O · Shaker Cup</b><span>Source value</span></div><em>RAW</em></div>
          <div class="imp-plan-row"><div><b>P · Formula-1</b><span>Second-set source value</span></div><em>RAW</em></div>
          <div class="imp-plan-row"><div><b>Q · Afresh</b><span>Second-set source value</span></div><em>RAW</em></div>
        </div>
      </aside>

      <?php
      $issueRows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['issues'])));
      if ($issueRows):
      ?>
        <article class="imp-card fss-wide">
          <h2>Rows requiring review</h2>
          <p>Only rows with structural/financial issues are listed below. No write is allowed while blocker checks fail.</p>
          <div style="overflow:auto">
            <table class="fss-table">
              <thead><tr><th>Source Row</th><th>Name</th><th>Date</th><th>Set</th><th>R Amount</th><th>U Mirror</th><th>S Profit</th><th>V Mirror</th><th>Issue</th></tr></thead>
              <tbody>
              <?php foreach (array_slice($issueRows, 0, 30) as $row): ?>
                <tr>
                  <td><?= (int)$row['source_row'] ?></td>
                  <td><?= fss_h($row['name']) ?></td>
                  <td><?= fss_h((string)($row['date'] ?? '—')) ?></td>
                  <td><?= fss_h($row['order_set']) ?></td>
                  <td><?= $row['amount_primary'] === null ? '—' : number_format((float)$row['amount_primary'], 2) ?></td>
                  <td><?= $row['amount_mirror'] === null ? '—' : number_format((float)$row['amount_mirror'], 2) ?></td>
                  <td><?= $row['profit_primary'] === null ? '—' : number_format((float)$row['profit_primary'], 2) ?></td>
                  <td><?= $row['profit_mirror'] === null ? '—' : number_format((float)$row['profit_mirror'], 2) ?></td>
                  <td><span class="fss-tag bad"><?= fss_h(implode(' • ', $row['issues'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endif; ?>

      <article class="imp-card imp-derived">
        <h2>Source distribution</h2>
        <div class="imp-derived-list">
          <?php foreach ($orderSetCounts as $label => $count): ?>
            <div class="imp-derived-item"><b>Order Set · <?= fss_h($label) ?></b><span><?= number_format($count) ?> row(s)</span></div>
          <?php endforeach; ?>
          <?php foreach ($orderLevelCounts as $label => $count): ?>
            <div class="imp-derived-item"><b>Order Level · <?= fss_h($label) ?></b><span><?= number_format($count) ?> row(s)</span></div>
          <?php endforeach; ?>
        </div>
      </article>

      <div class="imp-footer-note"><strong>Next write boundary:</strong> only after every blocker is PASS will Step 8P create one source-linked order fact per raw row. R/S will be the financial facts; U/V will remain reconciliation evidence. M–Q and W will remain preserved in the raw source until the Product & Price order-item model is ready.</div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
