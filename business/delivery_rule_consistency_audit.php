<?php
declare(strict_types=1);

require_once __DIR__ . '/config/role_portal_auth.php';
require_once __DIR__ . '/config/customer_membership.php';

function drca_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$checks = [];
$pass = 0;
$review = 0;

try {
    $pdo = role_portal_db();
    role_portal_ensure($pdo);
    security_step17_session_start();
    $user = security_step17_session_user($pdo, true);
    if (!$user) {
        header('Location: ../login.php');
        exit;
    }
    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header('Location: ../change_password.php?required=1');
        exit;
    }
    if ((string)($user['role_code'] ?? '') !== 'admin') {
        header('Location: access_denied.php?permission=admin');
        exit;
    }

    $root = dirname(__DIR__);
    $files = [
        'membership' => $root . '/business/config/customer_membership.php',
        'order_request' => $root . '/business/config/customer_order_request.php',
        'legacy_store' => $root . '/business/config/public_store_step23.php',
        'checkout' => $root . '/shop/checkout.php',
        'submit' => $root . '/shop/submit.php',
        'store' => $root . '/shop/index.php',
        'order_center' => $root . '/business/public_order_center.php',
    ];

    $src = [];
    foreach ($files as $key => $path) {
        $src[$key] = is_file($path) ? (string)file_get_contents($path) : '';
    }

    $add = static function (string $name, bool $ok, string $detail, string $fix = '') use (&$checks, &$pass, &$review): void {
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
        if ($ok) $pass++; else $review++;
    };

    $add(
        'Canonical home-delivery charge constant',
        defined('CUSTOMER_HOME_DELIVERY_CHARGE') && abs((float)CUSTOMER_HOME_DELIVERY_CHARGE - 118.00) < 0.001,
        'CUSTOMER_HOME_DELIVERY_CHARGE must be ₹118.00.'
    );

    $add(
        'Canonical free-delivery VP threshold',
        defined('CUSTOMER_FREE_DELIVERY_VP') && abs((float)CUSTOMER_FREE_DELIVERY_VP - 100.00) < 0.001,
        'CUSTOMER_FREE_DELIVERY_VP must be 100.00 VP.'
    );

    $add(
        'Active member/customer quote uses canonical constants',
        str_contains($src['membership'], "CUSTOMER_FREE_DELIVERY_VP)?CUSTOMER_HOME_DELIVERY_CHARGE:0.0") ||
        (str_contains($src['membership'], 'CUSTOMER_FREE_DELIVERY_VP') && str_contains($src['membership'], 'CUSTOMER_HOME_DELIVERY_CHARGE')),
        'cm_cart_quote() must calculate Home Delivery from the ₹118 / 100 VP constants.'
    );

    $add(
        'Current checkout submits through customer order-request engine',
        str_contains($src['submit'], "customer_order_request.php") && str_contains($src['submit'], 'cor_submit(') && !str_contains($src['submit'], 'ps23_submit('),
        'shop/submit.php must use cor_submit(), not the legacy ps23_submit() path.'
    );

    $add(
        'Checkout customer text matches ₹118 / 100 VP rule',
        str_contains($src['checkout'], 'below 100 total VP = ₹118') && str_contains($src['checkout'], '100 VP or more = ₹0'),
        'Checkout must show ₹118 below 100 VP and ₹0 at 100+ VP.'
    );

    $add(
        'Storefront customer text matches ₹118 / 100 VP rule',
        str_contains($src['store'], 'below 100') && str_contains($src['store'], '₹118') && str_contains($src['store'], '100 VP') && str_contains($src['store'], '₹0'),
        'Storefront must communicate the same delivery rule.'
    );

    $add(
        'Order queue text matches ₹118 / 100 VP rule',
        str_contains($src['order_center'], '₹118 delivery below 100 VP'),
        'Admin/Coach order queue must show the same delivery rule.'
    );

    $legacyBad = preg_match("/\$delivery\s*=\s*\([^;]*\$vp\s*<\s*100\s*\)\s*\?\s*100(?:\.0+)?\s*:\s*0(?:\.0+)?\s*;/", $src['legacy_store']) === 1
        || str_contains($src['legacy_store'], "?100.0:0.0");
    $legacyGood = str_contains($src['legacy_store'], '118.0') || str_contains($src['legacy_store'], '118.00') || str_contains($src['legacy_store'], 'PUBLIC_STORE_HOME_DELIVERY_CHARGE');
    $add(
        'Legacy STEP23 quote path is consistent',
        !$legacyBad && $legacyGood,
        $legacyBad
            ? 'Legacy ps23_cart_quote() still contains the old ₹100 home-delivery charge.'
            : 'Legacy STEP23 quote path must also resolve to ₹118 below 100 VP.',
        $legacyBad ? 'Replace the old ₹100 calculation in business/config/public_store_step23.php with the canonical ₹118 / 100 VP rule.' : ''
    );

    $add(
        'Order request persistence uses canonical delivery result',
        str_contains($src['order_request'], "\$quote['delivery_charge']")
        && str_contains($src['order_request'], 'CUSTOMER_FREE_DELIVERY_VP')
        && str_contains($src['order_request'], '₹118 delivery charge'),
        'Saved requests and event notes must persist the server-calculated delivery charge.'
    );
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$overall = !$error && $review === 0;
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Delivery Rule Consistency Audit - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<style>
body{background:#f5f8f6;color:#173c2c}.dr-wrap{max-width:1380px;margin:auto;padding:18px}.dr-hero{padding:22px;border:1px solid #dce8e1;border-radius:20px;background:linear-gradient(135deg,#f5fbf7,#f8f9ff)}.dr-hero h1{margin:6px 0}.dr-hero p{margin:0;color:#687970;line-height:1.6}.dr-summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.dr-stat{padding:11px 14px;border:1px solid #dce8e1;border-radius:13px;background:#fff;font-weight:900}.dr-banner{margin-top:14px;padding:14px;border-radius:14px;font-weight:900}.dr-banner.pass{background:#eaf8ef;color:#176f45;border:1px solid #ccead8}.dr-banner.review{background:#fff3e8;color:#915319;border:1px solid #f2d5b8}.dr-banner.error{background:#fff0f0;color:#963c3c;border:1px solid #f0caca}.dr-table-wrap{margin-top:14px;border:1px solid #dce8e1;border-radius:16px;background:#fff;overflow:auto}.dr-table{width:100%;border-collapse:collapse;min-width:900px}.dr-table th,.dr-table td{padding:11px;border-bottom:1px solid #edf1ef;text-align:left;vertical-align:top;font-size:.72rem}.dr-table th{background:#f7faf8;color:#506159;font-size:.62rem;text-transform:uppercase}.dr-chip{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:.58rem;font-weight:900}.dr-chip.pass{background:#eaf8ef;color:#176f45}.dr-chip.review{background:#fff0f0;color:#963c3c}.dr-fix{margin-top:5px;color:#915319;font-weight:800}.dr-note{margin-top:14px;padding:13px;border:1px solid #dce8e1;border-radius:13px;background:#fff;color:#687970;font-size:.7rem;line-height:1.6}
</style>
</head>
<body>
<div class="dr-wrap">
<section class="dr-hero">
<div class="os-kicker">DELIVERY RULE CONSISTENCY AUDIT</div>
<h1>One delivery rule across every customer/order path.</h1>
<p>Target rule: Home Delivery / Ecom Drop Point below 100 applicable VP = ₹118; 100 VP or more = ₹0; Club Pickup = ₹0. This page is read-only and does not modify orders, pricing, stock or accounts.</p>
</section>

<?php if($error):?>
<div class="dr-banner error">Audit error: <?=drca_h($error)?></div>
<?php else:?>
<div class="dr-summary"><div class="dr-stat"><?=drca_h($pass)?> PASS</div><div class="dr-stat"><?=drca_h($review)?> REVIEW</div><div class="dr-stat"><?=drca_h(count($checks))?> CHECKS</div></div>
<div class="dr-banner <?=$overall?'pass':'review'?>"><?=$overall?'DELIVERY RULE AUDIT PASS — all checked paths use the same ₹118 / 100 VP rule.':'DELIVERY RULE AUDIT NEEDS REVIEW — one or more old paths still differ from the canonical rule.'?></div>
<div class="dr-table-wrap"><table class="dr-table"><thead><tr><th>Status</th><th>Check</th><th>Detail</th></tr></thead><tbody>
<?php foreach($checks as $c):?><tr><td><span class="dr-chip <?=$c['ok']?'pass':'review'?>"><?=$c['ok']?'PASS':'REVIEW'?></span></td><td><strong><?=drca_h($c['name'])?></strong></td><td><?=drca_h($c['detail'])?><?php if($c['fix']):?><div class="dr-fix">Fix: <?=drca_h($c['fix'])?></div><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div>
<div class="dr-note"><strong>Important:</strong> the currently tested Customer Membership checkout already uses the canonical ₹118 rule. This audit also checks dormant/legacy STEP23 code so an older path cannot reintroduce a different charge later.</div>
<?php endif;?>
</div>
</body>
</html>
