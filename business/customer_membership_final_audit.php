<?php
declare(strict_types=1);

require_once __DIR__ . '/config/customer_membership.php';

function cmfa_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cmfa_count(PDO $pdo, string $sql, array $args = []): int
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return (int)$s->fetchColumn();
}

function cmfa_add(array &$checks, string $name, bool $pass, string $detail, string $area): void
{
    $checks[] = [
        'name' => $name,
        'pass' => $pass,
        'detail' => $detail,
        'area' => $area,
    ];
}

$error = null;
$user = null;
$checks = [];
$metrics = [
    'customer_accounts' => 0,
    'regular_customers' => 0,
    'memberships' => 0,
    'active_memberships' => 0,
    'coaches' => 0,
    'member_orders' => 0,
];
$memberRows = [];
$labelRows = [];

try {
    $pdo = role_portal_db();
    cm_ensure($pdo);
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
    if ((string)($user['role_code'] ?? '') !== 'admin' || !security_step17_has_permission($pdo, 'customers.manage', $user)) {
        header('Location: access_denied.php?permission=customers.manage');
        exit;
    }

    $ctx = security_step17_context($pdo);
    $orgId = (int)($ctx['organization_id'] ?? 0);

    // ---------- Summary metrics ----------
    $metrics['customer_accounts'] = cmfa_count($pdo,
        "SELECT COUNT(DISTINCT u.id)
         FROM system_users u
         JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
         WHERE a.role_code='customer' AND u.is_active=1 AND a.is_active=1",
        [$orgId]
    );
    $metrics['memberships'] = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_membership_profiles WHERE organization_id=?",
        [$orgId]
    );
    $metrics['active_memberships'] = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_membership_profiles WHERE organization_id=? AND membership_status='active'",
        [$orgId]
    );
    $metrics['regular_customers'] = cmfa_count($pdo,
        "SELECT COUNT(DISTINCT u.id)
         FROM system_users u
         JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
         LEFT JOIN customer_membership_profiles m ON m.organization_id=? AND m.user_id=u.id
         WHERE a.role_code='customer' AND u.is_active=1 AND a.is_active=1 AND m.id IS NULL",
        [$orgId, $orgId]
    );
    $metrics['coaches'] = cmfa_count($pdo,
        "SELECT COUNT(DISTINCT u.id)
         FROM system_users u
         JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
         WHERE a.role_code='coach' AND u.is_active=1 AND a.is_active=1",
        [$orgId]
    );
    if (business_table_exists($pdo, 'public_order_requests') && business_column_exists($pdo, 'public_order_requests', 'customer_membership_id')) {
        $metrics['member_orders'] = cmfa_count($pdo,
            "SELECT COUNT(*) FROM public_order_requests WHERE organization_id=? AND customer_membership_id IS NOT NULL",
            [$orgId]
        );
    }

    // ---------- 1. Core schema ----------
    $coreTables = ['customer_discount_labels', 'customer_membership_profiles', 'customer_promotions'];
    $missingCore = [];
    foreach ($coreTables as $table) {
        if (!business_table_exists($pdo, $table)) $missingCore[] = $table;
    }
    cmfa_add($checks, 'Membership core tables', !$missingCore,
        !$missingCore ? 'All membership tables are available.' : 'Missing: '.implode(', ', $missingCore), 'Schema');

    // ---------- 2. Critical flow files ----------
    $flowFiles = [
        '../register.php',
        '../club_member_login.php',
        '../customer_portal.php',
        'customer_membership_manager.php',
        'customer_membership_conversion.php',
        '../shop/index.php',
        '../shop/cart.php',
        '../shop/checkout.php',
        '../shop/submit.php',
        'public_order_center.php',
    ];
    $missingFiles = [];
    foreach ($flowFiles as $relative) {
        if (!is_file(__DIR__ . '/' . $relative)) $missingFiles[] = $relative;
    }
    cmfa_add($checks, 'Customer/member flow files', !$missingFiles,
        !$missingFiles ? 'Registration, conversion, login, storefront, cart, checkout and order queue files are present.' : 'Missing: '.implode(', ', $missingFiles), 'Application');

    // ---------- 3. Admin conversion guard ----------
    $conversionPath = __DIR__ . '/customer_membership_conversion.php';
    $conversionSource = is_file($conversionPath) ? (string)file_get_contents($conversionPath) : '';
    $guardOk = $conversionSource !== ''
        && str_contains($conversionSource, "role_code")
        && str_contains($conversionSource, "customers.manage")
        && str_contains($conversionSource, "convert_customer");
    cmfa_add($checks, 'Admin-controlled conversion endpoint', $guardOk,
        $guardOk ? 'Conversion endpoint contains role/permission and explicit conversion-action guards.' : 'Conversion endpoint guard markers need review.', 'Security');

    // ---------- 4-5. Unique indexes ----------
    $indexStmt = $pdo->prepare("SELECT index_name,non_unique,GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') cols
        FROM information_schema.statistics
        WHERE table_schema=DATABASE() AND table_name='customer_membership_profiles'
        GROUP BY index_name,non_unique");
    $indexStmt->execute();
    $indexes = $indexStmt->fetchAll();
    $uniqueUser = false;
    $uniqueCode = false;
    foreach ($indexes as $idx) {
        if ((int)$idx['non_unique'] !== 0) continue;
        $cols = strtolower((string)$idx['cols']);
        if ($cols === 'organization_id,user_id') $uniqueUser = true;
        if ($cols === 'organization_id,member_code') $uniqueCode = true;
    }
    cmfa_add($checks, 'One membership per customer DB protection', $uniqueUser,
        $uniqueUser ? 'Unique index protects organization_id + user_id.' : 'Unique organization_id + user_id index is missing.', 'Integrity');
    cmfa_add($checks, 'Unique Club Member ID DB protection', $uniqueCode,
        $uniqueCode ? 'Unique index protects organization_id + member_code.' : 'Unique organization_id + member_code index is missing.', 'Integrity');

    // ---------- 6-10. Membership integrity ----------
    $duplicateUsers = cmfa_count($pdo,
        "SELECT COUNT(*) FROM (
            SELECT user_id FROM customer_membership_profiles WHERE organization_id=? GROUP BY user_id HAVING COUNT(*)>1
         ) x", [$orgId]);
    cmfa_add($checks, 'No duplicate membership by customer', $duplicateUsers === 0,
        $duplicateUsers === 0 ? 'No duplicate customer membership rows found.' : $duplicateUsers.' duplicate customer group(s) found.', 'Integrity');

    $duplicateCodes = cmfa_count($pdo,
        "SELECT COUNT(*) FROM (
            SELECT member_code FROM customer_membership_profiles WHERE organization_id=? GROUP BY member_code HAVING COUNT(*)>1
         ) x", [$orgId]);
    cmfa_add($checks, 'No duplicate Club Member IDs', $duplicateCodes === 0,
        $duplicateCodes === 0 ? 'All Club Member IDs are unique.' : $duplicateCodes.' duplicate Member ID group(s) found.', 'Integrity');

    $badStatuses = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_membership_profiles
         WHERE organization_id=? AND membership_status NOT IN ('pending','active','suspended')", [$orgId]);
    cmfa_add($checks, 'Membership status domain', $badStatuses === 0,
        $badStatuses === 0 ? 'All membership statuses are valid.' : $badStatuses.' invalid membership status row(s) found.', 'Integrity');

    $badCodes = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_membership_profiles
         WHERE organization_id=? AND (member_code IS NULL OR member_code='' OR member_code NOT REGEXP '^[A-Z0-9-]{6,60}$')", [$orgId]);
    cmfa_add($checks, 'Club Member ID format', $badCodes === 0,
        $badCodes === 0 ? 'All Member IDs use the allowed uppercase letters/numbers/hyphen format.' : $badCodes.' Member ID row(s) have an invalid format.', 'Integrity');

    $badCustomerLinks = cmfa_count($pdo,
        "SELECT COUNT(*)
         FROM customer_membership_profiles m
         LEFT JOIN system_users u ON u.id=m.user_id
         LEFT JOIN organization_user_access a ON a.user_id=m.user_id AND a.organization_id=m.organization_id AND a.role_code='customer' AND a.is_active=1
         WHERE m.organization_id=? AND (u.id IS NULL OR u.is_active<>1 OR a.user_id IS NULL)", [$orgId]);
    cmfa_add($checks, 'Membership → Customer account linkage', $badCustomerLinks === 0,
        $badCustomerLinks === 0 ? 'Every membership belongs to an active Customer login account.' : $badCustomerLinks.' membership row(s) are not linked to an active Customer role.', 'Roles');

    // ---------- 11-18. Active member/label/tier/coach integrity ----------
    $unverifiedActive = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_membership_profiles
         WHERE organization_id=? AND membership_status='active' AND verified_at IS NULL", [$orgId]);
    cmfa_add($checks, 'Active memberships are verified', $unverifiedActive === 0,
        $unverifiedActive === 0 ? 'Every active Club Member has verification timestamp.' : $unverifiedActive.' active membership(s) are missing verified_at.', 'Membership');

    $activeWithoutTier = cmfa_count($pdo,
        "SELECT COUNT(*)
         FROM customer_membership_profiles m
         LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id AND l.organization_id=m.organization_id
         WHERE m.organization_id=? AND m.membership_status='active'
           AND (l.id IS NULL OR l.status<>'active' OR l.pricing_tier_code IS NULL OR TRIM(l.pricing_tier_code)='')", [$orgId]);
    cmfa_add($checks, 'Active member → active exact-price label', $activeWithoutTier === 0,
        $activeWithoutTier === 0 ? 'Every active Club Member has an active label linked to an exact price tier.' : $activeWithoutTier.' active membership(s) need a valid exact-price label.', 'Pricing');

    $seedMap = ['BRONZE'=>'PC_BRONZE','SILVER'=>'PC_SILVER','GOLD'=>'PC_GOLD'];
    foreach ($seedMap as $labelCode => $tierCode) {
        $s = $pdo->prepare("SELECT pricing_tier_code,status FROM customer_discount_labels WHERE organization_id=? AND label_code=? LIMIT 1");
        $s->execute([$orgId, $labelCode]);
        $row = $s->fetch();
        $ok = $row && (string)$row['pricing_tier_code'] === $tierCode && (string)$row['status'] === 'active';
        cmfa_add($checks, $labelCode.' exact tier mapping', (bool)$ok,
            $ok ? $labelCode.' → '.$tierCode.' is active and exact.' : $labelCode.' must map to active '.$tierCode.'.', 'Pricing');
    }

    $invalidTierLinks = cmfa_count($pdo,
        "SELECT COUNT(*) FROM customer_discount_labels l
         LEFT JOIN product_discount_tiers t ON t.organization_id=l.organization_id AND t.tier_code=l.pricing_tier_code AND t.status='active'
         LEFT JOIN product_markets pm ON pm.id=t.market_id AND pm.market_code='IN'
         WHERE l.organization_id=? AND l.status='active' AND l.pricing_tier_code IS NOT NULL AND TRIM(l.pricing_tier_code)<>''
           AND (t.id IS NULL OR pm.id IS NULL)", [$orgId]);
    cmfa_add($checks, 'Active labels resolve to active India price tiers', $invalidTierLinks === 0,
        $invalidTierLinks === 0 ? 'Every active priced label resolves to an active IN market tier.' : $invalidTierLinks.' label-to-tier mapping(s) do not resolve correctly.', 'Pricing');

    $tiersWithoutPrices = cmfa_count($pdo,
        "SELECT COUNT(*) FROM (
            SELECT l.id
            FROM customer_discount_labels l
            JOIN product_discount_tiers t ON t.organization_id=l.organization_id AND t.tier_code=l.pricing_tier_code AND t.status='active'
            JOIN product_markets pm ON pm.id=t.market_id AND pm.market_code='IN'
            LEFT JOIN product_tier_prices tp ON tp.discount_tier_id=t.id
            WHERE l.organization_id=? AND l.status='active' AND l.pricing_tier_code IS NOT NULL AND TRIM(l.pricing_tier_code)<>''
            GROUP BY l.id
            HAVING COUNT(tp.price_version_id)=0
         ) x", [$orgId]);
    cmfa_add($checks, 'Exact tier price records available', $tiersWithoutPrices === 0,
        $tiersWithoutPrices === 0 ? 'Every active priced label has stored exact tier price records.' : $tiersWithoutPrices.' active priced label(s) have no exact tier price rows.', 'Pricing');

    $badCoachLinks = cmfa_count($pdo,
        "SELECT COUNT(*)
         FROM customer_membership_profiles m
         LEFT JOIN system_users u ON u.id=m.coach_user_id
         LEFT JOIN organization_user_access a ON a.user_id=m.coach_user_id AND a.organization_id=m.organization_id AND a.role_code='coach' AND a.is_active=1
         WHERE m.organization_id=? AND m.coach_user_id IS NOT NULL
           AND (u.id IS NULL OR u.is_active<>1 OR a.user_id IS NULL)", [$orgId]);
    cmfa_add($checks, 'Assigned Coach role integrity', $badCoachLinks === 0,
        $badCoachLinks === 0 ? 'Every assigned Coach is an active Coach-role user.' : $badCoachLinks.' membership(s) have an invalid/inactive Coach assignment.', 'Roles');

    // ---------- 19. Delivery constants ----------
    $deliveryConstantsOk = defined('CUSTOMER_HOME_DELIVERY_CHARGE') && defined('CUSTOMER_FREE_DELIVERY_VP')
        && abs((float)CUSTOMER_HOME_DELIVERY_CHARGE - 118.0) < 0.001
        && abs((float)CUSTOMER_FREE_DELIVERY_VP - 100.0) < 0.001;
    cmfa_add($checks, 'Customer delivery rule constants', $deliveryConstantsOk,
        $deliveryConstantsOk ? 'Home Delivery below 100 VP = ₹118; 100+ VP = ₹0.' : 'Delivery constants do not match ₹118 / 100 VP rule.', 'Checkout');

    // ---------- 20-21. Order schema ----------
    $requestColumns = ['customer_user_id','customer_membership_id','customer_price_mode','discount_label_code','discount_amount','total_vp','delivery_charge','estimated_total'];
    $missingRequestCols = [];
    foreach ($requestColumns as $col) {
        if (!business_column_exists($pdo, 'public_order_requests', $col)) $missingRequestCols[] = $col;
    }
    cmfa_add($checks, 'Member pricing fields on order requests', !$missingRequestCols,
        !$missingRequestCols ? 'Order request table contains membership/pricing/VP/delivery snapshot fields.' : 'Missing: '.implode(', ', $missingRequestCols), 'Orders');

    $itemColumns = ['unit_customer_price','line_customer_price','discount_amount','pricing_source','promotion_code'];
    $missingItemCols = [];
    foreach ($itemColumns as $col) {
        if (!business_column_exists($pdo, 'public_order_request_items', $col)) $missingItemCols[] = $col;
    }
    cmfa_add($checks, 'Member pricing fields on order items', !$missingItemCols,
        !$missingItemCols ? 'Order item table contains server-calculated customer price and pricing-source fields.' : 'Missing: '.implode(', ', $missingItemCols), 'Orders');

    // ---------- 22-26. Persisted member order integrity ----------
    if (!$missingRequestCols) {
        $badOrderLinks = cmfa_count($pdo,
            "SELECT COUNT(*)
             FROM public_order_requests o
             LEFT JOIN customer_membership_profiles m ON m.id=o.customer_membership_id AND m.organization_id=o.organization_id
             WHERE o.organization_id=? AND o.customer_membership_id IS NOT NULL
               AND (m.id IS NULL OR o.customer_user_id IS NULL OR o.customer_user_id<>m.user_id)", [$orgId]);
        cmfa_add($checks, 'Member order → membership linkage', $badOrderLinks === 0,
            $badOrderLinks === 0 ? 'All member-linked order requests point to the same Customer account as their membership.' : $badOrderLinks.' member order request(s) have mismatched customer/membership linkage.', 'Orders');

        $missingOrderSnapshot = cmfa_count($pdo,
            "SELECT COUNT(*) FROM public_order_requests
             WHERE organization_id=? AND customer_membership_id IS NOT NULL
               AND (customer_user_id IS NULL OR customer_price_mode IS NULL OR TRIM(customer_price_mode)='' OR discount_label_code IS NULL OR TRIM(discount_label_code)='')", [$orgId]);
        cmfa_add($checks, 'Member order pricing snapshot', $missingOrderSnapshot === 0,
            $missingOrderSnapshot === 0 ? 'Member order requests retain customer, membership price mode and discount-label snapshot.' : $missingOrderSnapshot.' member order request(s) are missing pricing snapshot data.', 'Orders');

        if (!$missingItemCols) {
            $badItemMath = cmfa_count($pdo,
                "SELECT COUNT(*)
                 FROM public_order_request_items i
                 JOIN public_order_requests o ON o.id=i.public_order_id AND o.organization_id=i.organization_id
                 WHERE o.organization_id=? AND o.customer_membership_id IS NOT NULL
                   AND (i.unit_customer_price IS NULL OR i.line_customer_price IS NULL
                     OR ABS(i.line_customer_price - ROUND(i.unit_customer_price*i.quantity,2))>0.02
                     OR ABS(i.discount_amount - ROUND(i.line_mrp-i.line_customer_price,2))>0.02)", [$orgId]);
            cmfa_add($checks, 'Member order item price mathematics', $badItemMath === 0,
                $badItemMath === 0 ? 'Stored member line totals and discounts match server-calculated unit prices.' : $badItemMath.' member order item(s) have inconsistent stored pricing math.', 'Orders');
        } else {
            cmfa_add($checks, 'Member order item price mathematics', false, 'Cannot verify because required item pricing columns are missing.', 'Orders');
        }

        $badDelivery = cmfa_count($pdo,
            "SELECT COUNT(*) FROM public_order_requests
             WHERE organization_id=? AND customer_membership_id IS NOT NULL AND (
                (delivery_mode='club_pickup' AND ABS(delivery_charge)>0.01)
                OR (delivery_mode='home_delivery' AND total_vp>0 AND total_vp<100 AND ABS(delivery_charge-118)>0.01)
                OR (delivery_mode='home_delivery' AND total_vp>=100 AND ABS(delivery_charge)>0.01)
             )", [$orgId]);
        cmfa_add($checks, 'Persisted member delivery calculation', $badDelivery === 0,
            $badDelivery === 0 ? 'Persisted member orders follow ₹118 below 100 VP / ₹0 at 100+ VP / ₹0 Club Pickup.' : $badDelivery.' member order request(s) have a delivery-rule mismatch.', 'Checkout');

        cmfa_add($checks, 'End-to-end member order proof', $metrics['member_orders'] > 0,
            $metrics['member_orders'] > 0 ? $metrics['member_orders'].' member-linked order request(s) are persisted for Admin/Coach review.' : 'No member-linked order request is stored yet; submit one runtime test order.', 'Runtime proof');
    } else {
        for ($i = 0; $i < 5; $i++) {
            cmfa_add($checks, ['Member order → membership linkage','Member order pricing snapshot','Member order item price mathematics','Persisted member delivery calculation','End-to-end member order proof'][$i], false, 'Cannot verify because member order request schema is incomplete.', 'Orders');
        }
    }

    // ---------- Detail tables ----------
    $s = $pdo->prepare("SELECT m.member_code,m.membership_status,m.joined_at,m.verified_at,
            u.full_name customer_name,u.email,l.label_code,l.label_name,l.pricing_tier_code,c.full_name coach_name
        FROM customer_membership_profiles m
        JOIN system_users u ON u.id=m.user_id
        LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id
        LEFT JOIN system_users c ON c.id=m.coach_user_id
        WHERE m.organization_id=?
        ORDER BY FIELD(m.membership_status,'active','pending','suspended'),u.full_name");
    $s->execute([$orgId]);
    $memberRows = $s->fetchAll();

    $s = $pdo->prepare("SELECT label_code,label_name,badge_text,pricing_tier_code,status,sort_order
        FROM customer_discount_labels WHERE organization_id=? ORDER BY sort_order,label_name");
    $s->execute([$orgId]);
    $labelRows = $s->fetchAll();

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$passCount = 0;
$reviewCount = 0;
foreach ($checks as $check) {
    if ($check['pass']) $passCount++; else $reviewCount++;
}
$overallPass = !$error && $reviewCount === 0 && count($checks) > 0;
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Customer Membership Final Audit - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<link rel="stylesheet" href="assets/workspace_refresh.css">
<style>
body{background:#f5f8f6;color:#173c2c}.cmfa-wrap{max-width:1520px;margin:0 auto;padding:18px}.cmfa-hero{padding:22px;border:1px solid #dce8e1;border-radius:20px;background:linear-gradient(135deg,#f5fbf7,#f8f9ff)}.cmfa-hero h1{margin:6px 0 7px}.cmfa-hero p{margin:0;color:#687970;line-height:1.6;max-width:1080px}.cmfa-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}.cmfa-stat{background:#fff;border:1px solid #dce8e1;border-radius:14px;padding:12px}.cmfa-stat b{display:block;font-size:1.18rem}.cmfa-stat span{display:block;margin-top:4px;font-size:.63rem;color:#708078}.cmfa-banner{margin-top:14px;padding:14px 16px;border-radius:14px;font-weight:900}.cmfa-banner.pass{background:#eaf8ef;color:#176f45;border:1px solid #ccead8}.cmfa-banner.review{background:#fff3e8;color:#915319;border:1px solid #f2d5b8}.cmfa-banner.error{background:#fff0f0;color:#963c3c;border:1px solid #f0caca}.cmfa-table-wrap{margin-top:14px;border:1px solid #dce8e1;border-radius:16px;background:#fff;overflow:auto}.cmfa-table{width:100%;border-collapse:collapse;min-width:980px}.cmfa-table th,.cmfa-table td{padding:9px 10px;border-bottom:1px solid #edf1ef;text-align:left;vertical-align:top;font-size:.68rem}.cmfa-table th{background:#f7faf8;color:#506159;font-size:.6rem;text-transform:uppercase;letter-spacing:.04em}.cmfa-chip{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:.57rem;font-weight:900}.cmfa-chip.pass{background:#eaf8ef;color:#176f45}.cmfa-chip.review{background:#fff0f0;color:#963c3c}.cmfa-muted{color:#78877f}.cmfa-section{margin-top:14px;padding:16px;border:1px solid #dce8e1;border-radius:16px;background:#fff}.cmfa-section h2{margin:0 0 10px}.cmfa-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.cmfa-actions a{display:inline-flex;padding:8px 10px;border:1px solid #d3e2da;border-radius:10px;background:#fff;color:#176f45;text-decoration:none;font-size:.68rem;font-weight:850}.cmfa-note{margin-top:14px;padding:13px 15px;border:1px solid #dce8e1;border-radius:14px;background:#fff;color:#687970;font-size:.68rem;line-height:1.6}@media(max-width:1050px){.cmfa-summary{grid-template-columns:repeat(3,1fr)}}@media(max-width:620px){.cmfa-summary{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="cmfa-wrap">
<section class="cmfa-hero">
<div class="os-kicker">CUSTOMER MEMBERSHIP FINAL AUDIT</div>
<h1>Accounts, Club Membership, exact pricing, Coach assignment and member-order persistence.</h1>
<p>This audit is read-only after the normal membership bootstrap. It checks data integrity and the server-side links used by the tested Regular Customer → Club Member → Storefront → Cart → Checkout → Admin/Coach order-request flow.</p>
<div class="cmfa-actions"><a href="customer_membership_conversion.php">Membership Conversion</a><a href="customer_membership_manager.php">Club Members & Labels</a><a href="public_order_center.php">Order Requests</a><a href="../shop/index.php" target="_blank" rel="noopener">Open Storefront</a></div>
</section>

<?php if ($error): ?>
<div class="cmfa-banner error">Audit error: <?=cmfa_h($error)?></div>
<?php else: ?>
<section class="cmfa-summary">
<div class="cmfa-stat"><b><?=number_format($metrics['customer_accounts'])?></b><span>Active Customer accounts</span></div>
<div class="cmfa-stat"><b><?=number_format($metrics['regular_customers'])?></b><span>Regular Customers</span></div>
<div class="cmfa-stat"><b><?=number_format($metrics['memberships'])?></b><span>Total Club memberships</span></div>
<div class="cmfa-stat"><b><?=number_format($metrics['active_memberships'])?></b><span>Active / verified members</span></div>
<div class="cmfa-stat"><b><?=number_format($metrics['member_orders'])?></b><span>Member-linked requests</span></div>
<div class="cmfa-stat"><b><?=number_format($passCount)?> PASS / <?=number_format($reviewCount)?> REVIEW</b><span>Final audit result</span></div>
</section>

<?php if ($overallPass): ?>
<div class="cmfa-banner pass">CUSTOMER MEMBERSHIP FINAL AUDIT PASS — account conversion, membership integrity, exact-tier mapping, Coach linkage, delivery rules and persisted member-order context are consistent.</div>
<?php else: ?>
<div class="cmfa-banner review">CUSTOMER MEMBERSHIP FINAL AUDIT NEEDS REVIEW — <?=number_format($reviewCount)?> check(s) require attention. See the exact row details below.</div>
<?php endif; ?>

<div class="cmfa-table-wrap">
<table class="cmfa-table">
<thead><tr><th>Status</th><th>Area</th><th>Check</th><th>Result / Detail</th></tr></thead>
<tbody>
<?php foreach ($checks as $check): ?>
<tr>
<td><span class="cmfa-chip <?=$check['pass']?'pass':'review'?>"><?=$check['pass']?'PASS':'REVIEW'?></span></td>
<td><?=cmfa_h($check['area'])?></td>
<td><strong><?=cmfa_h($check['name'])?></strong></td>
<td class="<?=$check['pass']?'cmfa-muted':''?>"><?=cmfa_h($check['detail'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<section class="cmfa-section">
<h2>Club Member Register Snapshot</h2>
<div class="cmfa-table-wrap" style="margin-top:0"><table class="cmfa-table"><thead><tr><th>Customer</th><th>Member ID</th><th>Label / Exact Tier</th><th>Coach</th><th>Status</th><th>Verification</th></tr></thead><tbody>
<?php if(!$memberRows):?><tr><td colspan="6">No Club Member profiles are stored.</td></tr><?php endif;?>
<?php foreach($memberRows as $row):?><tr><td><strong><?=cmfa_h($row['customer_name'])?></strong><br><span class="cmfa-muted"><?=cmfa_h($row['email'])?></span></td><td><?=cmfa_h($row['member_code'])?></td><td><?=cmfa_h($row['label_name']?:'—')?><br><span class="cmfa-muted"><?=cmfa_h($row['pricing_tier_code']?:'—')?></span></td><td><?=cmfa_h($row['coach_name']?:'Administrator managed')?></td><td><?=cmfa_h(strtoupper($row['membership_status']))?></td><td><?=cmfa_h($row['verified_at']?:'—')?></td></tr><?php endforeach;?>
</tbody></table></div>
</section>

<section class="cmfa-section">
<h2>Discount Label Snapshot</h2>
<div class="cmfa-table-wrap" style="margin-top:0"><table class="cmfa-table"><thead><tr><th>Code</th><th>Label</th><th>Badge</th><th>Exact Price Tier</th><th>Status</th></tr></thead><tbody>
<?php foreach($labelRows as $row):?><tr><td><strong><?=cmfa_h($row['label_code'])?></strong></td><td><?=cmfa_h($row['label_name'])?></td><td><?=cmfa_h($row['badge_text']?:'—')?></td><td><?=cmfa_h($row['pricing_tier_code']?:'Informational only')?></td><td><?=cmfa_h(strtoupper($row['status']))?></td></tr><?php endforeach;?>
</tbody></table></div>
</section>

<div class="cmfa-note"><strong>Boundary:</strong> this audit verifies membership/account/order consistency and exact tier references. It does not turn an order request into a sale, collect payment, allocate stock, expose internal cost/profit data, or replace the already-completed visual product-image audit.</div>
<?php endif; ?>
</div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
