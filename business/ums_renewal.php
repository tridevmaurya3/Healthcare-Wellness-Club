<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function ur_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ur_trim(mixed $value): string
{
    return trim((string)$value);
}

function ur_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', ur_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function ur_source_values(?string $json): array
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

function ur_month_number(mixed $value): ?int
{
    $raw = ur_trim($value);
    if ($raw === '') {
        return null;
    }

    $map = [
        'january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,
        'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,
        'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,'october'=>10,'oct'=>10,
        'november'=>11,'nov'=>11,'december'=>12,'dec'=>12,
    ];

    $key = ur_key($raw);
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (is_numeric($raw)) {
        $number = (int)$raw;
        return ($number >= 1 && $number <= 12) ? $number : null;
    }
    return null;
}

function ur_month_name(int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!m', (string)$month);
    return $date ? $date->format('F') : (string)$month;
}

function ur_excel_date(mixed $value): ?string
{
    $raw = ur_trim($value);
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                return (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }
    }

    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

function ur_active_status(mixed $umsType, mixed $activeFlag): bool
{
    $type = ur_key($umsType);
    $flag = ur_key($activeFlag);

    if (str_contains($type, 'not active') || str_contains($type, 'inactive')) {
        return false;
    }
    if (in_array($flag, ['no', 'false', '0', 'inactive', 'not active'], true)) {
        return false;
    }
    return true;
}

function ur_is_self_team(mixed $team): bool
{
    return in_array(ur_key($team), ['self', 'myself'], true);
}

function ur_date_display(?string $value): string
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

$error = null;
$organizationId = 0;
$sourceMapped = 0;
$sourceTotal = 0;
$renewalFactCount = 0;
$periodOptions = [];
$teamOptions = [];
$supervisorOptions = [];
$selectedYear = 0;
$selectedMonth = 0;
$selectedTeam = 'ALL';
$selectedSupervisor = 'ALL';
$renewedRows = [];
$activeCandidates = [];
$pendingRows = [];
$reviewRows = [];
$renewedNameKeys = [];
$renewedMemberIds = [];
$activeNameCounts = [];
$metrics = [
    'renewed' => 0,
    'renewed_linked' => 0,
    'renewed_source_only' => 0,
    'active_candidates' => 0,
    'pending' => 0,
    'identity_review' => 0,
    'self_excluded' => 0,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['renewals', 'raw_source_records', 'ums_records', 'members'] as $table) {
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
        throw new RuntimeException('Operational source layer must be fully reconciled at 757/757 before UMS Renewal can run.');
    }

    $renewalCountStmt = $pdo->prepare("SELECT COUNT(*) FROM renewals WHERE organization_id=? AND source_sheet='Renewal UMS'");
    $renewalCountStmt->execute([$organizationId]);
    $renewalFactCount = (int)$renewalCountStmt->fetchColumn();
    if ($renewalFactCount !== 141) {
        throw new RuntimeException('Renewal facts are not reconciled at 141/141.');
    }

    $renewalStmt = $pdo->prepare(
        "SELECT n.id, n.member_id, n.member_name_snapshot, n.renewal_date, n.notes, n.source_row, r.raw_json
         FROM renewals n
         LEFT JOIN raw_source_records r ON r.id=n.source_record_id
         WHERE n.organization_id=? AND n.source_sheet='Renewal UMS'
         ORDER BY n.renewal_date, n.id"
    );
    $renewalStmt->execute([$organizationId]);
    $allRenewals = [];
    foreach ($renewalStmt->fetchAll() as $row) {
        $v = ur_source_values($row['raw_json'] ?? null);
        $yearRaw = ur_trim($v['C'] ?? '');
        $year = is_numeric($yearRaw) ? (int)$yearRaw : (int)(new DateTimeImmutable((string)$row['renewal_date']))->format('Y');
        $month = ur_month_number($v['D'] ?? '');
        if ($month === null) {
            $month = (int)(new DateTimeImmutable((string)$row['renewal_date']))->format('n');
        }
        $team = ur_trim($v['H'] ?? '');
        $supervisor = ur_trim($v['G'] ?? '');
        $name = ur_trim($row['member_name_snapshot'] ?? ($v['B'] ?? ''));

        $prepared = [
            'id' => (int)$row['id'],
            'member_id' => $row['member_id'] !== null ? (int)$row['member_id'] : null,
            'name' => $name,
            'name_key' => ur_key($name),
            'year' => $year,
            'month' => $month,
            'renewal_date' => (string)$row['renewal_date'],
            'ums_type' => ur_trim($v['E'] ?? ''),
            'supervisor' => $supervisor,
            'team' => $team,
            'source_row' => (int)$row['source_row'],
        ];
        $allRenewals[] = $prepared;

        $periodKey = sprintf('%04d-%02d', $year, $month);
        $periodOptions[$periodKey] = ['year'=>$year, 'month'=>$month];
        if ($team !== '') {
            $teamOptions[ur_key($team)] = $team;
        }
        if ($supervisor !== '') {
            $supervisorOptions[ur_key($supervisor)] = $supervisor;
        }
    }

    $newUmsStmt = $pdo->prepare(
        "SELECT r.id raw_id, r.source_row, r.mapped_entity_id ums_id, r.raw_json,
                u.member_id, m.full_name
         FROM raw_source_records r
         LEFT JOIN ums_records u ON u.id=r.mapped_entity_id AND u.source_record_id=r.id
         LEFT JOIN members m ON m.id=u.member_id
         WHERE r.organization_id=? AND r.source_dataset='New UMS' AND r.mapping_status='mapped'
         ORDER BY r.source_row"
    );
    $newUmsStmt->execute([$organizationId]);
    $allCandidates = [];
    foreach ($newUmsStmt->fetchAll() as $row) {
        $v = ur_source_values($row['raw_json'] ?? null);
        $name = ur_trim($v['F'] ?? ($row['full_name'] ?? ''));
        $team = ur_trim($v['E'] ?? '');
        $supervisor = ur_trim($v['L'] ?? '');
        $sponsor = ur_trim($v['G'] ?? '');
        $startDate = ur_excel_date($v['H'] ?? '');
        $active = ur_active_status($v['M'] ?? '', $v['K'] ?? '');

        $candidate = [
            'raw_id' => (int)$row['raw_id'],
            'source_row' => (int)$row['source_row'],
            'member_id' => $row['member_id'] !== null ? (int)$row['member_id'] : null,
            'name' => $name,
            'name_key' => ur_key($name),
            'team' => $team,
            'supervisor' => $supervisor,
            'sponsor' => $sponsor,
            'start_date' => $startDate,
            'ums_type' => ur_trim($v['M'] ?? ''),
            'source_active_flag' => ur_trim($v['K'] ?? ''),
            'active' => $active,
        ];
        $allCandidates[] = $candidate;

        if ($team !== '') {
            $teamOptions[ur_key($team)] = $teamOptions[ur_key($team)] ?? $team;
        }
        if ($supervisor !== '') {
            $supervisorOptions[ur_key($supervisor)] = $supervisorOptions[ur_key($supervisor)] ?? $supervisor;
        }
    }

    krsort($periodOptions, SORT_STRING);
    uasort($teamOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    uasort($supervisorOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));

    $requestedYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : 0;
    $requestedMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : 0;
    $requestedPeriodKey = ($requestedYear > 0 && $requestedMonth >= 1 && $requestedMonth <= 12)
        ? sprintf('%04d-%02d', $requestedYear, $requestedMonth)
        : '';

    if ($requestedPeriodKey !== '' && isset($periodOptions[$requestedPeriodKey])) {
        $selectedYear = $requestedYear;
        $selectedMonth = $requestedMonth;
    } else {
        $latest = reset($periodOptions);
        $selectedYear = (int)($latest['year'] ?? (int)date('Y'));
        $selectedMonth = (int)($latest['month'] ?? (int)date('n'));
    }

    $requestedTeam = ur_trim($_GET['team'] ?? 'ALL');
    $requestedTeamKey = ur_key($requestedTeam);
    if ($requestedTeam !== 'ALL' && isset($teamOptions[$requestedTeamKey])) {
        $selectedTeam = $teamOptions[$requestedTeamKey];
    }

    $requestedSupervisor = ur_trim($_GET['supervisor'] ?? 'ALL');
    $requestedSupervisorKey = ur_key($requestedSupervisor);
    if ($requestedSupervisor !== 'ALL' && isset($supervisorOptions[$requestedSupervisorKey])) {
        $selectedSupervisor = $supervisorOptions[$requestedSupervisorKey];
    }

    $teamFilterKey = $selectedTeam === 'ALL' ? null : ur_key($selectedTeam);
    $supervisorFilterKey = $selectedSupervisor === 'ALL' ? null : ur_key($selectedSupervisor);

    foreach ($allRenewals as $row) {
        if ($row['year'] !== $selectedYear || $row['month'] !== $selectedMonth) {
            continue;
        }
        if ($teamFilterKey !== null && ur_key($row['team']) !== $teamFilterKey) {
            continue;
        }
        if ($supervisorFilterKey !== null && ur_key($row['supervisor']) !== $supervisorFilterKey) {
            continue;
        }
        $renewedRows[] = $row;
        if ($row['member_id'] !== null) {
            $renewedMemberIds[$row['member_id']] = true;
            $metrics['renewed_linked']++;
        } else {
            $renewedNameKeys[$row['name_key']] = true;
            $metrics['renewed_source_only']++;
        }
    }
    $metrics['renewed'] = count($renewedRows);

    $monthEnd = (new DateTimeImmutable(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth)))->modify('last day of this month')->format('Y-m-d');

    foreach ($allCandidates as $candidate) {
        if (!$candidate['active']) {
            continue;
        }
        if ($candidate['start_date'] !== null && $candidate['start_date'] > $monthEnd) {
            continue;
        }
        if (ur_is_self_team($candidate['team'])) {
            $metrics['self_excluded']++;
            continue;
        }
        if ($teamFilterKey !== null && ur_key($candidate['team']) !== $teamFilterKey) {
            continue;
        }
        if ($supervisorFilterKey !== null && ur_key($candidate['supervisor']) !== $supervisorFilterKey) {
            continue;
        }

        $activeCandidates[] = $candidate;
        if ($candidate['name_key'] !== '') {
            $activeNameCounts[$candidate['name_key']] = ($activeNameCounts[$candidate['name_key']] ?? 0) + 1;
        }
    }
    $metrics['active_candidates'] = count($activeCandidates);

    foreach ($activeCandidates as $candidate) {
        $safeRenewed = false;
        $review = false;

        if ($candidate['member_id'] !== null && isset($renewedMemberIds[$candidate['member_id']])) {
            $safeRenewed = true;
        } elseif ($candidate['name_key'] !== '' && isset($renewedNameKeys[$candidate['name_key']])) {
            if (($activeNameCounts[$candidate['name_key']] ?? 0) === 1) {
                $safeRenewed = true;
            } else {
                $review = true;
            }
        }

        if ($safeRenewed) {
            continue;
        }

        $candidate['status'] = $review ? 'IDENTITY REVIEW' : 'PENDING';
        if ($review) {
            $reviewRows[] = $candidate;
        } else {
            $pendingRows[] = $candidate;
        }
    }

    $metrics['pending'] = count($pendingRows);
    $metrics['identity_review'] = count($reviewRows);

    usort($renewedRows, static fn(array $a, array $b): int => strcmp($b['renewal_date'], $a['renewal_date']) ?: strnatcasecmp($a['name'], $b['name']));
    usort($pendingRows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($reviewRows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
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
  <title>UMS Renewal Live Report - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .ur-wide{grid-column:span 12}.ur-main{grid-column:span 8}.ur-side{grid-column:span 4}
    .ur-filter{display:grid;grid-template-columns:1fr 1.2fr 1.4fr 1.4fr auto;gap:10px;align-items:end}.ur-filter label{font-size:.67rem;font-weight:800;color:#64736b;text-transform:uppercase}.ur-filter select{width:100%;margin-top:5px;padding:10px;border:1px solid #dce6df;border-radius:11px;background:#fff;color:#23332a}.ur-filter button{padding:11px 18px;border:0;border-radius:11px;background:#19764a;color:#fff;font-weight:800;cursor:pointer}
    .ur-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.75rem}.ur-table th,.ur-table td{padding:10px;border-bottom:1px solid #e8efea;text-align:left;vertical-align:top}.ur-table th{font-size:.65rem;text-transform:uppercase;color:#65746c}.ur-muted{color:#697770}.ur-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#276746;font-size:.66rem;font-weight:800}.ur-badge.warn{background:#fff3d7;color:#805b12}.ur-badge.review{background:#f3edff;color:#6d4ca3}.ur-badge.gray{background:#f0f3f1;color:#56645d}.ur-note{padding:13px 14px;margin-top:12px;border:1px solid #dfe8e2;border-radius:13px;background:#f8fbf9;color:#53645a;font-size:.76rem;line-height:1.55}
    @media(max-width:980px){.ur-main,.ur-side{grid-column:span 12}.ur-filter{grid-template-columns:1fr 1fr}.ur-filter .wide{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • UMS Renewal Live Report</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="master_business_tracking.php">← Master Business</a>
      <a href="derived_reports_audit.php">Formula Audit</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9F • UMS Renewal live engine</div>
      <h1>Renewed and pending UMS lists now come from normalized source facts, with preservation-first identity matching.</h1>
      <p>Spreadsheet FILTER/MATCH behavior is translated into a safe live anti-join. Linked Member IDs are preferred; source-name matching is used only when one active candidate has that exact name. Duplicate-name ambiguity is shown for review instead of guessed.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good"><?= number_format($sourceMapped) ?> / <?= number_format($sourceTotal) ?> source mapped</span>
      <span class="imp-chip <?= $error === null ? 'good' : '' ?>"><?= $error === null ? 'UMS RENEWAL LIVE' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert ur-wide"><strong>UMS Renewal could not run:</strong> <?= ur_h($error) ?></div>
    <?php else: ?>
      <article class="imp-card ur-wide">
        <form method="get" class="ur-filter">
          <div>
            <label for="year">Year</label>
            <select id="year" name="year">
              <?php $years = array_values(array_unique(array_map(static fn(array $p): int => $p['year'], $periodOptions))); rsort($years); ?>
              <?php foreach ($years as $year): ?><option value="<?= $year ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="month">Renewal Month</label>
            <select id="month" name="month">
              <?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m === $selectedMonth ? 'selected' : '' ?>><?= ur_h(ur_month_name($m)) ?></option><?php endfor; ?>
            </select>
          </div>
          <div class="wide">
            <label for="team">Team</label>
            <select id="team" name="team">
              <option value="ALL">All Teams</option>
              <?php foreach ($teamOptions as $team): ?><option value="<?= ur_h($team) ?>" <?= ur_key($team) === ur_key($selectedTeam) ? 'selected' : '' ?>><?= ur_h($team) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="wide">
            <label for="supervisor">Supervisor</label>
            <select id="supervisor" name="supervisor">
              <option value="ALL">All Supervisors</option>
              <?php foreach ($supervisorOptions as $supervisor): ?><option value="<?= ur_h($supervisor) ?>" <?= ur_key($supervisor) === ur_key($selectedSupervisor) ? 'selected' : '' ?>><?= ur_h($supervisor) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><button type="submit">Apply</button></div>
        </form>
      </article>

      <section class="imp-summary" aria-label="UMS renewal summary">
        <article class="imp-kpi green"><small>Renewed</small><strong><?= number_format($metrics['renewed']) ?></strong><span><?= ur_h(ur_month_name($selectedMonth)) ?> <?= $selectedYear ?></span></article>
        <article class="imp-kpi blue"><small>Active Candidates</small><strong><?= number_format($metrics['active_candidates']) ?></strong><span>Started on/before month end</span></article>
        <article class="imp-kpi gold"><small>Pending</small><strong><?= number_format($metrics['pending']) ?></strong><span>Safe anti-join result</span></article>
        <article class="imp-kpi"><small>Identity Review</small><strong><?= number_format($metrics['identity_review']) ?></strong><span>Duplicate-name ambiguity</span></article>
      </section>

      <article class="imp-card ur-main">
        <h2>Renewed UMS</h2>
        <p>Renewal facts filtered by source Year, UMS Month, Team and Supervisor.</p>
        <div style="overflow:auto">
          <table class="ur-table">
            <thead><tr><th>Name</th><th>Renewal Date</th><th>UMS Type</th><th>Team</th><th>Supervisor</th><th>Identity</th></tr></thead>
            <tbody>
            <?php if (!$renewedRows): ?>
              <tr><td colspan="6" class="ur-muted">No renewal rows match this filter.</td></tr>
            <?php else: foreach ($renewedRows as $row): ?>
              <tr>
                <td><strong><?= ur_h($row['name']) ?></strong><br><span class="ur-muted">Source row <?= number_format($row['source_row']) ?></span></td>
                <td><?= ur_h(ur_date_display($row['renewal_date'])) ?></td>
                <td><?= ur_h($row['ums_type'] !== '' ? $row['ums_type'] : '—') ?></td>
                <td><?= ur_h($row['team'] !== '' ? $row['team'] : '—') ?></td>
                <td><?= ur_h($row['supervisor'] !== '' ? $row['supervisor'] : '—') ?></td>
                <td><span class="ur-badge <?= $row['member_id'] !== null ? '' : 'gray' ?>"><?= $row['member_id'] !== null ? 'MEMBER LINKED' : 'SOURCE NAME' ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </article>

      <aside class="imp-card ur-side">
        <h2>Matching policy</h2>
        <div class="imp-plan-list">
          <div class="imp-plan-row"><div><b>1. Member ID first</b><span>A linked renewal is matched to the exact active Member ID.</span></div><em>SAFE</em></div>
          <div class="imp-plan-row"><div><b>2. Unique source name</b><span>If the renewal has no Member ID, exact-name matching is allowed only when one active candidate owns that name.</span></div><em>SAFE</em></div>
          <div class="imp-plan-row"><div><b>3. Duplicate source name</b><span>Ambiguous names are not auto-merged or silently marked renewed.</span></div><em>REVIEW</em></div>
          <div class="imp-plan-row"><div><b>Self/Myself rows</b><span>Exact source Team labels Self/Myself are excluded from pending candidates, matching the workbook's self-exclusion behavior.</span></div><em><?= number_format($metrics['self_excluded']) ?></em></div>
        </div>
        <div class="ur-note"><strong>Source integrity:</strong> <?= number_format($renewalFactCount) ?>/141 Renewal UMS facts are present. Of the filtered renewed rows, <?= number_format($metrics['renewed_linked']) ?> use canonical Member IDs and <?= number_format($metrics['renewed_source_only']) ?> retain source-name identity.</div>
      </aside>

      <article class="imp-card ur-main">
        <h2>Pending Renewal</h2>
        <p>Active UMS candidates that have no safe matching renewal record in the selected Year/Month context.</p>
        <div style="overflow:auto">
          <table class="ur-table">
            <thead><tr><th>Name</th><th>UMS Start</th><th>UMS Type</th><th>Team</th><th>Sponsor</th><th>Supervisor</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$pendingRows): ?>
              <tr><td colspan="7" class="ur-muted">No safely identified pending rows match this filter.</td></tr>
            <?php else: foreach ($pendingRows as $row): ?>
              <tr>
                <td><strong><?= ur_h($row['name']) ?></strong><br><span class="ur-muted">Source row <?= number_format($row['source_row']) ?></span></td>
                <td><?= ur_h(ur_date_display($row['start_date'])) ?></td>
                <td><?= ur_h($row['ums_type'] !== '' ? $row['ums_type'] : '—') ?></td>
                <td><?= ur_h($row['team'] !== '' ? $row['team'] : '—') ?></td>
                <td><?= ur_h($row['sponsor'] !== '' ? $row['sponsor'] : '—') ?></td>
                <td><?= ur_h($row['supervisor'] !== '' ? $row['supervisor'] : '—') ?></td>
                <td><span class="ur-badge warn">PENDING</span></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </article>

      <aside class="imp-card ur-side">
        <h2>Identity review queue</h2>
        <p>These rows are intentionally kept separate from definite pending status because an unresolved source-name renewal collides with more than one active source identity.</p>
        <?php if (!$reviewRows): ?>
          <div class="ur-note">No duplicate-name renewal ambiguity exists for the selected filter.</div>
        <?php else: ?>
          <?php foreach ($reviewRows as $row): ?>
            <div class="imp-plan-row"><div><b><?= ur_h($row['name']) ?></b><span><?= ur_h($row['team'] !== '' ? $row['team'] : 'No team') ?> • Source row <?= number_format($row['source_row']) ?></span></div><em style="color:#6d4ca3">REVIEW</em></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="ur-note"><strong>Important:</strong> “Pending” here means the active source identity is not safely present in the selected renewal dataset. It does not guess payment amount, VP value, or merge duplicate people.</div>
      </aside>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
