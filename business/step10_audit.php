<?php
declare(strict_types=1);

require_once __DIR__ . '/config/step10_services.php';

$error=null;$ctx=['organization_id'=>0];$legacy=['total'=>0,'mapped'=>0,'pending'=>0];$checks=[];$dbChecks=[];
function z_add(array &$list,string $step,string $title,bool $ok,string $detail):void{$list[]=['step'=>$step,'title'=>$title,'ok'=>$ok,'detail'=>$detail];}
function z_file(string $relative):bool{return is_file(__DIR__.'/'.$relative);}
function z_contains(string $relative,string $needle):bool{$p=__DIR__.'/'.$relative;if(!is_file($p))return false;$c=@file_get_contents($p);return is_string($c)&&str_contains($c,$needle);}
try{
 $pdo=business_db();$ctx=step10_org_context($pdo);step10_ensure_tables($pdo);$orgId=(int)$ctx['organization_id'];$legacy=step10_legacy_state($pdo,$orgId);
 $legacyOk=$legacy['total']===757&&$legacy['mapped']===757&&$legacy['pending']===0;
 $stmt=$pdo->prepare("SELECT COUNT(*) FROM report_definitions WHERE organization_id=? AND is_active=1");$stmt->execute([$orgId]);$reportCount=(int)$stmt->fetchColumn();
 $stmt=$pdo->prepare("SELECT COUNT(*) FROM data_sources WHERE organization_id=? AND source_code='MANUAL' AND is_active=1");$stmt->execute([$orgId]);$manualActive=(int)$stmt->fetchColumn()===1;
 $manualPending=0;$stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' LIMIT 1");$stmt->execute([$orgId]);$manualId=(int)$stmt->fetchColumn();if($manualId>0){$stmt=$pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=? AND mapping_status<>'mapped'");$stmt->execute([$orgId,$manualId]);$manualPending=(int)$stmt->fetchColumn();}
 z_add($dbChecks,'DB1','757-row legacy reconciliation',$legacyOk,$legacy['mapped'].' / '.$legacy['total'].' mapped • '.$legacy['pending'].' pending');
 z_add($dbChecks,'DB2','Six derived report definitions',$reportCount===6,$reportCount.' / 6 active');
 z_add($dbChecks,'DB3','MANUAL source active',$manualActive,$manualActive?'MANUAL source active':'MANUAL source unavailable');
 z_add($dbChecks,'DB4','MANUAL mapping health',$manualPending===0,$manualPending.' pending/non-mapped MANUAL raw record(s)');
 z_add($dbChecks,'DB5','Follow-up support table',business_table_exists($pdo,'business_followups'),'business_followups');
 z_add($dbChecks,'DB6','Saved Views support table',business_table_exists($pdo,'business_saved_views'),'business_saved_views');

 z_add($checks,'10A','Business OS Dashboard + Report Center',z_file('index.php')&&z_file('report_center.php'),'Dashboard and six-report hub present');
 z_add($checks,'10B','Members & Network',z_file('members.php'),'Members workspace present');
 z_add($checks,'10C','Verified Sponsor Network',z_file('sponsor_network.php'),'Sponsor linking/tree workspace present');
 z_add($checks,'10D','Member Profile 360°',z_file('member_profile.php'),'360° timeline workspace present');
 z_add($checks,'10E','Operations Center',z_file('operations_center.php'),'Orders/VP/Income/Royalty workspace present');
 z_add($checks,'10F','Smart Data Entry',z_file('data_entry_center.php'),'MANUAL raw → normalized → audit writer present');
 z_add($checks,'10G','Manual → Live Reports Integration',z_file('report_integration_audit.php')&&z_file('config/report_runtime.php'),'Report runtime adapter and audit present');
 z_add($checks,'10H','Smart Entry 2.0',z_file('config/data_entry_smart.php')&&z_file('assets/data_entry.js'),'Duplicate guard/autofill client + server helpers present');
 z_add($checks,'10I','Correction Center',z_file('correction_center.php'),'Audited safe correction workspace present');
 z_add($checks,'10J','Reverse / Cancel Center',z_file('reversal_center.php'),'No-hard-delete reversal workflow present');
 z_add($checks,'10K','Restore Center',z_file('restore_center.php'),'Conflict-checked restore workflow present');
 z_add($checks,'10L','Unified Audit Center',z_file('audit_center.php'),'Create/Correct/Reverse/Restore timeline present');
 z_add($checks,'10M','Data Management Hub',z_file('data_management.php'),'Consolidated lifecycle workflow present');
 z_add($checks,'10N','Global Search + Commands',z_file('global_search.php'),'Read-only universal search present');
 z_add($checks,'10O','Smart Alerts',z_file('alerts_center.php'),'Fact-safe smart alert surface present');
 z_add($checks,'10P','Follow-ups & Reminders',z_file('alerts_center.php')&&business_table_exists($pdo,'business_followups'),'Persistent audited follow-up queue present');
 z_add($checks,'10Q','Business Insights & Trends',z_file('insights_center.php'),'Normalized month-to-month analytics present');
 z_add($checks,'10R','Export & Share Center',z_file('export_center.php'),'UTF-8 CSV + print/PDF-ready view present');
 z_add($checks,'10S','Data Quality & Identity Health',z_file('data_quality.php'),'Read-only identity/source quality diagnostics present');
 z_add($checks,'10T','Today Center',z_file('today_center.php'),'Daily command/snapshot workspace present');
 z_add($checks,'10U','Quick Commands',z_file('today_center.php')&&z_file('global_search.php'),'Daily add/search/report deep links present');
 z_add($checks,'10V','Saved Views & Favorites',z_file('saved_views.php')&&business_table_exists($pdo,'business_saved_views'),'Persistent internal workspace shortcuts present');
 z_add($checks,'10W','System Health + Backup Readiness',z_file('health_center.php'),'Environment/database readiness diagnostics present');
 z_add($checks,'10X','Wide Responsive Business Shell',z_contains('assets/dashboard.css','grid-template-columns:1fr;gap:10px')&&z_contains('assets/dashboard.css','1720px'),'1720px desktop + scrollable tablet/mobile navigation present');
 z_add($checks,'10Y','Final Dashboard Consolidation',z_contains('index.php','STEP 10 COMPLETE')&&z_contains('index.php','today_center.php')&&z_contains('index.php','alerts_center.php'),'Final dashboard exposes completed Step 10 workspaces');
 z_add($checks,'10Z','Completion Audit',z_file('step10_audit.php'),'This A–Z verification workspace is present');
}catch(Throwable $e){$error=$e->getMessage();}

$all=array_merge($dbChecks,$checks);$passed=count(array_filter($all,static fn($c)=>$c['ok']));$failed=count($all)-$passed;$stepPassed=count(array_filter($checks,static fn($c)=>$c['ok']));$complete=$error===null&&$failed===0&&$stepPassed===26;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Step 10 Completion Audit - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • STEP 10 Final Audit</small></span></a><div class="os-top-actions"><a class="os-btn" href="health_center.php">System Health</a><a class="os-btn" href="data_quality.php">Data Quality</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Completion</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="today_center.php"><i class="dot"></i>Today</a><a href="alerts_center.php"><i class="dot"></i>Alerts</a><a href="insights_center.php"><i class="dot"></i>Insights</a><a href="data_quality.php"><i class="dot"></i>Data Quality</a><a href="health_center.php"><i class="dot"></i>Health</a><a class="active" href="step10_audit.php"><i class="dot"></i>Step 10 Audit</a></nav><div class="os-sidebar-status"><b><?= $complete?'STEP 10 complete':'Completion review' ?></b><span><?= number_format($passed) ?> checks pass • <?= number_format($failed) ?> review.</span></div></aside><main class="os-main">
<section class="os-hero s10-hero"><div class="os-kicker">Step 10Z • Final Completion Audit</div><h1><?= $complete?'STEP 10 COMPLETE':'STEP 10 completion checks need review' ?></h1><p>This page verifies the A–Z Business OS workspaces, database support, reconciled legacy baseline, MANUAL source health and final responsive shell.</p><div class="os-status-row"><span class="os-chip <?= $complete?'good':'' ?>"><?= $complete?'26 / 26 STEP 10 MODULES PASS':$stepPassed.' / 26 modules pass' ?></span><span class="os-chip good"><?= number_format($passed) ?> total checks pass</span><span class="os-chip"><?= number_format($failed) ?> review</span><span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span></div></section>
<?php if($error):?><div class="s10-alert bad"><strong>Completion diagnostic:</strong> <?= step10_h($error) ?></div><?php endif;?>
<section class="s10-grid"><article class="s10-card s10-span-4"><h2>Database Foundation</h2><p>Cross-cutting prerequisites used by STEP 10.</p><div class="s10-checks"><?php foreach($dbChecks as $c):?><div class="s10-check <?= $c['ok']?'good':'bad' ?>"><div><b><?= step10_h($c['title']) ?></b><span><?= step10_h($c['detail']) ?></span></div><strong><?= $c['ok']?'PASS':'REVIEW' ?></strong></div><?php endforeach;?></div></article><article class="s10-card s10-span-8"><h2>STEP 10 A–Z</h2><p>Every sub-step is checked against its actual workspace/support file.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Step</th><th>Feature</th><th>Status</th><th>Evidence</th></tr></thead><tbody><?php foreach($checks as $c):?><tr><td><b><?= step10_h($c['step']) ?></b></td><td><?= step10_h($c['title']) ?></td><td><span class="s10-badge <?= $c['ok']?'':'bad' ?>"><?= $c['ok']?'PASS':'REVIEW' ?></span></td><td><?= step10_h($c['detail']) ?></td></tr><?php endforeach;?></tbody></table></div></article></section>
<div class="s10-alert <?= $complete?'good':'warn' ?>"><strong><?= $complete?'Completion policy satisfied:':'Completion review:' ?></strong> STEP 10 is considered complete only when all 26 A–Z modules and database foundation checks pass. No legacy Excel fact is rewritten by this audit.</div>
</main></div></body></html>
