<?php
declare(strict_types=1);

session_start();
require_once __DIR__.'/config/sales_step15.php';

if (empty($_SESSION['crm_customer_csrf'])) $_SESSION['crm_customer_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['crm_customer_csrf'];
$error=null;
$success=null;
$customers=[];
$customerAccounts=[];
$members=[];
$edit=null;

try {
    $pdo=business_db();
    sales_step15_ensure($pdo);
    sales_step15_backfill($pdo);
    $ctx=sales_step15_context($pdo);
    $orgId=(int)$ctx['organization_id'];

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Security token mismatch.');
        $id=sales_step15_save_customer($pdo,(int)($_POST['customer_id']??0),$_POST);
        $success='Customer #'.$id.' saved.';
    }

    $customers=sales_step15_customers($pdo,$orgId);
    $members=product_step12_members($pdo,$orgId);

    if (business_table_exists($pdo,'system_users') && business_table_exists($pdo,'organization_user_access')) {
        $hasMembership=business_table_exists($pdo,'customer_membership_profiles') && business_table_exists($pdo,'customer_discount_labels');
        if ($hasMembership) {
            $sql="SELECT u.id,u.full_name,u.email,u.mobile,u.is_active,u.last_login_at,
                         a.role_code,
                         cm.member_code,cm.membership_status,
                         dl.label_name,dl.badge_text,
                         coach.full_name coach_name
                  FROM system_users u
                  JOIN organization_user_access a
                    ON a.user_id=u.id AND a.organization_id=? AND a.role_code='customer'
                  LEFT JOIN customer_membership_profiles cm
                    ON cm.organization_id=a.organization_id AND cm.user_id=u.id
                  LEFT JOIN customer_discount_labels dl ON dl.id=cm.discount_label_id
                  LEFT JOIN system_users coach ON coach.id=cm.coach_user_id
                  ORDER BY u.id DESC";
        } else {
            $sql="SELECT u.id,u.full_name,u.email,u.mobile,u.is_active,u.last_login_at,
                         a.role_code,
                         NULL member_code,NULL membership_status,NULL label_name,NULL badge_text,NULL coach_name
                  FROM system_users u
                  JOIN organization_user_access a
                    ON a.user_id=u.id AND a.organization_id=? AND a.role_code='customer'
                  ORDER BY u.id DESC";
        }
        $q=$pdo->prepare($sql);
        $q->execute([$orgId]);
        $customerAccounts=$q->fetchAll();
    }

    $editId=(int)($_GET['edit']??0);
    if ($editId>0) $edit=sales_step15_customer($pdo,$orgId,$editId);
} catch (Throwable $e) {
    $error=$e->getMessage();
}

function cc_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function cc_m(mixed $v):string{return '₹'.number_format((float)$v,2,'.',',');}
function cc_dt(mixed $v):string{
    $v=trim((string)$v);
    if($v==='')return 'Never';
    try{return (new DateTimeImmutable($v))->format('d M Y, h:i A');}catch(Throwable){return $v;}
}
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Customer Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<style>
.account-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0}.account-summary article{padding:14px;border:1px solid #dce8e1;border-radius:15px;background:#fff}.account-summary small{display:block;color:#718179;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.account-summary b{display:block;margin-top:5px;color:#173c2c;font-size:1.25rem}.login-account-card{padding:14px;border:1px solid #dce8e1;border-radius:15px;background:#fff}.login-account-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.login-account-card h3{margin:0;color:#173c2c;font-size:.88rem}.login-account-card p{margin:5px 0 0;color:#6d7e75;font-size:.69rem;line-height:1.5}.login-account-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.login-chip{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef5f1;color:#476057;font-size:.58rem;font-weight:850}.login-chip.member{background:#e9f7ee;color:#176b43}.login-chip.off{background:#fff0f0;color:#a24444}.login-account-note{margin:12px 0 0;padding:11px 13px;border-radius:12px;background:#f7faf8;color:#66776e;font-size:.68rem;line-height:1.5}@media(max-width:900px){.account-summary{grid-template-columns:repeat(2,1fr)}.login-account-grid{grid-template-columns:1fr}}@media(max-width:540px){.account-summary{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="logo"><span><strong>Healthcare Wellness Club</strong><small>Customer Accounts + CRM</small></span></a><div class="os-top-actions"><a class="os-btn" href="customer_membership_manager.php">Club Members</a><a class="os-btn" href="sales_fulfillment.php">Fulfillment</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header>
<div class="os-layout">
<aside class="os-sidebar"><div class="os-nav-label">Customers & Sales</div><nav class="os-nav"><a class="active" href="customer_center.php"><i class="dot"></i>Customers</a><a href="customer_membership_manager.php"><i class="dot"></i>Club Members</a><a href="sales_fulfillment.php"><i class="dot"></i>Fulfillment</a><a href="sales_invoice.php"><i class="dot"></i>Invoices</a><a href="sales_delivery.php"><i class="dot"></i>Delivery</a><a href="sales_returns.php"><i class="dot"></i>Returns</a><a href="sales_refunds.php"><i class="dot"></i>Refunds</a><a href="sales_receivables.php"><i class="dot"></i>Receivables</a><a href="crm_followups.php"><i class="dot"></i>Follow-ups</a></nav></aside>
<main class="os-main">
<section class="os-hero pp-hero"><div class="os-kicker">CUSTOMER CENTER</div><h1>Customer sign-ups and Customer Master records in one Administrator workspace.</h1><p>A website sign-up creates a secure Customer login account. It is shown below immediately. Customer Master remains a separate business/CRM identity so names or mobile numbers are never silently merged.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'CUSTOMER CENTER LIVE':'Review required' ?></span><span class="os-chip"><?= count($customerAccounts) ?> login accounts</span><span class="os-chip"><?= count($customers) ?> CRM customers</span></div></section>

<?php if($error):?><div class="pp-alert bad"><strong>Customer diagnostic:</strong> <?=cc_h($error)?></div><?php endif;?>
<?php if($success):?><div class="pp-alert good"><strong>Saved:</strong> <?=cc_h($success)?></div><?php endif;?>

<?php if(!$error):?>
<?php
$activeAccounts=0;$clubAccounts=0;$regularAccounts=0;
foreach($customerAccounts as $a){
    if((int)$a['is_active']===1)$activeAccounts++;
    if((string)($a['membership_status']??'')==='active' && trim((string)($a['member_code']??''))!=='')$clubAccounts++;else$regularAccounts++;
}
?>
<section class="account-summary">
<article><small>Customer Login Accounts</small><b><?=count($customerAccounts)?></b></article>
<article><small>Active Accounts</small><b><?=$activeAccounts?></b></article>
<article><small>Regular Customers</small><b><?=$regularAccounts?></b></article>
<article><small>Active Club Members</small><b><?=$clubAccounts?></b></article>
</section>

<section class="os-card" style="margin-top:14px">
<div class="os-title-row"><div><h2>Customer Login Accounts / New Sign-ups</h2><p>Every new website registration appears here as soon as the Customer account is created.</p></div><a class="os-btn" href="role_accounts.php">Account Administration</a></div>
<?php if($customerAccounts):?><div class="login-account-grid" style="margin-top:12px">
<?php foreach($customerAccounts as $a):
    $isMember=(string)($a['membership_status']??'')==='active' && trim((string)($a['member_code']??''))!=='';
?>
<article class="login-account-card">
<h3><?=cc_h($a['full_name'])?></h3>
<p><?=cc_h($a['email'])?><?php if(!empty($a['mobile'])):?><br><?=cc_h($a['mobile'])?><?php endif;?></p>
<div class="login-account-meta">
<span class="login-chip <?=((int)$a['is_active']===1)?'':'off'?>"><?=((int)$a['is_active']===1)?'ACTIVE LOGIN':'INACTIVE LOGIN'?></span>
<?php if($isMember):?><span class="login-chip member"><?=cc_h($a['badge_text']?:$a['label_name']?:'CLUB MEMBER')?></span><?php else:?><span class="login-chip">REGULAR CUSTOMER</span><?php endif;?>
</div>
<p style="margin-top:8px">Last login: <?=cc_h(cc_dt($a['last_login_at']??null))?></p>
<?php if($isMember):?><p>Member ID: <b><?=cc_h($a['member_code'])?></b><?php if(!empty($a['coach_name'])):?> • Coach: <?=cc_h($a['coach_name'])?><?php endif;?></p><?php else:?><p>No Club Member ID assigned.</p><?php endif;?>
</article>
<?php endforeach;?></div><?php else:?><div class="login-account-note">No Customer login account exists yet. A new website Sign Up will appear here automatically.</div><?php endif;?>
<div class="login-account-note"><strong>Identity rule:</strong> a sign-up account is a secure login identity. The Customer Master below is the business/sales CRM identity. They are intentionally not auto-merged only because a name, email or mobile looks similar.</div>
</section>

<section class="pp-grid" style="margin-top:14px">
<article class="os-card pp-span-5"><h2><?=$edit?'Edit Customer':'Add Customer Master'?></h2><form method="post" class="pp-form"><input type="hidden" name="csrf" value="<?=cc_h($csrf)?>"><input type="hidden" name="customer_id" value="<?=(int)($edit['id']??0)?>"><label>Customer Name<input name="customer_name" value="<?=cc_h($edit['customer_name']??'')?>" required></label><label>Mobile<input name="mobile" value="<?=cc_h($edit['mobile']??'')?>"></label><label>Email<input type="email" name="email" value="<?=cc_h($edit['email']??'')?>"></label><label>Customer Type<select name="customer_type"><?php foreach(['retail'=>'Retail','preferred'=>'Preferred Customer','associate'=>'Associate','member'=>'Member Customer','other'=>'Other'] as $k=>$v):?><option value="<?=cc_h($k)?>" <?=($edit['customer_type']??'retail')===$k?'selected':''?>><?=cc_h($v)?></option><?php endforeach;?></select></label><label>Verified Member Link (optional)<select name="member_id"><option value="">No member link</option><?php foreach($members as $m):?><option value="<?=(int)$m['id']?>" <?=(int)($edit['member_id']??0)===(int)$m['id']?'selected':''?>><?=cc_h($m['full_name'].' • #'.$m['id'].($m['mobile']?' • '.$m['mobile']:''))?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="active" <?=($edit['status']??'active')==='active'?'selected':''?>>Active</option><option value="inactive" <?=($edit['status']??'')==='inactive'?'selected':''?>>Inactive</option></select></label><label>Notes<textarea name="notes" rows="3"><?=cc_h($edit['notes']??'')?></textarea></label><button><?=$edit?'Update Customer':'Create Customer'?> →</button></form></article>

<article class="os-card pp-span-7"><div class="os-title-row"><div><h2>Customer Master</h2><p>CRM, sales and balance identity. Existing sales remain snapshot-only until explicitly linked.</p></div></div><div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Customer</th><th>Contact</th><th>Type</th><th>Member</th><th>Sales</th><th>Receivable</th><th>Credit Due</th><th></th></tr></thead><tbody><?php foreach($customers as $c):?><tr><td><a href="customer_detail.php?id=<?=(int)$c['id']?>"><b><?=cc_h($c['customer_name'])?></b></a><small><?=cc_h($c['customer_code'].' • '.strtoupper($c['status']))?></small></td><td><?=cc_h($c['mobile']?:'—')?><small><?=cc_h($c['email']?:'')?></small></td><td><?=cc_h(ucwords(str_replace('_',' ',$c['customer_type'])))?></td><td><?=cc_h($c['member_name']?:'—')?></td><td><?=(int)$c['sales_count']?><small><?=cc_m($c['original_charge'])?></small></td><td><b><?=cc_m($c['receivable'])?></b></td><td><?=cc_m($c['credit_due'])?></td><td><a href="?edit=<?=(int)$c['id']?>">Edit</a> · <a href="customer_detail.php?id=<?=(int)$c['id']?>">360°</a></td></tr><?php endforeach;?><?php if(!$customers):?><tr><td colspan="8">No Customer Master records yet. This is valid; Customer login accounts are listed above and are not silently merged into CRM identities.</td></tr><?php endif;?></tbody></table></div></article>
</section>
<?php endif;?>
<div class="os-footer-note"><strong>Customer sign-up location:</strong> Administrator → Customer Center → Customer Login Accounts / New Sign-ups.</div>
</main>
</div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
