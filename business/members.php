<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function mem_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mem_trim(mixed $value): string
{
    return trim((string)$value);
}

function mem_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', mem_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function mem_source_values(?string $json): array
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

function mem_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y');
    } catch (Throwable) {
        return (string)$value;
    }
}

function mem_num(float|int $value, int $decimals = 3): string
{
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function mem_duration(?string $startDate): string
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
        if ($diff->y > 0) {
            $parts[] = $diff->y . 'y';
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m . 'm';
        }
        if ($diff->d > 0 || !$parts) {
            $parts[] = $diff->d . 'd';
        }
        return implode(' ', $parts);
    } catch (Throwable) {
        return '—';
    }
}

function mem_status_class(string $status): string
{
    $key = mem_key($status);
    if (str_contains($key, 'inactive') || str_contains($key, 'expired')) {
        return 'danger';
    }
    if (str_contains($key, 'pending') || str_contains($key, 'review')) {
        return 'warn';
    }
    return 'good';
}

$error = null;
$organizationId = 0;
$sourceTotal = 0;
$sourceMapped = 0;
$members = [];
$filteredMembers = [];
$selectedMember = null;
$nameCounts = [];
$mobileCounts = [];
$teamOptions = [];
$statusOptions = [];
$sponsorLinkedCount = 0;
$sponsorLinkLaterCount = 0;
$selectedActivity = [
    'orders' => [],
    'renewals' => [],
    'vp' => [],
];

$filters = [
    'q' => mem_trim($_GET['q'] ?? ''),
    'status' => mem_trim($_GET['status'] ?? 'ALL'),
    'team' => mem_trim($_GET['team'] ?? 'ALL'),
];
$requestedMemberId = isset($_GET['member']) && is_numeric($_GET['member']) ? (int)$_GET['member'] : 0;

$summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'duplicate_names' => 0,
    'shared_mobiles' => 0,
    'linked_sponsors' => 0,
    'link_later' => 0,
    'teams' => 0,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['members', 'ums_records', 'raw_source_records', 'orders', 'renewals', 'volume_point_entries'] as $table) {
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
        throw new RuntimeException('Operational source layer must remain reconciled at 757/757 before Members & Network can run.');
    }

    $memberStmt = $pdo->prepare(
        "SELECT
            m.id, m.member_code, m.external_member_code, m.full_name, m.mobile, m.email,
            m.city, m.state_region, m.country_code, m.sponsor_member_id, m.member_type,
            m.join_date, m.status member_status, m.source_row, m.source_key,
            sm.full_name linked_sponsor_name,
            u.id ums_id, u.start_date ums_start_date, u.expiry_date, u.renewal_due_date,
            u.status ums_status, u.set_type, u.notes ums_notes,
            r.raw_json,
            (SELECT COUNT(*) FROM orders o WHERE o.organization_id=m.organization_id AND o.member_id=m.id) order_count,
            (SELECT COALESCE(SUM(o.net_amount),0) FROM orders o WHERE o.organization_id=m.organization_id AND o.member_id=m.id) order_value,
            (SELECT COUNT(*) FROM renewals n WHERE n.organization_id=m.organization_id AND n.member_id=m.id) renewal_count,
            (SELECT MAX(n.renewal_date) FROM renewals n WHERE n.organization_id=m.organization_id AND n.member_id=m.id) last_renewal_date,
            (SELECT COUNT(*) FROM volume_point_entries v WHERE v.organization_id=m.organization_id AND v.member_id=m.id) vp_fact_count,
            (SELECT COALESCE(SUM(v.volume_points),0) FROM volume_point_entries v WHERE v.organization_id=m.organization_id AND v.member_id=m.id) total_vp
         FROM members m
         LEFT JOIN members sm ON sm.id=m.sponsor_member_id AND sm.organization_id=m.organization_id
         LEFT JOIN ums_records u ON u.id=(
             SELECT u2.id FROM ums_records u2
             WHERE u2.organization_id=m.organization_id AND u2.member_id=m.id
             ORDER BY u2.start_date DESC, u2.id DESC LIMIT 1
         )
         LEFT JOIN raw_source_records r ON r.id=m.source_record_id
         WHERE m.organization_id=?
         ORDER BY m.full_name, m.id"
    );
    $memberStmt->execute([$organizationId]);

    foreach ($memberStmt->fetchAll() as $row) {
        $values = mem_source_values($row['raw_json'] ?? null);
        $team = mem_trim($values['E'] ?? '');
        $sourceSponsor = mem_trim($values['G'] ?? '');
        $sourceDuration = mem_trim($values['J'] ?? '');
        $sourceActiveFlag = mem_trim($values['K'] ?? '');
        $sourceSupervisor = mem_trim($values['L'] ?? '');
        $sourceUmsType = mem_trim($values['M'] ?? '');
        $nameKey = mem_key($row['full_name']);
        $mobileKey = preg_replace('/\D+/', '', (string)($row['mobile'] ?? '')) ?? '';
        $status = mem_trim($row['ums_status'] ?: $row['member_status']);
        if ($status === '') {
            $status = 'active';
        }

        $prepared = [
            'id' => (int)$row['id'],
            'full_name' => (string)$row['full_name'],
            'name_key' => $nameKey,
            'mobile' => (string)($row['mobile'] ?? ''),
            'mobile_key' => $mobileKey,
            'email' => (string)($row['email'] ?? ''),
            'member_code' => (string)($row['member_code'] ?? ''),
            'external_member_code' => (string)($row['external_member_code'] ?? ''),
            'member_type' => (string)($row['member_type'] ?? ''),
            'join_date' => $row['join_date'] ? (string)$row['join_date'] : null,
            'member_status' => (string)$row['member_status'],
            'ums_id' => $row['ums_id'] !== null ? (int)$row['ums_id'] : null,
            'ums_status' => $status,
            'ums_start_date' => $row['ums_start_date'] ? (string)$row['ums_start_date'] : null,
            'expiry_date' => $row['expiry_date'] ? (string)$row['expiry_date'] : null,
            'renewal_due_date' => $row['renewal_due_date'] ? (string)$row['renewal_due_date'] : null,
            'set_type' => (string)($row['set_type'] ?? ''),
            'team' => $team,
            'source_sponsor' => $sourceSponsor,
            'source_duration' => $sourceDuration,
            'source_active_flag' => $sourceActiveFlag,
            'source_supervisor' => $sourceSupervisor,
            'source_ums_type' => $sourceUmsType,
            'sponsor_member_id' => $row['sponsor_member_id'] !== null ? (int)$row['sponsor_member_id'] : null,
            'linked_sponsor_name' => (string)($row['linked_sponsor_name'] ?? ''),
            'source_row' => (int)($row['source_row'] ?? 0),
            'source_key' => (string)($row['source_key'] ?? ''),
            'order_count' => (int)$row['order_count'],
            'order_value' => (float)$row['order_value'],
            'renewal_count' => (int)$row['renewal_count'],
            'last_renewal_date' => $row['last_renewal_date'] ? (string)$row['last_renewal_date'] : null,
            'vp_fact_count' => (int)$row['vp_fact_count'],
            'total_vp' => (float)$row['total_vp'],
        ];

        $members[] = $prepared;
        $nameCounts[$nameKey] = ($nameCounts[$nameKey] ?? 0) + 1;
        if ($mobileKey !== '') {
            $mobileCounts[$mobileKey] = ($mobileCounts[$mobileKey] ?? 0) + 1;
        }
        if ($team !== '') {
            $teamOptions[mem_key($team)] = $team;
        }
        $statusOptions[mem_key($status)] = $status;
        if ($prepared['sponsor_member_id'] !== null) {
            $sponsorLinkedCount++;
        } elseif ($sourceSponsor !== '') {
            $sponsorLinkLaterCount++;
        }
    }

    uasort($teamOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    uasort($statusOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));

    foreach ($members as &$member) {
        $member['duplicate_name'] = ($nameCounts[$member['name_key']] ?? 0) > 1;
        $member['shared_mobile'] = $member['mobile_key'] !== '' && ($mobileCounts[$member['mobile_key']] ?? 0) > 1;
        $member['identity_state'] = $member['duplicate_name'] || $member['shared_mobile'] ? 'review' : 'clear';
        $member['sponsor_state'] = $member['sponsor_member_id'] !== null ? 'linked' : ($member['source_sponsor'] !== '' ? 'link_later' : 'none');
    }
    unset($member);

    $summary['total'] = count($members);
    $summary['active'] = count(array_filter($members, static fn(array $m): bool => mem_key($m['ums_status']) === 'active'));
    $summary['inactive'] = count(array_filter($members, static fn(array $m): bool => mem_key($m['ums_status']) !== 'active'));
    $summary['duplicate_names'] = count(array_filter($nameCounts, static fn(int $count): bool => $count > 1));
    $summary['shared_mobiles'] = count(array_filter($mobileCounts, static fn(int $count): bool => $count > 1));
    $summary['linked_sponsors'] = $sponsorLinkedCount;
    $summary['link_later'] = $sponsorLinkLaterCount;
    $summary['teams'] = count($teamOptions);

    $qKey = mem_key($filters['q']);
    $statusKey = mem_key($filters['status']);
    $teamKey = mem_key($filters['team']);

    $filteredMembers = array_values(array_filter($members, static function(array $member) use ($qKey, $statusKey, $teamKey): bool {
        if ($statusKey !== '' && $statusKey !== 'all' && mem_key($member['ums_status']) !== $statusKey) {
            return false;
        }
        if ($teamKey !== '' && $teamKey !== 'all' && mem_key($member['team']) !== $teamKey) {
            return false;
        }
        if ($qKey !== '') {
            $haystack = mem_key(implode(' ', [
                $member['full_name'], $member['mobile'], $member['email'], $member['team'],
                $member['source_sponsor'], $member['source_supervisor'], $member['member_code'],
                $member['external_member_code'], (string)$member['source_row'],
            ]));
            if (!str_contains($haystack, $qKey)) {
                return false;
            }
        }
        return true;
    }));

    if ($requestedMemberId > 0) {
        foreach ($members as $member) {
            if ($member['id'] === $requestedMemberId) {
                $selectedMember = $member;
                break;
            }
        }
    }
    if ($selectedMember === null && $filteredMembers) {
        $selectedMember = $filteredMembers[0];
    }
    if ($selectedMember === null && $members) {
        $selectedMember = $members[0];
    }

    if ($selectedMember !== null) {
        $orderStmt = $pdo->prepare(
            "SELECT id, order_date, order_type, description, net_amount, profit_amount, volume_points
             FROM orders WHERE organization_id=? AND member_id=? ORDER BY order_date DESC, id DESC LIMIT 5"
        );
        $orderStmt->execute([$organizationId, $selectedMember['id']]);
        $selectedActivity['orders'] = $orderStmt->fetchAll();

        $renewalStmt = $pdo->prepare(
            "SELECT id, renewal_date, amount, volume_points, notes
             FROM renewals WHERE organization_id=? AND member_id=? ORDER BY renewal_date DESC, id DESC LIMIT 5"
        );
        $renewalStmt->execute([$organizationId, $selectedMember['id']]);
        $selectedActivity['renewals'] = $renewalStmt->fetchAll();

        $vpStmt = $pdo->prepare(
            "SELECT id, entry_date, volume_points, order_type, vp_from, ordered_by, vp_type
             FROM volume_point_entries WHERE organization_id=? AND member_id=? ORDER BY entry_date DESC, id DESC LIMIT 5"
        );
        $vpStmt->execute([$organizationId, $selectedMember['id']]);
        $selectedActivity['vp'] = $vpStmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && $sourceTotal === 757 && $sourceMapped === 757;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Members & Network - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/members.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Members & Network</small></span>
    </a>
    <div class="os-top-actions">
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
      <a class="active" href="members.php"><i class="dot"></i>Members & Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
      <a href="derived_reports_audit.php"><i class="dot"></i>Formula Audit</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Member Tools</div>
    <nav class="os-nav">
      <a href="ums_renewal.php"><i class="dot"></i>UMS Renewal</a>
      <a href="ums_active_duration.php"><i class="dot"></i>Active Duration</a>
      <a href="name_wise_tracking.php"><i class="dot"></i>Name Wise Tracking</a>
      <a href="sp_house.php"><i class="dot"></i>SP House</a>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Member source ready' : 'Review required' ?></b>
      <span><?= number_format($sourceMapped) ?> / 757 source rows mapped • identity-safe source snapshots retained.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero mem-hero">
      <div class="os-kicker">Step 10B • Members & Network</div>
      <h1>One professional workspace for member identity, UMS lifecycle and network context.</h1>
      <p>Search and filter normalized members while preserving source Team, Sponsor and Supervisor context. Sponsor relations are shown as linked only when the database contains a verified relationship; unresolved source names stay visible as Link Later.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'MEMBERS WORKSPACE LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 source mapped</span>
        <span class="os-chip"><?= number_format($summary['total']) ?> member records</span>
        <span class="os-chip"><?= number_format($summary['teams']) ?> source teams</span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="os-footer-note mem-error"><strong>Members diagnostic:</strong> <?= mem_h($error) ?></div>
    <?php else: ?>
      <section class="os-grid mem-kpis">
        <article class="os-card os-kpi green"><small>Total Members</small><strong><?= number_format($summary['total']) ?></strong><span>Source-row preserved identities</span></article>
        <article class="os-card os-kpi blue"><small>Active UMS</small><strong><?= number_format($summary['active']) ?></strong><span>Latest UMS status active</span></article>
        <article class="os-card os-kpi gold"><small>Sponsor Links</small><strong><?= number_format($summary['linked_sponsors']) ?></strong><span><?= number_format($summary['link_later']) ?> source sponsor(s) Link Later</span></article>
        <article class="os-card os-kpi violet"><small>Identity Review</small><strong><?= number_format($summary['duplicate_names'] + $summary['shared_mobiles']) ?></strong><span>Duplicate-name/shared-mobile groups</span></article>
      </section>

      <section class="mem-workspace">
        <article class="os-card mem-list-card">
          <div class="os-title-row">
            <div><h2>Members Overview</h2><p>Showing <?= number_format(count($filteredMembers)) ?> of <?= number_format($summary['total']) ?> records.</p></div>
            <span class="mem-safe-badge">No auto-merge</span>
          </div>

          <form method="get" class="mem-filters">
            <div class="mem-field mem-search">
              <label for="q">Search</label>
              <input id="q" name="q" value="<?= mem_h($filters['q']) ?>" placeholder="Name, mobile, team, sponsor, source row…">
            </div>
            <div class="mem-field">
              <label for="status">UMS Status</label>
              <select id="status" name="status">
                <option value="ALL">All Status</option>
                <?php foreach ($statusOptions as $status): ?>
                  <option value="<?= mem_h($status) ?>" <?= mem_key($filters['status']) === mem_key($status) ? 'selected' : '' ?>><?= mem_h(ucwords($status)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mem-field">
              <label for="team">Team</label>
              <select id="team" name="team">
                <option value="ALL">All Teams</option>
                <?php foreach ($teamOptions as $team): ?>
                  <option value="<?= mem_h($team) ?>" <?= mem_key($filters['team']) === mem_key($team) ? 'selected' : '' ?>><?= mem_h($team) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mem-filter-actions">
              <button type="submit">Apply Filters</button>
              <a href="members.php">Reset</a>
            </div>
          </form>

          <div class="mem-table-wrap">
            <table class="mem-table">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>UMS</th>
                  <th>Team / Sponsor</th>
                  <th>VP</th>
                  <th>Orders</th>
                  <th>Identity</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$filteredMembers): ?>
                <tr><td colspan="7" class="mem-empty">No members match the selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($filteredMembers as $member): ?>
                  <?php
                    $qs = ['member'=>$member['id']];
                    if ($filters['q'] !== '') $qs['q'] = $filters['q'];
                    if (mem_key($filters['status']) !== 'all') $qs['status'] = $filters['status'];
                    if (mem_key($filters['team']) !== 'all') $qs['team'] = $filters['team'];
                    $memberUrl = 'members.php?' . http_build_query($qs);
                  ?>
                  <tr class="<?= $selectedMember && $selectedMember['id'] === $member['id'] ? 'selected' : '' ?>">
                    <td>
                      <div class="mem-person">
                        <span class="mem-avatar"><?= mem_h(strtoupper(substr($member['full_name'], 0, 1))) ?></span>
                        <div><b><?= mem_h($member['full_name']) ?></b><small>Member #<?= number_format($member['id']) ?> • Source row <?= number_format($member['source_row']) ?></small></div>
                      </div>
                    </td>
                    <td><span class="mem-status <?= mem_status_class($member['ums_status']) ?>"><?= mem_h(ucwords($member['ums_status'])) ?></span><small class="mem-block">Since <?= mem_h(mem_date($member['ums_start_date'])) ?></small></td>
                    <td><b class="mem-small-title"><?= mem_h($member['team'] !== '' ? $member['team'] : '—') ?></b><small class="mem-block"><?= mem_h($member['linked_sponsor_name'] !== '' ? $member['linked_sponsor_name'] : ($member['source_sponsor'] !== '' ? $member['source_sponsor'] : 'No sponsor source')) ?></small></td>
                    <td><b><?= mem_h(mem_num($member['total_vp'])) ?></b><small class="mem-block"><?= number_format($member['vp_fact_count']) ?> VP fact(s)</small></td>
                    <td><b><?= number_format($member['order_count']) ?></b><small class="mem-block">₹<?= number_format($member['order_value'], 2) ?></small></td>
                    <td>
                      <?php if ($member['identity_state'] === 'review'): ?><span class="mem-status warn">Review</span><?php else: ?><span class="mem-status good">Clear</span><?php endif; ?>
                      <small class="mem-block"><?= $member['sponsor_state'] === 'linked' ? 'Sponsor linked' : ($member['sponsor_state'] === 'link_later' ? 'Sponsor Link Later' : 'No sponsor source') ?></small>
                    </td>
                    <td><a class="mem-open" href="<?= mem_h($memberUrl) ?>">Open →</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>

        <aside class="os-card mem-detail-card">
          <?php if ($selectedMember === null): ?>
            <div class="mem-empty-detail">Select a member to view details.</div>
          <?php else: ?>
            <div class="mem-detail-head">
              <span class="mem-avatar large"><?= mem_h(strtoupper(substr($selectedMember['full_name'], 0, 1))) ?></span>
              <div>
                <div class="mem-detail-status"><span class="mem-status <?= mem_status_class($selectedMember['ums_status']) ?>"><?= mem_h(ucwords($selectedMember['ums_status'])) ?></span><?php if ($selectedMember['identity_state'] === 'review'): ?><span class="mem-status warn">Identity Review</span><?php endif; ?></div>
                <h2><?= mem_h($selectedMember['full_name']) ?></h2>
                <p>Member #<?= number_format($selectedMember['id']) ?> • Source row <?= number_format($selectedMember['source_row']) ?></p>
              </div>
            </div>

            <div class="mem-detail-kpis">
              <div><small>Total VP</small><strong><?= mem_h(mem_num($selectedMember['total_vp'])) ?></strong></div>
              <div><small>Orders</small><strong><?= number_format($selectedMember['order_count']) ?></strong></div>
              <div><small>Renewals</small><strong><?= number_format($selectedMember['renewal_count']) ?></strong></div>
            </div>

            <section class="mem-panel">
              <div class="mem-panel-title"><h3>Member & UMS Information</h3><span><?= mem_h(mem_duration($selectedMember['ums_start_date'])) ?> active duration</span></div>
              <div class="mem-info-grid">
                <div><small>Mobile</small><b><?= mem_h($selectedMember['mobile'] !== '' ? $selectedMember['mobile'] : 'Not available') ?></b></div>
                <div><small>Join Date</small><b><?= mem_h(mem_date($selectedMember['join_date'])) ?></b></div>
                <div><small>UMS Start</small><b><?= mem_h(mem_date($selectedMember['ums_start_date'])) ?></b></div>
                <div><small>Source UMS Type</small><b><?= mem_h($selectedMember['source_ums_type'] !== '' ? $selectedMember['source_ums_type'] : '—') ?></b></div>
                <div><small>Source Active Flag</small><b><?= mem_h($selectedMember['source_active_flag'] !== '' ? $selectedMember['source_active_flag'] : '—') ?></b></div>
                <div><small>Last Renewal</small><b><?= mem_h(mem_date($selectedMember['last_renewal_date'])) ?></b></div>
              </div>
            </section>

            <section class="mem-panel">
              <div class="mem-panel-title"><h3>Network Context</h3><span class="mem-safe-badge">Source preserved</span></div>
              <div class="mem-network-line"><span>Team of</span><b><?= mem_h($selectedMember['team'] !== '' ? $selectedMember['team'] : '—') ?></b></div>
              <div class="mem-network-line"><span>Sponsor</span><b><?= mem_h($selectedMember['linked_sponsor_name'] !== '' ? $selectedMember['linked_sponsor_name'] : ($selectedMember['source_sponsor'] !== '' ? $selectedMember['source_sponsor'] : '—')) ?></b><em><?= $selectedMember['sponsor_state'] === 'linked' ? 'VERIFIED LINK' : ($selectedMember['sponsor_state'] === 'link_later' ? 'LINK LATER' : 'NO SOURCE') ?></em></div>
              <div class="mem-network-line"><span>Active Supervisor</span><b><?= mem_h($selectedMember['source_supervisor'] !== '' ? $selectedMember['source_supervisor'] : '—') ?></b></div>
              <?php if ($selectedMember['duplicate_name'] || $selectedMember['shared_mobile']): ?>
                <div class="mem-identity-note"><strong>Identity safety:</strong> <?= $selectedMember['duplicate_name'] ? 'This source name appears more than once. ' : '' ?><?= $selectedMember['shared_mobile'] ? 'This mobile appears on more than one source member. ' : '' ?>No automatic merge or sponsor linking is performed.</div>
              <?php endif; ?>
            </section>

            <section class="mem-panel">
              <div class="mem-panel-title"><h3>Recent Activity</h3><span>Live normalized facts</span></div>
              <div class="mem-tabs">
                <div class="mem-activity-group">
                  <h4>Volume Points</h4>
                  <?php if (!$selectedActivity['vp']): ?><p>No linked VP facts.</p><?php else: ?>
                    <?php foreach ($selectedActivity['vp'] as $vp): ?>
                      <div class="mem-activity"><div><b><?= mem_h(mem_num((float)$vp['volume_points'])) ?> VP</b><span><?= mem_h((string)($vp['order_type'] ?: $vp['vp_type'] ?: 'Volume Point')) ?></span></div><small><?= mem_h(mem_date((string)$vp['entry_date'])) ?></small></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="mem-activity-group">
                  <h4>Orders</h4>
                  <?php if (!$selectedActivity['orders']): ?><p>No linked orders.</p><?php else: ?>
                    <?php foreach ($selectedActivity['orders'] as $order): ?>
                      <div class="mem-activity"><div><b>₹<?= number_format((float)$order['net_amount'], 2) ?></b><span><?= mem_h((string)($order['description'] ?: $order['order_type'])) ?></span></div><small><?= mem_h(mem_date((string)$order['order_date'])) ?></small></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="mem-activity-group">
                  <h4>Renewals</h4>
                  <?php if (!$selectedActivity['renewals']): ?><p>No linked renewal facts.</p><?php else: ?>
                    <?php foreach ($selectedActivity['renewals'] as $renewal): ?>
                      <div class="mem-activity"><div><b>Renewal</b><span>Normalized Renewal UMS fact</span></div><small><?= mem_h(mem_date((string)$renewal['renewal_date'])) ?></small></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <div class="mem-detail-actions">
              <a href="ums_renewal.php">Open Renewal Center</a>
              <a href="ums_active_duration.php">Open Active Duration</a>
              <a href="name_wise_tracking.php?owner=<?= rawurlencode($selectedMember['full_name']) ?>">Name Wise Report</a>
            </div>
          <?php endif; ?>
        </aside>
      </section>

      <section class="os-grid mem-bottom-grid">
        <article class="os-card os-section">
          <div class="os-title-row"><div><h2>Identity & Network Safety</h2><p>Source truth is retained until a relationship is explicitly verified.</p></div></div>
          <div class="os-list">
            <div class="os-list-row"><div><b>Duplicate-name groups</b><span>Names that must not be auto-merged</span></div><strong><?= number_format($summary['duplicate_names']) ?></strong></div>
            <div class="os-list-row"><div><b>Shared-mobile groups</b><span>Mobile is contact data, never a unique identity key</span></div><strong><?= number_format($summary['shared_mobiles']) ?></strong></div>
            <div class="os-list-row"><div><b>Verified sponsor links</b><span>Only explicit database relations</span></div><strong><?= number_format($summary['linked_sponsors']) ?></strong></div>
            <div class="os-list-row"><div><b>Sponsor Link Later</b><span>Source sponsor name retained, relation unresolved</span></div><strong><?= number_format($summary['link_later']) ?></strong></div>
          </div>
        </article>
        <aside class="os-card os-side">
          <h2>Next Network Upgrade</h2>
          <p>The current page is a safe professional overview. The next network layer can add verified sponsor/upline linking, tree visualization and controlled edit workflows without changing imported source truth.</p>
          <div class="mem-next-list">
            <span>Verified sponsor-link review</span>
            <span>Interactive network tree</span>
            <span>Member edit with audit trail</span>
            <span>Merge preview — never silent merge</span>
          </div>
        </aside>
      </section>
    <?php endif; ?>

    <div class="os-footer-note"><strong>Identity policy:</strong> member name and mobile are searchable attributes, not global unique keys. Imported source rows remain independently traceable, and unresolved Sponsor/Team/Supervisor relationships are preserved without guessing.</div>
  </main>
</div>
</body>
</html>
