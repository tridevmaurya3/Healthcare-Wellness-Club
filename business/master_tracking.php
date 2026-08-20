<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function mt_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mt_trim(mixed $value): string
{
    return trim((string)$value);
}

function mt_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', mt_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function mt_source_values(?string $json): array
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

function mt_month_number(mixed $month): ?int
{
    $key = mt_key($month);
    $map = [
        'january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,
        'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,
        'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,'october'=>10,'oct'=>10,
        'november'=>11,'nov'=>11,'december'=>12,'dec'=>12,
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (is_numeric(mt_trim($month))) {
        $n = (int)mt_trim($month);
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    return null;
}

function mt_month_name(mixed $month): ?string
{
    $n = mt_month_number($month);
    if ($n === null) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!m', (string)$n);
    return $date ? $date->format('F') : null;
}

function mt_period_from_values(array $values, string $yearCol, string $monthCol, string $weekCol = ''): array
{
    $yearRaw = mt_trim($values[$yearCol] ?? '');
    $monthRaw = mt_trim($values[$monthCol] ?? '');
    $year = is_numeric($yearRaw) ? (int)$yearRaw : null;
    $monthNumber = mt_month_number($monthRaw);
    return [
        'year' => $year,
        'month' => $monthNumber !== null ? mt_month_name($monthRaw) : null,
        'month_number' => $monthNumber,
        'week' => $weekCol !== '' ? mt_trim($values[$weekCol] ?? '') : '',
    ];
}

function mt_period_matches(array $period, int $year, int $monthNumber, ?string $week = null): bool
{
    if (($period['year'] ?? null) !== $year || ($period['month_number'] ?? null) !== $monthNumber) {
        return false;
    }
    return $week === null || mt_key($period['week'] ?? '') === mt_key($week);
}

function mt_num(float|int $value, int $decimals = 2): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function mt_money(float|int $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

$error = null;
$organizationId = 0;
$vpRows = [];
$extraRows = [];
$newUmsRows = [];
$owners = [];
$ownerTotals = [];
$periods = [];
$weeks = ['Week-1', 'Week-2', 'Week-3', 'Week-4'];
$selectedOwner = '';
$selectedYear = 0;
$selectedMonth = '';
$selectedMonthNumber = 0;

$vpReport = [];
$umsReport = [];
$incomeReport = [];
$royaltyReport = [];
$kpis = [];
$sourceCompleteness = ['mapped' => 0, 'total' => 0];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['volume_point_entries', 'orders', 'income_entries', 'royalty_entries', 'raw_source_records'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    $sourceStateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows, SUM(mapping_status='mapped') mapped_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $sourceStateStmt->execute([$organizationId]);
    $sourceState = $sourceStateStmt->fetch() ?: [];
    $sourceCompleteness = [
        'mapped' => (int)($sourceState['mapped_rows'] ?? 0),
        'total' => (int)($sourceState['total_rows'] ?? 0),
    ];
    if ($sourceCompleteness['mapped'] !== 757 || $sourceCompleteness['total'] !== 757) {
        throw new RuntimeException('Operational source layer is not fully reconciled at 757/757 mapped rows.');
    }

    $vpStmt = $pdo->prepare(
        "SELECT v.id, v.member_id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label,
                v.volume_points, v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set,
                r.raw_json
         FROM volume_point_entries v
         LEFT JOIN raw_source_records r ON r.id=v.source_record_id
         WHERE v.organization_id=? AND v.source_sheet='Volume Points'
         ORDER BY v.entry_date, v.id"
    );
    $vpStmt->execute([$organizationId]);
    foreach ($vpStmt->fetchAll() as $row) {
        $values = mt_source_values($row['raw_json'] ?? null);
        $period = mt_period_from_values($values, 'D', 'E', 'F');
        if (($period['year'] ?? null) === null && !empty($row['entry_date'])) {
            $d = new DateTimeImmutable((string)$row['entry_date']);
            $period['year'] = (int)$d->format('Y');
            $period['month_number'] = (int)$d->format('n');
            $period['month'] = $d->format('F');
        }
        if (($period['week'] ?? '') === '') {
            $period['week'] = mt_trim($row['week_label'] ?? '');
        }
        $row['period'] = $period;
        $row['name_key'] = mt_key($row['member_name_snapshot'] ?? '');
        $row['level_key'] = mt_key($row['level_label'] ?? '');
        $row['order_type_key'] = mt_key($row['order_type'] ?? '');
        $vpRows[] = $row;

        $displayName = mt_trim($row['member_name_snapshot'] ?? '');
        if ($displayName !== '') {
            $key = mt_key($displayName);
            $owners[$key] = $owners[$key] ?? $displayName;
            $ownerTotals[$key] = ($ownerTotals[$key] ?? 0.0) + (float)$row['volume_points'];
        }
        if (($period['year'] ?? null) !== null && ($period['month_number'] ?? null) !== null) {
            $periodKey = sprintf('%04d-%02d', (int)$period['year'], (int)$period['month_number']);
            $periods[$periodKey] = ['year'=>(int)$period['year'], 'month'=>(string)$period['month'], 'month_number'=>(int)$period['month_number']];
        }
    }

    $extraStmt = $pdo->prepare(
        "SELECT o.id, o.member_id, o.order_date, o.net_amount, o.profit_amount, o.volume_points, o.notes, r.raw_json
         FROM orders o
         LEFT JOIN raw_source_records r ON r.id=o.source_record_id
         WHERE o.organization_id=? AND o.source_sheet='Extra Order for Customer'
         ORDER BY o.order_date, o.id"
    );
    $extraStmt->execute([$organizationId]);
    foreach ($extraStmt->fetchAll() as $row) {
        $values = mt_source_values($row['raw_json'] ?? null);
        $row['period'] = mt_period_from_values($values, 'B', 'C', 'D');
        $extraRows[] = $row;
        $p = $row['period'];
        if (($p['year'] ?? null) !== null && ($p['month_number'] ?? null) !== null) {
            $key = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month_number']);
            $periods[$key] = ['year'=>(int)$p['year'], 'month'=>(string)$p['month'], 'month_number'=>(int)$p['month_number']];
        }
    }

    $newUmsStmt = $pdo->prepare(
        "SELECT raw_json FROM raw_source_records
         WHERE organization_id=? AND source_dataset='New UMS' AND mapping_status='mapped'
         ORDER BY source_row"
    );
    $newUmsStmt->execute([$organizationId]);
    foreach ($newUmsStmt->fetchAll() as $row) {
        $values = mt_source_values($row['raw_json'] ?? null);
        $newUmsRows[] = [
            'period' => mt_period_from_values($values, 'B', 'C', 'D'),
            'team' => mt_trim($values['E'] ?? ''),
            'name' => mt_trim($values['F'] ?? ''),
        ];
    }

    if (!$owners || !$periods) {
        throw new RuntimeException('Master Tracking cannot run because owner/period source dimensions are empty.');
    }

    uasort($ownerTotals, static fn(float $a, float $b): int => $b <=> $a);
    $defaultOwnerKey = (string)array_key_first($ownerTotals);
    $requestedOwnerKey = mt_key($_GET['owner'] ?? '');
    $selectedOwnerKey = isset($owners[$requestedOwnerKey]) ? $requestedOwnerKey : $defaultOwnerKey;
    $selectedOwner = $owners[$selectedOwnerKey];

    krsort($periods);
    $defaultPeriod = reset($periods);
    $requestedYear = isset($_GET['year']) && is_numeric((string)$_GET['year']) ? (int)$_GET['year'] : 0;
    $requestedMonthNumber = mt_month_number($_GET['month'] ?? '');
    $requestedPeriodKey = ($requestedYear > 0 && $requestedMonthNumber !== null)
        ? sprintf('%04d-%02d', $requestedYear, $requestedMonthNumber)
        : '';
    $selectedPeriod = ($requestedPeriodKey !== '' && isset($periods[$requestedPeriodKey]))
        ? $periods[$requestedPeriodKey]
        : $defaultPeriod;
    $selectedYear = (int)$selectedPeriod['year'];
    $selectedMonth = (string)$selectedPeriod['month'];
    $selectedMonthNumber = (int)$selectedPeriod['month_number'];

    $bucketDefinitions = [
        'personal_ppv' => ['label'=>'Personal PPV', 'type'=>'owner_order', 'match'=>'Extra for Myself / Family'],
        'new_ums_vp' => ['label'=>'New UMS VP', 'type'=>'owner_order', 'match'=>'New UMS'],
        'renewal_ums_vp' => ['label'=>'Renewal UMS VP', 'type'=>'owner_order', 'match'=>'Renewal UMS'],
        'extra_order_vp' => ['label'=>'Extra Order VP', 'type'=>'extra_order'],
        'level_15' => ['label'=>'@15% VP', 'type'=>'level', 'match'=>'@15%'],
        'level_25' => ['label'=>'@25% VP', 'type'=>'level', 'match'=>'@25%'],
        'level_35' => ['label'=>'@35% VP', 'type'=>'level', 'match'=>'@35%'],
        'level_42' => ['label'=>'@42% VP', 'type'=>'level', 'match'=>'@42%'],
        'level_50' => ['label'=>'@50% VP', 'type'=>'level', 'match'=>'@50%'],
        'total_non_sp' => ['label'=>'Total Non-SP VP', 'type'=>'non_owner_total'],
        'total_vp' => ['label'=>'Total VP', 'type'=>'all_vp'],
    ];

    foreach ($bucketDefinitions as $bucketKey => $def) {
        $valuesByWeek = [];
        foreach ($weeks as $week) {
            $sum = 0.0;
            if ($def['type'] === 'extra_order') {
                foreach ($extraRows as $row) {
                    if (mt_period_matches($row['period'], $selectedYear, $selectedMonthNumber, $week)) {
                        $sum += (float)$row['volume_points'];
                    }
                }
            } else {
                foreach ($vpRows as $row) {
                    if (!mt_period_matches($row['period'], $selectedYear, $selectedMonthNumber, $week)) {
                        continue;
                    }
                    $include = match ($def['type']) {
                        'owner_order' => $row['name_key'] === $selectedOwnerKey && $row['order_type_key'] === mt_key($def['match']),
                        'level' => $row['name_key'] !== $selectedOwnerKey && $row['level_key'] === mt_key($def['match']),
                        'non_owner_total' => $row['name_key'] !== $selectedOwnerKey,
                        'all_vp' => true,
                        default => false,
                    };
                    if ($include) {
                        $sum += (float)$row['volume_points'];
                    }
                }
            }
            $valuesByWeek[$week] = $sum;
        }
        $vpReport[$bucketKey] = [
            'label' => $def['label'],
            'weeks' => $valuesByWeek,
            'total' => array_sum($valuesByWeek),
        ];
    }

    $umsDefinitions = [
        'first_line' => ['label'=>'First Line UMS', 'team'=>'Myself'],
        'non_supervisor' => ['label'=>'Non-Supervisor UMS', 'team'=>'Non-Supervisor'],
        'organizational' => ['label'=>'Organizational UMS', 'team'=>'Supervisor'],
    ];
    foreach ($umsDefinitions as $key => $def) {
        $counts = [];
        foreach ($weeks as $week) {
            $count = 0;
            foreach ($newUmsRows as $row) {
                if (mt_period_matches($row['period'], $selectedYear, $selectedMonthNumber, $week)
                    && mt_key($row['team']) === mt_key($def['team'])
                    && $row['name'] !== '') {
                    $count++;
                }
            }
            $counts[$week] = $count;
        }
        $umsReport[$key] = ['label'=>$def['label'], 'weeks'=>$counts, 'total'=>array_sum($counts)];
    }
    $totalNewCounts = [];
    foreach ($weeks as $week) {
        $count = 0;
        foreach ($newUmsRows as $row) {
            if (mt_period_matches($row['period'], $selectedYear, $selectedMonthNumber, $week) && $row['name'] !== '') {
                $count++;
            }
        }
        $totalNewCounts[$week] = $count;
    }
    $umsReport['total'] = ['label'=>'Total New UMS', 'weeks'=>$totalNewCounts, 'total'=>array_sum($totalNewCounts)];

    $periodKey = sprintf('%04d-%02d', $selectedYear, $selectedMonthNumber);
    $incomeStmt = $pdo->prepare(
        "SELECT income_type, COALESCE(SUM(amount),0) amount
         FROM income_entries
         WHERE organization_id=? AND source_sheet='Monthely_Income' AND period_key=?
         GROUP BY income_type"
    );
    $incomeStmt->execute([$organizationId, $periodKey]);
    $monthlyIncome = ['retail'=>0.0,'check'=>0.0,'club'=>0.0];
    foreach ($incomeStmt->fetchAll() as $row) {
        $type = mt_key($row['income_type'] ?? '');
        if (array_key_exists($type, $monthlyIncome)) {
            $monthlyIncome[$type] = (float)$row['amount'];
        }
    }

    $extraProfitWeeks = [];
    foreach ($weeks as $week) {
        $sum = 0.0;
        foreach ($extraRows as $row) {
            if (mt_period_matches($row['period'], $selectedYear, $selectedMonthNumber, $week)) {
                $sum += (float)$row['profit_amount'];
            }
        }
        $extraProfitWeeks[$week] = $sum;
    }

    $royaltyStmt = $pdo->prepare(
        "SELECT period_label, MAX(amount) amount
         FROM royalty_entries
         WHERE organization_id=? AND source_sheet='Royalty_Tracking'
           AND YEAR(royalty_date)=? AND MONTH(royalty_date)=?
         GROUP BY period_label"
    );
    $royaltyStmt->execute([$organizationId, $selectedYear, $selectedMonthNumber]);
    $royaltyCumulative = [];
    foreach ($royaltyStmt->fetchAll() as $row) {
        $royaltyCumulative[mt_key($row['period_label'] ?? '')] = (float)$row['amount'];
    }
    $running = 0.0;
    foreach ($weeks as $week) {
        $cumulative = $royaltyCumulative[mt_key($week)] ?? 0.0;
        $increment = max(0.0, $cumulative - $running);
        $royaltyReport[$week] = $increment;
        $running += $increment;
    }

    $incomeReport = [
        'monthly_retail' => $monthlyIncome['retail'],
        'monthly_check' => $monthlyIncome['check'],
        'monthly_club' => $monthlyIncome['club'],
        'extra_profit_weeks' => $extraProfitWeeks,
        'extra_profit_total' => array_sum($extraProfitWeeks),
        'royalty_weeks' => $royaltyReport,
        'royalty_total' => array_sum($royaltyReport),
    ];

    $kpis = [
        'personal_vp' => $vpReport['personal_ppv']['total'] + $vpReport['new_ums_vp']['total'] + $vpReport['renewal_ums_vp']['total'],
        'total_vp' => $vpReport['total_vp']['total'],
        'non_sp_vp' => $vpReport['total_non_sp']['total'],
        'extra_order_vp' => $vpReport['extra_order_vp']['total'],
        'new_ums_count' => $umsReport['total']['total'],
        'known_income' => $monthlyIncome['retail'] + $monthlyIncome['check'] + $monthlyIncome['club'],
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$monthOptions = [];
foreach ($periods as $period) {
    $monthOptions[(int)$period['month_number']] = (string)$period['month'];
}
ksort($monthOptions);
$yearOptions = array_values(array_unique(array_map(static fn(array $p): int => (int)$p['year'], $periods)));
rsort($yearOptions);
$ownerOptions = $owners;
natcasesort($ownerOptions);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Master Tracking Live - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    :root{--mt-green:#176b45;--mt-ink:#173228;--mt-muted:#68766f;--mt-line:#e3ebe6;--mt-warn:#fff8e7}
    .mt-wide{grid-column:span 12}.mt-main{grid-column:span 8}.mt-side{grid-column:span 4}
    .mt-filter{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end;padding:15px;border:1px solid var(--mt-line);border-radius:15px;background:#fff;margin-bottom:16px}.mt-filter label{display:grid;gap:6px;font-size:.7rem;font-weight:800;color:#53655c}.mt-filter select{min-height:42px;border:1px solid #dce7e1;border-radius:10px;padding:0 11px;background:#fff;color:#213b30}.mt-filter button{min-height:42px;border:0;border-radius:10px;background:var(--mt-green);color:#fff;padding:0 18px;font-weight:800;cursor:pointer}
    .mt-kpis{grid-column:span 12;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.mt-kpi{padding:15px;border:1px solid var(--mt-line);border-radius:14px;background:#fff}.mt-kpi small{display:block;color:var(--mt-muted);font-size:.67rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.mt-kpi strong{display:block;margin-top:7px;font-size:1.25rem;color:var(--mt-ink)}.mt-kpi span{display:block;margin-top:3px;color:#77847e;font-size:.69rem}
    .mt-table-wrap{overflow:auto;margin-top:12px}.mt-table{width:100%;border-collapse:collapse;min-width:720px;font-size:.76rem}.mt-table th,.mt-table td{padding:10px 11px;border-bottom:1px solid #e8efeb;text-align:right;white-space:nowrap}.mt-table th:first-child,.mt-table td:first-child{text-align:left}.mt-table th{font-size:.66rem;color:#66746d;text-transform:uppercase;background:#f8fbf9}.mt-table tr.total td{font-weight:900;background:#f2f8f4}.mt-table td.pos{color:#176b45;font-weight:800}
    .mt-section-note{margin-top:10px;color:var(--mt-muted);font-size:.74rem;line-height:1.55}.mt-chip{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#1f6b45;font-size:.66rem;font-weight:900}.mt-chip.warn{background:#fff3d7;color:#7d5b12}.mt-rule{padding:12px 13px;border:1px solid #ebdfb8;border-radius:12px;background:var(--mt-warn);margin-top:9px}.mt-rule b{display:block;color:#654c13;font-size:.78rem}.mt-rule span{display:block;margin-top:4px;color:#77673e;font-size:.72rem;line-height:1.5}.mt-mini-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:12px}.mt-mini{padding:13px;border:1px solid var(--mt-line);border-radius:12px;background:#fbfdfc}.mt-mini small{display:block;color:#6c7a73;font-size:.66rem}.mt-mini strong{display:block;margin-top:5px;color:#1c3d2f;font-size:1rem}.mt-source{padding:12px;border-radius:12px;background:#eff8f3;color:#285c43;font-size:.73rem;line-height:1.55;margin-top:12px}
    @media(max-width:1000px){.mt-kpis{grid-template-columns:repeat(3,1fr)}.mt-main,.mt-side{grid-column:span 12}}@media(max-width:680px){.mt-filter{grid-template-columns:1fr 1fr}.mt-filter label:first-child{grid-column:span 2}.mt-filter button{grid-column:span 2}.mt-kpis{grid-template-columns:repeat(2,1fr)}.mt-mini-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<header class="imp-topbar"><div class="imp-topbar-inner"><a class="imp-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Master Tracking Live</small></span></a><nav class="imp-nav" aria-label="Business navigation"><a href="derived_reports_audit.php">Formula Audit</a><a href="index.php">Business OS</a></nav></div></header>

<main class="imp-shell">
  <section class="imp-hero"><div><div class="imp-kicker">Step 9B • Derived Report 1 of 6</div><h1>Master Tracking is now a live database-powered report.</h1><p>Weekly VP, New UMS counts, extra-order performance, monthly source income and royalty are calculated from the reconciled operational layer. Hard-coded workbook owner names are replaced by a selector, while unsafe legacy currency/price formulas stay explicitly deferred.</p></div><div class="imp-safety"><span class="imp-chip good">757 / 757 source mapped</span><span class="imp-chip good">Read-only derived report</span><span class="imp-chip good">Master Tracking LIVE</span></div></section>

  <?php if ($error !== null): ?>
    <section class="imp-grid"><div class="imp-alert mt-wide"><strong>Master Tracking could not run:</strong> <?= mt_h($error) ?></div></section>
  <?php else: ?>
    <form class="mt-filter" method="get"><label>Owner / Member<select name="owner"><?php foreach ($ownerOptions as $owner): ?><option value="<?= mt_h($owner) ?>" <?= mt_key($owner)==mt_key($selectedOwner)?'selected':'' ?>><?= mt_h($owner) ?></option><?php endforeach; ?></select></label><label>Year<select name="year"><?php foreach ($yearOptions as $year): ?><option value="<?= $year ?>" <?= $year===$selectedYear?'selected':'' ?>><?= $year ?></option><?php endforeach; ?></select></label><label>Month<select name="month"><?php foreach ($monthOptions as $monthNum=>$monthName): ?><option value="<?= mt_h($monthName) ?>" <?= $monthNum===$selectedMonthNumber?'selected':'' ?>><?= mt_h($monthName) ?></option><?php endforeach; ?></select></label><button type="submit">Refresh Report</button></form>

    <section class="imp-grid">
      <section class="mt-kpis" aria-label="Master Tracking summary"><article class="mt-kpi"><small>Selected Owner</small><strong style="font-size:1rem"><?= mt_h($selectedOwner) ?></strong><span><?= mt_h($selectedMonth) ?> <?= $selectedYear ?></span></article><article class="mt-kpi"><small>Personal VP</small><strong><?= mt_num($kpis['personal_vp'], 3) ?></strong><span>PPV + New UMS + Renewal</span></article><article class="mt-kpi"><small>Total VP</small><strong><?= mt_num($kpis['total_vp'], 3) ?></strong><span>All Volume Points source</span></article><article class="mt-kpi"><small>Non-SP VP</small><strong><?= mt_num($kpis['non_sp_vp'], 3) ?></strong><span>All VP excluding owner</span></article><article class="mt-kpi"><small>Extra Order VP</small><strong><?= mt_num($kpis['extra_order_vp'], 3) ?></strong><span>Extra customer orders</span></article><article class="mt-kpi"><small>New UMS</small><strong><?= number_format((int)$kpis['new_ums_count']) ?></strong><span>Selected source period</span></article></section>

      <article class="imp-card mt-main"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start"><div><h2>Personal Weekly VP Tracking</h2><p>Workbook rows 4–13 rebuilt from normalized VP and Extra Order facts.</p></div><span class="mt-chip">LIVE CALCULATION</span></div><div class="mt-table-wrap"><table class="mt-table"><thead><tr><th>Tracking</th><?php foreach ($weeks as $week): ?><th><?= mt_h($week) ?></th><?php endforeach; ?><th>Total</th></tr></thead><tbody><?php foreach ($vpReport as $key=>$row): ?><tr class="<?= in_array($key,['total_non_sp','total_vp'],true)?'total':'' ?>"><td><?= mt_h($row['label']) ?></td><?php foreach ($weeks as $week): ?><td class="<?= $row['weeks'][$week] != 0 ? 'pos' : '' ?>"><?= mt_num((float)$row['weeks'][$week],3) ?></td><?php endforeach; ?><td class="pos"><?= mt_num((float)$row['total'],3) ?></td></tr><?php endforeach; ?></tbody></table></div><p class="mt-section-note"><strong>Parity rule:</strong> @15/@25/@35/@42/@50 rows exclude the selected owner exactly as the original Master_Tracking formulas excluded the hard-coded owner. “Total VP” is the Volume Points dataset total; Extra Order VP remains a separate source exactly like the workbook.</p></article>

      <aside class="imp-card mt-side"><h2>Source-backed monthly values</h2><p>These amounts come from normalized operational facts, not duplicated formula cells.</p><div class="mt-mini-grid"><div class="mt-mini"><small>Retail Income</small><strong><?= mt_money($incomeReport['monthly_retail']) ?></strong></div><div class="mt-mini"><small>Check Income</small><strong><?= mt_money($incomeReport['monthly_check']) ?></strong></div><div class="mt-mini"><small>Club Income</small><strong><?= mt_money($incomeReport['monthly_club']) ?></strong></div></div><div class="mt-mini-grid"><div class="mt-mini"><small>Extra Order Profit</small><strong><?= mt_money($incomeReport['extra_profit_total']) ?></strong></div><div class="mt-mini"><small>Royalty</small><strong><?= mt_money($incomeReport['royalty_total']) ?></strong></div><div class="mt-mini"><small>Total Source Income</small><strong><?= mt_money($kpis['known_income']) ?></strong></div></div><div class="mt-source"><strong>Trace status:</strong> <?= number_format($sourceCompleteness['mapped']) ?> / <?= number_format($sourceCompleteness['total']) ?> operational raw rows are mapped. This report performs no database write.</div></aside>

      <article class="imp-card mt-main"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start"><div><h2>Weekly New UMS Tracking</h2><p>Team categories are read from preserved New UMS source metadata so they are not guessed from names.</p></div><span class="mt-chip">SOURCE TRACE</span></div><div class="mt-table-wrap"><table class="mt-table"><thead><tr><th>Tracking</th><?php foreach ($weeks as $week): ?><th><?= mt_h($week) ?></th><?php endforeach; ?><th>Total</th></tr></thead><tbody><?php foreach ($umsReport as $key=>$row): ?><tr class="<?= $key==='total'?'total':'' ?>"><td><?= mt_h($row['label']) ?></td><?php foreach ($weeks as $week): ?><td><?= number_format((int)$row['weeks'][$week]) ?></td><?php endforeach; ?><td><?= number_format((int)$row['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></article>

      <aside class="imp-card mt-side"><h2>Legacy rules intentionally not guessed</h2><div class="mt-rule"><b>Approximate Check Income</b><span>The workbook multiplies VP values by a live USD→INR rate and royalty percentages. This will activate only after a versioned exchange-rate rule is configured.</span></div><div class="mt-rule"><b>Weekly Retail / Club Retail Income</b><span>The old sheet contains hard-coded product-price difference constants. Monthly source income is shown live now; weekly reconstruction waits for the Product & Price rule catalog.</span></div><div class="mt-rule"><b>Non-Supervisor ₹ value</b><span>@15/@25/@35/@42 VP quantities are live. Their rupee conversion remains deferred until the exchange-rate and compensation rule source is explicit.</span></div></aside>

      <article class="imp-card mt-wide"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start"><div><h2>Weekly Income & Royalty — safe live components</h2><p>Extra-order profit and royalty can be reproduced exactly from normalized facts. Monthly Retail/Check/Club source totals are shown above because their workbook weekly split contains legacy product-price constants.</p></div><span class="mt-chip warn">LEGACY PRICE RULES DEFERRED</span></div><div class="mt-table-wrap"><table class="mt-table"><thead><tr><th>Tracking</th><?php foreach ($weeks as $week): ?><th><?= mt_h($week) ?></th><?php endforeach; ?><th>Total</th></tr></thead><tbody><tr><td>Extra Order Income / Profit</td><?php foreach ($weeks as $week): ?><td><?= mt_money((float)$incomeReport['extra_profit_weeks'][$week]) ?></td><?php endforeach; ?><td><?= mt_money((float)$incomeReport['extra_profit_total']) ?></td></tr><tr class="total"><td>Royalty</td><?php foreach ($weeks as $week): ?><td><?= mt_money((float)$incomeReport['royalty_weeks'][$week]) ?></td><?php endforeach; ?><td><?= mt_money((float)$incomeReport['royalty_total']) ?></td></tr></tbody></table></div><p class="mt-section-note">Royalty follows the workbook’s cumulative-to-weekly behavior: each week is the period’s cumulative maximum minus previously recognized weeks.</p></article>
    </section>
  <?php endif; ?>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
