<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_mfa.php';

$error=null;$csrf='';$user=null;
try{
    $pdo=role_portal_db();role_mfa_ensure($pdo);security_step17_session_start();
    $user=role_mfa_pending_user($pdo);
    if(!$user){header('Location: login.php');exit;}
    $csrf=security_step17_csrf();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        $user=role_mfa_complete_pending($pdo,(string)($_POST['code']??''));
        $next=(int)($user['must_change_password']??0)===1?'change_password.php?required=1':role_portal_home($user);
        header('Location: '.$next);exit;
    }
}catch(Throwable $e){$error=$e->getMessage();}
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Verify Sign In - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"><style>.mfa-shell{max-width:560px;margin:5vh auto}.mfa-code{font-size:1.2rem;letter-spacing:.18em;text-align:center}.mfa-methods{display:grid;gap:8px;margin-top:14px}.mfa-method{padding:12px;border:1px solid var(--auth-line);border-radius:13px;background:#f9fbfa;font-size:.76rem;color:var(--auth-muted)}.mfa-user{display:flex;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid var(--auth-line);border-radius:12px;background:#fff;margin:14px 0;font-size:.78rem}.mfa-user b{text-align:right}@media(max-width:560px){.mfa-user{flex-direction:column}.mfa-user b{text-align:left}}</style></head><body class="auth-page"><header class="auth-top"><a class="auth-brand" href="index.html"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Two-Step Verification</small></span></a><nav class="auth-nav"><a class="auth-btn" href="logout.php">Cancel & Sign Out</a></nav></header><main class="auth-shell"><section class="auth-form-card mfa-shell"><div class="auth-kicker">SECOND SECURITY STEP</div><h1 style="margin:10px 0 6px">Verify your sign in</h1><p>Your password was accepted. Enter the current 6-digit code from your authenticator app, or use one unused recovery code.</p><?php if($user):?><div class="mfa-user"><span>Account</span><b><?=security_step17_h((string)$user['email'])?> • <?=security_step17_h(strtoupper((string)$user['role_code']))?></b></div><?php endif;?><?php if($error):?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif;?><form class="auth-form" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><div class="auth-field"><label for="code">Authenticator / Recovery Code</label><input class="auth-input mfa-code" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus placeholder="000000"></div><button class="auth-submit" type="submit">Verify & Continue</button></form><div class="mfa-methods"><div class="mfa-method"><strong>Authenticator code:</strong> normally 6 digits and changes every 30 seconds.</div><div class="mfa-method"><strong>Recovery code:</strong> use one only if your authenticator is unavailable. Each recovery code works once.</div></div><div class="auth-security"><strong>Security:</strong> verification attempts are rate-limited. Too many incorrect codes close this pending sign-in and require a fresh password login.</div></section></main></body></html>
