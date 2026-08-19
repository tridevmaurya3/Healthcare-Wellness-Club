<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_portal_auth.php';
$error=null;$submitted=false;$csrf='';
try{
    $pdo=role_portal_db();role_portal_ensure($pdo);security_step17_session_start();$csrf=security_step17_csrf();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        role_portal_request_recovery($pdo,(string)($_POST['email']??''));
        $submitted=true;
    }
}catch(Throwable $e){$error='Recovery request could not be processed right now. Please try again later.';}
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Forgot Password - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"></head><body class="auth-page">
<header class="auth-top"><a class="auth-brand" href="index.html"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Secure Account Recovery</small></span></a><nav class="auth-nav"><a class="auth-btn" href="index.html">Home</a><a class="auth-btn" href="login.php">Sign In</a></nav></header>
<main class="auth-shell" style="max-width:760px"><section class="auth-form-card" style="max-width:620px;margin:4vh auto"><div class="auth-kicker">ACCOUNT RECOVERY</div><h1 style="margin:8px 0 10px">Forgot your password?</h1><p>Enter the email used for your Healthcare Wellness Club account. For privacy, this page never confirms whether an account exists.</p>
<?php if($submitted):?><div class="auth-alert good"><strong>Request received.</strong> If a matching active account exists, a secure recovery request has been recorded for the Administrator. Contact the club/Administrator to receive a temporary password. You will be required to change it at your next sign-in.</div><?php endif;?>
<?php if($error):?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif;?>
<?php if(!$submitted):?><form class="auth-form" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><div class="auth-field"><label for="email">Email / Login ID</label><input class="auth-input" id="email" type="email" name="email" required autocomplete="email" autofocus placeholder="name@example.com"></div><button class="auth-submit" type="submit">Request Secure Recovery</button></form><?php endif;?>
<div class="auth-security"><strong>Privacy & security:</strong> requests are rate-limited, the entered identifier is stored as a one-way hash for unmatched requests, and no password is ever emailed or stored in a recovery request. Administrator-issued temporary passwords force a password change and revoke older sessions.</div><p style="margin-top:18px"><a href="login.php">← Back to secure sign in</a></p></section></main></body></html>
