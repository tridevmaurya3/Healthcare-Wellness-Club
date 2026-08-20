<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function mbt_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mbt_trim(mixed $value): string
{
    return trim((string)$value);
}

function mbt_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', mbt_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function mbt_source_values(?string $json): array
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

function mbt_month_number(mixed $value): ?int
{
    $raw = mbt_trim($value);
    if ($raw === '') {
        return null;
    }
    $map = [
        'january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,
        'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,
        'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,'october'=>10,'oct'=>10,
        'november'=>11,'nov'=>11,'december'=>12,'dec'=>12,
    ];
    $key = mbt_key($raw);
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (is_numeric($raw)) {
        $n = (int)$raw;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    return null;
}

function mbt_month_name(int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!m', (string)$month);
    return $date ? $date->format('F') : (string)$month;
}

function mbt_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function mbt_money(float|int $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function mbt_resolve_distinct_label(array $rows, string $field): string
{
    $labels = [];
    foreach ($rows as $row) {
        $label = mbt_trim($row[$field] ?? '');
        if ($label !== '') {
            $labels[mbt_key($label)] = $label;
        }
    }
    if (count($labels) === 1) {
        return (string)reset($labels);
    }
    return count($labels) > 1 ? '(ambiguous)' : '(unresolved)';
}

$error = null;
$organizationId = 0;
$sourceMapped = 0;
$sourceTotal = 0;
$owners = [];
$ownerVp = [];
$periods = [];
$teamOptions = [];
$selectedOwner = '';
$selectedYear = 0;
$selectedMonth = 0;
$selectedTeam = 'ALL';

$vpRows = [];
$newUmsRows = [];
$memberMetadata = [];
$activeRows = [];
$newRows = [];
$teamDistribution = [];
$metrics = [
    'ppv' => 0.0,
    'dvp' => 0.0,
    'total_vp' => 0.0,
    'personal_consumption' => 0.0,
    'royalty' => 0.0,
    'active_count' => 0,
    'new_ums_count' => 0,
    'avg_customer_vp' => 0.0,
    'myself_count' => 0,
    'non_supervisor_count' => 0,
    'supervisor_count' => 0,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['volume_point_entries','ums_activity_snapshots','royalty_entries','raw_source_records'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
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
        throw new RuntimeException('Operational source layer must be fully reconciled at 757/757 before Master Business Tracking can run.');
    }

    $vpStmt = $pdo->prepare(
        "SELECT v.member_name_snapshot, v.entry_date, v.volume_points, v.order_type, r.raw_json
         FROM volume_point_entries v
         LEFT JOIN raw_source_records r ON r.id=v.source_record_id
         WHERE v.organization_id=? AND v.source_sheet='Volume Points'
         ORDER BY v.entry_date, v.id"
    );
    $vpStmt->execute([$organizationId]);
    foreach ($vpStmt->fetchAll() as $row) {
        $values = mbt_source_values($row['raw_json'] ?? null);
        $yearRaw = mbt_trim($values['D'] ?? '');
        $monthRaw = mbt_trim($values['E'] ?? '');
        $year = is_numeric($yearRaw) ? (int)$yearRaw : null;
        $month = mbt_month_number($monthRaw);
        if (($year === null || $month === null) && !empty($row['entry_date'])) {
            $date = new DateTimeImmutable((string)$row['entry_date']);
            $year ??= (int)$date->format('Y');
            $month ??= (int)$date->format('n');
        }
        $name = mbt_trim($row['member_name_snapshot'] ?? '');
        $nameKey = mbt_key($name);
        $prepared = [
            'name'=>$name,
            'name_key'=>$nameKey,
            'year'=>$year,
            'month'=>$month,
            'vp'=>(float)$row['volume_points'],
            'order_type'=>mbt_trim($row['order_type'] ?? ''),
            'order_type_key'=>mbt_key($row['order_type'] ?? ''),
        ];
        $vpRows[] = $prepared;
        if ($name !== '') {
            $owners[$nameKey] = $owners[$nameKey] ?? $name;
            $ownerVp[$nameKey] = ($ownerVp[$nameKey] ?? 0.0) + $prepared['vp'];
        }
        if ($year !== null && $month !== null) {
            $periods[sprintf('%04d-%02d', $year, $month)] = ['year'=>$year,'month'=>$month];
        }
    }

    $newStmt = $pdo->prepare(
        "SELECT source_row, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset='New UMS' AND mapping_status='mapped'
         ORDER BY source_row"
    );
    $newStmt->execute([$organizationId]);
    foreach ($newStmt->fetchAll() as $row) {
        $v = mbt_source_values($row['raw_json'] ?? null);
        $yearRaw = mbt_trim($v['B'] ?? '');
        $month = mbt_month_number($v['C'] ?? '');
        $prepared = [
            'source_row'=>(int)$row['source_row'],
            'year'=>is_numeric($yearRaw) ? (int)$yearRaw : null,
            'month'=>$month,
            'team'=>mbt_trim($v['E'] ?? ''),
            'name'=>mbt_trim($v['F'] ?? ''),
            'sponsor'=>mbt_trim($v['G'] ?? ''),
            'active_flag'=>mbt_trim($v['K'] ?? ''),
            'supervisor'=>mbt_trim($v['L'] ?? ''),
            'ums_type'=>mbt_trim($v['M'] ?? ''),
        ];
        $prepared['name_key'] = mbt_key($prepared['name']);
        $newUmsRows[] = $prepared;
        if ($prepared['name_key'] !== '') {
            $memberMetadata[$prepared['name_key']][] = $prepared;
        }
        if ($prepared['team'] !== '') {
            $teamOptions[mbt_key($prepared['team'])] = $prepared['team'];
        }
        if ($prepared['year'] !== null && $prepared['month'] !== null) {
            $periods[sprintf('%04d-%02d', $prepared['year'], $prepared['month'])] = ['year'=>$prepared['year'],'month'=>$prepared['month']];
        }
    }

    uasort($owners, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    arsort($ownerVp, SORT_NUMERIC);
    uasort($teamOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    krsort($periods, SORT_STRING);

    $requestedOwner = mbt_trim($_GET['owner'] ?? '');
    $requestedOwnerKey = mbt_key($requestedOwner);
    if ($requestedOwner !== '' && isset($owners[$requestedOwnerKey])) {
        $selectedOwner = $owners[$requestedOwnerKey];
    } else {
        $topOwnerKey = array_key_first($ownerVp);
        $selectedOwner = $topOwnerKey !== null && isset($owners[$topOwnerKey]) ? $owners[$topOwnerKey] : (string)reset($owners);
    }
    $selectedOwnerKey = mbt_key($selectedOwner);

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

    $requestedTeam = mbt_trim($_GET['team'] ?? 'ALL');
    $requestedTeamKey = mbt_key($requestedTeam);
    if ($requestedTeam !== '' && mbt_key($requestedTeam) !== 'all' && isset($teamOptions[$requestedTeamKey])) {
        $selectedTeam = $teamOptions[$requestedTeamKey];
    }
    $selectedTeamKey = mbt_key($selectedTeam);

    $nameTeam = [];
    foreach ($memberMetadata as $nameKey => $metaRows) {
        $nameTeam[$nameKey] = mbt_resolve_distinct_label($metaRows, 'team');
    }

    foreach ($vpRows as $row) {
        if (($row['year'] ?? null) !== $selectedYear || ($row['month'] ?? null) !== $selectedMonth) {
            continue;
        }
        $isOwner = $row['name_key'] === $selectedOwnerKey;
        if ($isOwner) {
            $metrics['ppv'] += $row['vp'];
            if ($row['order_type_key'] === mbt_key('Extra for Myself / Family')) {
                $metrics['personal_consumption'] += $row['vp'];
            }
            continue;
        }

        if ($selectedTeam !== 'ALL') {
            $resolvedTeam = $nameTeam[$row['name_key']] ?? '(unresolved)';
            if (mbt_key($resolvedTeam) !== $selectedTeamKey) {
                continue;
            }
        }
        $metrics['dvp'] += $row['vp'];
    }
    $metrics['total_vp'] = $metrics['ppv'] + $metrics['dvp'];

    $royaltyStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(amount),0)
         FROM royalty_entries
         WHERE organization_id=? AND source_sheet='Royalty_Tracking'
           AND YEAR(royalty_date)=? AND MONTH(royalty_date)=?"
    );
    $royaltyStmt->execute([$organizationId, $selectedYear, $selectedMonth]);
    $metrics['royalty'] = (float)$royaltyStmt->fetchColumn();

    $snapshotStmt = $pdo->prepare(
        "SELECT member_name_snapshot, snapshot_year, snapshot_month_number
         FROM ums_activity_snapshots
         WHERE organization_id=? AND source_sheet='Active UMS Month_Wise'
           AND snapshot_year=? AND snapshot_month_number=? AND is_active=1
         ORDER BY member_name_snapshot"
    );
    $snapshotStmt->execute([$organizationId, $selectedYear, $selectedMonth]);
    foreach ($snapshotStmt->fetchAll() as $row) {
        $name = mbt_trim($row['member_name_snapshot'] ?? '');
        $nameKey = mbt_key($name);
        if ($name === '' || $nameKey === $selectedOwnerKey) {
            continue;
        }
        $metaRows = $memberMetadata[$nameKey] ?? [];
        $team = mbt_resolve_distinct_label($metaRows, 'team');
        $sponsor = mbt_resolve_distinct_label($metaRows, 'sponsor');
        $supervisor = mbt_resolve_distinct_label($metaRows, 'supervisor');
        if ($selectedTeam !== 'ALL' && mbt_key($team) !== $selectedTeamKey) {
            continue;
        }
        $activeRows[$nameKey] = [
            'name'=>$name,
            'team'=>$team,
            'sponsor'=>$sponsor,
            'supervisor'=>$supervisor,
        ];
        $teamKey = mbt_key($team);
        $teamDistribution[$teamKey] ??= ['label'=>$team,'count'=>0];
        $teamDistribution[$teamKey]['count']++;
        if ($teamKey === mbt_key('Myself')) {
            $metrics['myself_count']++;
        } elseif ($teamKey === mbt_key('Non-Supervisor')) {
            $metrics['non_supervisor_count']++;
        } elseif ($teamKey === mbt_key('Supervisor')) {
            $metrics['supervisor_count']++;
        }
    }
    $metrics['active_count'] = count($activeRows);

    foreach ($newUmsRows as $row) {
        if (($row['year'] ?? null) !== $selectedYear || ($row['month'] ?? null) !== $selectedMonth) {
            continue;
        }
        if ($row['name_key'] === $selectedOwnerKey) {
            continue;
        }
        if ($selectedTeam !== 'ALL' && mbt_key($row['team']) !== $selectedTeamKey) {
            continue;
        }
        $newRows[] = $row;
    }
    $metrics['new_ums_count'] = count($newRows);

    if ($metrics['active_count'] > 0) {
        $metrics['avg_customer_vp'] = ($metrics['total_vp'] - $metrics['personal_consumption']) / $metrics['active_count'];
    }

    uasort($activeRows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($newRows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    uasort($teamDistribution, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
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
  <title>Master Business Tracking - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .mbt-wide{grid-column:span 12}.mbt-main{grid-column:span 8}.mbt-side{grid-column:span 4}
    .mbt-filter{display:grid;grid-template-columns:2fr 1fr 1fr 1.4fr auto;gap:10px;align-items:end}.mbt-filter label{font-size:.67rem;font-weight:800;color:#65736c;text-transform:uppercase}.mbt-filter select{width:100%;margin-top:5px;padding:10px;border:1px solid #dce7e0;border-radius:11px;background:#fff;color:#22332a}.mbt-filter button{padding:11px 17px;border:0;border-radius:11px;background:#19764a;color:#fff;font-weight:800;cursor:pointer}
    .mbt-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.75rem}.mbt-table th,.mbt-table td{padding:10px;border-bottom:1px solid #e8efea;text-align:left}.mbt-table th{font-size:.66rem;color:#65746c;text-transform:uppercase}.mbt-right{text-align:right!important}.mbt-muted{color:#6d7a73}.mbt-rule{padding:13px 14px;border:1px solid #dce8e1;border-radius:13px;background:#f8fbf9;font-size:.77rem;line-height:1.55;color:#526159}.mbt-rule.warn{background:#fff9e9;border-color:#ecd9a8;color:#735415}.mbt-chip{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#266746;font-size:.67rem;font-weight:800}
    @media(max-width:1000px){.mbt-main,.mbt-side{grid-column:span 12}.mbt-filter{grid-template-columns:1fr 1fr}.mbt-filter .owner,.mbt-filter .team{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Master Business Tracking</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="name_wise_tracking.php">← Name Wise</a>
      <a href="master_tracking.php">Master Tracking</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9E • Master Business Tracking live engine</div>
      <h1>Business performance now comes from live normalized facts, with owner and team rules exposed as filters.</h1>
      <p>PPV, DVP, Total VP, Royalty, active UMS snapshots and New UMS are calculated from the normalized source layer. Team logic uses only preserved source labels; unresolved identities are never guessed.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good"><?= number_format($sourceMapped) ?> / <?= number_format($sourceTotal) ?> source mapped</span>
      <span class="imp-chip <?= $error === null ? 'good' : '' ?>"><?= $error === null ? 'MASTER BUSINESS LIVE' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert mbt-wide"><strong>Master Business Tracking could not run:</strong> <?= mbt_h($error) ?></div>
    <?php else: ?>
      <article class="imp-card mbt-wide">
        <form method="get" class="mbt-filter">
          <div class="owner"><label for="owner">Owner</label><select id="owner" name="owner">
            <?php foreach ($owners as $name): ?><option value="<?= mbt_h($name) ?>" <?= mbt_key($name)===mbt_key($selectedOwner)?'selected':'' ?>><?= mbt_h($name) ?></option><?php endforeach; ?>
          </select></div>
          <div><label for="year">Year</label><select id="year" name="year">
            <?php $years=array_values(array_unique(array_map(static fn(array $p):int=>$p['year'],$periods))); rsort($years); foreach($years as $year): ?><option value="<?= $year ?>" <?= $year===$selectedYear?'selected':'' ?>><?= $year ?></option><?php endforeach; ?>
          </select></div>
          <div><label for="month">Month</label><select id="month" name="month">
            <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$selectedMonth?'selected':'' ?>><?= mbt_h(mbt_month_name($m)) ?></option><?php endfor; ?>
          </select></div>
          <div class="team"><label for="team">Team Filter</label><select id="team" name="team"><option value="ALL" <?= $selectedTeam==='ALL'?'selected':'' ?>>All Teams</option>
            <?php foreach($teamOptions as $team): ?><option value="<?= mbt_h($team) ?>" <?= mbt_key($team)===mbt_key($selectedTeam)?'selected':'' ?>><?= mbt_h($team) ?></option><?php endforeach; ?>
          </select></div>
          <button type="submit">Apply</button>
        </form>
      </article>

      <section class="imp-summary" aria-label="Master business summary">
        <article class="imp-kpi green"><small>PPV</small><strong><?= mbt_num($metrics['ppv']) ?></strong><span><?= mbt_h($selectedOwner) ?> personal VP</span></article>
        <article class="imp-kpi blue"><small>DVP</small><strong><?= mbt_num($metrics['dvp']) ?></strong><span><?= $selectedTeam==='ALL'?'Organization VP excluding owner':'Filtered team VP' ?></span></article>
        <article class="imp-kpi gold"><small>Total VP</small><strong><?= mbt_num($metrics['total_vp']) ?></strong><span>PPV + DVP</span></article>
        <article class="imp-kpi"><small>Royalty</small><strong><?= mbt_money($metrics['royalty']) ?></strong><span>Maximum source value for month</span></article>
      </section>

      <section class="imp-summary" aria-label="UMS summary">
        <article class="imp-kpi green"><small>Active UMS</small><strong><?= number_format($metrics['active_count']) ?></strong><span>Monthly active snapshot</span></article>
        <article class="imp-kpi blue"><small>New UMS</small><strong><?= number_format($metrics['new_ums_count']) ?></strong><span>Selected month/team</span></article>
        <article class="imp-kpi gold"><small>Personal Consumption</small><strong><?= mbt_num($metrics['personal_consumption']) ?></strong><span>Owner Extra for Myself / Family VP</span></article>
        <article class="imp-kpi"><small>Avg Customer VP</small><strong><?= mbt_num($metrics['avg_customer_vp']) ?></strong><span>(Total VP − Personal Consumption) ÷ Active UMS</span></article>
      </section>

      <article class="imp-card mbt-main">
        <h2>Active UMS • <?= mbt_h(mbt_month_name($selectedMonth)) ?> <?= $selectedYear ?></h2>
        <p>Monthly activity comes from <code>ums_activity_snapshots</code>. Team/Sponsor/Supervisor labels are resolved only when the preserved New UMS source has one unambiguous label for that name.</p>
        <table class="mbt-table"><thead><tr><th>Name</th><th>Team</th><th>Sponsor</th><th>Supervisor</th></tr></thead><tbody>
          <?php if(!$activeRows): ?><tr><td colspan="4" class="mbt-muted">No active UMS snapshot rows match this period/filter.</td></tr><?php endif; ?>
          <?php foreach($activeRows as $row): ?><tr><td><b><?= mbt_h($row['name']) ?></b></td><td><?= mbt_h($row['team']) ?></td><td><?= mbt_h($row['sponsor']) ?></td><td><?= mbt_h($row['supervisor']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </article>

      <aside class="imp-card mbt-side">
        <h2>Exact Team label counts</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>Myself</b><span>Exact source label only</span></div><em><?= number_format($metrics['myself_count']) ?></em></div>
          <div class="imp-plan-row"><div><b>Non-Supervisor</b><span>Exact source label only</span></div><em><?= number_format($metrics['non_supervisor_count']) ?></em></div>
          <div class="imp-plan-row"><div><b>Supervisor</b><span>Exact source label only</span></div><em><?= number_format($metrics['supervisor_count']) ?></em></div>
        </div>
        <?php if($teamDistribution): ?>
          <h2 style="margin-top:22px">All source team labels</h2>
          <div class="imp-plan-list"><?php foreach($teamDistribution as $team): ?><div class="imp-plan-row"><div><b><?= mbt_h($team['label']) ?></b><span>Active monthly snapshot</span></div><em><?= number_format($team['count']) ?></em></div><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="mbt-rule warn" style="margin-top:16px"><strong>Identity safety:</strong> if one name has conflicting Team/Sponsor/Supervisor labels in the source, this report shows <em>(ambiguous)</em> instead of choosing one.</div>
      </aside>

      <article class="imp-card mbt-wide">
        <h2>New UMS • <?= mbt_h(mbt_month_name($selectedMonth)) ?> <?= $selectedYear ?></h2>
        <table class="mbt-table"><thead><tr><th>Source Row</th><th>Name</th><th>Team</th><th>Sponsor</th><th>Active Flag</th><th>UMS Type</th></tr></thead><tbody>
          <?php if(!$newRows): ?><tr><td colspan="6" class="mbt-muted">No New UMS rows match this period/filter.</td></tr><?php endif; ?>
          <?php foreach($newRows as $row): ?><tr><td>#<?= number_format($row['source_row']) ?></td><td><b><?= mbt_h($row['name']) ?></b></td><td><?= mbt_h($row['team']) ?></td><td><?= mbt_h($row['sponsor']) ?></td><td><?= mbt_h($row['active_flag']) ?></td><td><?= mbt_h($row['ums_type']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </article>

      <article class="imp-card mbt-wide">
        <div class="mbt-rule"><strong>Live-engine policy:</strong> DVP excludes the selected owner. When a Team filter is active, DVP includes only VP names whose New UMS source has one exact resolved Team label matching that filter. Royalty reproduces the workbook behavior by taking the maximum royalty source value in the selected month. No hard-coded owner name is used anywhere in this report.</div>
      </article>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
