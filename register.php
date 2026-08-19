<?php
declare(strict_types=1);

require_once __DIR__ . '/business/config/customer_registration.php';

$error = null;
$csrf = '';
$name = '';
$email = '';
$mobile = '';

try {
    $pdo = role_portal_db();
    customer_registration_ensure($pdo);
    security_step17_session_start();

    $existing = security_step17_session_user($pdo, false);
    if ($existing) {
        header('Location: ' . role_portal_home($existing));
        exit;
    }

    $csrf = security_step17_csrf();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        security_step17_verify_csrf((string)($_POST['csrf'] ?? ''));

        // Hidden honeypot: normal users never fill this field.
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            throw new RuntimeException('Registration could not be completed. Please try again.');
        }

        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        customer_registration_create($pdo, $name, $email, $mobile, $password, $confirm);

        $_SESSION['hwc_registration_success'] = 1;
        $_SESSION['hwc_registration_email'] = $email;
        header('Location: login.php?registered=1');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    try {
        security_step17_session_start();
        $csrf = security_step17_csrf();
    } catch (Throwable) {
        $csrf = '';
    }
}
?><!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Create Customer Account - Healthcare Wellness Club</title>
    <link rel="stylesheet" href="pages/auth.css">
    <style>
        .signup-wrap{max-width:760px;margin:0 auto}.signup-card{padding:28px;border:1px solid #dce8e1;border-radius:22px;background:#fff;box-shadow:0 18px 48px rgba(29,75,54,.08)}
        .signup-head{margin-bottom:20px}.signup-head h1{margin:6px 0 8px;color:#173c2c;font-size:1.65rem}.signup-head p{margin:0;color:#6c7d74;line-height:1.65;font-size:.86rem}
        .signup-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.signup-grid .full{grid-column:1/-1}.signup-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px}
        .signup-note{margin-top:18px;padding:14px 15px;border:1px solid #dce8e1;border-radius:15px;background:linear-gradient(135deg,#f6fbf8,#fffaf0);color:#60736a;font-size:.75rem;line-height:1.6}
        .password-help{margin-top:6px;color:#788a81;font-size:.68rem;line-height:1.45}.hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important}
        @media(max-width:680px){.signup-card{padding:20px}.signup-grid{grid-template-columns:1fr}.signup-grid .full{grid-column:auto}}
    </style>
</head>
<body class="auth-page">
<header class="auth-top">
    <a class="auth-brand" href="index.html"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Customer Registration</small></span></a>
    <nav class="auth-nav"><a class="auth-btn" href="index.html">Home</a><a class="auth-btn" href="shop/index.php">Products</a><a class="auth-btn" href="login.php">Sign In</a></nav>
</header>
<main class="auth-shell">
    <div class="signup-wrap">
        <section class="signup-card">
            <div class="signup-head"><div class="auth-kicker">NEW CUSTOMER SIGN UP</div><h1>Create your Customer account</h1><p>Register as a regular Customer to access your private portal, browse products and send product order requests. Club Member status is never granted automatically; an Administrator or your assigned Coach verifies and assigns it separately.</p></div>
            <?php if ($error): ?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif; ?>
            <form class="auth-form" method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>">
                <div class="hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
                <div class="signup-grid">
                    <div class="auth-field full"><label for="full_name">Full Name</label><input class="auth-input" id="full_name" name="full_name" type="text" maxlength="120" required autocomplete="name" value="<?=security_step17_h($name)?>" autofocus></div>
                    <div class="auth-field"><label for="email">Email</label><input class="auth-input" id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?=security_step17_h($email)?>"></div>
                    <div class="auth-field"><label for="mobile">Mobile Number</label><input class="auth-input" id="mobile" name="mobile" type="tel" maxlength="20" autocomplete="tel" value="<?=security_step17_h($mobile)?>" placeholder="e.g. 9876543210"></div>
                    <div class="auth-field"><label for="password">Create Password</label><div class="password-wrap"><input class="auth-input" id="password" name="password" type="password" required autocomplete="new-password"><button class="password-toggle" id="togglePassword" type="button" aria-label="Show password">Show</button></div><div class="password-help">Minimum 12 characters with uppercase, lowercase, number and special character.</div></div>
                    <div class="auth-field"><label for="confirm_password">Confirm Password</label><div class="password-wrap"><input class="auth-input" id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password"><button class="password-toggle" id="toggleConfirm" type="button" aria-label="Show confirm password">Show</button></div></div>
                </div>
                <div class="signup-actions"><button class="auth-submit" type="submit">Create Customer Account</button><a class="auth-btn" href="login.php">Already have an account?</a></div>
            </form>
            <div class="signup-note"><strong>Already a verified Club Member?</strong> If your Customer account and Club Member ID have already been assigned by the club, use <a href="club_member_login.php"><b>Club Member Login</b></a>. Creating a new regular account does not create or upgrade a membership label.</div>
        </section>
    </div>
</main>
<script>
function wireToggle(buttonId,inputId){document.getElementById(buttonId)?.addEventListener('click',function(){const p=document.getElementById(inputId);const show=p.type==='password';p.type=show?'text':'password';this.textContent=show?'Hide':'Show';this.setAttribute('aria-label',show?'Hide password':'Show password')});}
wireToggle('togglePassword','password');wireToggle('toggleConfirm','confirm_password');
</script>
</body>
</html>
