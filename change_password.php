<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_portal_auth.php';
$error=null;$success=null;$csrf='';$user=null;
try{
    $pdo=role_portal_db();role_portal_ensure($pdo);$user=security_step17_session_user($pdo,true);
    if(!$user){header('Location: login.php');exit;}
    $csrf=security_step17_csrf();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        $new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
        if(!hash_equals($new,$confirm))throw new RuntimeException('New password confirmation does not match.');
        security_step17_change_password($pdo,(int)$user['id'],(string)($_POST['current_password']??''),$new);
        $success='Password changed successfully. Other active sessions were revoked.';
        $user=security_step17_session_user($pdo,true);
    }
}catch(Throwable $e){$error=$e->getMessage();}
$home=$user?role_portal_home($user):'login.php';
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Change Password - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"></head><body class="auth-page"><header class="auth-top"><a class="auth-brand" href="<?=$home?>"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Account Security</small></span></a><nav class="auth-nav"><a class="auth-btn" href="<?=$home?>">My Portal</a><a class="auth-btn" href="account.php">Profile & Devices</a><a class="auth-btn" href="logout.php">Sign Out</a></nav></header><main class="auth-shell" style="max-width:760px"><section class="auth-form-card"><div class="auth-kicker">PASSWORD SECURITY</div><h2>Change your password</h2><p>Use a unique password that you do not reuse elsewhere.</p><?php if(isset($_GET['required'])):?><div class="auth-alert">Your account has a temporary password. Change it before continuing to your portal.</div><?php endif;?><?php if($error):?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif;?><?php if($success):?><div class="auth-alert good"><?=security_step17_h($success)?> <a href="<?=$home?>"><strong>Continue to My Portal →</strong></a></div><?php endif;?><form class="auth-form" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><div class="auth-field"><label>Current Password</label><input class="auth-input" type="password" name="current_password" required autocomplete="current-password"></div><div class="auth-field"><label>New Password</label><input class="auth-input" type="password" name="new_password" required autocomplete="new-password"></div><div class="auth-field"><label>Confirm New Password</label><input class="auth-input" type="password" name="confirm_password" required autocomplete="new-password"></div><button class="auth-submit">Change Password Securely</button></form><div class="auth-security"><strong>Password policy:</strong> minimum 12 characters with uppercase, lowercase, number and special character. Recent passwords cannot be reused.</div></section></main></body></html>