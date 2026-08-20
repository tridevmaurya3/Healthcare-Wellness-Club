<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function nwt_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nwt_trim(mixed $value): string
{
    return trim((string)$value);
}

function nwt_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', nwt_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function nwt_source_values(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    try {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($payload['values'] ?? null) ? (array)$payload['values'] : [];
    } catch (Throwable) {
        return [];
    }
}

function nwt_month_number(mixed $value): ?int
{
    $raw = nwt_trim($value);
    if ($raw === '') {
        return null;
    }

    $key = nwt_key($raw);
    $map = [
        'january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,
        'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,
        'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,'october'=>10,'oct'=>10,
        'november'=>11,'nov'=>11,'december'=>12,'dec'=>12,
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (is_numeric($raw)) {
        $number = (int)$raw;
        return $number >= 1 && $number <= 12 ? $number : null;
    }
    return null;
}

function nwt_month_name(int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!m', (string)$month);
    return $date ? $date->format('F') : (string)$month;
}

function nwt_period(array $values, ?string $entryDate): array
{
    $yearRaw = nwt_trim($values['D'] ?? '');
    $monthRaw = nwt_trim($values['E'] ?? '');
    $year = is_numeric($yearRaw) ? (int)$yearRaw : null;
    $month = nwt_month_number($monthRaw);

    if (($year === null || $month === null) && $entryDate) {
        try {
            $date = new DateTimeImmutable($entryDate);
            $year ??= (int)$date->format('Y');
            $month ??= (int)$date->format('n');
        } catch (Throwable) {
        }
    }

    return ['year' => $year, 'month' => $month];
}

function nwt_same_period(array $row, int $year, int $month): bool
{
    return ($row['period']['year'] ?? null) === $year && ($row['period']['month'] ?? null) === $month;
}

function nwt_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

$error = null;
$organizationId = 0;
$rows = [];
$owners = [];
$ownerVp = [];
$periods = [];
$selectedOwner = '';
$selectedYear = 0;
$selectedMonth = 0;
$sourceMapped = 0;
$sourceTotal = 0;

$metrics = [
    'personal_consumption' => 0.0,
    'renewal_ums_raw' => 0.0,
    'first_line_new_ums' => 0.0,
    'first_line_pc' => 0.0,
    'first_line_associate' => 0.0,
    'club_source_vp_unadjusted' => 0.0,
    'total_vp' => 0.0,
];

$pcMembers = [];
$associateMembers = [];
$newUmsMembers = [];
$nameRows = [];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    if (!business_table_exists($pdo, 'volume_point_entries') || !business_table_exists($pdo, 'raw_source_records')) {
        throw new RuntimeException('Volume Points source tables are not ready.');
    }

    $stateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows, SUM(mapping_status='mapped') mapped_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $stateStmt->execute([$organizationId]);
    $state = $stateStmt->fetch() ?: [];
    $sourceTotal = (int)($state['total_rows'] ?? 0);
    $sourceMapped = (int)($state['mapped_rows'] ?? 0);
    if ($sourceTotal !== 757 || $sourceMapped !== 757) {
        throw new RuntimeException('Operational source layer must be fully reconciled at 757/757 before Name Wise Tracking can run.');
    }

    $stmt = $pdo->prepare(
        "SELECT v.id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label, v.volume_points,
                v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set, r.raw_json
         FROM volume_point_entries v
         LEFT JOIN raw_source_records r ON r.id=v.source_record_id
         WHERE v.organization_id=? AND v.source_sheet='Volume Points'
         ORDER BY v.entry_date, v.id"
    );
    $stmt->execute([$organizationId]);

    foreach ($stmt->fetchAll() as $row) {
        $values = nwt_source_values($row['raw_json'] ?? null);
        $period = nwt_period($values, $row['entry_date'] ?? null);
        $name = nwt_trim($row['member_name_snapshot'] ?? '');
        $prepared = [
            'name' => $name,
            'name_key' => nwt_key($name),
            'period' => $period,
            'level' => nwt_trim($row['level_label'] ?? ''),
            'level_key' => nwt_key($row['level_label'] ?? ''),
            'order_type' => nwt_trim($row['order_type'] ?? ''),
            'order_type_key' => nwt_key($row['order_type'] ?? ''),
            'vp_from' => nwt_trim($row['vp_from'] ?? ''),
            'vp_from_key' => nwt_key($row['vp_from'] ?? ''),
            'ordered_by' => nwt_trim($row['ordered_by'] ?? ''),
            'ordered_by_key' => nwt_key($row['ordered_by'] ?? ''),
            'vp_type' => nwt_trim($row['vp_type'] ?? ''),
            'vp_type_key' => nwt_key($row['vp_type'] ?? ''),
            'order_set' => nwt_trim($row['order_set'] ?? ''),
            'volume_points' => (float)$row['volume_points'],
        ];
        $rows[] = $prepared;

        if ($name !== '') {
            $owners[$prepared['name_key']] = $owners[$prepared['name_key']] ?? $name;
            $ownerVp[$prepared['name_key']] = ($ownerVp[$prepared['name_key']] ?? 0.0) + $prepared['volume_points'];
        }
        if (($period['year'] ?? null) !== null && ($period['month'] ?? null) !== null) {
            $periodKey = sprintf('%04d-%02d', (int)$period['year'], (int)$period['month']);
            $periods[$periodKey] = ['year' => (int)$period['year'], 'month' => (int)$period['month']];
        }
    }

    if (!$rows) {
        throw new RuntimeException('No normalized Volume Points rows were found.');
    }

    uasort($owners, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    arsort($ownerVp, SORT_NUMERIC);
    krsort($periods, SORT_STRING);

    $requestedOwner = nwt_trim($_GET['owner'] ?? '');
    $requestedOwnerKey = nwt_key($requestedOwner);
    if ($requestedOwner !== '' && isset($owners[$requestedOwnerKey])) {
        $selectedOwner = $owners[$requestedOwnerKey];
    } else {
        $topOwnerKey = array_key_first($ownerVp);
        $selectedOwner = $topOwnerKey !== null && isset($owners[$topOwnerKey])
            ? $owners[$topOwnerKey]
            : (string)reset($owners);
    }
    $selectedOwnerKey = nwt_key($selectedOwner);

    $requestedYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : 0;
    $requestedMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : 0;
    $requestedPeriodKey = ($requestedYear > 0 && $requestedMonth >= 1 && $requestedMonth <= 12)
        ? sprintf('%04d-%02d', $requestedYear, $requestedMonth)
        : '';

    if ($requestedPeriodKey !== '' && isset($periods[$requestedPeriodKey])) {
        $selectedYear = $requestedYear;
        $selectedMonth = $requestedMonth;
    } else {
        $latest = reset($periods);
        $selectedYear = (int)($latest['year'] ?? 0);
        $selectedMonth = (int)($latest['month'] ?? 0);
    }

    $filtered = array_values(array_filter(
        $rows,
        static fn(array $row): bool => nwt_same_period($row, $selectedYear, $selectedMonth)
    ));

    foreach ($filtered as $row) {
        $vp = (float)$row['volume_points'];
        $isOwner = $row['name_key'] === $selectedOwnerKey;
        $metrics['total_vp'] += $vp;

        if ($isOwner && $row['order_type_key'] === nwt_key('Extra for Myself / Family')) {
            $metrics['personal_consumption'] += $vp;
        }
        if ($isOwner && $row['order_type_key'] === nwt_key('Renewal UMS')) {
            $metrics['renewal_ums_raw'] += $vp;
        }
        if (!$isOwner && $row['order_type_key'] === nwt_key('New UMS') && $row['vp_from_key'] === nwt_key('1st Line')) {
            $metrics['first_line_new_ums'] += $vp;
            if ($row['name'] !== '') {
                $key = $row['name_key'];
                $newUmsMembers[$key] ??= ['name'=>$row['name'], 'vp'=>0.0, 'rows'=>0];
                $newUmsMembers[$key]['vp'] += $vp;
                $newUmsMembers[$key]['rows']++;
            }
        }
        if (!$isOwner && $row['vp_from_key'] === nwt_key('1st Line') && $row['ordered_by_key'] === nwt_key('PC')) {
            $metrics['first_line_pc'] += $vp;
            if ($row['name'] !== '') {
                $key = $row['name_key'];
                $pcMembers[$key] ??= ['name'=>$row['name'], 'vp'=>0.0, 'rows'=>0];
                $pcMembers[$key]['vp'] += $vp;
                $pcMembers[$key]['rows']++;
            }
        }
        if (!$isOwner && $row['vp_from_key'] === nwt_key('1st Line') && $row['ordered_by_key'] === nwt_key('AS')) {
            $metrics['first_line_associate'] += $vp;
            if ($row['name'] !== '') {
                $key = $row['name_key'];
                $associateMembers[$key] ??= ['name'=>$row['name'], 'vp'=>0.0, 'rows'=>0];
                $associateMembers[$key]['vp'] += $vp;
                $associateMembers[$key]['rows']++;
            }
        }
        if ($row['vp_from_key'] === nwt_key('UMS')) {
            $metrics['club_source_vp_unadjusted'] += $vp;
        }
    }

    uasort($pcMembers, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);
    uasort($associateMembers, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);
    uasort($newUmsMembers, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);

    $allNameKeys = array_unique(array_merge(array_keys($pcMembers), array_keys($associateMembers), array_keys($newUmsMembers)));
    foreach ($allNameKeys as $key) {
        $nameRows[] = [
            'name' => $pcMembers[$key]['name'] ?? $associateMembers[$key]['name'] ?? $newUmsMembers[$key]['name'] ?? $key,
            'pc_vp' => (float)($pcMembers[$key]['vp'] ?? 0),
            'associate_vp' => (float)($associateMembers[$key]['vp'] ?? 0),
            'new_ums_vp' => (float)($newUmsMembers[$key]['vp'] ?? 0),
        ];
    }
    usort($nameRows, static fn(array $a, array $b): int => (($b['pc_vp'] + $b['associate_vp'] + $b['new_ums_vp']) <=> ($a['pc_vp'] + $a['associate_vp'] + $a['new_ums_vp'])));
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Name Wise Tracking Live Report - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .nwt-wide{grid-column:span 12}.nwt-main{grid-column:span 8}.nwt-side{grid-column:span 4}
    .nwt-filter{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}.nwt-filter label{font-size:.68rem;font-weight:800;color:#64736b;text-transform:uppercase}.nwt-filter select{width:100%;margin-top:5px;padding:10px;border:1px solid #dce6df;border-radius:11px;background:#fff;color:#23332a}.nwt-filter button{padding:11px 18px;border:0;border-radius:11px;background:#19764a;color:#fff;font-weight:800;cursor:pointer}
    .nwt-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.76rem}.nwt-table th,.nwt-table td{padding:10px;border-bottom:1px solid #e8efea;text-align:left}.nwt-table th{font-size:.66rem;text-transform:uppercase;color:#65746c}.nwt-right{text-align:right!important}.nwt-muted{color:#697770}.nwt-rule{padding:13px 14px;margin-top:12px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.77rem;line-height:1.55}
    .nwt-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#276746;font-size:.68rem;font-weight:800}.nwt-badge.warn{background:#fff3d7;color:#805b12}
    @media(max-width:900px){.nwt-main,.nwt-side{grid-column:span 12}.nwt-filter{grid-template-columns:1fr 1fr}.nwt-filter .owner{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Name Wise Tracking Live Report</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="sp_house.php">← SP House</a>
      <a href="master_tracking.php">Master Tracking</a>
      <a href="derived_reports_audit.php">Formula Audit</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9D • Name Wise Tracking live engine</div>
      <h1>Name Wise Tracking now groups real Volume Point facts by member instead of spreadsheet array formulas.</h1>
      <p>The live report preserves the workbook dimensions and shows First-Line PC, First-Line Associate and First-Line New UMS VP name by name. Legacy VP adjustment constants remain isolated until they are explicitly versioned.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good"><?= number_format($sourceMapped) ?> / <?= number_format($sourceTotal) ?> source mapped</span>
      <span class="imp-chip <?= $error === null ? 'good' : '' ?>"><?= $error === null ? 'NAME WISE LIVE' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert nwt-wide"><strong>Name Wise Tracking could not run:</strong> <?= nwt_h($error) ?></div>
    <?php else: ?>
      <article class="imp-card nwt-wide">
        <form method="get" class="nwt-filter">
          <div class="owner">
            <label for="owner">Member / Owner</label>
            <select id="owner" name="owner">
              <?php foreach ($owners as $name): ?>
                <option value="<?= nwt_h($name) ?>" <?= nwt_key($name) === nwt_key($selectedOwner) ? 'selected' : '' ?>><?= nwt_h($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="year">Year</label>
            <select id="year" name="year">
              <?php $years = array_values(array_unique(array_map(static fn(array $p): int => $p['year'], $periods))); rsort($years); ?>
              <?php foreach ($years as $year): ?><option value="<?= $year ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="month">Month</label>
            <select id="month" name="month">
              <?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m === $selectedMonth ? 'selected' : '' ?>><?= nwt_h(nwt_month_name($m)) ?></option><?php endfor; ?>
            </select>
          </div>
          <button type="submit">Apply</button>
        </form>
      </article>

      <section class="imp-summary" aria-label="Name Wise summary">
        <article class="imp-kpi green"><small>Personal Consumption</small><strong><?= nwt_num($metrics['personal_consumption']) ?></strong><span>Owner • Extra for Myself / Family</span></article>
        <article class="imp-kpi blue"><small>Renewal UMS</small><strong><?= nwt_num($metrics['renewal_ums_raw']) ?></strong><span>Raw source VP • unadjusted</span></article>
        <article class="imp-kpi gold"><small>First-Line New UMS</small><strong><?= nwt_num($metrics['first_line_new_ums']) ?></strong><span>Name-wise New UMS VP</span></article>
        <article class="imp-kpi"><small>Total VP</small><strong><?= nwt_num($metrics['total_vp']) ?></strong><span>Selected period source total</span></article>
      </section>

      <article class="imp-card nwt-main">
        <h2>Name-wise first-line tracking</h2>
        <p>One row per source name for the selected period. These are source-preserved names; uncertain identities are not automatically merged.</p>
        <div style="overflow:auto">
          <table class="nwt-table">
            <thead><tr><th>Name</th><th class="nwt-right">First-Line PC VP</th><th class="nwt-right">First-Line Associate VP</th><th class="nwt-right">First-Line New UMS VP</th><th class="nwt-right">Combined</th></tr></thead>
            <tbody>
            <?php if (!$nameRows): ?>
              <tr><td colspan="5" class="nwt-muted">No first-line name-wise rows for this selection.</td></tr>
            <?php else: ?>
              <?php foreach ($nameRows as $row): ?>
                <?php $combined = $row['pc_vp'] + $row['associate_vp'] + $row['new_ums_vp']; ?>
                <tr>
                  <td><strong><?= nwt_h($row['name']) ?></strong></td>
                  <td class="nwt-right"><?= nwt_num($row['pc_vp']) ?></td>
                  <td class="nwt-right"><?= nwt_num($row['associate_vp']) ?></td>
                  <td class="nwt-right"><?= nwt_num($row['new_ums_vp']) ?></td>
                  <td class="nwt-right"><strong><?= nwt_num($combined) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </article>

      <aside class="imp-card nwt-side">
        <h2>Workbook summary translation</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Personal Consumption</b><span>Selected owner • Extra for Myself / Family</span></div><em><?= nwt_num($metrics['personal_consumption']) ?></em></div>
          <div class="imp-plan-row"><div><b>Renewal UMS</b><span>Selected owner • raw source VP</span></div><em><?= nwt_num($metrics['renewal_ums_raw']) ?></em></div>
          <div class="imp-plan-row"><div><b>First-Line New UMS</b><span>1st Line + New UMS</span></div><em><?= nwt_num($metrics['first_line_new_ums']) ?></em></div>
          <div class="imp-plan-row"><div><b>First-Line PC</b><span>1st Line + Ordered By PC</span></div><em><?= nwt_num($metrics['first_line_pc']) ?></em></div>
          <div class="imp-plan-row"><div><b>First-Line Associate</b><span>1st Line + Ordered By AS</span></div><em><?= nwt_num($metrics['first_line_associate']) ?></em></div>
          <div class="imp-plan-row"><div><b>Club VP source</b><span>VP From = UMS • unadjusted</span></div><em><?= nwt_num($metrics['club_source_vp_unadjusted']) ?></em></div>
          <div class="imp-plan-row"><div><b>Total VP</b><span>All Volume Points in selected period</span></div><em><?= nwt_num($metrics['total_vp']) ?></em></div>
        </div>

        <div class="nwt-rule">
          <strong>Legacy rule intentionally deferred:</strong><br>
          The workbook uses the same historical numeric VP-adjustment constants as SP House. This live page displays source/raw VP until those constants are converted into named, versioned <code>calculation_rules</code>. No hidden correction is being guessed.
        </div>
      </aside>

      <article class="imp-card nwt-wide">
        <h2>Report interpretation</h2>
        <p class="nwt-muted">Selected: <strong><?= nwt_h($selectedOwner) ?></strong> • <?= nwt_h(nwt_month_name($selectedMonth)) ?> <?= $selectedYear ?>. Name grouping is based on the preserved Volume Points source name. The final identity-reconciliation phase can later link verified duplicate/source variants without changing the underlying source facts.</p>
      </article>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
