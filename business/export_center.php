<?php
declare(strict_types=1);

require_once __DIR__ . '/config/step10_services.php';

$error=null;
$ctx=['organization_id'=>0];
$legacy=['total'=>0,'mapped'=>0,'pending'=>0];
$rev=defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed';
$dataset=step10_key($_GET['dataset'] ?? 'orders');
$year=step10_trim($_GET['year'] ?? 'ALL');
$month=step10_trim($_GET['month'] ?? 'ALL');
$download=($_GET['download'] ?? '')==='1';
$preview=[]; $headers=[]; $totalRows=0;

$datasets=[
 'members'=>['label'=>'Members','date'=>'join_date'],
 'orders'=>['label'=>'Orders','date'=>'order_date'],
 'vp'=>['label'=>'Volume Points','date'=>'entry_date'],
 'renewals'=>['label'=>'Renewals','date'=>'renewal_date'],
 'income'=>['label'=>'Income','date'=>'income_date'],
 'royalty'=>['label'=>'Royalty','date'=>'royalty_date'],
 'audit'=>['label'=>'Audit Activity','date'=>'created_at'],
];
if(!isset($datasets[$dataset]))$dataset='orders';
if($year!=='ALL'&&!preg_match('/^\d{4}$/',$year))$year='ALL';
if($month!=='ALL'&&(!is_numeric($month)||(int)$month<1||(int)$month>12))$month='ALL';

function ex_period_sql(string $column,string $year,string $month,array &$params): string
{
    $parts=[];
    if($year!=='ALL'){ $parts[]="YEAR({$column})=?"; $params[]=(int)$year; }
    if($month!=='ALL'){ $parts[]="MONTH({$column})=?"; $params[]=(int)$month; }
    return $parts?' AND '.implode(' AND ',$parts):'';
}

try{
 $pdo=business_db();
 $ctx=step10_org_context($pdo); $orgId=(int)$ctx['organization_id'];
 $legacy=step10_legacy_state($pdo,$orgId);
 if($legacy['total']!==757||$legacy['mapped']!==757||$legacy['pending']!==0)throw new RuntimeException('Legacy source must remain reconciled at 757/757 before export.');

 $params=[$orgId];
 $whereRev=$dataset==='audit'?'':" AND COALESCE(source_sheet,'')<>?";
 if($dataset!=='audit')$params[]=$rev;
 $dateCol=$datasets[$dataset]['date'];
 $period=ex_period_sql($dateCol,$year,$month,$params);

 $sql='';
 if($dataset==='members'){
  $headers=['Member ID','Name','Mobile','Join Date','Status','Member Type','Sponsor Member ID','Source'];
  $sql="SELECT id,full_name,mobile,join_date,status,member_type,sponsor_member_id,source_sheet FROM members WHERE organization_id=?{$whereRev}{$period} ORDER BY full_name,id";
 }elseif($dataset==='orders'){
  $headers=['Order ID','Date','Member','Type','Description','Gross','Discount','Net','Profit','VP','Source'];
  $sql="SELECT o.id,o.order_date,COALESCE(m.full_name,'Source-only customer') member_name,o.order_type,o.description,o.gross_amount,o.discount_amount,o.net_amount,o.profit_amount,o.volume_points,o.source_sheet FROM orders o LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id WHERE o.organization_id=? AND COALESCE(o.source_sheet,'')<>?";
  if($year!=='ALL'){ $sql.=' AND YEAR(o.order_date)=?'; } if($month!=='ALL'){ $sql.=' AND MONTH(o.order_date)=?'; } $sql.=' ORDER BY o.order_date DESC,o.id DESC';
 }elseif($dataset==='vp'){
  $headers=['VP ID','Date','Member','VP','Order Type','VP From','Ordered By','VP Type','Level','Week','Source'];
  $sql="SELECT v.id,v.entry_date,COALESCE(m.full_name,v.member_name_snapshot,'Source-only member') member_name,v.volume_points,v.order_type,v.vp_from,v.ordered_by,v.vp_type,v.level_label,v.week_label,v.source_sheet FROM volume_point_entries v LEFT JOIN members m ON m.id=v.member_id AND m.organization_id=v.organization_id WHERE v.organization_id=? AND COALESCE(v.source_sheet,'')<>?";
  if($year!=='ALL')$sql.=' AND YEAR(v.entry_date)=?'; if($month!=='ALL')$sql.=' AND MONTH(v.entry_date)=?'; $sql.=' ORDER BY v.entry_date DESC,v.id DESC';
 }elseif($dataset==='renewals'){
  $headers=['Renewal ID','Date','Member','Amount','VP','Currency','Source'];
  $sql="SELECT n.id,n.renewal_date,COALESCE(m.full_name,n.member_name_snapshot,'Source-only member') member_name,n.amount,n.volume_points,n.currency_code,n.source_sheet FROM renewals n LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id WHERE n.organization_id=? AND COALESCE(n.source_sheet,'')<>?";
  if($year!=='ALL')$sql.=' AND YEAR(n.renewal_date)=?'; if($month!=='ALL')$sql.=' AND MONTH(n.renewal_date)=?'; $sql.=' ORDER BY n.renewal_date DESC,n.id DESC';
 }elseif($dataset==='income'){
  $headers=['Income ID','Date','Type','Amount','Currency','Period','Source'];
  $sql="SELECT id,income_date,income_type,amount,currency_code,period_key,source_sheet FROM income_entries WHERE organization_id=?{$whereRev}{$period} ORDER BY income_date DESC,id DESC";
 }elseif($dataset==='royalty'){
  $headers=['Royalty ID','Date','Period','Amount','VP','Currency','Source'];
  $sql="SELECT id,royalty_date,period_label,amount,volume_points,currency_code,source_sheet FROM royalty_entries WHERE organization_id=?{$whereRev}{$period} ORDER BY royalty_date DESC,id DESC";
 }else{
  $headers=['Audit ID','Date/Time','Event','Entity Type','Entity ID','Details'];
  $params=[$orgId]; $period=ex_period_sql('created_at',$year,$month,$params);
  $sql="SELECT id,created_at,event_type,entity_type,entity_id,details_json FROM audit_logs WHERE organization_id=?{$period} ORDER BY id DESC";
 }

 $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(); $totalRows=count($rows);
 if($download){
   $safe=preg_replace('/[^a-z0-9_-]+/i','-',$dataset)?:'export';
   $periodName=($year==='ALL'?'all-years':$year).'-'.($month==='ALL'?'all-months':str_pad((string)(int)$month,2,'0',STR_PAD_LEFT));
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="hwc-'.$safe.'-'.$periodName.'.csv"');
   echo "\xEF\xBB\xBF";
   $out=fopen('php://output','w');
   fputcsv($out,$headers);
   foreach($rows as $r){
      if($dataset==='audit'){
        $details=step10_json_array($r['details_json'] ?? null);
        $line=[(int)$r['id'],$r['created_at'],$r['event_type'],$r['entity_type'],$r['entity_id'],json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
      }else{$line=array_values($r);}
      fputcsv($out,$line);
   }
   fclose($out); exit;
 }
 $preview=array_slice($rows,0,50);
}catch(Throwable $e){$error=$e->getMessage();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Export Center - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club logo"><span><strong>Healthcare Wellness Club</strong><small>Business OS • Export & Share</small></span></a><div class="os-top-actions"><a class="os-btn" href="insights_center.php">Insights</a><a class="os-btn" href="data_quality.php">Data Quality</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Business OS</div><nav class="os-nav"><a href="index.php"><i class="dot"></i>Dashboard</a><a href="today_center.php"><i class="dot"></i>Today Center</a><a href="alerts_center.php"><i class="dot"></i>Alerts & Follow-ups</a><a href="insights_center.php"><i class="dot"></i>Insights</a><a class="active" href="export_center.php"><i class="dot"></i>Export Center</a><a href="data_quality.php"><i class="dot"></i>Data Quality</a><a href="health_center.php"><i class="dot"></i>System Health</a><a href="report_center.php"><i class="dot"></i>Report Center</a></nav><div class="os-sidebar-status"><b>Read-only export</b><span>CSV exports reflect filtered normalized data. Reversed MANUAL facts are excluded by default.</span></div></aside><main class="os-main">
<section class="os-hero s10-hero"><div class="os-kicker">Step 10R • Export & Share Center</div><h1>Export the exact normalized dataset you are reviewing.</h1><p>Choose dataset and period, preview the first 50 rows, download UTF-8 CSV for Excel, or use browser Print for a clean shareable page.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'EXPORT CENTER LIVE':'Review required' ?></span><span class="os-chip good"><?= number_format($legacy['mapped']) ?> / 757 legacy mapped</span><span class="os-chip">CSV + PRINT</span></div></section>
<?php if($error): ?><div class="s10-alert bad"><strong>Export diagnostic:</strong> <?= step10_h($error) ?></div><?php else: ?>
<form class="s10-toolbar" method="get"><select name="dataset"><?php foreach($datasets as $k=>$d): ?><option value="<?= step10_h($k) ?>" <?= $dataset===$k?'selected':'' ?>><?= step10_h($d['label']) ?></option><?php endforeach; ?></select><select name="year"><option value="ALL">All Years</option><?php for($y=(int)date('Y');$y>=2020;$y--): ?><option value="<?= $y ?>" <?= $year===(string)$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select><select name="month"><option value="ALL">All Months</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $month===(string)$m?'selected':'' ?>><?= step10_h(DateTimeImmutable::createFromFormat('!m',(string)$m)->format('F')) ?></option><?php endfor; ?></select><button type="submit">Preview</button><a class="os-btn primary" href="?<?= step10_h(http_build_query(['dataset'=>$dataset,'year'=>$year,'month'=>$month,'download'=>1])) ?>">Download CSV</a><button type="button" onclick="window.print()">Print / Save PDF</button></form>
<section class="s10-kpis"><div class="s10-kpi"><small>Dataset</small><strong style="font-size:1rem"><?= step10_h($datasets[$dataset]['label']) ?></strong><span>Selected export</span></div><div class="s10-kpi"><small>Rows</small><strong><?= number_format($totalRows) ?></strong><span>Matching current filters</span></div><div class="s10-kpi"><small>Year</small><strong><?= step10_h($year) ?></strong><span>Filter</span></div><div class="s10-kpi"><small>Month</small><strong><?= step10_h($month==='ALL'?'ALL':DateTimeImmutable::createFromFormat('!m',$month)->format('M')) ?></strong><span>Filter</span></div></section>
<section class="s10-card" style="margin-top:14px"><h2>Preview</h2><p>First <?= number_format(min(50,$totalRows)) ?> of <?= number_format($totalRows) ?> matching rows. Download contains all matching rows.</p><div class="s10-table-wrap"><table class="s10-table"><thead><tr><?php foreach($headers as $h): ?><th><?= step10_h($h) ?></th><?php endforeach; ?></tr></thead><tbody><?php if(!$preview): ?><tr><td colspan="<?= count($headers) ?>" class="s10-empty">No matching rows.</td></tr><?php endif; ?><?php foreach($preview as $r): ?><tr><?php if($dataset==='audit'): ?><td><?= (int)$r['id'] ?></td><td><?= step10_h($r['created_at']) ?></td><td><?= step10_h($r['event_type']) ?></td><td><?= step10_h($r['entity_type']) ?></td><td><?= step10_h($r['entity_id']) ?></td><td><?= step10_h(substr((string)$r['details_json'],0,220)) ?></td><?php else: ?><?php foreach(array_values($r) as $v): ?><td><?= step10_h($v) ?></td><?php endforeach; ?><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>
<div class="s10-alert"><strong>Export policy:</strong> CSV is UTF-8 with BOM for Excel compatibility. Browser Print can be saved as PDF. The export page does not modify database data.</div>
<?php endif; ?></main></div><script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
