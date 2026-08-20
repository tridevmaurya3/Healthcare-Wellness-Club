<?php
declare(strict_types=1);

require_once __DIR__ . '/config/customer_membership.php';

function cmc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$success = null;
$csrf = '';
$regularCustomers = [];
$memberCustomers = [];
$labels = [];
$coaches = [];
$selectedUserId = max(0, (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0));

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
    $csrf = security_step17_csrf();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        security_step17_verify_csrf((string)($_POST['csrf'] ?? ''));
        if ((string)($_POST['action'] ?? '') !== 'convert_customer') {
            throw new RuntimeException('Unknown conversion action.');
        }

        $customerUserId = max(0, (int)($_POST['user_id'] ?? 0));
        $labelId = max(0, (int)($_POST['discount_label_id'] ?? 0));
        $coachId = max(0, (int)($_POST['coach_user_id'] ?? 0));
        $joinedAt = trim((string)($_POST['joined_at'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($customerUserId <= 0) {
            throw new RuntimeException('Choose a regular Customer login account.');
        }
        if ($labelId <= 0) {
            throw new RuntimeException('Choose a Club discount label.');
        }

        $q = $pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile
            FROM system_users u
            JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
            WHERE u.id=? AND a.role_code='customer' AND u.is_active=1 AND a.is_active=1
            LIMIT 1");
        $q->execute([$orgId, $customerUserId]);
        $customer = $q->fetch();
        if (!$customer) {
            throw new RuntimeException('The selected Customer login account is not active.');
        }

        $q = $pdo->prepare("SELECT m.id,m.member_code,m.membership_status,l.label_name
            FROM customer_membership_profiles m
            LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id
            WHERE m.organization_id=? AND m.user_id=? LIMIT 1");
        $q->execute([$orgId, $customerUserId]);
        $existing = $q->fetch();
        if ($existing) {
            throw new RuntimeException('This Customer is already a Club Member ('.$existing['member_code'].'). No duplicate membership was created.');
        }

        $q = $pdo->prepare("SELECT id,label_code,label_name,pricing_tier_code
            FROM customer_discount_labels
            WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
        $q->execute([$orgId, $labelId]);
        $label = $q->fetch();
        if (!$label || trim((string)($label['pricing_tier_code'] ?? '')) === '') {
            throw new RuntimeException('Choose an active Club label linked to an exact price tier.');
        }

        if ($coachId > 0) {
            $q = $pdo->prepare("SELECT u.id,u.full_name
                FROM system_users u
                JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
                WHERE u.id=? AND a.role_code='coach' AND u.is_active=1 AND a.is_active=1
                LIMIT 1");
            $q->execute([$orgId, $coachId]);
            if (!$q->fetch()) {
                throw new RuntimeException('Choose a valid active Coach.');
            }
        }

        if ($joinedAt === '') {
            $joinedAt = date('Y-m-d');
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $joinedAt);
        if (!$date || $date->format('Y-m-d') !== $joinedAt) {
            throw new RuntimeException('Joined date is invalid.');
        }

        $memberCode = cm_generate_member_code($pdo, $orgId);

        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("INSERT INTO customer_membership_profiles
                (organization_id,user_id,member_code,coach_user_id,discount_label_id,membership_status,joined_at,verified_at,assigned_by,notes)
                VALUES(?,?,?,?,?,'active',?,NOW(),?,?)");
            $s->execute([
                $orgId,
                $customerUserId,
                $memberCode,
                $coachId ?: null,
                $labelId,
                $joinedAt,
                (int)$user['id'],
                $notes !== '' ? $notes : null,
            ]);
            $profileId = (int)$pdo->lastInsertId();

            security_step17_audit($pdo, (int)$user['id'], 'regular_customer_converted_to_club_member', 'customer_membership', $profileId, [
                'customer_user_id' => $customerUserId,
                'member_code' => $memberCode,
                'label_id' => $labelId,
                'label_code' => (string)$label['label_code'],
                'pricing_tier_code' => (string)$label['pricing_tier_code'],
                'coach_user_id' => $coachId ?: null,
            ]);

            $pdo->commit();
            $success = 'Conversion complete: '.(string)$customer['full_name'].' is now an active Club Member. Member ID: '.$memberCode.' • Label: '.(string)$label['label_name'];
            $selectedUserId = 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $q = $pdo->prepare("SELECT u.id,u.full_name,u.email,u.mobile
        FROM system_users u
        JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
        LEFT JOIN customer_membership_profiles m ON m.organization_id=? AND m.user_id=u.id
        WHERE a.role_code='customer' AND u.is_active=1 AND a.is_active=1 AND m.id IS NULL
        ORDER BY u.full_name,u.email");
    $q->execute([$orgId, $orgId]);
    $regularCustomers = $q->fetchAll();

    $q = $pdo->prepare("SELECT u.id,u.full_name,u.email,m.member_code,m.membership_status,
            l.label_name,l.pricing_tier_code,c.full_name coach_name
        FROM customer_membership_profiles m
        JOIN system_users u ON u.id=m.user_id
        LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id
        LEFT JOIN system_users c ON c.id=m.coach_user_id
        WHERE m.organization_id=?
        ORDER BY u.full_name,u.email");
    $q->execute([$orgId]);
    $memberCustomers = $q->fetchAll();

    $q = $pdo->prepare("SELECT id,label_code,label_name,pricing_tier_code
        FROM customer_discount_labels
        WHERE organization_id=? AND status='active' AND pricing_tier_code IS NOT NULL AND pricing_tier_code<>''
        ORDER BY sort_order,label_name");
    $q->execute([$orgId]);
    $labels = $q->fetchAll();

    $q = $pdo->prepare("SELECT u.id,u.full_name,u.email
        FROM system_users u
        JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=?
        WHERE a.role_code='coach' AND u.is_active=1 AND a.is_active=1
        ORDER BY u.full_name,u.email");
    $q->execute([$orgId]);
    $coaches = $q->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Regular Customer → Club Member - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<link rel="stylesheet" href="assets/workspace_refresh.css">
<style>
body{background:#f5f8f6}.cmc-wrap{max-width:1480px;margin:0 auto;padding:18px}.cmc-hero{padding:22px;border:1px solid #dce8e1;border-radius:20px;background:linear-gradient(135deg,#f5fbf7,#f8f9ff)}.cmc-hero h1{margin:6px 0;color:#173c2c}.cmc-hero p{margin:0;color:#687970;line-height:1.6;max-width:1000px}.cmc-stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.cmc-chip{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#176f45;font-size:.62rem;font-weight:900}.cmc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(390px,.72fr);gap:14px;margin-top:14px}.cmc-card{padding:17px;border:1px solid #dce8e1;border-radius:17px;background:#fff}.cmc-card h2{margin:0 0 4px;color:#173c2c}.cmc-card p{margin:0;color:#718178;font-size:.7rem;line-height:1.5}.cmc-list{display:grid;gap:8px;margin-top:12px}.cmc-customer{padding:12px;border:1px solid #e0e9e4;border-radius:13px;background:#fbfdfc;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}.cmc-customer b{display:block;color:#173c2c}.cmc-customer small{display:block;color:#75857d;margin-top:3px}.cmc-btn{display:inline-flex;padding:8px 10px;border-radius:9px;border:1px solid #cee2d6;background:#edf7f1;color:#176f45;text-decoration:none;font-size:.64rem;font-weight:900;white-space:nowrap}.cmc-form{display:grid;gap:9px;margin-top:12px}.cmc-form label{display:grid;gap:5px;color:#52685d;font-size:.7rem;font-weight:850}.cmc-form input,.cmc-form select,.cmc-form textarea{width:100%;padding:10px 11px;border:1px solid #d8e4dd;border-radius:10px;background:#fbfdfc;color:#173c2c}.cmc-form textarea{min-height:78px;resize:vertical}.cmc-form button{padding:11px 14px;border:0;border-radius:11px;background:#176f45;color:#fff;font-weight:900;cursor:pointer}.cmc-alert{margin-top:14px;padding:12px 14px;border-radius:12px}.cmc-alert.good{background:#eaf8ef;color:#176f45;border:1px solid #ccead8}.cmc-alert.bad{background:#fff0f0;color:#963c3c;border:1px solid #f0caca}.cmc-table-wrap{margin-top:14px;border:1px solid #dce8e1;border-radius:16px;background:#fff;overflow:auto}.cmc-table{width:100%;border-collapse:collapse;min-width:900px}.cmc-table th,.cmc-table td{padding:9px 10px;border-bottom:1px solid #edf1ef;text-align:left;font-size:.68rem}.cmc-table th{background:#f7faf8;color:#53655c;text-transform:uppercase;font-size:.59rem}.cmc-note{margin-top:12px;padding:12px 14px;border:1px solid #e7ddbd;border-radius:12px;background:#fffaf0;color:#705d31;font-size:.69rem;line-height:1.55}@media(max-width:980px){.cmc-grid{grid-template-columns:1fr}}@media(max-width:620px){.cmc-customer{grid-template-columns:1fr}.cmc-btn{justify-self:start}}
</style>
</head>
<body>
<div class="cmc-wrap">
<section class="cmc-hero">
<div class="os-kicker">CUSTOMER MEMBERSHIP CONVERSION</div>
<h1>Regular Customer → Active Club Member</h1>
<p>This flow upgrades an existing Customer login account. It never creates a second login account. The same customer keeps the same password/MFA/session security; only a unique Club Member profile, assigned label and exact member price tier are added.</p>
<div class="cmc-stats"><span class="cmc-chip"><?=count($regularCustomers)?> Regular Customers</span><span class="cmc-chip"><?=count($memberCustomers)?> Club Members</span><span class="cmc-chip">Admin Controlled</span></div>
</section>

<?php if ($error): ?><div class="cmc-alert bad"><strong><?=cmc_h($error)?></strong></div><?php endif; ?>
<?php if ($success): ?><div class="cmc-alert good"><strong><?=cmc_h($success)?></strong></div><?php endif; ?>

<?php if (!$error): ?>
<section class="cmc-grid">
<article class="cmc-card">
<h2>Regular Customer Accounts</h2>
<p>Only active Customer login accounts without an existing membership appear here.</p>
<div class="cmc-list">
<?php foreach ($regularCustomers as $customer): ?>
<div class="cmc-customer">
<div><b><?=cmc_h($customer['full_name'])?></b><small><?=cmc_h($customer['email'])?></small><?php if(!empty($customer['mobile'])):?><small><?=cmc_h($customer['mobile'])?></small><?php endif;?></div>
<a class="cmc-btn" href="?user_id=<?=(int)$customer['id']?>">Convert to Club Member →</a>
</div>
<?php endforeach; ?>
<?php if (!$regularCustomers): ?><div class="cmc-note">No regular Customer account is waiting for conversion.</div><?php endif; ?>
</div>
</article>

<article class="cmc-card">
<h2>Activate Club Membership</h2>
<p>Choose a regular customer, exact Club label and optional Coach. Member ID is generated securely.</p>
<form method="post" class="cmc-form">
<input type="hidden" name="csrf" value="<?=cmc_h($csrf)?>">
<input type="hidden" name="action" value="convert_customer">
<label>Regular Customer
<select name="user_id" required>
<option value="">Choose customer</option>
<?php foreach($regularCustomers as $customer):?><option value="<?=(int)$customer['id']?>" <?=$selectedUserId===(int)$customer['id']?'selected':''?>><?=cmc_h($customer['full_name'].' • '.$customer['email'])?></option><?php endforeach;?>
</select>
</label>
<label>Club Discount Label
<select name="discount_label_id" required>
<option value="">Choose label</option>
<?php foreach($labels as $label):?><option value="<?=(int)$label['id']?>"><?=cmc_h($label['label_name'].' • '.$label['pricing_tier_code'])?></option><?php endforeach;?>
</select>
</label>
<label>Assigned Coach (optional)
<select name="coach_user_id"><option value="">Administrator managed</option><?php foreach($coaches as $coach):?><option value="<?=(int)$coach['id']?>"><?=cmc_h($coach['full_name'].' • '.$coach['email'])?></option><?php endforeach;?></select>
</label>
<label>Joined Date<input type="date" name="joined_at" value="<?=cmc_h(date('Y-m-d'))?>" required></label>
<label>Internal Note<textarea name="notes" placeholder="Optional membership note"></textarea></label>
<button type="submit">Convert & Activate Membership →</button>
</form>
<div class="cmc-note"><strong>Safety:</strong> the database has one-membership-per-customer protection. Re-running conversion for the same Customer cannot create a duplicate Club Member profile.</div>
</article>
</section>

<section class="cmc-table-wrap">
<table class="cmc-table"><thead><tr><th>Club Member</th><th>Member ID</th><th>Label / Tier</th><th>Coach</th><th>Status</th></tr></thead><tbody>
<?php foreach($memberCustomers as $member):?><tr><td><strong><?=cmc_h($member['full_name'])?></strong><br><?=cmc_h($member['email'])?></td><td><?=cmc_h($member['member_code'])?></td><td><?=cmc_h($member['label_name'] ?: '—')?><?php if(!empty($member['pricing_tier_code'])):?><br><small><?=cmc_h($member['pricing_tier_code'])?></small><?php endif;?></td><td><?=cmc_h($member['coach_name'] ?: 'Administrator')?></td><td><?=cmc_h(strtoupper((string)$member['membership_status']))?></td></tr><?php endforeach;?>
<?php if(!$memberCustomers):?><tr><td colspan="5">No Club Members yet.</td></tr><?php endif;?>
</tbody></table>
</section>

<div class="cmc-note"><a href="customer_membership_manager.php">← Open full Club Members & Offers manager</a> &nbsp;•&nbsp; <a href="customer_center.php">Customer Center</a> &nbsp;•&nbsp; <a href="../shop/index.php">Storefront</a></div>
<?php endif; ?>
</div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
