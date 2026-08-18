<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function mp_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mp_trim(mixed $value): string
{
    return trim((string)$value);
}

function mp_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', mp_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function mp_values(?string $json): array
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

function mp_date(?string $value, string $format = 'd M Y'): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable) {
        return (string)$value;
    }
}

function mp_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function mp_money(float|int $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function mp_status_class(string $status): string
{
    $key = mp_key($status);
    if (str_contains($key, 'inactive') || str_contains($key, 'expired')) {
        return 'danger';
    }
    if (str_contains($key, 'pending') || str_contains($key, 'review')) {
        return 'warn';
    }
    return 'good';
}

function mp_initial(string $name): string
{
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}

function mp_duration(?string $startDate): string
{
    if (!$startDate) {
        return '—';
    }
    try {
        $start = new DateTimeImmutable($startDate);
        $today = new DateTimeImmutable('today');
        if ($start > $today) {
            return 'Starts later';
        }
        $diff = $start->diff($today);
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . 'y';
        if ($diff->m > 0) $parts[] = $diff->m . 'm';
        if ($diff->d > 0 || !$parts) $parts[] = $diff->d . 'd';
        return implode(' ', $parts);
    } catch (Throwable) {
        return '—';
    }
}

function mp_add_event(array &$timeline, string $date, string $type, string $title, string $subtitle, array $meta = []): void
{
    if ($date === '') {
        return;
    }
    $timeline[] = [
        'date' => $date,
        'type' => $type,
        'title' => $title,
        'subtitle' => $subtitle,
        'meta' => $meta,
    ];
}

$error = null;
$organizationId = 0;
$sourceTotal = 0;
$sourceMapped = 0;
$memberOptions = [];
$membersById = [];
$selected = null;
$sourceMeta = [];
$latestUms = null;
$umsRows = [];
$timeline = [];
$filteredTimeline = [];
$lineage = [];
$downline = [];
$eventCounts = ['all'=>0,'ums'=>0,'vp'=>0,'order'=>0,'renewal'=>0,'activity'=>0];
$guardCounts = ['vp'=>0,'renewal'=>0,'activity'=>0];
$metrics = [
    'vp_facts' => 0,
    'total_vp' => 0.0,
    'orders' => 0,
    'order_value' => 0.0,
    'profit' => 0.0,
    'order_vp' => 0.0,
    'renewals' => 0,
    'last_renewal' => null,
    'active_snapshots' => 0,
    'last_active_snapshot' => null,
    'direct_downline' => 0,
];

$requestedMemberId = isset($_GET['member']) && is_numeric($_GET['member']) ? (int)$_GET['member'] : 0;
$eventFilter = mp_key($_GET['event'] ?? 'all');
if (!in_array($eventFilter, ['all','ums','vp','order','renewal','activity'], true)) {
    $eventFilter = 'all';
}

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['members','ums_records','raw_source_records','volume_point_entries','orders','renewals','ums_activity_snapshots'] as $table) {
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
        throw new RuntimeException('Operational source layer must remain reconciled at 757/757 before Member Profile 360 can run.');
    }

    $allMembersStmt = $pdo->prepare(
        "SELECT id, full_name, mobile, sponsor_member_id, status, source_row
         FROM members WHERE organization_id=? ORDER BY full_name, id"
    );
    $allMembersStmt->execute([$organizationId]);
    foreach ($allMembersStmt->fetchAll() as $row) {
        $item = [
            'id'=>(int)$row['id'],
            'full_name'=>(string)$row['full_name'],
            'mobile'=>(string)($row['mobile'] ?? ''),
            'sponsor_member_id'=>$row['sponsor_member_id'] !== null ? (int)$row['sponsor_member_id'] : null,
            'status'=>(string)$row['status'],
            'source_row'=>(int)($row['source_row'] ?? 0),
        ];
        $memberOptions[] = $item;
        $membersById[$item['id']] = $item;
    }
    if (!$memberOptions) {
        throw new RuntimeException('No normalized members were found.');
    }

    if ($requestedMemberId <= 0 || !isset($membersById[$requestedMemberId])) {
        $requestedMemberId = (int)$memberOptions[0]['id'];
    }

    $memberStmt = $pdo->prepare(
        "SELECT m.*, sm.full_name linked_sponsor_name, r.raw_json
         FROM members m
         LEFT JOIN members sm ON sm.id=m.sponsor_member_id AND sm.organization_id=m.organization_id
         LEFT JOIN raw_source_records r ON r.id=m.source_record_id
         WHERE m.organization_id=? AND m.id=? LIMIT 1"
    );
    $memberStmt->execute([$organizationId, $requestedMemberId]);
    $selected = $memberStmt->fetch();
    if (!$selected) {
        throw new RuntimeException('Selected member could not be found.');
    }

    $values = mp_values($selected['raw_json'] ?? null);
    $sourceMeta = [
        'team' => mp_trim($values['E'] ?? ''),
        'sponsor' => mp_trim($values['G'] ?? ''),
        'duration' => mp_trim($values['J'] ?? ''),
        'active_flag' => mp_trim($values['K'] ?? ''),
        'active_supervisor' => mp_trim($values['L'] ?? ''),
        'ums_type' => mp_trim($values['M'] ?? ''),
    ];

    $umsStmt = $pdo->prepare(
        "SELECT id, set_type, start_date, expiry_date, renewal_due_date, status, amount, currency_code,
                volume_points, source_sheet, source_row
         FROM ums_records WHERE organization_id=? AND member_id=?
         ORDER BY start_date DESC, id DESC"
    );
    $umsStmt->execute([$organizationId, $requestedMemberId]);
    $umsRows = $umsStmt->fetchAll();
    $latestUms = $umsRows[0] ?? null;

    foreach ($umsRows as $ums) {
        $date = (string)($ums['start_date'] ?? '');
        mp_add_event(
            $timeline,
            $date,
            'ums',
            'UMS lifecycle started',
            'Status: ' . ((string)($ums['status'] ?: 'unknown')),
            [
                'Set type' => (string)($ums['set_type'] ?: ($sourceMeta['ums_type'] ?: '—')),
                'Source' => (string)($ums['source_sheet'] ?: 'UMS record'),
                'Source row' => (string)($ums['source_row'] ?: '—'),
            ]
        );
    }

    if (!empty($selected['join_date'])) {
        mp_add_event($timeline, (string)$selected['join_date'], 'ums', 'Member joined', 'Normalized member lifecycle start', ['Member ID'=>(string)$selected['id']]);
    }

    $vpStmt = $pdo->prepare(
        "SELECT id, entry_date, volume_points, order_type, vp_from, ordered_by, vp_type, level_label, week_label
         FROM volume_point_entries
         WHERE organization_id=? AND member_id=? ORDER BY entry_date DESC, id DESC"
    );
    $vpStmt->execute([$organizationId, $requestedMemberId]);
    $vpRows = $vpStmt->fetchAll();
    foreach ($vpRows as $vp) {
        $metrics['vp_facts']++;
        $metrics['total_vp'] += (float)$vp['volume_points'];
        mp_add_event(
            $timeline,
            (string)($vp['entry_date'] ?? ''),
            'vp',
            mp_num((float)$vp['volume_points']) . ' VP',
            (string)($vp['order_type'] ?: $vp['vp_type'] ?: 'Volume Point entry'),
            [
                'VP From' => (string)($vp['vp_from'] ?: '—'),
                'Ordered By' => (string)($vp['ordered_by'] ?: '—'),
                'Level' => (string)($vp['level_label'] ?: '—'),
                'Week' => (string)($vp['week_label'] ?: '—'),
            ]
        );
    }

    $orderStmt = $pdo->prepare(
        "SELECT id, order_date, order_type, description, net_amount, profit_amount, volume_points, source_sheet
         FROM orders WHERE organization_id=? AND member_id=? ORDER BY order_date DESC, id DESC"
    );
    $orderStmt->execute([$organizationId, $requestedMemberId]);
    $orderRows = $orderStmt->fetchAll();
    foreach ($orderRows as $order) {
        $metrics['orders']++;
        $metrics['order_value'] += (float)$order['net_amount'];
        $metrics['profit'] += (float)$order['profit_amount'];
        $metrics['order_vp'] += (float)$order['volume_points'];
        mp_add_event(
            $timeline,
            (string)$order['order_date'],
            'order',
            'Order • ' . mp_money((float)$order['net_amount']),
            (string)($order['description'] ?: $order['order_type']),
            [
                'Profit' => mp_money((float)$order['profit_amount']),
                'VP' => mp_num((float)$order['volume_points']),
                'Source' => (string)($order['source_sheet'] ?: 'Order'),
            ]
        );
    }

    $renewalStmt = $pdo->prepare(
        "SELECT id, renewal_date, amount, currency_code, volume_points, source_sheet
         FROM renewals WHERE organization_id=? AND member_id=? ORDER BY renewal_date DESC, id DESC"
    );
    $renewalStmt->execute([$organizationId, $requestedMemberId]);
    $renewalRows = $renewalStmt->fetchAll();
    foreach ($renewalRows as $renewal) {
        $metrics['renewals']++;
        $metrics['last_renewal'] ??= (string)$renewal['renewal_date'];
        mp_add_event(
            $timeline,
            (string)$renewal['renewal_date'],
            'renewal',
            'UMS Renewal',
            'Verified renewal fact linked to this member',
            [
                'Amount' => mp_money((float)$renewal['amount']),
                'VP' => mp_num((float)$renewal['volume_points']),
                'Source' => (string)($renewal['source_sheet'] ?: 'Renewal UMS'),
            ]
        );
    }

    $snapshotStmt = $pdo->prepare(
        "SELECT id, snapshot_date, snapshot_year, snapshot_month, snapshot_month_number, is_active, source_row
         FROM ums_activity_snapshots
         WHERE organization_id=? AND member_id=? ORDER BY snapshot_date DESC, id DESC"
    );
    $snapshotStmt->execute([$organizationId, $requestedMemberId]);
    $snapshotRows = $snapshotStmt->fetchAll();
    foreach ($snapshotRows as $snapshot) {
        if ((int)$snapshot['is_active'] === 1) {
            $metrics['active_snapshots']++;
            $metrics['last_active_snapshot'] ??= (string)($snapshot['snapshot_date'] ?? '');
        }
        mp_add_event(
            $timeline,
            (string)($snapshot['snapshot_date'] ?? ''),
            'activity',
            ((int)$snapshot['is_active'] === 1 ? 'Active UMS snapshot' : 'UMS activity snapshot'),
            (string)$snapshot['snapshot_month'] . ' ' . (string)$snapshot['snapshot_year'],
            ['Source row'=>(string)($snapshot['source_row'] ?: '—')]
        );
    }

    // Same-name source-only rows are counted for transparency, but never attributed to this member profile.
    $nameKey = mp_key($selected['full_name']);
    $sameNameVp = $pdo->prepare("SELECT member_name_snapshot FROM volume_point_entries WHERE organization_id=? AND member_id IS NULL AND member_name_snapshot IS NOT NULL");
    $sameNameVp->execute([$organizationId]);
    foreach ($sameNameVp->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (mp_key($name) === $nameKey) $guardCounts['vp']++;
    }
    if (business_column_exists($pdo, 'renewals', 'member_name_snapshot')) {
        $sameNameRenewal = $pdo->prepare("SELECT member_name_snapshot FROM renewals WHERE organization_id=? AND member_id IS NULL AND member_name_snapshot IS NOT NULL");
        $sameNameRenewal->execute([$organizationId]);
        foreach ($sameNameRenewal->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (mp_key($name) === $nameKey) $guardCounts['renewal']++;
        }
    }
    $sameNameActivity = $pdo->prepare("SELECT member_name_snapshot FROM ums_activity_snapshots WHERE organization_id=? AND member_id IS NULL AND member_name_snapshot IS NOT NULL");
    $sameNameActivity->execute([$organizationId]);
    foreach ($sameNameActivity->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (mp_key($name) === $nameKey) $guardCounts['activity']++;
    }

    // Verified sponsor lineage only.
    $cursor = $selected['sponsor_member_id'] !== null ? (int)$selected['sponsor_member_id'] : null;
    $seen = [];
    for ($depth = 0; $cursor !== null && $depth < 20; $depth++) {
        if (isset($seen[$cursor]) || !isset($membersById[$cursor])) {
            break;
        }
        $seen[$cursor] = true;
        $lineage[] = $membersById[$cursor];
        $cursor = $membersById[$cursor]['sponsor_member_id'];
    }

    foreach ($membersById as $member) {
        if ($member['sponsor_member_id'] === $requestedMemberId) {
            $downline[] = $member;
        }
    }
    usort($downline, static fn(array $a, array $b): int => strnatcasecmp($a['full_name'], $b['full_name']));
    $metrics['direct_downline'] = count($downline);

    usort($timeline, static function(array $a, array $b): int {
        $dateCompare = strcmp((string)$b['date'], (string)$a['date']);
        if ($dateCompare !== 0) return $dateCompare;
        return strcmp((string)$a['type'], (string)$b['type']);
    });

    foreach ($timeline as $event) {
        if (isset($eventCounts[$event['type']])) {
            $eventCounts[$event['type']]++;
        }
    }
    $eventCounts['all'] = count($timeline);
    $filteredTimeline = $eventFilter === 'all'
        ? $timeline
        : array_values(array_filter($timeline, static fn(array $event): bool => $event['type'] === $eventFilter));
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && $sourceTotal === 757 && $sourceMapped === 757 && $selected !== null;
$memberStatus = $latestUms ? (string)$latestUms['status'] : (string)($selected['status'] ?? 'active');
$excludedSameName = array_sum($guardCounts);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Member Profile 360 - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/member_profile.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Member Profile 360°</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="members.php">Members</a>
      <a class="os-btn" href="sponsor_network.php">Sponsor Network</a>
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
      <a class="active" href="member_profile.php?member=<?= (int)$requestedMemberId ?>"><i class="dot"></i>Member Profile 360°</a>
      <a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Lifecycle Tools</div>
    <nav class="os-nav">
      <a href="ums_renewal.php"><i class="dot"></i>UMS Renewal</a>
      <a href="ums_active_duration.php"><i class="dot"></i>Active Duration</a>
      <?php if ($selected): ?>
        <a href="name_wise_tracking.php?owner=<?= rawurlencode((string)$selected['full_name']) ?>"><i class="dot"></i>Name Wise Tracking</a>
      <?php endif; ?>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Profile source verified' : 'Review required' ?></b>
      <span><?= number_format($sourceMapped) ?> / 757 source mapped • timeline uses verified Member IDs only.</span>
    </div>
  </aside>

  <main class="os-main">
    <?php if ($error !== null): ?>
      <div class="os-footer-note mp-error"><strong>Member Profile diagnostic:</strong> <?= mp_h($error) ?></div>
    <?php else: ?>
      <section class="os-hero mp-hero">
        <div class="mp-hero-grid">
          <div class="mp-person-head">
            <span class="mp-avatar"><?= mp_h(mp_initial((string)$selected['full_name'])) ?></span>
            <div>
              <div class="os-kicker">Step 10D • Member Profile 360°</div>
              <div class="mp-status-row">
                <span class="mp-status <?= mp_status_class($memberStatus) ?>"><?= mp_h(ucwords($memberStatus)) ?></span>
                <span class="mp-status good">Verified Member #<?= number_format((int)$selected['id']) ?></span>
              </div>
              <h1><?= mp_h((string)$selected['full_name']) ?></h1>
              <p>UMS lifecycle, verified network, business facts and complete linked timeline in one identity-safe profile.</p>
            </div>
          </div>
          <form method="get" class="mp-member-picker">
            <label for="member">Switch Member</label>
            <div>
              <select id="member" name="member">
                <?php foreach ($memberOptions as $option): ?>
                  <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $requestedMemberId ? 'selected' : '' ?>><?= mp_h($option['full_name']) ?> • #<?= (int)$option['id'] ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit">Open Profile</button>
            </div>
          </form>
        </div>
        <div class="os-status-row">
          <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'PROFILE 360 LIVE' : 'Review required' ?></span>
          <span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 source mapped</span>
          <span class="os-chip"><?= number_format($eventCounts['all']) ?> linked timeline events</span>
          <span class="os-chip"><?= number_format($metrics['direct_downline']) ?> direct downline</span>
        </div>
      </section>

      <section class="os-grid mp-kpis">
        <article class="os-card os-kpi green"><small>Lifetime VP</small><strong><?= mp_h(mp_num($metrics['total_vp'])) ?></strong><span><?= number_format($metrics['vp_facts']) ?> verified VP facts</span></article>
        <article class="os-card os-kpi blue"><small>Orders</small><strong><?= number_format($metrics['orders']) ?></strong><span><?= mp_h(mp_money($metrics['order_value'])) ?> order value</span></article>
        <article class="os-card os-kpi gold"><small>Profit</small><strong><?= mp_h(mp_money($metrics['profit'])) ?></strong><span><?= mp_h(mp_num($metrics['order_vp'])) ?> order VP</span></article>
        <article class="os-card os-kpi violet"><small>Renewals</small><strong><?= number_format($metrics['renewals']) ?></strong><span>Last: <?= mp_h(mp_date($metrics['last_renewal'])) ?></span></article>
      </section>

      <section class="mp-top-grid">
        <article class="os-card mp-summary-card">
          <div class="os-title-row"><div><h2>Member & UMS Summary</h2><p>Normalized identity plus current lifecycle state.</p></div><span class="mp-safe">Verified ID</span></div>
          <div class="mp-info-grid">
            <div><small>Mobile</small><b><?= mp_h($selected['mobile'] ?: 'Not available') ?></b></div>
            <div><small>Join Date</small><b><?= mp_h(mp_date($selected['join_date'] ? (string)$selected['join_date'] : null)) ?></b></div>
            <div><small>UMS Start</small><b><?= mp_h(mp_date($latestUms['start_date'] ?? null)) ?></b></div>
            <div><small>Live Duration</small><b><?= mp_h(mp_duration($latestUms['start_date'] ?? null)) ?></b></div>
            <div><small>Source UMS Type</small><b><?= mp_h($sourceMeta['ums_type'] ?: '—') ?></b></div>
            <div><small>Source Active Flag</small><b><?= mp_h($sourceMeta['active_flag'] ?: '—') ?></b></div>
            <div><small>Active Supervisor</small><b><?= mp_h($sourceMeta['active_supervisor'] ?: '—') ?></b></div>
            <div><small>Active Snapshots</small><b><?= number_format($metrics['active_snapshots']) ?></b></div>
          </div>
        </article>

        <aside class="os-card mp-network-card">
          <div class="os-title-row"><div><h2>Verified Network</h2><p>Only database-verified Sponsor links are treated as relationships.</p></div><a class="mp-mini-link" href="sponsor_network.php">Tree →</a></div>
          <div class="mp-network-row"><span>Team</span><b><?= mp_h($sourceMeta['team'] ?: '—') ?></b></div>
          <div class="mp-network-row"><span>Verified Sponsor</span><b><?= mp_h($selected['linked_sponsor_name'] ?: 'Not linked') ?></b></div>
          <div class="mp-network-row"><span>Source Sponsor</span><b><?= mp_h($sourceMeta['sponsor'] ?: '—') ?></b></div>
          <div class="mp-network-row"><span>Direct Downline</span><b><?= number_format($metrics['direct_downline']) ?></b></div>
          <?php if ($lineage): ?>
            <div class="mp-lineage">
              <small>Verified upline path</small>
              <div><?php foreach ($lineage as $index => $parent): ?><a href="member_profile.php?member=<?= (int)$parent['id'] ?>"><?= mp_h($parent['full_name']) ?></a><?= $index < count($lineage)-1 ? '<span>←</span>' : '' ?><?php endforeach; ?></div>
            </div>
          <?php endif; ?>
          <?php if ($downline): ?>
            <div class="mp-downline">
              <small>Direct downline</small>
              <div><?php foreach ($downline as $child): ?><a href="member_profile.php?member=<?= (int)$child['id'] ?>"><?= mp_h($child['full_name']) ?></a><?php endforeach; ?></div>
            </div>
          <?php endif; ?>
        </aside>
      </section>

      <?php if ($excludedSameName > 0): ?>
        <div class="mp-guard"><strong>Identity guard:</strong> <?= number_format($excludedSameName) ?> source-only row(s) share this displayed name but do not have this verified Member ID. They are intentionally excluded from Profile 360 totals/timeline: <?= number_format($guardCounts['vp']) ?> VP, <?= number_format($guardCounts['renewal']) ?> Renewal, <?= number_format($guardCounts['activity']) ?> Activity.</div>
      <?php endif; ?>

      <section class="os-card mp-timeline-card">
        <div class="os-title-row">
          <div><h2>Complete Business Timeline</h2><p>Every normalized event currently linked to Member #<?= number_format((int)$selected['id']) ?>.</p></div>
          <span class="mp-safe">No name-based attribution</span>
        </div>

        <div class="mp-filter-row">
          <?php foreach ([
              'all'=>'All', 'ums'=>'UMS', 'vp'=>'VP', 'order'=>'Orders', 'renewal'=>'Renewals', 'activity'=>'Activity'
          ] as $key=>$label): ?>
            <a class="<?= $eventFilter === $key ? 'active' : '' ?>" href="member_profile.php?member=<?= (int)$requestedMemberId ?>&event=<?= mp_h($key) ?>"><?= mp_h($label) ?><span><?= number_format($eventCounts[$key]) ?></span></a>
          <?php endforeach; ?>
        </div>

        <div class="mp-timeline">
          <?php if (!$filteredTimeline): ?>
            <div class="mp-empty">No verified events in this timeline filter.</div>
          <?php else: ?>
            <?php $lastYear = null; ?>
            <?php foreach ($filteredTimeline as $event): ?>
              <?php $year = mp_date($event['date'], 'Y'); ?>
              <?php if ($year !== $lastYear): $lastYear = $year; ?>
                <div class="mp-year"><span><?= mp_h($year) ?></span></div>
              <?php endif; ?>
              <article class="mp-event type-<?= mp_h($event['type']) ?>">
                <div class="mp-event-date"><b><?= mp_h(mp_date($event['date'], 'd')) ?></b><span><?= mp_h(mp_date($event['date'], 'M')) ?></span></div>
                <div class="mp-event-dot"></div>
                <div class="mp-event-body">
                  <div class="mp-event-title"><div><span class="mp-type"><?= mp_h(strtoupper($event['type'])) ?></span><h3><?= mp_h($event['title']) ?></h3></div><time><?= mp_h(mp_date($event['date'])) ?></time></div>
                  <p><?= mp_h($event['subtitle']) ?></p>
                  <?php if ($event['meta']): ?>
                    <div class="mp-meta">
                      <?php foreach ($event['meta'] as $label=>$value): ?><span><small><?= mp_h($label) ?></small><b><?= mp_h($value) ?></b></span><?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

      <section class="mp-bottom-grid">
        <article class="os-card">
          <h2>Source Trace</h2>
          <p>This profile remains traceable to its original normalized source identity.</p>
          <div class="mp-source-grid">
            <div><small>Member ID</small><b>#<?= number_format((int)$selected['id']) ?></b></div>
            <div><small>Source Sheet</small><b><?= mp_h($selected['source_sheet'] ?: 'New UMS') ?></b></div>
            <div><small>Source Row</small><b><?= number_format((int)($selected['source_row'] ?? 0)) ?></b></div>
            <div><small>Source Key</small><b><?= mp_h($selected['source_key'] ?: '—') ?></b></div>
          </div>
        </article>
        <aside class="os-card">
          <h2>Profile Actions</h2>
          <p>Jump to related operational views while preserving the selected identity.</p>
          <div class="mp-actions">
            <a href="members.php?member=<?= (int)$requestedMemberId ?>">Members Workspace <span>→</span></a>
            <a href="sponsor_network.php">Sponsor Network <span>→</span></a>
            <a href="ums_renewal.php">Renewal Center <span>→</span></a>
            <a href="name_wise_tracking.php?owner=<?= rawurlencode((string)$selected['full_name']) ?>">Name Wise Report <span>→</span></a>
          </div>
        </aside>
      </section>
    <?php endif; ?>

    <div class="os-footer-note"><strong>Profile 360 policy:</strong> totals and timeline events are joined by verified <code>member_id</code>. Source-only rows with the same displayed name are never silently attributed to this profile.</div>
  </main>
</div>
</body>
</html>
