<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_portal_auth.php';

$error=null;$user=null;$groups=[];
function fh_add(array &$groups,string $group,string $title,string $href,string $desc,string $tag='OPEN'):void{
    $groups[$group][]=['title'=>$title,'href'=>$href,'desc'=>$desc,'tag'=>$tag];
}
function fh_can(PDO $pdo,array $user,string $permission):bool{
    try{return security_step17_has_permission($pdo,$permission,$user);}catch(Throwable){return false;}
}
try{
    $pdo=role_portal_db();role_portal_ensure($pdo);security_step17_session_start();
    $user=security_step17_session_user($pdo,true);
    if(!$user){header('Location: login.php');exit;}
    if((int)($user['must_change_password']??0)===1){header('Location: change_password.php?required=1');exit;}
    $role=(string)($user['role_code']??'viewer');

    fh_add($groups,'My Workspace','My Portal',role_portal_home($user),'Return to your role-specific home workspace.','HOME');
    fh_add($groups,'My Workspace','My Account','account.php','Profile, active sessions, login history and device controls.','ACCOUNT');
    fh_add($groups,'My Workspace','Security Alerts','security_alerts.php','Review sign-in and account-security alerts.','SECURITY');
    fh_add($groups,'My Workspace','Trusted Devices','trusted_devices.php','Manage trusted browser recognition and revoke device trust.','DEVICE');
    fh_add($groups,'My Workspace','Two-Step Verification','mfa_settings.php','Authenticator app verification and recovery-code management.','MFA');
    fh_add($groups,'My Workspace','Change Password','change_password.php','Change your password under the current password policy.','PASSWORD');

    if($role==='customer'){
        fh_add($groups,'Customer Services','Customer Portal','customer_portal.php','Private customer-facing account and request workspace.','CUSTOMER');
        fh_add($groups,'Customer Services','Browse Products','shop/index.php','Browse the public product catalog at current MRP.','SHOP');
        fh_add($groups,'Customer Services','My Cart','shop/cart.php','Review products before submitting an order request.','CART');
        fh_add($groups,'Customer Services','Track Request','shop/status.php','Track a public request using its private tracking details.','TRACK');
    }elseif($role==='coach'){
        fh_add($groups,'Coach Workspace','Coach Portal','coach_portal.php','Customer guidance, leads and follow-up workspace.','COACH');
        if(fh_can($pdo,$user,'leads.view')){
            fh_add($groups,'Coach Workspace','Lead CRM','business/lead_center.php','Review website enquiries and lead progress.','CRM');
            fh_add($groups,'Coach Workspace','Follow-ups','business/lead_followups.php','Open and manage permitted follow-up work.','FOLLOW-UP');
            fh_add($groups,'Coach Workspace','Appointments','business/lead_appointments.php','Review permitted lead appointments.','APPOINTMENT');
        }
        if(fh_can($pdo,$user,'customers.view'))fh_add($groups,'Coach Workspace','Customer Center','business/customer_center.php','Open customer records allowed for the Coach role.','CUSTOMERS');
        if(fh_can($pdo,$user,'products.view'))fh_add($groups,'Coach Workspace','Product Catalog','business/product_catalog.php','View product details for customer guidance.','PRODUCTS');
        fh_add($groups,'Coach Workspace','Public Storefront','shop/index.php','Open the public catalog for customer product guidance.','SHOP');
    }else{
        fh_add($groups,'Command & Daily Work','Business Dashboard','business/index.php','Business OS dashboard and connected workspaces.','DASHBOARD');
        fh_add($groups,'Command & Daily Work','Global Search','business/global_search.php','Search across available Business OS data.','SEARCH');
        fh_add($groups,'Command & Daily Work','Today Center','business/today_center.php','Daily work, reminders and operational attention items.','TODAY');
        fh_add($groups,'Command & Daily Work','Notifications','business/notification_inbox.php','In-app operational and communication notifications.','INBOX');

        if(fh_can($pdo,$user,'storefront.view')){
            fh_add($groups,'Public Store & Requests','Public Order Requests','business/public_order_center.php','Review website checkout requests before internal conversion.','ORDERS');
            fh_add($groups,'Public Store & Requests','Open Storefront','shop/index.php','Open the customer-facing product storefront.','SHOP');
            fh_add($groups,'Public Store & Requests','STEP 23 Audit','business/step23_audit.php','Public-store safety and integration audit.','AUDIT');
        }

        if(fh_can($pdo,$user,'products.view'))fh_add($groups,'Business Operations','Product Catalog','business/product_catalog.php','Product catalog, pricing and product data.','PRODUCTS');
        if(fh_can($pdo,$user,'sales.view'))fh_add($groups,'Business Operations','Sales Center','business/product_sales_center.php','Quotes, sales, payment and sale workflow.','SALES');
        if(fh_can($pdo,$user,'inventory.view'))fh_add($groups,'Business Operations','Inventory Center','business/inventory_center.php','Stock, batches, expiry and inventory analytics.','INVENTORY');
        if(fh_can($pdo,$user,'purchases.view'))fh_add($groups,'Business Operations','Purchase Center','business/purchase_center.php','Suppliers, purchase orders, bills and payables.','PURCHASES');
        if(fh_can($pdo,$user,'customers.view'))fh_add($groups,'Business Operations','Customer Center','business/customer_center.php','Customer CRM, fulfillment and receivables.','CUSTOMERS');
        if(fh_can($pdo,$user,'finance.view'))fh_add($groups,'Business Operations','Finance Center','business/finance_center.php','Journal, cashbook, P&L and reconciliation.','FINANCE');

        if(fh_can($pdo,$user,'leads.view')){
            fh_add($groups,'CRM & Communications','Lead CRM','business/lead_center.php','Website enquiries and lead pipeline.','LEADS');
            fh_add($groups,'CRM & Communications','Follow-ups','business/lead_followups.php','Lead follow-up tasks and next actions.','FOLLOW-UP');
            fh_add($groups,'CRM & Communications','Appointments','business/lead_appointments.php','Lead and customer appointment workflow.','APPOINTMENTS');
        }
        if(fh_can($pdo,$user,'communications.view')){
            fh_add($groups,'CRM & Communications','Communication Center','business/communication_center.php','Communication events, in-app notices and delivery flow.','COMMUNICATION');
            fh_add($groups,'CRM & Communications','Communication Outbox','business/communication_outbox.php','Review queued/sent communication evidence.','OUTBOX');
        }
        if(fh_can($pdo,$user,'bi.view')){
            fh_add($groups,'Executive BI','Executive Overview','business/executive_bi.php','KPIs, trends, targets and action signals.','BI');
            fh_add($groups,'Executive BI','Trends','business/bi_trends.php','Trend analysis across supported business metrics.','TRENDS');
            fh_add($groups,'Executive BI','Action Center','business/bi_action_center.php','Source-backed action signals and priorities.','ACTIONS');
            fh_add($groups,'Executive BI','Targets','business/bi_targets.php','Targets and performance tracking.','TARGETS');
        }

        if(fh_can($pdo,$user,'security.users.manage')){
            fh_add($groups,'Admin Access & Security','Role Accounts','business/role_accounts.php','Create and manage Administrator, Coach and Customer accounts.','ROLES');
            fh_add($groups,'Admin Access & Security','Account Security','business/account_security.php','Account lock/unlock and security alert review.','LOCKS');
            fh_add($groups,'Admin Access & Security','Password Recovery','business/password_recovery.php','Review user-assisted password recovery requests.','RECOVERY');
            fh_add($groups,'Admin Access & Security','User Management','business/user_management.php','Manage internal system users and access state.','USERS');
            fh_add($groups,'Admin Access & Security','Permission Matrix','business/permission_matrix.php','Review Administrator-controlled role permissions.','PERMISSIONS');
        }
        if(fh_can($pdo,$user,'security.sessions.manage'))fh_add($groups,'Admin Access & Security','Security Sessions','business/security_sessions.php','Review and revoke active security sessions.','SESSIONS');
        if(fh_can($pdo,$user,'security.audit.view'))fh_add($groups,'Admin Access & Security','Security Audit','business/security_audit.php','Review login and security audit evidence.','AUDIT');

        if(fh_can($pdo,$user,'backup.view'))fh_add($groups,'Recovery & Launch','Backup Center','business/backup_center.php','Encrypted backup, verification and recovery readiness.','BACKUP');
        if(fh_can($pdo,$user,'audit.view'))fh_add($groups,'Recovery & Launch','Audit Center','business/audit_center.php','Data quality and operational audit workspace.','AUDIT');
        if(fh_can($pdo,$user,'deployment.view')){
            fh_add($groups,'Recovery & Launch','Final Launch Center','business/final_launch_center.php','Final QA, UAT and controlled production-launch gate.','LAUNCH');
            fh_add($groups,'Recovery & Launch','STEP 25 Audit','business/step25_audit.php','Automated final QA and production-readiness audit.','STEP 25');
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}
$roleLabel=strtoupper((string)($user['role_code']??'USER'));
$home=$user?role_portal_home($user):'login.php';
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>All Features - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"><style>
.hub-shell{width:min(1480px,calc(100% - 28px));margin:22px auto 58px}.hub-hero{padding:26px 28px;border:1px solid #dce8e1;border-radius:24px;background:radial-gradient(circle at 91% 6%,rgba(62,111,224,.13),transparent 30%),linear-gradient(135deg,#fff,#f3faf6);box-shadow:0 12px 32px rgba(31,65,46,.05)}.hub-kicker{display:inline-flex;padding:6px 9px;border:1px solid #d2e6d9;border-radius:999px;background:#eef9f3;color:#176f45;font-size:.64rem;font-weight:850;letter-spacing:.08em}.hub-hero h1{margin:10px 0 6px;font-size:clamp(2rem,4vw,3.25rem);line-height:1.03;letter-spacing:-.055em;color:#173c2c}.hub-hero p{max-width:900px;margin:0;color:#677970;font-size:.86rem;line-height:1.65}.hub-role{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.hub-role span{padding:7px 10px;border:1px solid #dce8e1;border-radius:999px;background:#fff;color:#526c60;font-size:.68rem;font-weight:800}.hub-group{margin-top:17px;padding:18px;border:1px solid #dce8e1;border-radius:20px;background:rgba(255,255,255,.93);box-shadow:0 9px 26px rgba(31,65,46,.035)}.hub-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end}.hub-head h2{margin:0;font-size:1.03rem;color:#183d2d;letter-spacing:-.025em}.hub-head span{color:#7a8b82;font-size:.67rem}.hub-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:13px}.hub-card{display:flex;flex-direction:column;min-height:144px;padding:14px 15px;border:1px solid #dfe9e3;border-radius:15px;background:#fbfdfc;transition:.16s ease}.hub-card:nth-child(4n+2){background:#f4f8ff}.hub-card:nth-child(4n+3){background:#f8f4ff}.hub-card:nth-child(4n+4){background:#fff9ec}.hub-card:hover{transform:translateY(-2px);border-color:#c8ddd1;box-shadow:0 10px 24px rgba(31,65,46,.07)}.hub-tag{align-self:flex-start;padding:4px 7px;border:1px solid #d8e6de;border-radius:999px;background:#fff;color:#43705a;font-size:.56rem;font-weight:850;letter-spacing:.055em}.hub-card b{margin-top:11px;color:#173c2c;font-size:.9rem;letter-spacing:-.015em}.hub-card p{margin:6px 0 0;color:#6b7c73;font-size:.7rem;line-height:1.5}.hub-card strong{margin-top:auto;padding-top:12px;color:#177447;font-size:.68rem}.hub-error{margin-top:14px;padding:12px 14px;border:1px solid #efd2d4;border-radius:13px;background:#fff4f4;color:#8b454b}@media(max-width:1100px){.hub-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.hub-shell{width:min(100% - 16px,1480px);margin-top:10px}.hub-hero{padding:20px}.hub-grid{grid-template-columns:1fr}.hub-card{min-height:0}.hub-head{align-items:flex-start;flex-direction:column}}</style></head><body class="auth-page"><header class="auth-top"><a class="auth-brand" href="<?=$home?>"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>All Features • Role-aware Navigation</small></span></a><nav class="auth-nav"><a class="auth-btn" href="<?=$home?>">My Portal</a><a class="auth-btn" href="account.php">My Account</a><a class="auth-btn" href="security_alerts.php">Alerts</a><a class="auth-btn" href="logout.php">Sign Out</a></nav></header><main class="hub-shell"><section class="hub-hero"><span class="hub-kicker">ROLE-AWARE FEATURE HUB</span><h1>Everything you can use, in one place.</h1><p>This launcher shows direct buttons for the features allowed by your signed-in role. Server-side permissions still protect every destination even if a URL is typed manually.</p><div class="hub-role"><span><?=security_step17_h($roleLabel)?></span><?php if($user):?><span><?=security_step17_h((string)$user['full_name'])?></span><?php endif;?></div></section><?php if($error):?><div class="hub-error"><?=security_step17_h($error)?></div><?php endif;?><?php foreach($groups as $group=>$items):?><section class="hub-group"><div class="hub-head"><h2><?=security_step17_h($group)?></h2><span><?=count($items)?> feature<?=count($items)===1?'':'s'?></span></div><div class="hub-grid"><?php foreach($items as $item):?><a class="hub-card" href="<?=security_step17_h($item['href'])?>"><span class="hub-tag"><?=security_step17_h($item['tag'])?></span><b><?=security_step17_h($item['title'])?></b><p><?=security_step17_h($item['desc'])?></p><strong>Open →</strong></a><?php endforeach;?></div></section><?php endforeach;?></main></body></html>
