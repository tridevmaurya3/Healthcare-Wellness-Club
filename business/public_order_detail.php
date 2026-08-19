<?php
declare(strict_types=1);

require_once __DIR__ . '/config/storefront_role_bridge.php';

$error=null;$success=null;$o=[];$customers=[];$user=[];$csrf='';
$id=(int)($_GET['id']??$_POST['id']??0);
$canManage=false;$home='index.php';

try{
    $pdo=role_portal_db();
    srb_ensure($pdo);
    $ctx=ps23_admin_ensure($pdo);
    $user=ps23_guard($pdo,'storefront.view');
    $orgId=(int)$ctx['organization_id'];
    $canManage=security_step17_has_permission($pdo,'storefront.manage',$user);
    $home=(string)($user['role_code']??'')==='coach'?'../coach_portal.php':'index.php';
    $csrf=security_step17_csrf();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canManage)throw new RuntimeException('This role has view-only access to product order requests.');
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='status'){
            ps23_set_status($pdo,$orgId,$id,(string)($_POST['status']??''),(string)($_POST['note']??''),(int)$user['id']);
            $success='Order request status updated.';
        }elseif($action==='link_customer'){
            ps23_link_customer($pdo,$orgId,$id,(int)($_POST['customer_id']??0),(int)$user['id']);
            $success='Customer linked explicitly.';
        }elseif($action==='create_quote'){
            $qid=ps23_create_quote($pdo,$orgId,$id,(int)$user['id']);
            $success='Internal MRP quote created: #'.$qid.'.';
        }
    }

    $o=ps23_order($pdo,$orgId,$id);
    if($canManage && ps23_table($pdo,'crm_customers')){
        $s=$pdo->prepare("SELECT id,customer_name,mobile FROM crm_customers WHERE organization_id=? AND status='active' ORDER BY customer_name LIMIT 1000");
        $s->execute([$orgId]);$customers=$s->fetchAll();
    }
}catch(Throwable $e){$error=$e->getMessage();}
?>
<!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Public Order Detail - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/product_pro.css"><link rel="stylesheet" href="assets/workspace_refresh.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="public_order_center.php"><img src="../img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Customer Product Request 360</small></span></a><div class="os-top-actions"><a class="os-btn" href="public_order_center.php">Order Queue</a><?php if($canManage&&!empty($o['quote_id'])):?><a class="os-btn" href="product_quote_detail.php?id=<?=(int)$o['quote_id']?>">Open Quote</a><?php endif;?><a class="os-btn primary" href="<?=$home?>">My Portal</a></div></div></header>
<main class="os-main" style="max-width:1450px;margin:18px auto 60px;padding:0 12px"><section class="os-hero pp-hero"><div class="os-kicker">CUSTOMER ORDER REQUEST</div><h1><?=ps23_h($o['order_code']??'Order request')?></h1><p>Review the customer request and its availability snapshot. Coach access is view-only; authorized Admin/Manager controls handle customer linking, status changes, internal quote creation and final sales handoff.</p><div class="os-status-row"><?php if($o):?><span class="os-chip good"><?=ps23_h(strtoupper(str_replace('_',' ',(string)$o['order_status'])))?></span><span class="os-chip"><?=ps23_h($o['created_at'])?></span><span class="os-chip"><?=$canManage?'MANAGE ACCESS':'COACH VIEW-ONLY'?></span><?php endif;?></div></section>
<?php if($error):?><div class="pp-alert bad" style="margin-top:14px"><?=ps23_h($error)?></div><?php endif;?><?php if($success):?><div class="pp-alert good" style="margin-top:14px"><?=ps23_h($success)?></div><?php endif;?>
<?php if($o):?><section class="pp-grid" style="margin-top:14px"><article class="os-card pp-span-8"><div class="os-title-row"><div><h2>Customer Request</h2><p>Customer-entered contact and delivery preference.</p></div></div><div class="pp-source"><b><?=ps23_h($o['customer_name'])?></b><span><?=ps23_h($o['mobile'])?><?=!empty($o['email'])?' • '.ps23_h($o['email']):''?></span></div><div class="pp-source"><b>Delivery</b><span><?=ps23_h(ucwords(str_replace('_',' ',$o['delivery_mode'])))?></span></div><?php if(!empty($o['address_text'])):?><div class="pp-source"><b>Address</b><span><?=ps23_h($o['address_text'])?> <?=ps23_h($o['postal_code']??'')?></span></div><?php endif;?><?php if(!empty($o['customer_note'])):?><div class="pp-source"><b>Customer Note</b><span><?=ps23_h($o['customer_note'])?></span></div><?php endif;?><h2 style="margin-top:18px">Product Snapshot</h2><div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Stock</th><th>Product</th><th>Qty</th><th>MRP</th><th>VP</th><th>Availability at request</th></tr></thead><tbody><?php foreach($o['items'] as $i):?><tr><td><?=ps23_h($i['stock_no'])?></td><td><?=ps23_h($i['product_name_snapshot'])?></td><td><?=ps23_h($i['quantity'])?></td><td><?=ps23_money($i['line_mrp'])?></td><td><?=ps23_h($i['line_vp'])?></td><td><?=ps23_h(ucwords(str_replace('_',' ',(string)$i['availability_status'])))?></td></tr><?php endforeach;?></tbody></table></div><div class="pp-alert" style="margin-top:12px">A product marked unavailable/limited here was still allowed as a <b>request</b>. Final allocation must be confirmed before sale finalization.</div></article>
<aside class="os-card pp-span-4"><h2>Request Summary</h2><div class="pp-total"><span>MRP subtotal</span><strong><?=ps23_money($o['subtotal_mrp'])?></strong></div><div class="pp-total"><span>Total VP</span><strong><?=ps23_h($o['total_vp'])?></strong></div><div class="pp-total"><span>Delivery</span><strong><?=ps23_money($o['delivery_charge'])?></strong></div><div class="pp-total pp-grand"><span>Estimated total</span><strong><?=ps23_money($o['estimated_total'])?></strong></div><div class="pp-alert" style="background:#fff7ea;color:#805a20">Tax status: <?=ps23_h(str_replace('_',' ',$o['tax_status']))?>. Payment: <?=ps23_h(str_replace('_',' ',$o['payment_status']))?>.</div>
<?php if($canManage):?><form method="post" class="pp-form"><input type="hidden" name="csrf" value="<?=ps23_h($csrf)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="status"><label>Status<select name="status"><?php foreach(['submitted','reviewing','confirmed','quote_ready','converted','cancelled'] as $s):?><option value="<?=$s?>" <?=$o['order_status']===$s?'selected':''?>><?=ps23_h(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select></label><label>Internal note<textarea name="note"><?=ps23_h($o['internal_note']??'')?></textarea></label><button>Update Status</button></form><form method="post" class="pp-form" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=ps23_h($csrf)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="link_customer"><label>Explicit Customer Link<select name="customer_id" required><option value="">Choose existing customer</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=$o['customer_id']==$c['id']?'selected':''?>><?=ps23_h($c['customer_name'].(!empty($c['mobile'])?' • '.$c['mobile']:''))?></option><?php endforeach;?></select></label><button>Link Customer</button></form><?php if(empty($o['quote_id'])):?><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=ps23_h($csrf)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="create_quote"><button class="os-btn primary" style="width:100%">Create Internal MRP Quote</button></form><?php else:?><div class="pp-alert good" style="margin-top:10px">Quote <?=ps23_h($o['quote_code']??('#'.$o['quote_id']))?> is linked.</div><?php endif;?><?php else:?><div class="pp-alert" style="margin-top:12px"><b>Coach view-only:</b> review the request and coordinate with the Administrator for stock confirmation, pricing/quote and finalization.</div><?php endif;?></aside>
<article class="os-card pp-span-12"><div class="os-title-row"><div><h2>Audit Trail</h2><p>Public submission and staff lifecycle events.</p></div></div><?php foreach($o['events'] as $e):?><div class="pp-source"><b><?=ps23_h(ucwords(str_replace('_',' ',$e['event_type'])))?></b><span><?=ps23_h($e['created_at'])?><?=!empty($e['actor_name'])?' • '.ps23_h($e['actor_name']):''?><?=!empty($e['note'])?' • '.ps23_h($e['note']):''?></span></div><?php endforeach;?></article></section><?php endif;?></main></body></html>
