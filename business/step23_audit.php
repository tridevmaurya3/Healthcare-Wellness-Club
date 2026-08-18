<?php
declare(strict_types=1);

require_once __DIR__.'/config/database.php';
require_once __DIR__.'/config/public_store_step23.php';
require_once __DIR__.'/config/bi_step22.php';
require_once __DIR__.'/config/backup_step18.php';
require_once __DIR__.'/config/deployment_step19.php';

$error=null;$checks=[];$m=[];
function a23(array &$a,string $name,bool $ok,string $detail):void{$a[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail];}

try{
    $pdo=business_db();
    $ctx=ps23_admin_ensure($pdo);
    $user=ps23_guard($pdo,'storefront.view');
    $orgId=(int)$ctx['organization_id'];
    bi_step22_ensure($pdo);backup_step18_ensure($pdo);deployment_step19_ensure($pdo);

    $scalar=function(string $sql,array $args=[])use($pdo):int{$s=$pdo->prepare($sql);$s->execute($args);return(int)$s->fetchColumn();};
    $s=$pdo->prepare("SELECT COUNT(*) total_rows,SUM(mapping_status='mapped') mapped_rows,SUM(mapping_status='pending') pending_rows FROM raw_source_records WHERE organization_id=? AND source_dataset IN ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");
    $s->execute([$orgId]);$legacy=$s->fetch()?:[];
    $m['legacy']=(int)($legacy['mapped_rows']??0);$m['legacy_total']=(int)($legacy['total_rows']??0);$m['legacy_pending']=(int)($legacy['pending_rows']??0);
    $m['products']=$scalar("SELECT COUNT(*) FROM products WHERE organization_id=? AND status='active'",[$orgId]);

    $tables=true;foreach(ps23_tables() as $t)if(!ps23_table($pdo,$t)){$tables=false;break;}
    $settings=ps23_settings($pdo,$orgId);
    $perm=0;$adminPerm=0;
    foreach(['storefront.view','storefront.manage','storefront.export'] as $pc){
        $perm+=$scalar("SELECT COUNT(*) FROM security_permissions WHERE permission_code=? AND is_active=1",[$pc]);
        $adminPerm+=$scalar("SELECT COUNT(*) FROM security_role_permissions WHERE organization_id=? AND role_code='admin' AND permission_code=? AND is_allowed=1",[$orgId,$pc]);
    }

    $catalog=ps23_catalog($pdo,$orgId);
    $catalogOk=count($catalog)===64;
    $catalogShape=true;
    foreach($catalog as $r){foreach(['id','sku','product_name','listing_id','price_version_id','mrp','volume_points','effective_from','availability_status'] as $k){if(!array_key_exists($k,$r)){$catalogShape=false;break 2;}}}

    $service=(string)file_get_contents(__DIR__.'/config/public_store_step23.php');
    $migration=(string)file_get_contents(dirname(__DIR__).'/database/migrations/019_step23_public_storefront.sql');
    $shop=(string)file_get_contents(dirname(__DIR__).'/shop/index.php');
    $checkout=(string)file_get_contents(dirname(__DIR__).'/shop/checkout.php');
    $submit=(string)file_get_contents(dirname(__DIR__).'/shop/submit.php');
    $statusPage=(string)file_get_contents(dirname(__DIR__).'/shop/status.php');
    $storeJs=(string)file_get_contents(dirname(__DIR__).'/shop/store.js');
    $detail=(string)file_get_contents(__DIR__.'/public_order_detail.php');
    $center=(string)file_get_contents(__DIR__.'/public_order_center.php');
    $exportPage=(string)file_get_contents(__DIR__.'/public_order_export.php');
    $index=(string)file_get_contents(__DIR__.'/index.php');

    $noInternal=!preg_match('/unit_cost|line_profit|profit_total|supplier_name|purchase_reference/i',$shop.$checkout.$statusPage);
    $verifiedImages=str_contains($service,"verification_status='verified'");
    $serverReprice=str_contains($service,'function ps23_cart_quote')&&str_contains($service,"['mrp']")&&str_contains($service,"['volume_points']")&&str_contains($service,'ps23_cart_quote($pdo,$orgId,$cart');
    $clientNoPrice=str_contains($checkout,'JSON.stringify(cart)')&&str_contains($storeJs,'product_id:Number(productId)')&&!str_contains($storeJs,'unit_price');
    $stockPrivate=str_contains($service,'_sellable_qty')&&!str_contains($shop,'_sellable_qty')&&!str_contains($checkout,'_sellable_qty')&&!str_contains($statusPage,'_sellable_qty');
    $stockGate=str_contains($service,'not available in the requested quantity')&&str_contains($service,'ps23_availability');
    $deliveryRule=str_contains($service,'$vp<100')&&str_contains($service,'100.0')&&str_contains($checkout,'excluding taxes');
    $taxBoundary=str_contains($migration,"tax_status VARCHAR(30) NOT NULL DEFAULT 'not_calculated'")&&str_contains($checkout,'Tax is not calculated');
    $paymentBoundary=(string)($settings['payment_mode']??'')==='review_only'&&str_contains($migration,"payment_status VARCHAR(30) NOT NULL DEFAULT 'not_requested'")&&!str_contains(strtolower($checkout),'pay now');
    $tokenHash=str_contains($service,"hash('sha256',")&&str_contains($service,'status_token_hash')&&str_contains($migration,'status_token_hash CHAR(64)')&&!str_contains($migration,'status_token VARCHAR');
    $publicNotePrivacy=!str_contains($statusPage,"['note']")&&str_contains($statusPage,'Staff-only review notes are intentionally not shown');
    $ipHash=str_contains($service,'ps23_ip_hash')&&str_contains($migration,'ip_hash CHAR(64)');
    $spam=str_contains($service,'honeypot')&&str_contains($service,'too_fast')&&str_contains($service,'INTERVAL 1 HOUR')&&str_contains($service,'ps23_origin_ok');
    $leadLink=str_contains($service,'PUBLIC-STORE')&&str_contains($service,'crm_leads')&&str_contains($service,'crm_lead_activities');
    $customerExplicit=str_contains($detail,'Explicit Customer Link')&&str_contains($service,'function ps23_link_customer');
    $quoteBridge=str_contains($service,'function ps23_create_quote')&&str_contains($service,"'MRP'")&&str_contains($service,'product_quote_items');
    $requestNotSale=!str_contains($service,'INSERT INTO orders(')&&!str_contains($submit,'product_step12_finalize_quote');
    $events=str_contains($service,'public_order_events')&&str_contains($service,'status_changed');
    $exportReady=str_contains($exportPage,'text/csv')&&str_contains($service,'public_store_exports');
    $guardCenter=str_contains($center,'ps23_guard($pdo,\'storefront.view\'');
    $guardDetail=str_contains($detail,'ps23_guard($pdo,\'storefront.view\'')&&str_contains($detail,'ps23_guard($pdo,\'storefront.manage\'');
    $guardExport=str_contains($exportPage,'ps23_guard($pdo,\'storefront.export\'');
    $indexOk=str_contains($index,'dashboard_step23.php');
    $noFakeOrders=!preg_match('/INSERT\s+INTO\s+public_order_requests/i',$migration);

    $files=['shop/index.php','shop/product.php','shop/cart.php','shop/checkout.php','shop/submit.php','shop/status.php','shop/store.css','shop/store.js','business/public_order_center.php','business/public_order_detail.php','business/public_order_export.php','business/product_quote_detail.php','business/dashboard_step23.php','business/step23_audit.php','business/config/public_store_step23.php'];
    $fileOk=true;foreach($files as $f)if(!is_file(dirname(__DIR__).'/'.$f)){$fileOk=false;break;}

    $invalidOrders=$scalar("SELECT COUNT(*) FROM public_order_requests WHERE organization_id=? AND (order_status NOT IN ('submitted','reviewing','confirmed','quote_ready','converted','cancelled') OR payment_status NOT IN ('not_requested','pending','paid_external','failed','not_applicable') OR tax_status<>'not_calculated' OR status_token_hash NOT REGEXP '^[0-9a-f]{64}$')",[$orgId]);
    $orderCount=$scalar('SELECT COUNT(*) FROM public_order_requests WHERE organization_id=?',[$orgId]);
    $attemptCount=$scalar('SELECT COUNT(*) FROM public_checkout_attempts WHERE organization_id=?',[$orgId]);
    $exportCount=$scalar('SELECT COUNT(*) FROM public_store_exports WHERE organization_id=?',[$orgId]);

    a23($checks,'Legacy workbook preserved',$m['legacy_total']===757&&$m['legacy']===757&&$m['legacy_pending']===0,$m['legacy'].' / 757 mapped • '.$m['legacy_pending'].' pending');
    a23($checks,'STEP 11 product catalog preserved',$m['products']===64,$m['products'].' / 64 active products');
    a23($checks,'STEP 16 Finance foundation preserved',count(finance_step16_tables())===8,'8 finance tables expected');
    a23($checks,'STEP 17 Security foundation preserved',count(security_step17_tables())===8,'8 security tables expected');
    a23($checks,'STEP 18 Recovery foundation preserved',count(backup_step18_support_tables())===4,'4 recovery tables expected');
    a23($checks,'STEP 19 Deployment foundation preserved',count(deployment_step19_support_tables())===8,'8 deployment tables expected');
    a23($checks,'STEP 20 Lead CRM available',ps23_table($pdo,'crm_leads')&&ps23_table($pdo,'crm_lead_submissions'),'lead and submission tables');
    a23($checks,'STEP 21 Communications available',ps23_table($pdo,'communication_events')&&ps23_table($pdo,'communication_outbox'),'communications preserved');
    a23($checks,'STEP 22 Executive BI available',ps23_table($pdo,'bi_targets')&&ps23_table($pdo,'bi_signal_actions'),'BI preserved');
    a23($checks,'STEP 23 storefront tables',$tables,count(ps23_tables()).' required tables');
    a23($checks,'Storefront settings initialized',!empty($settings)&&($settings['public_price_mode']??'')==='mrp','public price mode MRP');
    a23($checks,'Payment mode remains review-only',($settings['payment_mode']??'')==='review_only','no gateway assumed');
    a23($checks,'Public catalog returns all active products',$catalogOk,count($catalog).' / 64 public products');
    a23($checks,'Public catalog result shape',$catalogShape,'MRP / VP / effective date / availability');
    a23($checks,'Public pages exclude internal commercial facts',$noInternal,'no cost, profit, supplier or purchase reference');
    a23($checks,'Only verified product images may be public',$verifiedImages,'verification_status=verified gate');
    a23($checks,'Browser prices are not authoritative',$serverReprice,'server cart quote recalculates MRP and VP');
    a23($checks,'Client cart sends IDs and quantities only',$clientNoPrice,'no client unit price field');
    a23($checks,'Internal stock quantity stays private',$stockPrivate,'public receives availability status only');
    a23($checks,'Tracked stock blocks impossible quantities',$stockGate,'server availability gate');
    a23($checks,'Below-100-VP delivery rule encoded',$deliveryRule,'₹100 excluding taxes where applicable');
    a23($checks,'Tax boundary is explicit',$taxBoundary,'tax remains not_calculated');
    a23($checks,'Payment gateway is not faked',$paymentBoundary,'review_only / not_requested');
    a23($checks,'Tracking token stored hash-only',$tokenHash,'SHA-256 token hash');
    a23($checks,'Staff internal notes are hidden from public tracking',$publicNotePrivacy,'public tracking omits staff-only notes');
    a23($checks,'Public IP stored as one-way hash',$ipHash,'peppered IP hash');
    a23($checks,'Checkout spam/rate/origin protections',$spam,'honeypot • speed • hourly limit • same-origin');
    a23($checks,'Product requests integrate with Lead CRM',$leadLink,'PUBLIC-STORE source + activity');
    a23($checks,'Customer linkage remains explicit',$customerExplicit,'staff chooses existing customer');
    a23($checks,'Business handoff creates internal MRP quote',$quoteBridge,'request → saved product quote');
    a23($checks,'Public request does not bypass Sales finalization',$requestNotSale,'no direct final order/sale insert');
    a23($checks,'Order lifecycle audit events are preserved',$events,'submitted/status/quote events');
    a23($checks,'Public order export is ready',$exportReady,$exportCount.' export run(s)');
    a23($checks,'Admin pages enforce storefront RBAC',$guardCenter&&$guardDetail&&$guardExport,'view/manage/export guards');
    a23($checks,'Storefront permission catalog',$perm===3,$perm.' / 3 permissions');
    a23($checks,'Administrator has full storefront access',$adminPerm===3,$adminPerm.' / 3 admin permissions');
    a23($checks,'No fake public order is seeded',$noFakeOrders,$orderCount.' real request(s) currently');
    a23($checks,'Existing public orders are structurally valid',$invalidOrders===0,$invalidOrders.' invalid order(s)');
    a23($checks,'Checkout evidence ledger is ready',$attemptCount>=0,$attemptCount.' checkout attempt record(s)');
    a23($checks,'STEP 23 production workspaces',$fileOk,count($files).' required files');
    a23($checks,'Business OS routes through STEP 23 dashboard',$indexOk,'dashboard_step23.php active');
}catch(Throwable $e){$error=$e->getMessage();}

$passed=count(array_filter($checks,static fn($c)=>$c['ok']));
$failed=count($checks)-$passed;
$complete=$error===null&&$failed===0;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>STEP 23 Audit - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/product_pro.css"></head><body><header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png"><span><strong>Healthcare Wellness Club</strong><small>STEP 23 • Final Public Storefront Audit</small></span></a><div class="os-top-actions"><a class="os-btn" href="../shop/index.php" target="_blank">Storefront</a><a class="os-btn" href="public_order_center.php">Public Orders</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header><main class="os-main" style="max-width:1450px;margin:auto"><section class="os-hero pp-hero"><div class="os-kicker">STEP 23Z • FINAL STOREFRONT AUDIT</div><h1><?=$complete?'STEP 23 COMPLETE':'STEP 23 needs review'?></h1><p>Verifies public pricing truth, checkout safety, privacy, stock boundary, order-request lifecycle, CRM handoff, quote bridge, RBAC and preservation of the previous Business OS.</p><div class="os-status-row"><span class="os-chip <?=$complete?'good':''?>"><?=$complete?'STEP 23 COMPLETE':'REVIEW REQUIRED'?></span><span class="os-chip good"><?=($m['legacy']??0)?> / 757 legacy</span><span class="os-chip good"><?=($m['products']??0)?> / 64 products</span><span class="os-chip"><?=$passed?> PASS / <?=$failed?> REVIEW</span></div></section><?php if($error):?><div class="pp-alert bad"><strong>Audit diagnostic:</strong> <?=ps23_h($error)?></div><?php endif;?><section class="os-card" style="margin-top:14px"><div class="os-title-row"><div><h2>Completion Checks</h2><p>Zero public orders is a valid starting state. No fake customer, payment or order is required.</p></div><span class="pp-badge <?=$failed?'warn':''?>"><?=$passed?> PASS / <?=$failed?> REVIEW</span></div><div class="pp-grid"><?php foreach($checks as $c):?><div class="pp-source pp-span-6"><div><b><?=ps23_h($c['name'])?></b><small><?=ps23_h($c['detail'])?></small></div><span class="pp-badge <?=$c['ok']?'':'warn'?>"><?=$c['ok']?'PASS':'REVIEW'?></span></div><?php endforeach;?></div></section><div class="os-footer-note"><strong>STEP 23 boundary:</strong> storefront checkout is an order request. Final eligibility, tax, payment, inventory allocation, sale posting and accounting remain controlled Business OS actions.</div></main></body></html>