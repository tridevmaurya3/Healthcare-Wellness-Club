<?php
declare(strict_types=1);

require_once __DIR__ . '/config/step10_services.php';

function in_delta(float $current, float $previous): string
{
    if (abs($previous) < 0.000001) return abs($current) < 0.000001 ? '0%' : 'New';
    $pct=(($current-$previous)/abs($previous))*100;
    return ($pct>0?'+':'') . number_format($pct,1) . '%';
}

$error=null;
$ctx=['organization_id'=>0,'club_id'=>0];
$legacy=['total'=>0,'mapped'=>0,'pending'=>0];
$periods=[];
$selectedYear=''; $selectedMonth='';
$metrics=['vp'=>0.0,'order_value'=>0.0,'profit'=>0.0,'income'=>0.0,'royalty'=>0.0,'new_members'=>0,'renewals'=>0,'orders'=>0];
$previous=$metrics;
$monthly=[];
$topVp=[]; $topOrders=[];
$rev=defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed';

try {
    $pdo=business_db();
    foreach (['organizations','raw_source_records','members','orders','volume_point_entries','renewals','income_entries','royalty_entries'] as $table) {
        if (!business_table_exists($pdo,$table)) throw new RuntimeException("Required table {$table} is missing.");
    }
    $ctx=step10_org_context($pdo); $orgId=(int)$ctx['organization_id'];
    $legacy=step10_legacy_state($pdo,$orgId);
    if ($legacy['total']!==757 || $legacy['mapped']!==757 || $legacy['pending']!==0) throw new RuntimeException('Legacy source layer must remain reconciled at 757/757 before Insights can run.');

    $dateSql="SELECT d FROM (
      SELECT MAX(order_date) d FROM orders WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
      UNION ALL SELECT MAX(entry_date) FROM volume_point_entries WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
      UNION ALL SELECT MAX(income_date) FROM income_entries WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
      UNION ALL SELECT MAX(royalty_date) FROM royalty_entries WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
      UNION ALL SELECT MAX(renewal_date) FROM renewals WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
      UNION ALL SELECT MAX(join_date) FROM members WHERE organization_id={$orgId} AND COALESCE(source_sheet,'')<>".$pdo->quote($rev)."
    ) x WHERE d IS NOT NULL ORDER BY d DESC LIMIT 1";
    $latest=(string)($pdo->query($dateSql)->fetchColumn() ?: date('Y-m-d'));
    $latestDate=new DateTimeImmutable($latest);
    $selectedYear=step10_trim($_GET['year'] ?? $latestDate->format('Y'));
    $selectedMonth=step10_trim($_GET['month'] ?? $latestDate->format('n'));
    if (!preg_match('/^\d{4}$/',$selectedYear)) $selectedYear=$latestDate->format('Y');
    if (!is_numeric($selectedMonth) || (int)$selectedMonth<1 || (int)$selectedMonth>12) $selectedMonth=$latestDate->format('n');
    $selectedStart=new DateTimeImmutable(sprintf('%04d-%02d-01',(int)$selectedYear,(int)$selectedMonth));
    $selectedEnd=$selectedStart->modify('first day of next month');
    $previousStart=$selectedStart->modify('-1 month');
    $previousEnd=$selectedStart;

    for ($i=0;$i<24;$i++) {
        $p=$latestDate->modify('first day of this month')->modify("-{$i} month");
        $periods[$p->format('Y-m')]=$p;
    }

    $metricSql=[
      'vp'=>["SELECT COALESCE(SUM(volume_points),0) FROM volume_point_entries WHERE organization_id=? AND entry_date>=? AND entry_date<? AND COALESCE(source_sheet,'')<>?"],
      'order_value'=>["SELECT COALESCE(SUM(net_amount),0) FROM orders WHERE organization_id=? AND order_date>=? AND order_date<? AND COALESCE(source_sheet,'')<>?"],
      'profit'=>["SELECT COALESCE(SUM(profit_amount),0) FROM orders WHERE organization_id=? AND order_date>=? AND order_date<? AND COALESCE(source_sheet,'')<>?"],
      'income'=>["SELECT COALESCE(SUM(amount),0) FROM income_entries WHERE organization_id=? AND income_date>=? AND income_date<? AND COALESCE(source_sheet,'')<>?"],
      'royalty'=>["SELECT COALESCE(SUM(amount),0) FROM royalty_entries WHERE organization_id=? AND royalty_date>=? AND royalty_date<? AND COALESCE(source_sheet,'')<>?"],
      'new_members'=>["SELECT COUNT(*) FROM members WHERE organization_id=? AND join_date>=? AND join_date<? AND COALESCE(source_sheet,'')<>?"],
      'renewals'=>["SELECT COUNT(*) FROM renewals WHERE organization_id=? AND renewal_date>=? AND renewal_date<? AND COALESCE(source_sheet,'')<>?"],
      'orders'=>["SELECT COUNT(*) FROM orders WHERE organization_id=? AND order_date>=? AND order_date<? AND COALESCE(source_sheet,'')<>?"],
    ];
    foreach ($metricSql as $key=>[$sql]) {
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$orgId,$selectedStart->format('Y-m-d'),$selectedEnd->format('Y-m-d'),$rev]);
        $metrics[$key]=in_array($key,['new_members','renewals','orders'],true)?(int)$stmt->fetchColumn():(float)$stmt->fetchColumn();
        $stmt->execute([$orgId,$previousStart->format('Y-m-d'),$previousEnd->format('Y-m-d'),$rev]);
        $previous[$key]=in_array($key,['new_members','renewals','orders'],true)?(int)$stmt->fetchColumn():(float)$stmt->fetchColumn();
    }

    $chartStart=$selectedStart->modify('-11 months');
    for ($i=0;$i<12;$i++) {
        $m=$chartStart->modify("+{$i} month"); $n=$m->modify('+1 month'); $key=$m->format('Y-m');
        $row=['label'=>$m->format('M Y'),'vp'=>0.0,'orders'=>0.0,'income'=>0.0,'royalty'=>0.0];
        $queries=[
          'vp'=>"SELECT COALESCE(SUM(volume_points),0) FROM volume_point_entries WHERE organization_id=? AND entry_date>=? AND entry_date<? AND COALESCE(source_sheet,'')<>?",
          'orders'=>"SELECT COALESCE(SUM(net_amount),0) FROM orders WHERE organization_id=? AND order_date>=? AND order_date<? AND COALESCE(source_sheet,'')<>?",
          'income'=>"SELECT COALESCE(SUM(amount),0) FROM income_entries WHERE organization_id=? AND income_date>=? AND income_date<? AND COALESCE(source_sheet,'')<>?",
          'royalty'=>"SELECT COALESCE(SUM(amount),0) FROM royalty_entries WHERE organization_id=? AND royalty_date>=? AND royalty_date<? AND COALESCE(source_sheet,'')<>?",
        ];
        foreach ($queries as $metric=>$sql) { $stmt=$pdo->prepare($sql); $stmt->execute([$orgId,$m->format('Y-m-d'),$n->format('Y-m-d'),$rev]); $row[$metric]=(float)$stmt->fetchColumn(); }
        $monthly[$key]=$row;
    }

    $stmt=$pdo->prepare("SELECT v.member_id,COALESCE(m.full_name,v.member_name_snapshot,'Source-only member') member_name,SUM(v.volume_points) total_vp,COUNT(*) facts
      FROM volume_point_entries v LEFT JOIN members m ON m.id=v.member_id AND m.organization_id=v.organization_id
      WHERE v.organization_id=? AND v.entry_date>=? AND v.entry_date<? AND COALESCE(v.source_sheet,'')<>?
      GROUP BY v.member_id,member_name ORDER BY total_vp DESC LIMIT 10");
    $stmt->execute([$orgId,$selectedStart->format('Y-m-d'),$selectedEnd->format('Y-m-d'),$rev]); $topVp=$stmt->fetchAll();

    $stmt=$pdo->prepare("SELECT o.member_id,COALESCE(m.full_name,'Source-only customer') member_name,SUM(o.net_amount) order_value,SUM(o.profit_amount) profit,COUNT(*) orders
      FROM orders o LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
      WHERE o.organization_id=? AND o.order_date>=? AND o.order_date<? AND COALESCE(o.source_sheet,'')<>?
      GROUP BY o.member_id,member_name ORDER BY order_value DESC LIMIT 10");
    $stmt->execute([$orgId,$selectedStart->format('Y-m-d'),$selectedEnd->format('Y-m-d'),$rev]); $topOrders=$stmt->fetchAll();
} catch (Throwable $e) { $error=$e->getMessage(); }

$maxVp=max(1.0,...array_map(static fn($r)=>(float)$r['vp'],$monthly ?: [['vp'=>1]]));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Business Insights - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Insights & Trends</small></span></a><div class="os-top-actions"><a class="os-btn" href="global_search.php">Global Search</a><a class="os-btn" href="export_center.php">Export</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="today_center.php"><i class="dot"></i>Today Center</a><a href="alerts_center.php"><i class="dot"></i>Alerts & Follow-ups</a><a class="active" href="insights_center.php"><i class="dot"></i>Insights & Trends</a><a href="export_center.php"><i class="dot"></i>Export Center</a><a href="data_quality.php"><i class="dot"></i>Data Quality</a><a href="health_center.php"><i class="dot"></i>System Health</a><a href="report_center.php"><i class="dot"></i>Report Center</a></nav><div class="os-sidebar-status"><b>Normalized facts only</b><span>Reversed MANUAL facts are excluded. No workbook formula values are copied into analytics.</span></div></aside><main class="os-main">
<section class="os-hero s10-hero"><div class="os-kicker">Step 10Q • Business Insights</div><h1>See month-to-month business movement from normalized facts, not copied spreadsheet summaries.</h1><p>VP, orders, profit, income, royalty, new members and renewal counts are recalculated directly from the database for the selected period.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'INSIGHTS LIVE':'Review required' ?></span><span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span><span class="os-chip"><?= step10_h($selectedStart->format('F Y')) ?></span></div></section>
<?php if ($error): ?><div class="s10-alert bad"><strong>Insights diagnostic:</strong> <?= step10_h($error) ?></div><?php else: ?>
<form class="s10-toolbar" method="get"><select name="year"><?php $ys=[];foreach($periods as $p)$ys[$p->format('Y')]=true; foreach(array_keys($ys) as $y): ?><option value="<?= step10_h($y) ?>" <?= $selectedYear===$y?'selected':'' ?>><?= step10_h($y) ?></option><?php endforeach; ?></select><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= (int)$selectedMonth===$m?'selected':'' ?>><?= step10_h(DateTimeImmutable::createFromFormat('!m',(string)$m)->format('F')) ?></option><?php endfor; ?></select><button type="submit">Apply Period</button></form>
<section class="s10-kpis"><div class="s10-kpi"><small>Volume Points</small><strong><?= step10_num($metrics['vp']) ?></strong><span><?= step10_h(in_delta((float)$metrics['vp'],(float)$previous['vp'])) ?> vs previous month</span></div><div class="s10-kpi"><small>Order Value</small><strong><?= step10_money($metrics['order_value']) ?></strong><span><?= step10_h(in_delta((float)$metrics['order_value'],(float)$previous['order_value'])) ?> vs previous month</span></div><div class="s10-kpi"><small>Profit</small><strong><?= step10_money($metrics['profit']) ?></strong><span><?= step10_h(in_delta((float)$metrics['profit'],(float)$previous['profit'])) ?> vs previous month</span></div><div class="s10-kpi"><small>Income</small><strong><?= step10_money($metrics['income']) ?></strong><span><?= step10_h(in_delta((float)$metrics['income'],(float)$previous['income'])) ?> vs previous month</span></div></section>
<section class="s10-grid"><article class="s10-card s10-span-8"><h2>12-Month VP Trend</h2><p>Live sum of normalized Volume Point facts.</p><div class="s10-chart"><?php foreach($monthly as $r): $w=min(100,max(2,((float)$r['vp']/$maxVp)*100)); ?><div class="s10-bar-row"><span><?= step10_h($r['label']) ?></span><div class="s10-bar"><i style="width:<?= number_format($w,2,'.','') ?>%"></i></div><strong><?= step10_num($r['vp']) ?> VP</strong></div><?php endforeach; ?></div></article><aside class="s10-card s10-span-4"><h2>Period Snapshot</h2><p>Other selected-month facts.</p><div class="s10-list"><div class="s10-row"><div><b>Orders</b><small>Normalized order facts</small></div><strong><?= number_format((int)$metrics['orders']) ?></strong></div><div class="s10-row"><div><b>Royalty</b><small>Source royalty facts</small></div><strong><?= step10_money($metrics['royalty']) ?></strong></div><div class="s10-row"><div><b>New Members</b><small>Join date in selected period</small></div><strong><?= number_format((int)$metrics['new_members']) ?></strong></div><div class="s10-row"><div><b>Renewals</b><small>Verified/member or source-preserved facts</small></div><strong><?= number_format((int)$metrics['renewals']) ?></strong></div></div></aside>
<article class="s10-card s10-span-6"><h2>Top VP Contributors</h2><p>Selected month; source-only identities remain labeled as such.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Member</th><th>VP</th><th>Facts</th><th>Profile</th></tr></thead><tbody><?php if(!$topVp): ?><tr><td colspan="4" class="s10-empty">No VP facts in this period.</td></tr><?php endif; ?><?php foreach($topVp as $r): ?><tr><td><?= step10_h($r['member_name']) ?></td><td><?= step10_num($r['total_vp']) ?></td><td><?= number_format((int)$r['facts']) ?></td><td><?= $r['member_id']?'<a class="s10-link" href="member_profile.php?member='.(int)$r['member_id'].'">Profile →</a>':'—' ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<article class="s10-card s10-span-6"><h2>Top Order Value</h2><p>Selected month; values remain linked to normalized order facts.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Member</th><th>Orders</th><th>Value</th><th>Profit</th></tr></thead><tbody><?php if(!$topOrders): ?><tr><td colspan="4" class="s10-empty">No orders in this period.</td></tr><?php endif; ?><?php foreach($topOrders as $r): ?><tr><td><?= step10_h($r['member_name']) ?></td><td><?= number_format((int)$r['orders']) ?></td><td><?= step10_money($r['order_value']) ?></td><td><?= step10_money($r['profit']) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section>
<?php endif; ?></main></div></body></html>
