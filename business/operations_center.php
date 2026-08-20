<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function op_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function op_trim(mixed $value): string
{
    return trim((string)$value);
}

function op_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', op_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function op_notes(mixed $value): array
{
    $raw = op_trim($value);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function op_money(float|int $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function op_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function op_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y');
    } catch (Throwable) {
        return $value;
    }
}

function op_period(?string $date): ?array
{
    if (!$date) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($date);
        return [
            'year' => (int)$d->format('Y'),
            'month' => (int)$d->format('n'),
            'key' => $d->format('Y-m'),
        ];
    } catch (Throwable) {
        return null;
    }
}

function op_period_matches(?string $date, string $yearFilter, string $monthFilter): bool
{
    $period = op_period($date);
    if ($period === null) {
        return false;
    }
    if ($yearFilter !== 'ALL' && $period['year'] !== (int)$yearFilter) {
        return false;
    }
    if ($monthFilter !== 'ALL' && $period['month'] !== (int)$monthFilter) {
        return false;
    }
    return true;
}

function op_search_matches(string $query, array $values): bool
{
    if ($query === '') {
        return true;
    }
    return str_contains(op_key(implode(' ', array_map(static fn(mixed $v): string => (string)$v, $values))), op_key($query));
}

function op_month_name(int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!m', (string)$month);
    return $date ? $date->format('F') : (string)$month;
}

function op_initial(string $name): string
{
    if ($name === '') {
        return '?';
    }
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}

$error = null;
$organizationId = 0;
$sourceTotal = 0;
$sourceMapped = 0;
$sourcePending = 0;
$members = [];
$membersById = [];
$orders = [];
$vpRows = [];
$incomeRows = [];
$royaltyRows = [];
$periods = [];

$filteredOrders = [];
$filteredVp = [];
$filteredIncome = [];
$filteredRoyalty = [];
$feed = [];

$filters = [
    'year' => op_trim($_GET['year'] ?? ''),
    'month' => op_trim($_GET['month'] ?? ''),
    'member' => isset($_GET['member']) && is_numeric($_GET['member']) ? (int)$_GET['member'] : 0,
    'q' => op_trim($_GET['q'] ?? ''),
];

$metrics = [
    'orders' => 0,
    'order_value' => 0.0,
    'profit' => 0.0,
    'order_vp' => 0.0,
    'vp_facts' => 0,
    'vp_total' => 0.0,
    'income_facts' => 0,
    'income_total' => 0.0,
    'income_retail' => 0.0,
    'income_check' => 0.0,
    'income_club' => 0.0,
    'royalty_facts' => 0,
    'royalty_total' => 0.0,
    'source_only_orders' => 0,
    'source_only_vp' => 0,
    'trace_total' => 0,
    'trace_with_source' => 0,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['raw_source_records','members','orders','volume_point_entries','income_entries','royalty_entries'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    $stateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows,
                SUM(mapping_status='mapped') mapped_rows,
                SUM(mapping_status='pending') pending_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $stateStmt->execute([$organizationId]);
    $state = $stateStmt->fetch() ?: [];
    $sourceTotal = (int)($state['total_rows'] ?? 0);
    $sourceMapped = (int)($state['mapped_rows'] ?? 0);
    $sourcePending = (int)($state['pending_rows'] ?? 0);
    if ($sourceTotal !== 757 || $sourceMapped !== 757 || $sourcePending !== 0) {
        throw new RuntimeException('Operational source layer must remain reconciled at 757/757 before Operations Center can run.');
    }

    $memberStmt = $pdo->prepare(
        "SELECT id, full_name, mobile FROM members WHERE organization_id=? ORDER BY full_name, id"
    );
    $memberStmt->execute([$organizationId]);
    foreach ($memberStmt->fetchAll() as $row) {
        $member = [
            'id' => (int)$row['id'],
            'full_name' => (string)$row['full_name'],
            'mobile' => (string)($row['mobile'] ?? ''),
        ];
        $members[] = $member;
        $membersById[$member['id']] = $member;
    }

    $orderStmt = $pdo->prepare(
        "SELECT o.id, o.member_id, o.order_date, o.order_type, o.description,
                o.gross_amount, o.net_amount, o.profit_amount, o.volume_points,
                o.notes, o.source_record_id, o.source_sheet, o.source_row,
                m.full_name linked_member_name
         FROM orders o
         LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
         WHERE o.organization_id=?
         ORDER BY o.order_date DESC, o.id DESC"
    );
    $orderStmt->execute([$organizationId]);
    foreach ($orderStmt->fetchAll() as $row) {
        $notes = op_notes($row['notes'] ?? null);
        $snapshotName = op_trim($notes['member_name_snapshot'] ?? '');
        $displayName = op_trim($row['linked_member_name'] ?? '');
        if ($displayName === '') {
            $displayName = $snapshotName;
        }
        if ($displayName === '' && preg_match('/Member:\s*(.+)$/i', (string)($row['description'] ?? ''), $match)) {
            $displayName = trim((string)$match[1]);
        }
        if ($displayName === '') {
            $displayName = 'Source-only customer';
        }
        $period = op_period((string)$row['order_date']);
        if ($period) {
            $periods[$period['key']] = $period;
        }
        $orders[] = $row + [
            'display_name' => $displayName,
            'identity_state' => $row['member_id'] !== null ? 'linked' : 'source_only',
            'metadata' => $notes,
        ];
    }

    $vpStmt = $pdo->prepare(
        "SELECT v.id, v.member_id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label,
                v.volume_points, v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set,
                v.source_record_id, v.source_sheet, v.source_row,
                m.full_name linked_member_name
         FROM volume_point_entries v
         LEFT JOIN members m ON m.id=v.member_id AND m.organization_id=v.organization_id
         WHERE v.organization_id=?
         ORDER BY v.entry_date DESC, v.id DESC"
    );
    $vpStmt->execute([$organizationId]);
    foreach ($vpStmt->fetchAll() as $row) {
        $displayName = op_trim($row['linked_member_name'] ?? '');
        if ($displayName === '') {
            $displayName = op_trim($row['member_name_snapshot'] ?? '');
        }
        if ($displayName === '') {
            $displayName = 'Source-only member';
        }
        $period = op_period($row['entry_date'] ? (string)$row['entry_date'] : null);
        if ($period) {
            $periods[$period['key']] = $period;
        }
        $vpRows[] = $row + [
            'display_name' => $displayName,
            'identity_state' => $row['member_id'] !== null ? 'linked' : 'source_only',
        ];
    }

    $incomeStmt = $pdo->prepare(
        "SELECT id, income_date, income_type, amount, currency_code, period_key, notes,
                source_record_id, source_sheet, source_row
         FROM income_entries
         WHERE organization_id=?
         ORDER BY income_date DESC, id DESC"
    );
    $incomeStmt->execute([$organizationId]);
    foreach ($incomeStmt->fetchAll() as $row) {
        $period = op_period((string)$row['income_date']);
        if ($period) {
            $periods[$period['key']] = $period;
        }
        $incomeRows[] = $row;
    }

    $royaltyStmt = $pdo->prepare(
        "SELECT id, royalty_date, period_label, amount, currency_code, volume_points, notes,
                source_record_id, source_sheet, source_row
         FROM royalty_entries
         WHERE organization_id=?
         ORDER BY royalty_date DESC, id DESC"
    );
    $royaltyStmt->execute([$organizationId]);
    foreach ($royaltyStmt->fetchAll() as $row) {
        $period = op_period($row['royalty_date'] ? (string)$row['royalty_date'] : null);
        if ($period) {
            $periods[$period['key']] = $period;
        }
        $royaltyRows[] = $row;
    }

    krsort($periods, SORT_STRING);
    $latestPeriod = reset($periods) ?: null;

    if ($filters['year'] === '') {
        $filters['year'] = $latestPeriod ? (string)$latestPeriod['year'] : 'ALL';
    }
    if ($filters['month'] === '') {
        $filters['month'] = $latestPeriod ? (string)$latestPeriod['month'] : 'ALL';
    }
    if ($filters['year'] !== 'ALL' && !preg_match('/^\d{4}$/', $filters['year'])) {
        $filters['year'] = 'ALL';
    }
    if ($filters['month'] !== 'ALL') {
        $monthValue = is_numeric($filters['month']) ? (int)$filters['month'] : 0;
        if ($monthValue < 1 || $monthValue > 12) {
            $filters['month'] = 'ALL';
        }
    }
    if ($filters['member'] > 0 && !isset($membersById[$filters['member']])) {
        $filters['member'] = 0;
    }

    $filteredOrders = array_values(array_filter($orders, static function(array $row) use ($filters): bool {
        if (!op_period_matches((string)$row['order_date'], $filters['year'], $filters['month'])) {
            return false;
        }
        if ($filters['member'] > 0 && (int)($row['member_id'] ?? 0) !== $filters['member']) {
            return false;
        }
        return op_search_matches($filters['q'], [
            $row['display_name'], $row['order_type'], $row['description'], $row['source_sheet'], $row['source_row'],
        ]);
    }));

    $filteredVp = array_values(array_filter($vpRows, static function(array $row) use ($filters): bool {
        if (!op_period_matches($row['entry_date'] ? (string)$row['entry_date'] : null, $filters['year'], $filters['month'])) {
            return false;
        }
        if ($filters['member'] > 0 && (int)($row['member_id'] ?? 0) !== $filters['member']) {
            return false;
        }
        return op_search_matches($filters['q'], [
            $row['display_name'], $row['order_type'], $row['vp_from'], $row['ordered_by'],
            $row['vp_type'], $row['level_label'], $row['week_label'], $row['source_sheet'], $row['source_row'],
        ]);
    }));

    $filteredIncome = array_values(array_filter($incomeRows, static function(array $row) use ($filters): bool {
        if (!op_period_matches((string)$row['income_date'], $filters['year'], $filters['month'])) {
            return false;
        }
        return op_search_matches($filters['q'], [
            $row['income_type'], $row['period_key'], $row['source_sheet'], $row['source_row'],
        ]);
    }));

    $filteredRoyalty = array_values(array_filter($royaltyRows, static function(array $row) use ($filters): bool {
        if (!op_period_matches($row['royalty_date'] ? (string)$row['royalty_date'] : null, $filters['year'], $filters['month'])) {
            return false;
        }
        return op_search_matches($filters['q'], [
            $row['period_label'], $row['source_sheet'], $row['source_row'],
        ]);
    }));

    foreach ($filteredOrders as $row) {
        $metrics['orders']++;
        $metrics['order_value'] += (float)$row['net_amount'];
        $metrics['profit'] += (float)$row['profit_amount'];
        $metrics['order_vp'] += (float)$row['volume_points'];
        if ($row['member_id'] === null) {
            $metrics['source_only_orders']++;
        }
        $metrics['trace_total']++;
        if ($row['source_record_id'] !== null) {
            $metrics['trace_with_source']++;
        }
        $feed[] = [
            'date' => (string)$row['order_date'], 'type' => 'Order', 'title' => $row['display_name'],
            'value' => op_money((float)$row['net_amount']), 'detail' => (string)($row['description'] ?: $row['order_type']),
        ];
    }

    foreach ($filteredVp as $row) {
        $metrics['vp_facts']++;
        $metrics['vp_total'] += (float)$row['volume_points'];
        if ($row['member_id'] === null) {
            $metrics['source_only_vp']++;
        }
        $metrics['trace_total']++;
        if ($row['source_record_id'] !== null) {
            $metrics['trace_with_source']++;
        }
        $feed[] = [
            'date' => (string)($row['entry_date'] ?? ''), 'type' => 'VP', 'title' => $row['display_name'],
            'value' => op_num((float)$row['volume_points']) . ' VP', 'detail' => (string)($row['order_type'] ?: $row['vp_type'] ?: 'Volume Point'),
        ];
    }

    foreach ($filteredIncome as $row) {
        $amount = (float)$row['amount'];
        $metrics['income_facts']++;
        $metrics['income_total'] += $amount;
        $typeKey = op_key($row['income_type']);
        if ($typeKey === 'retail') $metrics['income_retail'] += $amount;
        elseif ($typeKey === 'check') $metrics['income_check'] += $amount;
        elseif ($typeKey === 'club') $metrics['income_club'] += $amount;
        $metrics['trace_total']++;
        if ($row['source_record_id'] !== null) {
            $metrics['trace_with_source']++;
        }
        $feed[] = [
            'date' => (string)$row['income_date'], 'type' => 'Income', 'title' => ucfirst((string)$row['income_type']) . ' income',
            'value' => op_money($amount), 'detail' => (string)($row['period_key'] ?: 'Monthly income'),
        ];
    }

    foreach ($filteredRoyalty as $row) {
        $amount = (float)$row['amount'];
        $metrics['royalty_facts']++;
        $metrics['royalty_total'] += $amount;
        $metrics['trace_total']++;
        if ($row['source_record_id'] !== null) {
            $metrics['trace_with_source']++;
        }
        $feed[] = [
            'date' => (string)($row['royalty_date'] ?? ''), 'type' => 'Royalty', 'title' => 'Royalty',
            'value' => op_money($amount), 'detail' => (string)($row['period_label'] ?: 'Royalty period'),
        ];
    }

    usort($feed, static function(array $a, array $b): int {
        return strcmp((string)$b['date'], (string)$a['date']);
    });
    $feed = array_slice($feed, 0, 12);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && $sourceTotal === 757 && $sourceMapped === 757 && $sourcePending === 0;
$tracePercent = $metrics['trace_total'] > 0 ? round(($metrics['trace_with_source'] / $metrics['trace_total']) * 100, 1) : 0.0;
$selectedMemberName = $filters['member'] > 0 && isset($membersById[$filters['member']]) ? $membersById[$filters['member']]['full_name'] : 'All Members';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Operations Center - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/operations.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Operations Center</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="members.php">Members</a>
      <a class="os-btn" href="report_center.php">Report Center</a>
      <a class="os-btn primary" href="index.php">Dashboard</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav">
      <a href="index.php"><i class="dot"></i>Dashboard</a>
      <a href="members.php"><i class="dot"></i>Members & Network</a>
      <a class="active" href="operations_center.php"><i class="dot"></i>Operations Center</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Operational Views</div>
    <nav class="os-nav">
      <a href="#orders"><i class="dot"></i>Orders</a>
      <a href="#vp"><i class="dot"></i>Volume Points</a>
      <a href="#income"><i class="dot"></i>Income</a>
      <a href="#royalty"><i class="dot"></i>Royalty</a>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Operations source ready' : 'Review required' ?></b>
      <span><?= number_format($sourceMapped) ?> / 757 source mapped • <?= op_h((string)$tracePercent) ?>% visible facts retain a raw-source trace.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero op-hero">
      <div class="os-kicker">Step 10E • Operations Center</div>
      <h1>Orders, Volume Points, Income and Royalty in one professional workspace.</h1>
      <p>Use one set of period, member and search filters across the normalized operational facts. Member selection applies to Orders and VP; Income and Royalty remain organization-level financial facts by design.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'OPERATIONS CENTER LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 source mapped</span>
        <span class="os-chip"><?= op_h($selectedMemberName) ?></span>
        <span class="os-chip"><?= op_h($filters['year']) ?> / <?= $filters['month'] === 'ALL' ? 'All Months' : op_h(op_month_name((int)$filters['month'])) ?></span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="os-footer-note op-error"><strong>Operations diagnostic:</strong> <?= op_h($error) ?></div>
    <?php else: ?>
      <section class="os-card op-filter-card">
        <div class="os-title-row">
          <div><h2>Operational Filters</h2><p>Filters are read-only and never alter normalized source facts.</p></div>
          <a class="os-btn" href="operations_center.php?year=ALL&month=ALL">All-Time View</a>
        </div>
        <form method="get" class="op-filters">
          <div class="op-field">
            <label for="year">Year</label>
            <select id="year" name="year">
              <option value="ALL" <?= $filters['year'] === 'ALL' ? 'selected' : '' ?>>All Years</option>
              <?php $years = array_values(array_unique(array_map(static fn(array $p): int => (int)$p['year'], $periods))); rsort($years); ?>
              <?php foreach ($years as $year): ?>
                <option value="<?= $year ?>" <?= (string)$year === $filters['year'] ? 'selected' : '' ?>><?= $year ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="op-field">
            <label for="month">Month</label>
            <select id="month" name="month">
              <option value="ALL" <?= $filters['month'] === 'ALL' ? 'selected' : '' ?>>All Months</option>
              <?php for ($m=1; $m<=12; $m++): ?>
                <option value="<?= $m ?>" <?= (string)$m === $filters['month'] ? 'selected' : '' ?>><?= op_h(op_month_name($m)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="op-field op-member-field">
            <label for="member">Member — Orders & VP</label>
            <select id="member" name="member">
              <option value="0">All Members</option>
              <?php foreach ($members as $member): ?>
                <option value="<?= (int)$member['id'] ?>" <?= (int)$member['id'] === $filters['member'] ? 'selected' : '' ?>><?= op_h($member['full_name']) ?> • #<?= (int)$member['id'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="op-field op-search-field">
            <label for="q">Smart Search</label>
            <input id="q" name="q" value="<?= op_h($filters['q']) ?>" placeholder="Member, order type, VP type, income, source row…">
          </div>
          <div class="op-filter-actions">
            <button type="submit">Apply Filters</button>
            <a href="operations_center.php">Reset</a>
          </div>
        </form>
        <?php if ($filters['member'] > 0): ?>
          <div class="op-scope-note"><strong>Member scope:</strong> Orders and VP are filtered to <?= op_h($selectedMemberName) ?>. Income and Royalty remain organization-level because the imported source does not assign them to individual members.</div>
        <?php endif; ?>
      </section>

      <section class="os-grid op-kpis">
        <article class="os-card os-kpi green"><small>Order Value</small><strong><?= op_h(op_money($metrics['order_value'])) ?></strong><span><?= number_format($metrics['orders']) ?> order fact(s)</span></article>
        <article class="os-card os-kpi blue"><small>Order Profit</small><strong><?= op_h(op_money($metrics['profit'])) ?></strong><span><?= op_h(op_num($metrics['order_vp'])) ?> order-level VP</span></article>
        <article class="os-card os-kpi gold"><small>Volume Points</small><strong><?= op_h(op_num($metrics['vp_total'])) ?></strong><span><?= number_format($metrics['vp_facts']) ?> normalized VP fact(s)</span></article>
        <article class="os-card os-kpi violet"><small>Total Income</small><strong><?= op_h(op_money($metrics['income_total'])) ?></strong><span><?= number_format($metrics['income_facts']) ?> monthly component fact(s)</span></article>
        <article class="os-card os-kpi green"><small>Royalty</small><strong><?= op_h(op_money($metrics['royalty_total'])) ?></strong><span><?= number_format($metrics['royalty_facts']) ?> royalty fact(s)</span></article>
        <article class="os-card os-kpi blue"><small>Retail Income</small><strong><?= op_h(op_money($metrics['income_retail'])) ?></strong><span>Check <?= op_h(op_money($metrics['income_check'])) ?></span></article>
        <article class="os-card os-kpi gold"><small>Club Income</small><strong><?= op_h(op_money($metrics['income_club'])) ?></strong><span>Source Total remains validation-only</span></article>
        <article class="os-card os-kpi violet"><small>Source Trace</small><strong><?= op_h((string)$tracePercent) ?>%</strong><span><?= number_format($metrics['source_only_orders'] + $metrics['source_only_vp']) ?> source-only identity fact(s)</span></article>
      </section>

      <section class="op-main-grid">
        <article class="os-card op-workspace-card">
          <div class="os-title-row">
            <div><h2>Operational Data Workspace</h2><p>Switch tabs without losing the selected filters.</p></div>
            <span class="op-safe-badge">Normalized facts only</span>
          </div>
          <div class="op-tabs" role="tablist" aria-label="Operations data views">
            <button class="active" type="button" data-op-tab="orders">Orders <span><?= number_format(count($filteredOrders)) ?></span></button>
            <button type="button" data-op-tab="vp">Volume Points <span><?= number_format(count($filteredVp)) ?></span></button>
            <button type="button" data-op-tab="income">Income <span><?= number_format(count($filteredIncome)) ?></span></button>
            <button type="button" data-op-tab="royalty">Royalty <span><?= number_format(count($filteredRoyalty)) ?></span></button>
          </div>

          <div class="op-panel active" id="orders" data-op-panel="orders">
            <div class="op-panel-head"><div><h3>Orders</h3><p>First & Second Set plus Extra Order facts.</p></div><strong><?= op_h(op_money($metrics['order_value'])) ?></strong></div>
            <div class="op-table-wrap">
              <table class="op-table">
                <thead><tr><th>Date</th><th>Member</th><th>Type / Description</th><th class="right">Net Amount</th><th class="right">Profit</th><th class="right">VP</th><th>Trace</th></tr></thead>
                <tbody>
                <?php if (!$filteredOrders): ?>
                  <tr><td colspan="7" class="op-empty">No orders match the selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($filteredOrders as $row): ?>
                    <tr>
                      <td><b><?= op_h(op_date((string)$row['order_date'])) ?></b><small>#<?= number_format((int)$row['id']) ?></small></td>
                      <td>
                        <div class="op-person"><span><?= op_h(op_initial((string)$row['display_name'])) ?></span><div><b><?= op_h($row['display_name']) ?></b><small><?= $row['member_id'] !== null ? 'Verified Member ID' : 'Source identity only' ?></small></div></div>
                        <?php if ($row['member_id'] !== null): ?><a class="op-mini-link" href="member_profile.php?member=<?= (int)$row['member_id'] ?>">Profile 360° →</a><?php endif; ?>
                      </td>
                      <td><b><?= op_h((string)$row['order_type']) ?></b><small><?= op_h((string)($row['description'] ?: '—')) ?></small></td>
                      <td class="right"><b><?= op_h(op_money((float)$row['net_amount'])) ?></b></td>
                      <td class="right"><b><?= op_h(op_money((float)$row['profit_amount'])) ?></b></td>
                      <td class="right"><b><?= op_h(op_num((float)$row['volume_points'])) ?></b></td>
                      <td><span class="op-trace <?= $row['source_record_id'] !== null ? 'good' : 'warn' ?>"><?= $row['source_record_id'] !== null ? 'SOURCE LINKED' : 'NO RAW LINK' ?></span><small><?= op_h((string)($row['source_sheet'] ?: 'Manual/Other')) ?> • row <?= op_h((string)($row['source_row'] ?: '—')) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="op-panel" id="vp" data-op-panel="vp">
            <div class="op-panel-head"><div><h3>Volume Points</h3><p>Normalized VP facts with source dimensions preserved.</p></div><strong><?= op_h(op_num($metrics['vp_total'])) ?> VP</strong></div>
            <div class="op-table-wrap">
              <table class="op-table">
                <thead><tr><th>Date</th><th>Member</th><th>Order / VP Type</th><th>VP From / Ordered By</th><th>Level / Week</th><th class="right">VP</th><th>Trace</th></tr></thead>
                <tbody>
                <?php if (!$filteredVp): ?>
                  <tr><td colspan="7" class="op-empty">No Volume Point facts match the selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($filteredVp as $row): ?>
                    <tr>
                      <td><b><?= op_h(op_date($row['entry_date'] ? (string)$row['entry_date'] : null)) ?></b><small>#<?= number_format((int)$row['id']) ?></small></td>
                      <td><div class="op-person"><span><?= op_h(op_initial((string)$row['display_name'])) ?></span><div><b><?= op_h($row['display_name']) ?></b><small><?= $row['member_id'] !== null ? 'Verified Member ID' : 'Source identity only' ?></small></div></div><?php if ($row['member_id'] !== null): ?><a class="op-mini-link" href="member_profile.php?member=<?= (int)$row['member_id'] ?>">Profile 360° →</a><?php endif; ?></td>
                      <td><b><?= op_h((string)($row['order_type'] ?: '—')) ?></b><small><?= op_h((string)($row['vp_type'] ?: '—')) ?></small></td>
                      <td><b><?= op_h((string)($row['vp_from'] ?: '—')) ?></b><small><?= op_h((string)($row['ordered_by'] ?: '—')) ?></small></td>
                      <td><b><?= op_h((string)($row['level_label'] ?: '—')) ?></b><small><?= op_h((string)($row['week_label'] ?: '—')) ?></small></td>
                      <td class="right"><b><?= op_h(op_num((float)$row['volume_points'])) ?></b></td>
                      <td><span class="op-trace <?= $row['source_record_id'] !== null ? 'good' : 'warn' ?>"><?= $row['source_record_id'] !== null ? 'SOURCE LINKED' : 'NO RAW LINK' ?></span><small><?= op_h((string)($row['source_sheet'] ?: 'Manual/Other')) ?> • row <?= op_h((string)($row['source_row'] ?: '—')) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="op-panel" id="income" data-op-panel="income">
            <div class="op-panel-head"><div><h3>Monthly Income</h3><p>Retail, Check and Club are separate facts; source Total is not duplicated.</p></div><strong><?= op_h(op_money($metrics['income_total'])) ?></strong></div>
            <div class="op-income-summary">
              <div><small>Retail</small><strong><?= op_h(op_money($metrics['income_retail'])) ?></strong></div>
              <div><small>Check</small><strong><?= op_h(op_money($metrics['income_check'])) ?></strong></div>
              <div><small>Club</small><strong><?= op_h(op_money($metrics['income_club'])) ?></strong></div>
            </div>
            <div class="op-table-wrap">
              <table class="op-table">
                <thead><tr><th>Period</th><th>Income Type</th><th class="right">Amount</th><th>Currency</th><th>Trace</th></tr></thead>
                <tbody>
                <?php if (!$filteredIncome): ?>
                  <tr><td colspan="5" class="op-empty">No income facts match the selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($filteredIncome as $row): ?>
                    <tr>
                      <td><b><?= op_h(op_date((string)$row['income_date'])) ?></b><small><?= op_h((string)($row['period_key'] ?: '—')) ?></small></td>
                      <td><b><?= op_h(ucfirst((string)$row['income_type'])) ?> Income</b><small>Organization-level monthly fact</small></td>
                      <td class="right"><b><?= op_h(op_money((float)$row['amount'])) ?></b></td>
                      <td><?= op_h((string)$row['currency_code']) ?></td>
                      <td><span class="op-trace <?= $row['source_record_id'] !== null ? 'good' : 'warn' ?>"><?= $row['source_record_id'] !== null ? 'SOURCE LINKED' : 'NO RAW LINK' ?></span><small><?= op_h((string)($row['source_sheet'] ?: 'Manual/Other')) ?> • row <?= op_h((string)($row['source_row'] ?: '—')) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="op-panel" id="royalty" data-op-panel="royalty">
            <div class="op-panel-head"><div><h3>Royalty</h3><p>Period-level royalty facts from the normalized source layer.</p></div><strong><?= op_h(op_money($metrics['royalty_total'])) ?></strong></div>
            <div class="op-table-wrap">
              <table class="op-table">
                <thead><tr><th>Period Anchor</th><th>Period Label</th><th class="right">Royalty</th><th class="right">VP</th><th>Currency</th><th>Trace</th></tr></thead>
                <tbody>
                <?php if (!$filteredRoyalty): ?>
                  <tr><td colspan="6" class="op-empty">No royalty facts match the selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($filteredRoyalty as $row): ?>
                    <tr>
                      <td><b><?= op_h(op_date($row['royalty_date'] ? (string)$row['royalty_date'] : null)) ?></b><small>Month-period anchor</small></td>
                      <td><b><?= op_h((string)($row['period_label'] ?: '—')) ?></b></td>
                      <td class="right"><b><?= op_h(op_money((float)$row['amount'])) ?></b></td>
                      <td class="right"><b><?= op_h(op_num((float)$row['volume_points'])) ?></b></td>
                      <td><?= op_h((string)$row['currency_code']) ?></td>
                      <td><span class="op-trace <?= $row['source_record_id'] !== null ? 'good' : 'warn' ?>"><?= $row['source_record_id'] !== null ? 'SOURCE LINKED' : 'NO RAW LINK' ?></span><small><?= op_h((string)($row['source_sheet'] ?: 'Manual/Other')) ?> • row <?= op_h((string)($row['source_row'] ?: '—')) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </article>

        <aside class="os-card op-feed-card">
          <div class="os-title-row"><div><h2>Recent Operations Feed</h2><p>Latest facts inside the selected period/search scope.</p></div></div>
          <div class="op-feed">
            <?php if (!$feed): ?>
              <div class="op-empty-feed">No operational activity matches these filters.</div>
            <?php else: ?>
              <?php foreach ($feed as $event): ?>
                <div class="op-feed-row">
                  <span class="op-feed-icon <?= op_h(op_key($event['type'])) ?>"><?= op_h(substr((string)$event['type'], 0, 1)) ?></span>
                  <div><b><?= op_h($event['title']) ?></b><span><?= op_h($event['detail']) ?></span><small><?= op_h(op_date($event['date'])) ?> • <?= op_h($event['type']) ?></small></div>
                  <strong><?= op_h($event['value']) ?></strong>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="op-health">
            <div><span>Raw-source trace</span><b><?= op_h((string)$tracePercent) ?>%</b></div>
            <div><span>Source-only Orders</span><b><?= number_format($metrics['source_only_orders']) ?></b></div>
            <div><span>Source-only VP</span><b><?= number_format($metrics['source_only_vp']) ?></b></div>
          </div>
          <div class="op-identity-note"><strong>Identity safety:</strong> source-only member/customer names remain visible but are not attached to a Member Profile until a verified member_id exists.</div>
        </aside>
      </section>

      <div class="os-footer-note"><strong>Operations architecture:</strong> this page is a read-only workspace over normalized facts. Orders and VP may link to verified Member IDs; Monthly Income and Royalty remain organization-level source facts. No source identity is guessed or silently merged.</div>
    <?php endif; ?>
  </main>
</div>

<script>
(function(){
  const buttons = Array.from(document.querySelectorAll('[data-op-tab]'));
  const panels = Array.from(document.querySelectorAll('[data-op-panel]'));
  buttons.forEach(button => {
    button.addEventListener('click', () => {
      const target = button.getAttribute('data-op-tab');
      buttons.forEach(item => item.classList.toggle('active', item === button));
      panels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-op-panel') === target));
      const activePanel = document.querySelector('[data-op-panel="' + target + '"]');
      if (activePanel && window.innerWidth < 800) activePanel.scrollIntoView({behavior:'smooth', block:'start'});
    });
  });
})();
</script>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
