<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function uad_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uad_trim(mixed $value): string
{
    return trim((string)$value);
}

function uad_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', uad_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function uad_source_values(?string $json): array
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

function uad_date_display(?string $value): string
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

/**
 * Workbook-compatible display mode:
 * 1) whole calendar years are separated first;
 * 2) remaining days are converted to approximate months using 30.44 days/month.
 * Nothing is persisted; duration is recalculated on every request.
 */
function uad_legacy_duration(string $startDate, DateTimeImmutable $asOf): array
{
    try {
        $start = new DateTimeImmutable($startDate, $asOf->getTimezone());
    } catch (Throwable) {
        return ['valid' => false, 'label' => 'Invalid date', 'years' => 0, 'months' => 0.0, 'days' => 0];
    }

    $start = $start->setTime(0, 0, 0);
    $end = $asOf->setTime(0, 0, 0);

    if ($start > $end) {
        return ['valid' => true, 'label' => 'Not started', 'years' => 0, 'months' => 0.0, 'days' => 0];
    }

    $calendarDiff = $start->diff($end);
    $years = (int)$calendarDiff->y;
    $anniversary = $start->modify('+' . $years . ' years');
    if ($anniversary > $end && $years > 0) {
        $years--;
        $anniversary = $start->modify('+' . $years . ' years');
    }

    $remainingDays = (int)$anniversary->diff($end)->format('%a');
    $months = round($remainingDays / 30.44, 1);
    $totalDays = (int)$start->diff($end)->format('%a');

    $parts = [];
    if ($years > 0) {
        $parts[] = $years . ' yr' . ($years === 1 ? '' : 's');
    }
    $parts[] = rtrim(rtrim(number_format($months, 1, '.', ''), '0'), '.') . ' mo';

    return [
        'valid' => true,
        'label' => implode(' ', $parts),
        'years' => $years,
        'months' => $months,
        'days' => $totalDays,
    ];
}

$error = null;
$organizationId = 0;
$organizationName = '';
$timezoneName = 'UTC';
$sourceMapped = 0;
$sourceTotal = 0;
$umsFactCount = 0;
$activeRows = [];
$teamOptions = [];
$selectedTeam = 'ALL';
$selectedAsOf = '';
$asOf = null;
$metrics = [
    'active' => 0,
    'active_filtered' => 0,
    'unique_sponsors' => 0,
    'unique_teams' => 0,
    'avg_days' => 0.0,
    'longest_days' => 0,
];

try {
    $pdo = business_db();

    $orgStmt = $pdo->query("SELECT id, organization_name, timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1");
    $org = $orgStmt->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $organizationId = (int)$org['id'];
    $organizationName = (string)$org['organization_name'];
    $timezoneName = uad_trim($org['timezone'] ?? '') ?: 'UTC';

    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
        $timezoneName = 'UTC';
        $timezone = new DateTimeZone('UTC');
    }

    foreach (['members', 'ums_records', 'raw_source_records'] as $table) {
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
        throw new RuntimeException('Operational source layer must be fully reconciled at 757/757 before UMS Active Duration can run.');
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ums_records WHERE organization_id=? AND source_sheet='New UMS'");
    $countStmt->execute([$organizationId]);
    $umsFactCount = (int)$countStmt->fetchColumn();
    if ($umsFactCount !== 78) {
        throw new RuntimeException('New UMS facts are not reconciled at 78/78.');
    }

    $requestedAsOf = uad_trim($_GET['as_of'] ?? '');
    if ($requestedAsOf !== '') {
        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedAsOf, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $candidate instanceof DateTimeImmutable && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0));
        if (!$valid || $candidate->format('Y-m-d') !== $requestedAsOf) {
            throw new RuntimeException('As-of date is invalid. Use a real YYYY-MM-DD date.');
        }
        $asOf = $candidate;
    } else {
        $asOf = new DateTimeImmutable('today', $timezone);
    }
    $selectedAsOf = $asOf->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT u.id ums_id, u.member_id, u.start_date, u.status normalized_status, u.notes ums_notes,
                m.full_name member_full_name, r.source_row, r.raw_json
         FROM ums_records u
         INNER JOIN raw_source_records r ON r.id=u.source_record_id
         LEFT JOIN members m ON m.id=u.member_id
         WHERE u.organization_id=? AND u.source_sheet='New UMS'
         ORDER BY u.start_date, u.id"
    );
    $stmt->execute([$organizationId]);

    $allActive = [];
    foreach ($stmt->fetchAll() as $row) {
        $values = uad_source_values($row['raw_json'] ?? null);
        $name = uad_trim($row['member_full_name'] ?? '') ?: uad_trim($values['F'] ?? '');
        $team = uad_trim($values['E'] ?? '');
        $sponsor = uad_trim($values['G'] ?? '');
        $sourceActiveFlag = uad_trim($values['K'] ?? '');
        $activeSupervisor = uad_trim($values['L'] ?? '');
        $umsType = uad_trim($values['M'] ?? '');
        $status = uad_key($row['normalized_status'] ?? '');

        // Active Duration reproduces the active UMS view only. The normalized status is the authority;
        // source labels remain visible for audit and context.
        if ($status !== 'active') {
            continue;
        }

        $duration = uad_legacy_duration((string)$row['start_date'], $asOf);
        $prepared = [
            'ums_id' => (int)$row['ums_id'],
            'member_id' => $row['member_id'] !== null ? (int)$row['member_id'] : null,
            'source_row' => (int)$row['source_row'],
            'team' => $team,
            'name' => $name,
            'sponsor' => $sponsor,
            'start_date' => (string)$row['start_date'],
            'status' => (string)$row['normalized_status'],
            'source_active_flag' => $sourceActiveFlag,
            'active_supervisor' => $activeSupervisor,
            'ums_type' => $umsType,
            'duration' => $duration,
        ];
        $allActive[] = $prepared;
        if ($team !== '') {
            $teamOptions[uad_key($team)] = $team;
        }
    }

    uasort($teamOptions, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
    $metrics['active'] = count($allActive);

    $requestedTeam = uad_trim($_GET['team'] ?? 'ALL');
    if ($requestedTeam !== '' && uad_key($requestedTeam) !== 'all' && isset($teamOptions[uad_key($requestedTeam)])) {
        $selectedTeam = $teamOptions[uad_key($requestedTeam)];
    }

    $activeRows = array_values(array_filter($allActive, static function (array $row) use ($selectedTeam): bool {
        return $selectedTeam === 'ALL' || uad_key($row['team']) === uad_key($selectedTeam);
    }));

    usort($activeRows, static function (array $a, array $b): int {
        $daysCompare = ((int)$b['duration']['days']) <=> ((int)$a['duration']['days']);
        return $daysCompare !== 0 ? $daysCompare : strnatcasecmp((string)$a['name'], (string)$b['name']);
    });

    $metrics['active_filtered'] = count($activeRows);
    $sponsors = [];
    $teams = [];
    $totalDays = 0;
    $longest = 0;
    foreach ($activeRows as $row) {
        if ($row['sponsor'] !== '') {
            $sponsors[uad_key($row['sponsor'])] = true;
        }
        if ($row['team'] !== '') {
            $teams[uad_key($row['team'])] = true;
        }
        $days = (int)$row['duration']['days'];
        $totalDays += $days;
        $longest = max($longest, $days);
    }
    $metrics['unique_sponsors'] = count($sponsors);
    $metrics['unique_teams'] = count($teams);
    $metrics['avg_days'] = $metrics['active_filtered'] > 0 ? $totalDays / $metrics['active_filtered'] : 0.0;
    $metrics['longest_days'] = $longest;
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
  <title>UMS Active Duration Live Report - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .uad-wide{grid-column:span 12}.uad-main{grid-column:span 9}.uad-side{grid-column:span 3}
    .uad-filter{display:grid;grid-template-columns:1.4fr 1fr auto;gap:10px;align-items:end}.uad-filter label{font-size:.68rem;font-weight:800;color:#64736b;text-transform:uppercase}.uad-filter select,.uad-filter input{width:100%;margin-top:5px;padding:10px;border:1px solid #dce6df;border-radius:11px;background:#fff;color:#23332a}.uad-filter button{padding:11px 18px;border:0;border-radius:11px;background:#19764a;color:#fff;font-weight:800;cursor:pointer}
    .uad-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.75rem}.uad-table th,.uad-table td{padding:10px;border-bottom:1px solid #e8efea;text-align:left;vertical-align:top}.uad-table th{font-size:.65rem;text-transform:uppercase;color:#65746c}.uad-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#236744;font-size:.67rem;font-weight:800}.uad-muted{color:#6c7972}.uad-rule{padding:13px 14px;margin-top:12px;border:1px solid #d8e7de;border-radius:13px;background:#f7fbf8;color:#42544a;font-size:.77rem;line-height:1.58}.uad-rule.warn{background:#fff9e9;border-color:#ecd9a8;color:#735415}.uad-stat{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #e8efea;font-size:.77rem}.uad-stat strong{color:#1f593b}.uad-sub{display:block;margin-top:4px;font-size:.68rem;color:#76827c}
    @media(max-width:900px){.uad-main,.uad-side{grid-column:span 12}.uad-filter{grid-template-columns:1fr 1fr}.uad-filter button{grid-column:span 2}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • UMS Active Duration</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="ums_renewal.php">← UMS Renewal</a>
      <a href="derived_reports_audit.php">Formula Audit</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 9G • Final workbook-derived live report</div>
      <h1>UMS Active Duration is now calculated live from the normalized UMS lifecycle.</h1>
      <p>Active rows come from normalized New UMS facts. Team, Sponsor, source Active flag, Active Supervisor and UMS Type remain traceable to the original source row. Duration is recalculated for the chosen as-of date and is never stored as a stale fact.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Database write is OFF</span>
      <span class="imp-chip good"><?= number_format($sourceMapped) ?> / <?= number_format($sourceTotal) ?> source mapped</span>
      <span class="imp-chip <?= $error === null ? 'good' : '' ?>"><?= $error === null ? 'ACTIVE DURATION LIVE' : 'Review required' ?></span>
    </div>
  </section>

  <section class="imp-grid">
    <?php if ($error !== null): ?>
      <div class="imp-alert uad-wide"><strong>UMS Active Duration could not run:</strong> <?= uad_h($error) ?></div>
    <?php else: ?>
      <article class="imp-card uad-wide">
        <form method="get" class="uad-filter">
          <div>
            <label for="team">Team</label>
            <select id="team" name="team">
              <option value="ALL" <?= $selectedTeam === 'ALL' ? 'selected' : '' ?>>All Teams</option>
              <?php foreach ($teamOptions as $team): ?>
                <option value="<?= uad_h($team) ?>" <?= uad_key($team) === uad_key($selectedTeam) ? 'selected' : '' ?>><?= uad_h($team) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="as_of">As-of Date</label>
            <input id="as_of" type="date" name="as_of" value="<?= uad_h($selectedAsOf) ?>">
          </div>
          <button type="submit">Refresh Live Duration</button>
        </form>
      </article>

      <section class="imp-summary" aria-label="UMS Active Duration summary">
        <article class="imp-kpi green"><small>Active UMS</small><strong><?= number_format($metrics['active_filtered']) ?></strong><span><?= $selectedTeam === 'ALL' ? 'All teams' : uad_h($selectedTeam) ?></span></article>
        <article class="imp-kpi blue"><small>Unique Sponsors</small><strong><?= number_format($metrics['unique_sponsors']) ?></strong><span>Selected live view</span></article>
        <article class="imp-kpi gold"><small>Teams</small><strong><?= number_format($metrics['unique_teams']) ?></strong><span>Source-preserved labels</span></article>
        <article class="imp-kpi"><small>As-of Date</small><strong><?= uad_h((new DateTimeImmutable($selectedAsOf))->format('d M Y')) ?></strong><span><?= uad_h($timezoneName) ?></span></article>
      </section>

      <article class="imp-card uad-main">
        <h2>Active UMS duration list</h2>
        <p>Sorted by longest active duration. All identity and relationship labels remain source-traceable.</p>
        <div style="overflow:auto">
          <table class="uad-table">
            <thead>
              <tr>
                <th>#</th><th>Team of</th><th>Name</th><th>Sponsor</th><th>UMS Date</th><th>Live Duration</th><th>UMS Type</th><th>Active Supervisor</th><th>Source Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$activeRows): ?>
                <tr><td colspan="9" class="uad-muted">No active UMS rows match this filter.</td></tr>
              <?php endif; ?>
              <?php foreach ($activeRows as $i => $row): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td><?= uad_h($row['team'] ?: '—') ?></td>
                  <td><strong><?= uad_h($row['name'] ?: '—') ?></strong><span class="uad-sub">Member ID <?= $row['member_id'] !== null ? number_format($row['member_id']) : 'unlinked' ?> • source row <?= number_format($row['source_row']) ?></span></td>
                  <td><?= uad_h($row['sponsor'] ?: '—') ?></td>
                  <td><?= uad_h(uad_date_display($row['start_date'])) ?></td>
                  <td><span class="uad-badge"><?= uad_h($row['duration']['label']) ?></span><span class="uad-sub"><?= number_format((int)$row['duration']['days']) ?> elapsed days</span></td>
                  <td><?= uad_h($row['ums_type'] ?: '—') ?></td>
                  <td><?= uad_h($row['active_supervisor'] ?: '—') ?></td>
                  <td><span class="uad-badge"><?= uad_h($row['source_active_flag'] ?: $row['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>

      <aside class="imp-card uad-side">
        <h2>Live calculation state</h2>
        <div class="uad-stat"><span>New UMS facts</span><strong><?= number_format($umsFactCount) ?>/78</strong></div>
        <div class="uad-stat"><span>All active UMS</span><strong><?= number_format($metrics['active']) ?></strong></div>
        <div class="uad-stat"><span>Filtered active UMS</span><strong><?= number_format($metrics['active_filtered']) ?></strong></div>
        <div class="uad-stat"><span>Average active days</span><strong><?= number_format($metrics['avg_days'], 1) ?></strong></div>
        <div class="uad-stat"><span>Longest active days</span><strong><?= number_format($metrics['longest_days']) ?></strong></div>

        <div class="uad-rule">
          <strong>Duration policy</strong><br>
          Whole calendar years are separated first. Remaining days are converted using the workbook-compatible <strong>30.44 days per month</strong> display rule. The value is calculated at view time only.
        </div>
        <div class="uad-rule warn">
          <strong>Preservation-first rule</strong><br>
          Team, Sponsor, Active Supervisor and UMS Type are displayed from the original New UMS source row. They are not reconstructed or guessed from names.
        </div>
      </aside>

      <div class="imp-alert good uad-wide">
        <strong>Sheets 1–6 live-report milestone:</strong> Master Tracking, SP House, Name Wise Tracking, Master Business Tracking, UMS Renewal and UMS Active Duration now have database-powered live report pages. The next phase can consolidate these engines into the professional Business OS dashboard and report center.
      </div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
