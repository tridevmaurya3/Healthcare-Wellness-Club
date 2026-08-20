<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

function net_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function net_trim(mixed $value): string
{
    return trim((string)$value);
}

function net_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', net_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function net_values(?string $json): array
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

function net_would_cycle(int $memberId, int $sponsorId, array $parentMap): bool
{
    if ($memberId === $sponsorId) {
        return true;
    }

    $seen = [];
    $cursor = $sponsorId;
    for ($i = 0; $i < 200; $i++) {
        if ($cursor === $memberId) {
            return true;
        }
        if (isset($seen[$cursor])) {
            return true;
        }
        $seen[$cursor] = true;
        if (!isset($parentMap[$cursor]) || $parentMap[$cursor] === null) {
            return false;
        }
        $cursor = (int)$parentMap[$cursor];
    }
    return true;
}

function net_tree_html(int $memberId, array $membersById, array $children, array $treeStates, int $depth = 0): string
{
    if (!isset($membersById[$memberId]) || $depth > 12) {
        return '';
    }

    $m = $membersById[$memberId];
    $kids = $children[$memberId] ?? [];
    $state = $treeStates[$memberId] ?? 'root';
    $class = $state === 'candidate' ? 'candidate' : ($state === 'linked' ? 'linked' : '');
    $initial = function_exists('mb_substr') ? mb_substr((string)$m['full_name'], 0, 1, 'UTF-8') : substr((string)$m['full_name'], 0, 1);

    $html = '<li>';
    $html .= '<div class="net-node ' . net_h($class) . '">';
    $html .= '<span class="avatar">' . net_h(strtoupper($initial)) . '</span>';
    $html .= '<div><b>' . net_h($m['full_name']) . '</b><small>';
    $parts = [];
    if ($m['team'] !== '') {
        $parts[] = $m['team'];
    }
    $parts[] = 'Member #' . $m['id'];
    if ($state === 'candidate') {
        $parts[] = 'Safe-link preview';
    } elseif ($state === 'linked') {
        $parts[] = 'Sponsor linked';
    }
    $html .= net_h(implode(' • ', $parts)) . '</small></div>';
    if ($kids) {
        $html .= '<button class="toggle" type="button" aria-label="Toggle children">−</button>';
    }
    $html .= '</div>';

    if ($kids) {
        $html .= '<ul>';
        foreach ($kids as $childId) {
            $html .= net_tree_html((int)$childId, $membersById, $children, $treeStates, $depth + 1);
        }
        $html .= '</ul>';
    }
    $html .= '</li>';
    return $html;
}

if (empty($_SESSION['sponsor_network_csrf'])) {
    $_SESSION['sponsor_network_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['sponsor_network_csrf'];

$error = null;
$success = isset($_GET['linked']) && is_numeric($_GET['linked'])
    ? number_format((int)$_GET['linked']) . ' verified safe sponsor link(s) were applied successfully.'
    : null;
$organizationId = 0;
$sourceTotal = 0;
$sourceMapped = 0;
$members = [];
$membersById = [];
$nameIndex = [];
$rows = [];
$safeLinks = [];
$parentMap = [];
$treeParentMap = [];
$treeStates = [];
$children = [];
$roots = [];
$summary = [
    'total' => 0,
    'linked' => 0,
    'safe' => 0,
    'ambiguous' => 0,
    'not_found' => 0,
    'no_source' => 0,
    'cycle_blocked' => 0,
];

try {
    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    foreach (['members', 'raw_source_records', 'audit_logs'] as $table) {
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
        throw new RuntimeException('Operational source layer must remain reconciled at 757/757 before sponsor linking can run.');
    }

    $memberStmt = $pdo->prepare(
        "SELECT m.id, m.full_name, m.sponsor_member_id, m.source_row, m.source_record_id,
                sm.full_name linked_sponsor_name, r.raw_json
         FROM members m
         LEFT JOIN members sm ON sm.id=m.sponsor_member_id AND sm.organization_id=m.organization_id
         LEFT JOIN raw_source_records r ON r.id=m.source_record_id
         WHERE m.organization_id=?
         ORDER BY m.full_name, m.id"
    );
    $memberStmt->execute([$organizationId]);

    foreach ($memberStmt->fetchAll() as $row) {
        $values = net_values($row['raw_json'] ?? null);
        $prepared = [
            'id' => (int)$row['id'],
            'full_name' => (string)$row['full_name'],
            'name_key' => net_key($row['full_name']),
            'sponsor_member_id' => $row['sponsor_member_id'] !== null ? (int)$row['sponsor_member_id'] : null,
            'linked_sponsor_name' => (string)($row['linked_sponsor_name'] ?? ''),
            'source_sponsor' => net_trim($values['G'] ?? ''),
            'team' => net_trim($values['E'] ?? ''),
            'source_row' => (int)($row['source_row'] ?? 0),
        ];
        $members[] = $prepared;
        $membersById[$prepared['id']] = $prepared;
        $nameIndex[$prepared['name_key']][] = $prepared['id'];
        $parentMap[$prepared['id']] = $prepared['sponsor_member_id'];
    }

    $basicCandidates = [];
    foreach ($members as $member) {
        if ($member['sponsor_member_id'] !== null) {
            continue;
        }
        $sourceSponsor = $member['source_sponsor'];
        if ($sourceSponsor === '') {
            continue;
        }
        $matches = $nameIndex[net_key($sourceSponsor)] ?? [];
        if (count($matches) === 1 && (int)$matches[0] !== $member['id']) {
            $basicCandidates[$member['id']] = (int)$matches[0];
        }
    }

    // Test all potential exact-unique edges together. If a source loop exists, every edge in that loop is blocked.
    $proposedParentMap = $parentMap;
    foreach ($basicCandidates as $memberId => $sponsorId) {
        $proposedParentMap[(int)$memberId] = (int)$sponsorId;
    }

    foreach ($members as $member) {
        $status = 'none';
        $candidateId = null;
        $candidateName = '';
        $sourceSponsor = $member['source_sponsor'];

        if ($member['sponsor_member_id'] !== null) {
            $status = 'linked';
            $summary['linked']++;
            $candidateId = $member['sponsor_member_id'];
            $candidateName = $member['linked_sponsor_name'];
        } elseif ($sourceSponsor === '') {
            $status = 'no_source';
            $summary['no_source']++;
        } else {
            $matches = $nameIndex[net_key($sourceSponsor)] ?? [];
            if (!$matches) {
                $status = 'not_found';
                $summary['not_found']++;
            } elseif (count($matches) > 1) {
                $status = 'ambiguous';
                $summary['ambiguous']++;
            } elseif ((int)$matches[0] === $member['id']) {
                $status = 'cycle_blocked';
                $summary['cycle_blocked']++;
            } else {
                $candidateId = (int)$matches[0];
                $candidateName = $membersById[$candidateId]['full_name'] ?? $sourceSponsor;
                if (net_would_cycle($member['id'], $candidateId, $proposedParentMap)) {
                    $status = 'cycle_blocked';
                    $summary['cycle_blocked']++;
                } else {
                    $status = 'safe';
                    $summary['safe']++;
                    $safeLinks[$member['id']] = $candidateId;
                }
            }
        }

        $rows[] = $member + [
            'link_status' => $status,
            'candidate_id' => $candidateId,
            'candidate_name' => $candidateName,
        ];
    }

    $summary['total'] = count($members);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        }
        if (($_POST['confirm_safe_links'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the verified safe-link policy before applying sponsor links.');
        }
        if (!$safeLinks) {
            header('Location: sponsor_network.php?linked=0');
            exit;
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE members SET sponsor_member_id=?, updated_at=NOW() WHERE organization_id=? AND id=? AND sponsor_member_id IS NULL'
            );
            $applied = 0;
            foreach ($safeLinks as $memberId => $sponsorId) {
                $update->execute([(int)$sponsorId, $organizationId, (int)$memberId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Sponsor link state changed unexpectedly for member #' . (int)$memberId . '.');
                }
                $applied++;
            }

            $audit = $pdo->prepare(
                "INSERT INTO audit_logs
                 (organization_id, event_type, entity_type, details_json, ip_address, user_agent)
                 VALUES (?, 'verified_sponsor_links_applied', 'member_network', ?, ?, ?)"
            );
            $details = json_encode([
                'applied_links' => $applied,
                'policy' => 'source-sponsor-name-exact-unique-only',
                'ambiguous_blocked' => $summary['ambiguous'],
                'not_found_blocked' => $summary['not_found'],
                'cycle_blocked' => $summary['cycle_blocked'],
                'no_auto_merge' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $audit->execute([
                $organizationId,
                $details ?: '{}',
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);

            $pdo->commit();
            header('Location: sponsor_network.php?linked=' . $applied);
            exit;
        } catch (Throwable $transactionError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $transactionError;
        }
    }

    // Interactive tree uses existing verified links plus safe candidates as a clearly marked preview.
    $treeParentMap = $parentMap;
    foreach ($safeLinks as $memberId => $sponsorId) {
        if (($treeParentMap[$memberId] ?? null) === null) {
            $treeParentMap[$memberId] = $sponsorId;
        }
    }

    foreach ($members as $member) {
        $id = $member['id'];
        if ($member['sponsor_member_id'] !== null) {
            $treeStates[$id] = 'linked';
        } elseif (isset($safeLinks[$id])) {
            $treeStates[$id] = 'candidate';
        } else {
            $treeStates[$id] = 'root';
        }
    }

    foreach ($treeParentMap as $memberId => $sponsorId) {
        if ($sponsorId !== null && isset($membersById[(int)$sponsorId])) {
            $children[(int)$sponsorId][] = (int)$memberId;
        }
    }
    foreach ($children as &$childList) {
        usort($childList, static fn(int $a, int $b): int => strnatcasecmp(
            (string)($membersById[$a]['full_name'] ?? ''),
            (string)($membersById[$b]['full_name'] ?? '')
        ));
    }
    unset($childList);

    foreach ($members as $member) {
        $parent = $treeParentMap[$member['id']] ?? null;
        if ($parent === null || !isset($membersById[(int)$parent])) {
            $roots[] = $member['id'];
        }
    }
    usort($roots, static fn(int $a, int $b): int => strnatcasecmp(
        (string)($membersById[$a]['full_name'] ?? ''),
        (string)($membersById[$b]['full_name'] ?? '')
    ));
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
  <title>Sponsor Network - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/network.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Verified Sponsor Network</small></span>
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
      <a class="active" href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
      <a href="final_excel_seeding.php"><i class="dot"></i>Excel Data Center</a>
    </nav>
    <div class="os-nav-label" style="margin-top:8px">Network Safety</div>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Identity-safe linker ready' : 'Review required' ?></b>
      <span>Only exact unique source-sponsor matches can become safe candidates. Ambiguous names and loops remain blocked.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero net-hero">
      <div class="os-kicker">Step 10C • Verified Sponsor Network</div>
      <h1>Build the sponsor tree without guessing who a source name belongs to.</h1>
      <p>Existing verified links stay intact. New candidates are offered only when the source Sponsor name resolves to exactly one member in this organization and the proposed relationship does not create a network loop.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'SPONSOR NETWORK READY' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 source mapped</span>
        <span class="os-chip"><?= number_format($summary['linked']) ?> linked</span>
        <span class="os-chip"><?= number_format($summary['safe']) ?> safe candidate(s)</span>
      </div>
    </section>

    <?php if ($success !== null): ?><div class="net-success"><?= net_h($success) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="net-error"><strong>Network diagnostic:</strong> <?= net_h($error) ?></div><?php endif; ?>

    <?php if ($error === null): ?>
      <section class="os-grid net-kpis">
        <article class="os-card os-kpi green"><small>Members</small><strong><?= number_format($summary['total']) ?></strong><span>Organization member records</span></article>
        <article class="os-card os-kpi blue"><small>Verified Links</small><strong><?= number_format($summary['linked']) ?></strong><span>Already stored in sponsor_member_id</span></article>
        <article class="os-card os-kpi gold"><small>Safe Candidates</small><strong><?= number_format($summary['safe']) ?></strong><span>Exact unique + cycle-safe</span></article>
        <article class="os-card os-kpi violet"><small>Needs Review</small><strong><?= number_format($summary['ambiguous'] + $summary['not_found'] + $summary['cycle_blocked']) ?></strong><span>Ambiguous, missing or loop-blocked</span></article>
      </section>

      <section class="net-grid">
        <article class="os-card net-main">
          <div class="os-title-row">
            <div><h2>Sponsor Link Review</h2><p>Source Sponsor → candidate Member mapping. Nothing ambiguous is auto-selected.</p></div>
            <span class="net-badge good">No auto-merge</span>
          </div>

          <div class="net-table-wrap">
            <table class="net-table">
              <thead><tr><th>Member</th><th>Team</th><th>Source Sponsor</th><th>Resolved Sponsor</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><span class="net-name"><?= net_h($row['full_name']) ?></span><span class="net-sub">Member #<?= number_format($row['id']) ?> • Source row <?= number_format($row['source_row']) ?></span></td>
                  <td><?= net_h($row['team'] !== '' ? $row['team'] : '—') ?></td>
                  <td><?= net_h($row['source_sponsor'] !== '' ? $row['source_sponsor'] : '—') ?></td>
                  <td><?= net_h($row['candidate_name'] !== '' ? $row['candidate_name'] : ($row['linked_sponsor_name'] !== '' ? $row['linked_sponsor_name'] : '—')) ?></td>
                  <td>
                    <?php if ($row['link_status'] === 'linked'): ?><span class="net-badge good">VERIFIED LINKED</span>
                    <?php elseif ($row['link_status'] === 'safe'): ?><span class="net-badge good">SAFE CANDIDATE</span>
                    <?php elseif ($row['link_status'] === 'ambiguous'): ?><span class="net-badge warn">AMBIGUOUS</span>
                    <?php elseif ($row['link_status'] === 'not_found'): ?><span class="net-badge warn">SPONSOR NOT IN MEMBERS</span>
                    <?php elseif ($row['link_status'] === 'cycle_blocked'): ?><span class="net-badge warn">LOOP / SELF BLOCKED</span>
                    <?php else: ?><span class="net-badge muted">NO SOURCE SPONSOR</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($safeLinks): ?>
            <form method="post" class="net-safe-box">
              <input type="hidden" name="csrf" value="<?= net_h($csrf) ?>">
              <label><input type="checkbox" name="confirm_safe_links" value="yes" required><span>I confirm applying only the <?= number_format(count($safeLinks)) ?> exact-unique, cycle-safe sponsor candidate(s). Ambiguous/not-found relationships must remain unresolved.</span></label>
              <button type="submit">Apply <?= number_format(count($safeLinks)) ?> Verified Safe Sponsor Link(s) →</button>
            </form>
          <?php else: ?>
            <div class="net-note"><strong>No pending safe candidates.</strong> Existing verified links are preserved. Ambiguous or unavailable source sponsor names remain for manual identity review.</div>
          <?php endif; ?>
        </article>

        <aside class="os-card net-side net-tree-card">
          <div class="net-tree-toolbar">
            <div><h2>Interactive Network Tree</h2><p>Solid verified links + clearly marked safe-link preview.</p></div>
            <button id="treeToggle" type="button">Collapse All</button>
          </div>
          <div class="net-tree" id="networkTree">
            <?php if (!$roots): ?>
              <div class="net-empty">No network roots are available.</div>
            <?php else: ?>
              <ul>
                <?php foreach ($roots as $rootId): ?><?= net_tree_html((int)$rootId, $membersById, $children, $treeStates) ?><?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="net-note"><strong>Tree meaning:</strong> green/normal nodes are verified or roots; cream nodes are safe exact-match previews until you apply them. Unresolved sponsor names remain outside guessed parent relationships.</div>
        </aside>
      </section>
    <?php endif; ?>
  </main>
</div>

<script>
(function(){
  const tree=document.getElementById('networkTree');
  if(!tree)return;
  tree.addEventListener('click',function(e){
    const btn=e.target.closest('.toggle');
    if(!btn)return;
    const li=btn.closest('li');
    const ul=li?li.querySelector(':scope > ul'):null;
    if(!ul)return;
    const hidden=ul.hidden;
    ul.hidden=!hidden;
    btn.textContent=hidden?'−':'+';
  });
  const all=document.getElementById('treeToggle');
  let collapsed=false;
  if(all){
    all.addEventListener('click',function(){
      collapsed=!collapsed;
      tree.querySelectorAll('li > ul').forEach(function(ul){ul.hidden=collapsed;});
      tree.querySelectorAll('.toggle').forEach(function(btn){btn.textContent=collapsed?'+':'−';});
      all.textContent=collapsed?'Expand All':'Collapse All';
    });
  }
})();
</script>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
