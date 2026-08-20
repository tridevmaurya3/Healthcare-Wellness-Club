<?php
declare(strict_types=1);

require_once __DIR__ . '/config/step10_services.php';

$error=null;$ctx=['organization_id'=>0];$legacy=['total'=>0,'mapped'=>0,'pending'=>0];$checks=[];$duplicateNames=[];$sharedMobiles=[];$unresolvedSponsors=[];$rev=defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed';

function dq_check(array &$checks,string $label,string $state,string $detail,string $level='good'):void{$checks[]=['label'=>$label,'state'=>$state,'detail'=>$detail,'level'=>$level];}

try{
 $pdo=business_db();
 foreach(['organizations','data_sources','raw_source_records','members','orders','volume_point_entries','renewals','income_entries','royalty_entries'] as $t)if(!business_table_exists($pdo,$t))throw new RuntimeException("Required table {$t} is missing.");
 $ctx=step10_org_context($pdo);$orgId=(int)$ctx['organization_id'];$legacy=step10_legacy_state($pdo,$orgId);
 dq_check($checks,'Legacy source reconciliation',$legacy['mapped'].' / '.$legacy['total'],$legacy['pending'].' pending row(s).',($legacy['total']===757&&$legacy['mapped']===757&&$legacy['pending']===0)?'good':'bad');

 $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' LIMIT 1");$stmt->execute([$orgId]);$manualSourceId=(int)$stmt->fetchColumn();
 $manualPending=0;if($manualSourceId>0){$stmt=$pdo->prepare("SELECT COUNT(*) FROM raw_source_records WHERE organization_id=? AND data_source_id=? AND mapping_status<>'mapped'");$stmt->execute([$orgId,$manualSourceId]);$manualPending=(int)$stmt->fetchColumn();}
 dq_check($checks,'MANUAL raw mapping',$manualPending===0?'PASS':'REVIEW',$manualPending.' non-mapped MANUAL raw record(s).',$manualPending===0?'good':'bad');

 $stmt=$pdo->prepare("SELECT LOWER(TRIM(full_name)) name_key,MIN(full_name) name_label,COUNT(*) c,GROUP_CONCAT(id ORDER BY id SEPARATOR ', ') ids FROM members WHERE organization_id=? AND COALESCE(source_sheet,'')<>? GROUP BY LOWER(TRIM(full_name)) HAVING COUNT(*)>1 ORDER BY c DESC,name_label");$stmt->execute([$orgId,$rev]);$duplicateNames=$stmt->fetchAll();
 dq_check($checks,'Duplicate-name groups',(string)count($duplicateNames),'Kept separate for identity safety; review before any merge/link.',count($duplicateNames)===0?'good':'warn');

 $stmt=$pdo->prepare("SELECT id,full_name,mobile FROM members WHERE organization_id=? AND COALESCE(source_sheet,'')<>? ORDER BY id");$stmt->execute([$orgId,$rev]);$mobileIndex=[];$memberRows=$stmt->fetchAll();
 foreach($memberRows as $m){$digits=preg_replace('/\D+/','',(string)($m['mobile']??''))??'';if($digits===''||preg_match('/^0+$/',$digits))continue;$mobileIndex[$digits][]=$m;}
 foreach($mobileIndex as $digits=>$rows)if(count($rows)>1)$sharedMobiles[]=['mobile'=>$digits,'rows'=>$rows];
 dq_check($checks,'Shared valid mobiles',(string)count($sharedMobiles),'Same valid mobile across multiple active member rows requires human identity review.',count($sharedMobiles)===0?'good':'warn');

 $stmt=$pdo->prepare("SELECT m.id,m.full_name,r.raw_json FROM members m LEFT JOIN raw_source_records r ON r.id=m.source_record_id WHERE m.organization_id=? AND m.sponsor_member_id IS NULL AND COALESCE(m.source_sheet,'')<>? ORDER BY m.full_name,m.id");$stmt->execute([$orgId,$rev]);
 foreach($stmt->fetchAll() as $r){$j=step10_json_array($r['raw_json']??null);$s=step10_trim($j['values']['G']??'');if($s!=='')$unresolvedSponsors[]=['id'=>(int)$r['id'],'name'=>$r['full_name'],'sponsor'=>$s];}
 dq_check($checks,'Unresolved source sponsor links',(string)count($unresolvedSponsors),'Source sponsor name exists but verified sponsor_member_id is still NULL.',count($unresolvedSponsors)===0?'good':'warn');

 $sourceOnly=[];
 foreach([
   'Orders'=>"SELECT COUNT(*) FROM orders WHERE organization_id=? AND member_id IS NULL AND COALESCE(source_sheet,'')<>?",
   'VP'=>"SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=? AND member_id IS NULL AND COALESCE(source_sheet,'')<>?",
   'Renewals'=>"SELECT COUNT(*) FROM renewals WHERE organization_id=? AND member_id IS NULL AND COALESCE(source_sheet,'')<>?"
 ] as $label=>$sql){$stmt=$pdo->prepare($sql);$stmt->execute([$orgId,$rev]);$sourceOnly[$label]=(int)$stmt->fetchColumn();}
 dq_check($checks,'Source-only business identities',(string)array_sum($sourceOnly),'Orders '.$sourceOnly['Orders'].' • VP '.$sourceOnly['VP'].' • Renewals '.$sourceOnly['Renewals'].'. These stay source-preserved rather than guessed.',array_sum($sourceOnly)===0?'good':'warn');

 $missingTrace=[];
 foreach(['orders','volume_point_entries','renewals','income_entries','royalty_entries'] as $table){$stmt=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_record_id IS NULL AND COALESCE(source_sheet,'')<>?");$stmt->execute([$orgId,$rev]);$missingTrace[$table]=(int)$stmt->fetchColumn();}
 dq_check($checks,'Missing raw source trace',(string)array_sum($missingTrace),implode(' • ',array_map(static fn($k,$v)=>$k.' '.$v,array_keys($missingTrace),array_values($missingTrace))),array_sum($missingTrace)===0?'good':'warn');

 $reversed=0;foreach(['members','orders','volume_point_entries','renewals','income_entries','royalty_entries'] as $table){$stmt=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_sheet=?");$stmt->execute([$orgId,$rev]);$reversed+=(int)$stmt->fetchColumn();}
 dq_check($checks,'Reversed facts preserved',(string)$reversed,'Reversed MANUAL rows are intentionally retained and excluded from active workspaces.','good');
}catch(Throwable $e){$error=$e->getMessage();}

$bad=count(array_filter($checks,static fn($c)=>$c['level']==='bad'));$warn=count(array_filter($checks,static fn($c)=>$c['level']==='warn'));$pass=count(array_filter($checks,static fn($c)=>$c['level']==='good'));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Data Quality - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Data Quality & Identity Health</small></span></a><div class="os-top-actions"><a class="os-btn" href="members.php">Members</a><a class="os-btn" href="sponsor_network.php">Sponsor Network</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="today_center.php"><i class="dot"></i>Today Center</a><a href="alerts_center.php"><i class="dot"></i>Alerts</a><a href="insights_center.php"><i class="dot"></i>Insights</a><a href="export_center.php"><i class="dot"></i>Export</a><a class="active" href="data_quality.php"><i class="dot"></i>Data Quality</a><a href="health_center.php"><i class="dot"></i>System Health</a><a href="audit_center.php"><i class="dot"></i>Audit</a></nav><div class="os-sidebar-status"><b>No silent repair</b><span>This center diagnoses identity/source quality only. It does not merge or rewrite records automatically.</span></div></aside><main class="os-main">
<section class="os-hero s10-hero"><div class="os-kicker">Step 10S • Data Quality & Identity Health</div><h1>Know exactly what is clean, unresolved or intentionally source-only.</h1><p>Identity ambiguity is surfaced instead of guessed. Legacy source health, MANUAL mapping, duplicate names, shared mobiles, sponsor links and raw-source trace are checked together.</p><div class="os-status-row"><span class="os-chip <?= !$error&&$bad===0?'good':'' ?>"><?= !$error&&$bad===0?'DATA QUALITY LIVE':'Review required' ?></span><span class="os-chip good"><?= number_format($pass) ?> PASS</span><span class="os-chip"><?= number_format($warn) ?> REVIEW</span><span class="os-chip"><?= number_format($bad) ?> CRITICAL</span></div></section>
<?php if($error):?><div class="s10-alert bad"><strong>Quality diagnostic:</strong> <?= step10_h($error) ?></div><?php else:?>
<section class="s10-card" style="margin-top:14px"><h2>Quality Checks</h2><p>Read-only checks across the current operational database.</p><div class="s10-checks"><?php foreach($checks as $c):?><div class="s10-check <?= step10_h($c['level']) ?>"><div><b><?= step10_h($c['label']) ?></b><span><?= step10_h($c['detail']) ?></span></div><strong><?= step10_h($c['state']) ?></strong></div><?php endforeach;?></div></section>
<section class="s10-grid"><article class="s10-card s10-span-6"><h2>Duplicate-name Review</h2><p>Same display name is not treated as the same person automatically.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Name</th><th>Rows</th><th>Member IDs</th></tr></thead><tbody><?php if(!$duplicateNames):?><tr><td colspan="3" class="s10-empty">No duplicate-name groups.</td></tr><?php endif;?><?php foreach($duplicateNames as $r):?><tr><td><?= step10_h($r['name_label']) ?></td><td><?= (int)$r['c'] ?></td><td><?= step10_h($r['ids']) ?></td></tr><?php endforeach;?></tbody></table></div></article>
<article class="s10-card s10-span-6"><h2>Shared Mobile Review</h2><p>Placeholder zero numbers are ignored; only non-zero normalized digits are compared.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Mobile</th><th>Members</th></tr></thead><tbody><?php if(!$sharedMobiles):?><tr><td colspan="2" class="s10-empty">No shared valid mobile values.</td></tr><?php endif;?><?php foreach($sharedMobiles as $g):?><tr><td><?= step10_h(substr($g['mobile'],0,3).'••••'.substr($g['mobile'],-3)) ?></td><td><?= step10_h(implode(' • ',array_map(static fn($r)=>(string)$r['full_name'].' (#'.(int)$r['id'].')',$g['rows']))) ?></td></tr><?php endforeach;?></tbody></table></div></article>
<article class="s10-card s10-span-12"><h2>Unresolved Sponsor Context</h2><p>Source sponsor exists, but no verified link has been written.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><th>Member ID</th><th>Member</th><th>Source Sponsor</th><th>Action</th></tr></thead><tbody><?php if(!$unresolvedSponsors):?><tr><td colspan="4" class="s10-empty">No unresolved source sponsor links.</td></tr><?php endif;?><?php foreach(array_slice($unresolvedSponsors,0,100) as $r):?><tr><td>#<?= (int)$r['id'] ?></td><td><?= step10_h($r['name']) ?></td><td><?= step10_h($r['sponsor']) ?></td><td><a class="s10-link" href="sponsor_network.php">Review Network →</a></td></tr><?php endforeach;?></tbody></table></div></article></section>
<?php endif;?></main></div><script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
