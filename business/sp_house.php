<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function sph_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sph_trim(mixed $value): string
{
    return trim((string)$value);
}

function sph_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', sph_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function sph_source_values(?string $json): array
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

function sph_month_number(mixed $value): ?int
{
    $raw = sph_trim($value);
    if ($raw === '') {
        return null;
    }
    $key = sph_key($raw);
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
        $n = (int)$raw;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    return null;
}

function sph_month_name(int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!m', (string)$month);
    return $date ? $date->format('F') : (string)$month;
}

function sph_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function sph_period(array $values, ?string $entryDate): array
{
    $yearRaw = sph_trim($values['D'] ?? '');
    $monthRaw = sph_trim($values['E'] ?? '');
    $year = is_numeric($yearRaw) ? (int)$yearRaw : null;
    $month = sph_month_number($monthRaw);

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

function sph_same_period(array $row, int $year, int $month): bool
{
    return ($row['period']['year'] ?? null) === $year && ($row['period']['month'] ?? null) === $month;
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
    'personal_family' => 0.0,
    'new_ums' => 0.0,
    'renewal_ums_raw' => 0.0,
    'first_line_pc' => 0.0,
    'first_line_associate' => 0.0,
    'associate_team_vp' => 0.0,
    'club_source_vp_unadjusted' => 0.0,
    'renewal_plus_pc' => 0.0,
    'total_vp' => 0.0,
];
$pcMembers = [];
$associateMembers = [];
$dimensionRows = [];

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
        throw new RuntimeException('Operational source layer must be fully reconciled at 757/757 before SP House can run.');
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
        $values = sph_source_values($row['raw_json'] ?? null);
        $period = sph_period($values, $row['entry_date'] ?? null);
        $name = sph_trim($row['member_name_snapshot'] ?? '');
        $prepared = [
            'name' => $name,
            'name_key' => sph_key($name),
            'period' => $period,
            'level' => sph_trim($row['level_label'] ?? ''),
            'order_type' => sph_trim($row['order_type'] ?? ''),
            'order_type_key' => sph_key($row['order_type'] ?? ''),
            'vp_from' => sph_trim($row['vp_from'] ?? ''),
            'vp_from_key' => sph_key($row['vp_from'] ?? ''),
            'ordered_by' => sph_trim($row['ordered_by'] ?? ''),
            'ordered_by_key' => sph_key($row['ordered_by'] ?? ''),
            'vp_type' => sph_trim($row['vp_type'] ?? ''),
            'vp_type_key' => sph_key($row['vp_type'] ?? ''),
            'order_set' => sph_trim($row['order_set'] ?? ''),
            'volume_points' => (float)$row['volume_points'],
        ];
        $rows[] = $prepared;

        if ($name !== '') {
            $owners[$prepared['name_key']] = $owners[$prepared['name_key']] ?? $name;
            $ownerVp[$prepared['name_key']] = ($ownerVp[$prepared['name_key']] ?? 0.0) + $prepared['volume_points'];
        }
        if (($period['year'] ?? null) !== null && ($period['month'] ?? null) !== null) {
            $key = sprintf('%04d-%02d', (int)$period['year'], (int)$period['month']);
            $periods[$key] = ['year'=>(int)$period['year'], 'month'=>(int)$period['month']];
        }
    }

    if (!$rows) {
        throw new RuntimeException('No normalized Volume Points rows were found.');
    }

    uasort($owners, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    arsort($ownerVp, SORT_NUMERIC);
    krsort($periods, SORT_STRING);

    $requestedOwner = sph_trim($_GET['owner'] ?? '');
    $requestedOwnerKey = sph_key($requestedOwner);
    if ($requestedOwner !== '' && isset($owners[$requestedOwnerKey])) {
        $selectedOwner = $owners[$requestedOwnerKey];
    } else {
        $topOwnerKey = array_key_first($ownerVp);
        $selectedOwner = $topOwnerKey !== null && isset($owners[$topOwnerKey]) ? $owners[$topOwnerKey] : (string)reset($owners);
    }
    $selectedOwnerKey = sph_key($selectedOwner);

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

    $filtered = array_values(array_filter($rows, static fn(array $row): bool => sph_same_period($row, $selectedYear, $selectedMonth)));

    foreach ($filtered as $row) {
        $vp = (float)$row['volume_points'];
        $isOwner = $row['name_key'] === $selectedOwnerKey;

        $metrics['total_vp'] += $vp;

        if ($isOwner && $row['order_type_key'] === sph_key('Extra for Myself / Family')) {
            $metrics['personal_family'] += $vp;
        }
        if ($isOwner && $row['order_type_key'] === sph_key('New UMS')) {
            $metrics['new_ums'] += $vp;
        }
        if ($isOwner && $row['order_type_key'] === sph_key('Renewal UMS')) {
            $metrics['renewal_ums_raw'] += $vp;
        }
        if (!$isOwner && $row['ordered_by_key'] === sph_key('PC')) {
            $metrics['first_line_pc'] += $vp;
        }
        if (!$isOwner && $row['ordered_by_key'] === sph_key('AS')) {
            $metrics['first_line_associate'] += $vp;
        }
        if ($row['ordered_by_key'] === sph_key('AS') && $row['vp_type_key'] === sph_key('Team VP')) {
            $metrics['associate_team_vp'] += $vp;
        }
        if ($row['vp_from_key'] === sph_key('UMS')) {
            $metrics['club_source_vp_unadjusted'] += $vp;
        }

        if ($row['vp_from_key'] === sph_key('1st Line') && $row['ordered_by_key'] === sph_key('PC') && $row['name'] !== '') {
            $key = $row['name_key'];
            $pcMembers[$key] ??= ['name'=>$row['name'], 'vp'=>0.0, 'rows'=>0];
            $pcMembers[$key]['vp'] += $vp;
            $pcMembers[$key]['rows']++;
        }
        if ($row['vp_from_key'] === sph_key('1st Line') && $row['ordered_by_key'] === sph_key('AS') && $row['name'] !== '') {
            $key = $row['name_key'];
            $associateMembers[$key] ??= ['name'=>$row['name'], 'vp'=>0.0, 'rows'=>0];
            $associateMembers[$key]['vp'] += $vp;
            $associateMembers[$key]['rows']++;
        }
    }

    $metrics['renewal_plus_pc'] = $metrics['renewal_ums_raw'] + $metrics['first_line_pc'];

    uasort($pcMembers, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);
    uasort($associateMembers, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);

    $dimensionAgg = [];
    foreach ($filtered as $row) {
        $key = implode('|', [$row['vp_from'], $row['ordered_by'], $row['vp_type'], $row['level']]);
        if (!isset($dimensionAgg[$key])) {
            $dimensionAgg[$key] = [
                'vp_from'=>$row['vp_from'], 'ordered_by'=>$row['ordered_by'], 'vp_type'=>$row['vp_type'], 'level'=>$row['level'],
                'rows'=>0, 'vp'=>0.0,
            ];
        }
        $dimensionAgg[$key]['rows']++;
        $dimensionAgg[$key]['vp'] += (float)$row['volume_points'];
    }
    $dimensionRows = array_values($dimensionAgg);
    usort($dimensionRows, static fn(array $a, array $b): int => $b['vp'] <=> $a['vp']);
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
  <title>SP House Live Report - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .sph-wide{grid-column:span 12}.sph-main{grid-column:span 8}.sph-side{grid-column:span 4}
    .sph-filter{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}.sph-filter label{font-size:.68rem;font-weight:800;color:#64736b;text-transform:uppercase}.sph-filter select{width:100%;margin-top:5px;padding:10px;border:1px solid #dce6df;border-radius:11px;background:#fff;color:#23332a}.sph-filter button{padding:11px 18px;border:0;border-radius:11px;background:#19764a;color:#fff;font-weight:800;cursor:pointer}
    .sph-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.76rem}.sph-table th,.sph-table td{padding:10px;border-bottom:1px solid #e8efea;text-align:left}.sph-table th{font-size:.66rem;text-transform:uppercase;color:#65746c}.sph-right{text-align:right!important}.sph-muted{color:#697770}.sph-rule{padding:13px 14px;margin-top:12px;border:1px solid #ecd9a8;border-radius:13px;background:#fff9e9;color:#735415;font-size:.77rem;line-height:1.55}
    .sph-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#276746;font-size:.68rem;font-weight:800}.sph-badge.warn{background:#fff3d7;color:#805b12}
    @media(max-width:900px){.sph-main,.sph-side{grid-column:span 12}.sph-filter{grid-template-columns:1fr 1fr}.sph-filter .owner{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • SP House Live Report</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="master_tracking.php">← Master Tracking</a>
      <a href="derived_reports_audit.php">Formula Audit</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9C • SP House live engine</div>
      <h1>SP House now calculates from normalized Volume Points instead of copied spreadsheet formulas.</h1>
      <p>The report preserves the workbook dimensions — Name, Year, Month, VP From, Ordered By, VP Type and Order Type. Legacy VP adjustment constants are deliberately isolated and are not silently applied.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good"><?= number_format($sourceMapped) ?> / <?= number_format($sourceTotal) ?> source mapped</span>
      <span class="imp-chip <?= $error === null ? 'good' : '' ?>"><?= $error === null ? 'SP HOUSE LIVE' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert sph-wide"><strong>SP House could not run:</strong> <?= sph_h($error) ?></div>
    <?php else: ?>
      <article class="imp-card sph-wide">
        <form method="get" class="sph-filter">
          <div class="owner">
            <label for="owner">Member / Owner</label>
            <select id="owner" name="owner">
              <?php foreach ($owners as $name): ?>
                <option value="<?= sph_h($name) ?>" <?= sph_key($name) === sph_key($selectedOwner) ? 'selected' : '' ?>><?= sph_h($name) ?></option>
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
              <?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m === $selectedMonth ? 'selected' : '' ?>><?= sph_h(sph_month_name($m)) ?></option><?php endfor; ?>
            </select>
          </div>
          <button type="submit">Apply</button>
        </form>
      </article>

      <section class="imp-summary" aria-label="SP House summary">
        <article class="imp-kpi green"><small>Personal & Family</small><strong><?= sph_num($metrics['personal_family']) ?></strong><span>Exact order type: Extra for Myself / Family</span></article>
        <article class="imp-kpi blue"><small>New UMS VP</small><strong><?= sph_num($metrics['new_ums']) ?></strong><span>Selected member + New UMS</span></article>
        <article class="imp-kpi gold"><small>First-Line PC VP</small><strong><?= sph_num($metrics['first_line_pc']) ?></strong><span>Other names • Ordered By PC</span></article>
        <article class="imp-kpi"><small>Total Monthly VP</small><strong><?= sph_num($metrics['total_vp']) ?></strong><span>All Volume Point rows in period</span></article>
      </section>

      <article class="imp-card sph-main">
        <h2>Workbook-safe SP House summary</h2>
        <p>These values reproduce the direct filters/sums that do not depend on hidden legacy adjustment constants.</p>
        <table class="sph-table">
          <thead><tr><th>Metric</th><th>Workbook dimension</th><th class="sph-right">VP</th><th>Status</th></tr></thead>
          <tbody>
            <tr><td><b>Personal Consumption VP</b></td><td>Owner + Order Type = Extra for Myself / Family</td><td class="sph-right"><b><?= sph_num($metrics['personal_family']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>First Line New UMS VP</b></td><td>Owner + Order Type = New UMS</td><td class="sph-right"><b><?= sph_num($metrics['new_ums']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>Renewal UMS source VP</b></td><td>Owner + Order Type = Renewal UMS</td><td class="sph-right"><b><?= sph_num($metrics['renewal_ums_raw']) ?></b></td><td><span class="sph-badge warn">RAW / UNADJUSTED</span></td></tr>
            <tr><td><b>First Line PC VP</b></td><td>Other names + Ordered By = PC</td><td class="sph-right"><b><?= sph_num($metrics['first_line_pc']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>First Line Associate VP</b></td><td>Other names + Ordered By = AS</td><td class="sph-right"><b><?= sph_num($metrics['first_line_associate']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>Associate Team VP</b></td><td>Ordered By = AS + VP Type = Team VP</td><td class="sph-right"><b><?= sph_num($metrics['associate_team_vp']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>Renewal + First-Line PC</b></td><td>Workbook supporting total before adjustment</td><td class="sph-right"><b><?= sph_num($metrics['renewal_plus_pc']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
            <tr><td><b>Club source VP</b></td><td>VP From = UMS</td><td class="sph-right"><b><?= sph_num($metrics['club_source_vp_unadjusted']) ?></b></td><td><span class="sph-badge warn">RAW / UNADJUSTED</span></td></tr>
            <tr><td><b>Total VP</b></td><td>All source VP in selected Year + Month</td><td class="sph-right"><b><?= sph_num($metrics['total_vp']) ?></b></td><td><span class="sph-badge">LIVE</span></td></tr>
          </tbody>
        </table>

        <div class="sph-rule"><strong>Legacy adjustment guard:</strong> the original workbook changes specific VP values such as 62.8, 63.13, 70.6, 70.93, 33.25, 29.55, 33.58, 41.05 and 41.38 when calculating adjusted Renewal/Club VP. Those constants are not normal business facts, so this live report intentionally shows the raw source VP until the constants are stored as named/versioned calculation rules.</div>
      </article>

      <aside class="imp-card sph-side">
        <h2>Selected context</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b><?= sph_h($selectedOwner) ?></b><span>Selected Member / Owner</span></div><em>OWNER</em></div>
          <div class="imp-plan-row"><div><b><?= sph_h(sph_month_name($selectedMonth)) ?> <?= $selectedYear ?></b><span>Workbook Year + Month filter</span></div><em>PERIOD</em></div>
          <div class="imp-plan-row"><div><b><?= number_format(count($pcMembers)) ?></b><span>Unique first-line PC names</span></div><em>PC</em></div>
          <div class="imp-plan-row"><div><b><?= number_format(count($associateMembers)) ?></b><span>Unique first-line Associate names</span></div><em>AS</em></div>
        </div>
      </aside>

      <article class="imp-card sph-main">
        <h2>First-Line PC members</h2>
        <p>Exact workbook filter: VP From = <b>1st Line</b> and Ordered By = <b>PC</b>, then monthly VP is summed per unique name.</p>
        <table class="sph-table"><thead><tr><th>#</th><th>Name</th><th>Source rows</th><th class="sph-right">Monthly VP</th></tr></thead><tbody>
        <?php if (!$pcMembers): ?><tr><td colspan="4" class="sph-muted">No matching first-line PC rows in this period.</td></tr><?php else: $i=1; foreach ($pcMembers as $item): ?>
          <tr><td><?= $i++ ?></td><td><b><?= sph_h($item['name']) ?></b></td><td><?= number_format($item['rows']) ?></td><td class="sph-right"><b><?= sph_num($item['vp']) ?></b></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </article>

      <aside class="imp-card sph-side">
        <h2>First-Line Associates</h2>
        <p>Exact workbook filter: VP From = <b>1st Line</b> and Ordered By = <b>AS</b>.</p>
        <table class="sph-table"><thead><tr><th>Name</th><th class="sph-right">VP</th></tr></thead><tbody>
        <?php if (!$associateMembers): ?><tr><td colspan="2" class="sph-muted">No matching Associate rows.</td></tr><?php else: foreach ($associateMembers as $item): ?>
          <tr><td><?= sph_h($item['name']) ?></td><td class="sph-right"><b><?= sph_num($item['vp']) ?></b></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
      </aside>

      <article class="imp-card sph-wide">
        <h2>Source dimension audit</h2>
        <p>This compact matrix makes the live calculation transparent and debuggable. Nothing is inferred from a person’s name.</p>
        <table class="sph-table">
          <thead><tr><th>VP From</th><th>Ordered By</th><th>VP Type</th><th>Level</th><th class="sph-right">Rows</th><th class="sph-right">VP</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($dimensionRows, 0, 30) as $item): ?>
            <tr><td><?= sph_h($item['vp_from'] ?: '—') ?></td><td><?= sph_h($item['ordered_by'] ?: '—') ?></td><td><?= sph_h($item['vp_type'] ?: '—') ?></td><td><?= sph_h($item['level'] ?: '—') ?></td><td class="sph-right"><?= number_format($item['rows']) ?></td><td class="sph-right"><b><?= sph_num($item['vp']) ?></b></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </article>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
