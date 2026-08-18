<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function gs_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gs_trim(mixed $value): string
{
    return trim((string)$value);
}

function gs_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', gs_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function gs_money(mixed $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function gs_num(mixed $value): string
{
    $text = number_format((float)$value, 3, '.', ',');
    return rtrim(rtrim($text, '0'), '.');
}

function gs_date(?string $value): string
{
    if (!$value) return '—';
    try { return (new DateTimeImmutable($value))->format('d M Y'); }
    catch (Throwable) { return (string)$value; }
}

function gs_period_link(string $page, ?string $date, array $extra = []): string
{
    $params = $extra;
    if ($date) {
        try {
            $d = new DateTimeImmutable($date);
            $params['year'] = $d->format('Y');
            $params['month'] = $d->format('n');
        } catch (Throwable) {
        }
    }
    return $page . ($params ? '?' . http_build_query($params) : '');
}

$query = gs_trim($_GET['q'] ?? '');
$scope = gs_key($_GET['scope'] ?? 'all');
$allowedScopes = ['all','member','order','vp','renewal','income','royalty','tool','report'];
if (!in_array($scope, $allowedScopes, true)) $scope = 'all';

$error = null;
$organizationId = 0;
$sourceTotal = 0;
$sourceMapped = 0;
$sourcePending = 0;
$results = [];
$counts = ['member'=>0,'order'=>0,'vp'=>0,'renewal'=>0,'income'=>0,'royalty'=>0,'tool'=>0,'report'=>0];
$rev = BUSINESS_REVERSED_SOURCE_SHEET;

$tools = [
    ['title'=>'Data Management','keywords'=>'data management create correct reverse restore audit workflow','desc'=>'Create → Correct → Reverse → Restore → Audit workflow hub.','url'=>'data_management.php','badge'=>'CONTROL'],
    ['title'=>'Smart Data Entry','keywords'=>'add create new ums vp order renewal income royalty entry','desc'=>'Create new daily MANUAL business facts with raw trace and duplicate guard.','url'=>'data_entry_center.php','badge'=>'WRITE'],
    ['title'=>'Add New UMS','keywords'=>'new ums member create add sponsor','desc'=>'Create a new member + UMS lifecycle record.','url'=>'data_entry_center.php?tab=new_ums','badge'=>'WRITE'],
    ['title'=>'Add Volume Points','keywords'=>'vp volume points add create','desc'=>'Add a verified member-linked Volume Point fact.','url'=>'data_entry_center.php?tab=vp','badge'=>'WRITE'],
    ['title'=>'Add Order','keywords'=>'order add create sale profit discount','desc'=>'Add an order with value, profit and VP.','url'=>'data_entry_center.php?tab=order','badge'=>'WRITE'],
    ['title'=>'Add Renewal','keywords'=>'renewal ums renew add create','desc'=>'Record a verified member UMS renewal.','url'=>'data_entry_center.php?tab=renewal','badge'=>'WRITE'],
    ['title'=>'Add Income','keywords'=>'income retail check club add create','desc'=>'Add Retail, Check, Club or Other income.','url'=>'data_entry_center.php?tab=income','badge'=>'WRITE'],
    ['title'=>'Add Royalty','keywords'=>'royalty add create','desc'=>'Add a royalty amount and optional VP.','url'=>'data_entry_center.php?tab=royalty','badge'=>'WRITE'],
    ['title'=>'Correction Center','keywords'=>'edit correct correction wrong manual entry','desc'=>'Correct active MANUAL facts with before/after audit evidence.','url'=>'correction_center.php','badge'=>'SAFE EDIT'],
    ['title'=>'Reverse / Cancel Center','keywords'=>'reverse cancel delete remove wrong manual entry no hard delete','desc'=>'Remove a MANUAL fact from business effect without deleting evidence.','url'=>'reversal_center.php','badge'=>'NO DELETE'],
    ['title'=>'Restore Center','keywords'=>'restore recover reversed cancelled entry','desc'=>'Recover a reversed fact after conflict checks.','url'=>'restore_center.php','badge'=>'RECOVER'],
    ['title'=>'Unified Audit Center','keywords'=>'audit history timeline created corrected reversed restored activity','desc'=>'Read the complete manual lifecycle audit timeline.','url'=>'audit_center.php','badge'=>'READ ONLY'],
    ['title'=>'Operations Center','keywords'=>'operations orders vp income royalty analytics','desc'=>'Orders, VP, Income and Royalty operational workspace.','url'=>'operations_center.php','badge'=>'LIVE'],
    ['title'=>'Members & Network','keywords'=>'member members network ums sponsor search','desc'=>'Search normalized members and inspect UMS/network context.','url'=>'members.php','badge'=>'LIVE'],
    ['title'=>'Member Profile 360°','keywords'=>'member profile 360 timeline business history','desc'=>'Open a verified member’s complete business timeline.','url'=>'member_profile.php','badge'=>'LIVE'],
    ['title'=>'Sponsor Network','keywords'=>'sponsor network tree downline upline','desc'=>'Verified sponsor links and interactive network tree.','url'=>'sponsor_network.php','badge'=>'LIVE'],
    ['title'=>'Report Center','keywords'=>'reports reporting master tracking sp house name wise ums','desc'=>'Open all six live derived reports.','url'=>'report_center.php','badge'=>'6 REPORTS'],
    ['title'=>'Excel Data Center','keywords'=>'excel import historical legacy reconcile 757','desc'=>'Historical source reconciliation and normalized seeding tools.','url'=>'final_excel_seeding.php','badge'=>'LEGACY'],
    ['title'=>'Formula Audit','keywords'=>'formula audit mapping workbook derived rules','desc'=>'Review workbook formula mapping and derived-report logic.','url'=>'derived_reports_audit.php','badge'=>'AUDIT'],
];

$reports = [
    ['title'=>'Master Tracking','keywords'=>'master tracking weekly monthly vp income royalty','desc'=>'Weekly/monthly VP and business tracking.','url'=>'master_tracking.php'],
    ['title'=>'SP House','keywords'=>'sp house personal family first line associate team vp','desc'=>'SP/first-line and team VP live view.','url'=>'sp_house.php'],
    ['title'=>'Name Wise Tracking','keywords'=>'name wise tracking pc associate ums vp','desc'=>'Name-wise PC, Associate and UMS VP tracking.','url'=>'name_wise_tracking.php'],
    ['title'=>'Master Business Tracking','keywords'=>'master business ppv dvp active ums royalty new ums','desc'=>'PPV, DVP, royalty and active UMS business summary.','url'=>'master_business_tracking.php'],
    ['title'=>'UMS Renewal','keywords'=>'ums renewal renewed pending identity review','desc'=>'Renewed, pending and identity-review workspace.','url'=>'ums_renewal.php'],
    ['title'=>'UMS Active Duration','keywords'=>'ums active duration lifecycle team supervisor','desc'=>'Live UMS duration and lifecycle view.','url'=>'ums_active_duration.php'],
];

try {
    $pdo = business_db();
    foreach (['organizations','raw_source_records','members','orders','volume_point_entries','renewals','income_entries','royalty_entries'] as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException("Required table {$table} is missing.");
    }

    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) throw new RuntimeException('Healthcare Wellness Club organization was not found.');

    $state = $pdo->prepare("SELECT COUNT(*) total_rows,SUM(mapping_status='mapped') mapped_rows,SUM(mapping_status='pending') pending_rows
        FROM raw_source_records WHERE organization_id=? AND source_dataset IN
        ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");
    $state->execute([$organizationId]);
    $row = $state->fetch() ?: [];
    $sourceTotal = (int)($row['total_rows'] ?? 0);
    $sourceMapped = (int)($row['mapped_rows'] ?? 0);
    $sourcePending = (int)($row['pending_rows'] ?? 0);

    if ($query !== '') {
        $like = '%' . $query . '%';

        if ($scope === 'all' || $scope === 'member') {
            $stmt = $pdo->prepare("SELECT m.id,m.full_name,m.mobile,m.member_code,m.external_member_code,m.status,m.join_date,m.source_sheet,
                    sm.full_name sponsor_name
                FROM members m
                LEFT JOIN members sm ON sm.id=m.sponsor_member_id AND sm.organization_id=m.organization_id
                WHERE m.organization_id=? AND COALESCE(m.source_sheet,'')<>?
                  AND (m.full_name LIKE ? OR COALESCE(m.mobile,'') LIKE ? OR COALESCE(m.member_code,'') LIKE ?
                       OR COALESCE(m.external_member_code,'') LIKE ? OR CAST(m.id AS CHAR) LIKE ?)
                ORDER BY CASE WHEN m.full_name=? THEN 0 WHEN m.full_name LIKE CONCAT(?, '%') THEN 1 ELSE 2 END,m.full_name,m.id
                LIMIT 18");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like,$query,$query]);
            foreach ($stmt->fetchAll() as $r) {
                $meta = array_filter([
                    $r['mobile'] ?: null,
                    $r['status'] ?: null,
                    $r['sponsor_name'] ? 'Sponsor: ' . $r['sponsor_name'] : null,
                ]);
                $results[] = ['type'=>'member','title'=>(string)$r['full_name'],'subtitle'=>implode(' • ',$meta),'value'=>'Member #' . (int)$r['id'],'url'=>'member_profile.php?member=' . (int)$r['id'],'badge'=>'MEMBER'];
                $counts['member']++;
            }
        }

        if ($scope === 'all' || $scope === 'order') {
            $stmt = $pdo->prepare("SELECT o.id,o.member_id,o.order_date,o.order_type,o.description,o.net_amount,o.profit_amount,o.volume_points,m.full_name
                FROM orders o LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
                WHERE o.organization_id=? AND COALESCE(o.source_sheet,'')<>?
                  AND (CAST(o.id AS CHAR) LIKE ? OR COALESCE(m.full_name,'') LIKE ? OR COALESCE(o.order_type,'') LIKE ?
                       OR COALESCE(o.description,'') LIKE ? OR CAST(o.net_amount AS CHAR) LIKE ?)
                ORDER BY o.order_date DESC,o.id DESC LIMIT 14");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like]);
            foreach ($stmt->fetchAll() as $r) {
                $extra = $r['member_id'] ? ['member'=>(int)$r['member_id']] : [];
                $results[] = ['type'=>'order','title'=>'Order #' . (int)$r['id'] . ' • ' . ((string)($r['full_name'] ?: 'Source-only customer')),
                    'subtitle'=>gs_date((string)$r['order_date']) . ' • ' . ((string)($r['description'] ?: $r['order_type'] ?: 'Order')),
                    'value'=>gs_money($r['net_amount']) . ' • ' . gs_num($r['volume_points']) . ' VP',
                    'url'=>gs_period_link('operations_center.php',(string)$r['order_date'],$extra),'badge'=>'ORDER'];
                $counts['order']++;
            }
        }

        if ($scope === 'all' || $scope === 'vp') {
            $stmt = $pdo->prepare("SELECT v.id,v.member_id,v.member_name_snapshot,v.entry_date,v.volume_points,v.order_type,v.vp_from,v.ordered_by,v.vp_type,v.level_label,v.week_label,m.full_name
                FROM volume_point_entries v LEFT JOIN members m ON m.id=v.member_id AND m.organization_id=v.organization_id
                WHERE v.organization_id=? AND COALESCE(v.source_sheet,'')<>?
                  AND (CAST(v.id AS CHAR) LIKE ? OR COALESCE(m.full_name,v.member_name_snapshot,'') LIKE ? OR COALESCE(v.order_type,'') LIKE ?
                       OR COALESCE(v.vp_from,'') LIKE ? OR COALESCE(v.ordered_by,'') LIKE ? OR COALESCE(v.vp_type,'') LIKE ?
                       OR COALESCE(v.level_label,'') LIKE ? OR COALESCE(v.week_label,'') LIKE ? OR CAST(v.volume_points AS CHAR) LIKE ?)
                ORDER BY v.entry_date DESC,v.id DESC LIMIT 14");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like,$like,$like,$like,$like]);
            foreach ($stmt->fetchAll() as $r) {
                $name = (string)($r['full_name'] ?: $r['member_name_snapshot'] ?: 'Source-only member');
                $extra = $r['member_id'] ? ['member'=>(int)$r['member_id']] : [];
                $detail = array_filter([$r['order_type'] ?: null,$r['vp_from'] ? 'From: ' . $r['vp_from'] : null,$r['vp_type'] ?: null,$r['week_label'] ?: null]);
                $results[] = ['type'=>'vp','title'=>gs_num($r['volume_points']) . ' VP • ' . $name,'subtitle'=>gs_date($r['entry_date'] ? (string)$r['entry_date'] : null) . ($detail ? ' • ' . implode(' • ',$detail) : ''),'value'=>'VP #' . (int)$r['id'],'url'=>gs_period_link('operations_center.php',$r['entry_date'] ? (string)$r['entry_date'] : null,$extra),'badge'=>'VP'];
                $counts['vp']++;
            }
        }

        if ($scope === 'all' || $scope === 'renewal') {
            $hasSnapshot = business_column_exists($pdo,'renewals','member_name_snapshot');
            $nameExpr = $hasSnapshot ? "COALESCE(m.full_name,n.member_name_snapshot,'Source-only member')" : "COALESCE(m.full_name,'Source-only member')";
            $stmt = $pdo->prepare("SELECT n.id,n.member_id,n.renewal_date,n.amount,n.volume_points,{$nameExpr} display_name
                FROM renewals n LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id
                WHERE n.organization_id=? AND COALESCE(n.source_sheet,'')<>?
                  AND (CAST(n.id AS CHAR) LIKE ? OR {$nameExpr} LIKE ? OR CAST(n.amount AS CHAR) LIKE ? OR CAST(n.volume_points AS CHAR) LIKE ? OR CAST(n.renewal_date AS CHAR) LIKE ?)
                ORDER BY n.renewal_date DESC,n.id DESC LIMIT 14");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like]);
            foreach ($stmt->fetchAll() as $r) {
                $url = $r['member_id'] ? 'member_profile.php?member=' . (int)$r['member_id'] . '&event=renewal' : 'ums_renewal.php';
                $results[] = ['type'=>'renewal','title'=>'Renewal #' . (int)$r['id'] . ' • ' . (string)$r['display_name'],'subtitle'=>gs_date((string)$r['renewal_date']),'value'=>gs_money($r['amount']) . ' • ' . gs_num($r['volume_points']) . ' VP','url'=>$url,'badge'=>'RENEWAL'];
                $counts['renewal']++;
            }
        }

        if ($scope === 'all' || $scope === 'income') {
            $stmt = $pdo->prepare("SELECT id,income_date,income_type,amount,period_key FROM income_entries
                WHERE organization_id=? AND COALESCE(source_sheet,'')<>?
                  AND (CAST(id AS CHAR) LIKE ? OR income_type LIKE ? OR CAST(amount AS CHAR) LIKE ? OR CAST(income_date AS CHAR) LIKE ? OR COALESCE(period_key,'') LIKE ?)
                ORDER BY income_date DESC,id DESC LIMIT 12");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like]);
            foreach ($stmt->fetchAll() as $r) {
                $results[] = ['type'=>'income','title'=>ucfirst((string)$r['income_type']) . ' Income #' . (int)$r['id'],'subtitle'=>gs_date((string)$r['income_date']) . ($r['period_key'] ? ' • ' . $r['period_key'] : ''),'value'=>gs_money($r['amount']),'url'=>gs_period_link('operations_center.php',(string)$r['income_date'],['q'=>(string)$r['income_type']]),'badge'=>'INCOME'];
                $counts['income']++;
            }
        }

        if ($scope === 'all' || $scope === 'royalty') {
            $stmt = $pdo->prepare("SELECT id,royalty_date,period_label,amount,volume_points FROM royalty_entries
                WHERE organization_id=? AND COALESCE(source_sheet,'')<>?
                  AND (CAST(id AS CHAR) LIKE ? OR COALESCE(period_label,'') LIKE ? OR CAST(amount AS CHAR) LIKE ? OR CAST(volume_points AS CHAR) LIKE ? OR CAST(royalty_date AS CHAR) LIKE ?)
                ORDER BY royalty_date DESC,id DESC LIMIT 12");
            $stmt->execute([$organizationId,$rev,$like,$like,$like,$like,$like]);
            foreach ($stmt->fetchAll() as $r) {
                $results[] = ['type'=>'royalty','title'=>'Royalty #' . (int)$r['id'] . ($r['period_label'] ? ' • ' . $r['period_label'] : ''),'subtitle'=>gs_date($r['royalty_date'] ? (string)$r['royalty_date'] : null),'value'=>gs_money($r['amount']) . ' • ' . gs_num($r['volume_points']) . ' VP','url'=>gs_period_link('operations_center.php',$r['royalty_date'] ? (string)$r['royalty_date'] : null,['q'=>(string)($r['period_label'] ?: 'royalty')]),'badge'=>'ROYALTY'];
                $counts['royalty']++;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$searchKey = gs_key($query);
if ($scope === 'all' || $scope === 'tool') {
    foreach ($tools as $tool) {
        if ($query !== '' && !str_contains(gs_key($tool['title'] . ' ' . $tool['keywords'] . ' ' . $tool['desc']), $searchKey)) continue;
        $results[] = ['type'=>'tool','title'=>$tool['title'],'subtitle'=>$tool['desc'],'value'=>'Open tool','url'=>$tool['url'],'badge'=>$tool['badge']];
        $counts['tool']++;
    }
}
if ($scope === 'all' || $scope === 'report') {
    foreach ($reports as $report) {
        if ($query !== '' && !str_contains(gs_key($report['title'] . ' ' . $report['keywords'] . ' ' . $report['desc']), $searchKey)) continue;
        $results[] = ['type'=>'report','title'=>$report['title'],'subtitle'=>$report['desc'],'value'=>'Open live report','url'=>$report['url'],'badge'=>'REPORT'];
        $counts['report']++;
    }
}

$typeOrder = ['tool'=>0,'report'=>1,'member'=>2,'order'=>3,'vp'=>4,'renewal'=>5,'income'=>6,'royalty'=>7];
if ($query !== '') {
    usort($results, static function(array $a,array $b) use ($typeOrder): int {
        $ta = $typeOrder[$a['type']] ?? 99;
        $tb = $typeOrder[$b['type']] ?? 99;
        return $ta <=> $tb;
    });
}

$totalResults = count($results);
$sourceReady = $sourceTotal === 757 && $sourceMapped === 757 && $sourcePending === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Global Search & Command Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/global_search.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner">
<a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Global Search & Command Center</small></span></a>
<div class="os-top-actions"><a class="os-btn" href="data_management.php">Data Management</a><a class="os-btn" href="operations_center.php">Operations</a><a class="os-btn primary" href="index.php">Dashboard</a></div>
</div></header>

<div class="os-layout">
<aside class="os-sidebar">
<div class="os-nav-label">Business OS</div><nav class="os-nav">
<a href="index.php"><i class="dot"></i>Dashboard</a>
<a class="active" href="global_search.php"><i class="dot"></i>Global Search</a>
<a href="data_management.php"><i class="dot"></i>Data Management</a>
<a href="operations_center.php"><i class="dot"></i>Operations Center</a>
<a href="members.php"><i class="dot"></i>Members & Network</a>
<a href="member_profile.php"><i class="dot"></i>Member Profile 360°</a>
<a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
<a href="report_center.php"><i class="dot"></i>Report Center</a>
</nav>
<div class="os-sidebar-status"><b><?= $error===null ? 'Search engine ready' : 'Review required' ?></b><span><?= number_format($sourceMapped) ?> / 757 legacy mapped • reversed MANUAL facts stay hidden from active search.</span></div>
</aside>

<main class="os-main">
<section class="os-hero gs-hero"><div class="os-kicker">Step 10N • Universal Business Search</div>
<h1>Find a person, transaction, report or command from one search box.</h1>
<p>Search verified business data by member name/mobile/ID, order details, VP dimensions, renewal, income or royalty. You can also type commands such as “add order”, “correction”, “audit” or “master tracking” and jump directly to the right tool.</p>
<div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'GLOBAL SEARCH LIVE':'Review required' ?></span><span class="os-chip <?= $sourceReady?'good':'' ?>"><?= number_format($sourceMapped) ?> / 757 legacy mapped</span><span class="os-chip good">READ ONLY SEARCH</span><span class="os-chip">Reversed facts excluded</span></div>
</section>

<?php if ($error!==null): ?><div class="gs-alert bad"><strong>Search diagnostic:</strong> <?= gs_h($error) ?></div><?php endif; ?>

<section class="os-card gs-search-card">
<form method="get" class="gs-search-form" id="globalSearchForm">
<div class="gs-search-box"><span>⌕</span><input id="globalSearchInput" name="q" value="<?= gs_h($query) ?>" placeholder="Search member, mobile, order, VP, renewal, income, royalty, report or command…" autocomplete="off" autofocus><kbd>/</kbd></div>
<select name="scope" aria-label="Search scope">
<option value="all" <?= $scope==='all'?'selected':'' ?>>Everything</option>
<option value="member" <?= $scope==='member'?'selected':'' ?>>Members</option>
<option value="order" <?= $scope==='order'?'selected':'' ?>>Orders</option>
<option value="vp" <?= $scope==='vp'?'selected':'' ?>>Volume Points</option>
<option value="renewal" <?= $scope==='renewal'?'selected':'' ?>>Renewals</option>
<option value="income" <?= $scope==='income'?'selected':'' ?>>Income</option>
<option value="royalty" <?= $scope==='royalty'?'selected':'' ?>>Royalty</option>
<option value="tool" <?= $scope==='tool'?'selected':'' ?>>Tools / Commands</option>
<option value="report" <?= $scope==='report'?'selected':'' ?>>Reports</option>
</select>
<button type="submit">Search →</button>
</form>
<div class="gs-hints"><span>Try:</span><a href="?q=add+order">add order</a><a href="?q=correction">correction</a><a href="?q=audit">audit</a><a href="?q=master+tracking">master tracking</a><a href="?q=renewal">renewal</a></div>
</section>

<?php if ($error===null): ?>
<section class="gs-summary">
<?php foreach ($counts as $key=>$count): if ($count<=0) continue; ?><a href="?q=<?= rawurlencode($query) ?>&scope=<?= gs_h($key) ?>"><b><?= number_format($count) ?></b><span><?= gs_h(ucwords(str_replace('_',' ',$key))) ?></span></a><?php endforeach; ?>
</section>

<section class="gs-layout">
<article class="os-card gs-results">
<div class="os-title-row"><div><h2><?= $query===''?'Command Center':'Search Results' ?></h2><p><?= $query===''?'Choose a frequent Business OS command or live report.':number_format($totalResults) . ' result(s) for “' . gs_h($query) . '”.' ?></p></div><?php if ($query!==''): ?><a class="os-btn" href="global_search.php">Clear</a><?php endif; ?></div>

<?php if (!$results): ?>
<div class="gs-empty"><b>No matching result found.</b><span>Try a shorter name/mobile fragment, an ID, or change the search scope. Search never guesses member identity.</span></div>
<?php else: ?>
<div class="gs-list">
<?php foreach ($results as $item): ?>
<a class="gs-item <?= gs_h($item['type']) ?>" href="<?= gs_h($item['url']) ?>">
<span class="gs-icon"><?= gs_h(strtoupper(substr($item['badge'],0,2))) ?></span>
<div class="gs-body"><div><b><?= gs_h($item['title']) ?></b><em><?= gs_h($item['badge']) ?></em></div><p><?= gs_h($item['subtitle']) ?></p></div>
<strong><?= gs_h($item['value']) ?></strong><i>→</i>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</article>

<aside class="os-card gs-side">
<h2>Search Safety</h2><p>Global Search is a navigation/read layer. It does not modify or merge business records.</p>
<div class="gs-policy"><b>Identity-safe</b><span>Member results open their exact normalized Member ID. Duplicate names remain separate records.</span></div>
<div class="gs-policy"><b>Reversal-aware</b><span>Facts marked <?= gs_h(BUSINESS_REVERSED_SOURCE_SHEET) ?> are preserved for audit but excluded here.</span></div>
<div class="gs-policy"><b>Source health</b><span><?= number_format($sourceMapped) ?>/<?= number_format($sourceTotal) ?> legacy operational rows mapped; <?= number_format($sourcePending) ?> pending.</span></div>
<div class="gs-shortcuts"><b>Keyboard</b><span><kbd>/</kbd> focus search</span><span><kbd>Esc</kbd> clear current text</span></div>
</aside>
</section>
<?php endif; ?>

<div class="os-footer-note"><strong>Command rule:</strong> search opens existing Business OS tools and exact normalized facts. It never creates hidden writes, never auto-merges duplicate identities, and never changes the preserved 757-row legacy source baseline.</div>
</main></div>
<script src="assets/global_search.js"></script>
</body></html>