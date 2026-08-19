<?php
declare(strict_types=1);

require_once __DIR__ . '/config/storefront_role_bridge.php';

$error=null;
$rows=[];
$m=[];
$user=[];
$status=(string)($_GET['status']??'all');
$canExport=false;
$home='index.php';

try{
    $pdo=role_portal_db();
    srb_ensure($pdo);
    $ctx=ps23_admin_ensure($pdo);
    $user=ps23_guard($pdo,'storefront.view');
    $canExport=security_step17_has_permission($pdo,'storefront.export',$user);
    $home=(string)($user['role_code']??'')==='coach'?'../coach_portal.php':'index.php';
    $m=ps23_metrics($pdo,(int)$ctx['organization_id']);
    $rows=ps23_orders($pdo,(int)$ctx['organization_id'],$status);
}catch(Throwable $e){
    $error=$e->getMessage();
}
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Public Orders - Healthcare Wellness Club</title>
    <link rel="stylesheet" href="assets/dashboard.css">
    <link rel="stylesheet" href="assets/product_pro.css">
    <link rel="stylesheet" href="assets/workspace_refresh.css">
</head>
<body>
<header class="os-topbar">
    <div class="os-topbar-inner">
        <a class="os-brand" href="<?=$home?>">
            <img src="../img/logo.png" alt="Healthcare Wellness Club">
            <span><strong>Healthcare Wellness Club</strong><small>Customer Product Requests • Coach/Admin Queue</small></span>
        </a>
        <div class="os-top-actions">
            <a class="os-btn wr-feature-btn" href="../feature_hub.php">All Features</a>
            <a class="os-btn" href="../shop/index.php" target="_blank" rel="noopener">Open Storefront</a>
            <?php if($canExport):?><a class="os-btn" href="public_order_export.php">Export</a><?php endif;?>
            <a class="os-btn primary" href="<?=$home?>">My Portal</a>
        </div>
    </div>
</header>

<div class="os-layout">
    <aside class="os-sidebar">
        <div class="os-nav-label">Order Workspace</div>
        <nav class="os-nav">
            <a href="<?=$home?>"><i class="dot"></i>My Portal</a>
            <a href="../feature_hub.php"><i class="dot"></i>All Features</a>
            <a class="active" href="public_order_center.php"><i class="dot"></i>Order Requests</a>
            <a href="../shop/index.php" target="_blank" rel="noopener"><i class="dot"></i>Customer Storefront</a>
        </nav>

        <div class="os-nav-label" style="margin-top:8px">My Access & Security</div>
        <nav class="os-nav">
            <a href="../account.php"><i class="dot"></i>My Account</a>
            <a href="../security_alerts.php"><i class="dot"></i>Security Alerts</a>
            <a href="../trusted_devices.php"><i class="dot"></i>Trusted Devices</a>
            <a href="../mfa_settings.php"><i class="dot"></i>Two-Step Verification</a>
        </nav>

        <div class="os-sidebar-status">
            <b>Request ≠ Sale</b>
            <span>Customers may request products even when live stock is not confirmed. Availability, quote, payment, stock allocation and accounting remain controlled review steps.</span>
        </div>
    </aside>

    <main class="os-main">
        <section class="os-hero pp-hero">
            <div class="os-kicker">CUSTOMER REQUESTS • COACH + ADMIN VISIBILITY</div>
            <h1>Customer product requests arrive here for review and confirmation.</h1>
            <p>Coach users can view incoming requests and customer needs. Authorized Admin/Manager users keep the existing controlled actions for customer linking, status changes, quote creation, export and final sales handoff.</p>
            <div class="os-status-row">
                <span class="os-chip <?=!$error?'good':''?>"><?=!$error?'ORDER QUEUE CONNECTED':'REVIEW REQUIRED'?></span>
                <span class="os-chip"><?=number_format((int)($m['submitted']??0))?> new</span>
                <span class="os-chip"><?=number_format((int)($m['active']??0))?> under review</span>
                <span class="os-chip"><?=number_format((int)($m['quote_ready']??0))?> quote ready</span>
                <?php if($user):?><span class="os-chip good"><?=ps23_h(strtoupper((string)$user['role_code']))?> VIEW</span><?php endif;?>
            </div>
        </section>

        <?php if($error):?><div class="pp-alert bad"><?=ps23_h($error)?></div><?php endif;?>

        <section class="s10-kpis">
            <div class="s10-kpi"><small>Total Requests</small><strong><?=number_format((int)($m['total']??0))?></strong><span>all-time captured</span></div>
            <div class="s10-kpi"><small>Submitted</small><strong><?=number_format((int)($m['submitted']??0))?></strong><span>needs review</span></div>
            <div class="s10-kpi"><small>Quote Ready</small><strong><?=number_format((int)($m['quote_ready']??0))?></strong><span>internal quote created</span></div>
            <div class="s10-kpi"><small>Request Value</small><strong><?=ps23_money($m['request_value']??0)?></strong><span>estimate, excludes cancelled</span></div>
        </section>

        <section class="os-card">
            <div class="os-title-row" style="margin-bottom:12px"><div><h2>Order Request Queue</h2><p>Open any request to see customer details, product snapshot and availability-at-request evidence.</p></div><a class="os-btn" href="../feature_hub.php">Feature Hub →</a></div>
            <form method="get" class="pp-toolbar"><select name="status" aria-label="Order status filter"><option value="all">All statuses</option><?php foreach(['submitted','reviewing','confirmed','quote_ready','converted','cancelled'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=ps23_h(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select><button type="submit">Filter</button></form>
            <div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Request</th><th>Customer</th><th>Created</th><th>Delivery</th><th>Estimate</th><th>Status</th><th>Links</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="7">No public order requests yet.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td><a href="public_order_detail.php?id=<?=(int)$r['id']?>"><b><?=ps23_h($r['order_code'])?></b></a></td><td><?=ps23_h($r['customer_name'])?><small><?=ps23_h($r['mobile'])?></small></td><td><?=ps23_h($r['created_at'])?></td><td><?=ps23_h(ucwords(str_replace('_',' ',$r['delivery_mode'])))?></td><td><?=ps23_money($r['estimated_total'])?></td><td><span class="pp-badge"><?=ps23_h(strtoupper(str_replace('_',' ',$r['order_status'])))?></span></td><td><?=!empty($r['lead_id'])?'Lead ✓ ':''?><?=!empty($r['customer_id'])?'Customer ✓ ':''?><?=!empty($r['quote_id'])?'Quote ✓':''?></td></tr><?php endforeach;?></tbody></table></div>
        </section>
    </main>
</div>
</body>
</html>
