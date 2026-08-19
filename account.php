<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_trusted_devices.php';

$error=null;$success=null;$user=null;$sessions=[];$attempts=[];$csrf='';$trustedCurrent=null;$trustedCount=0;

function account_client_label(string $ua): string
{
    $platform='Unknown device';$browser='Browser';
    if(stripos($ua,'Android')!==false)$platform='Android';
    elseif(stripos($ua,'iPhone')!==false)$platform='iPhone';
    elseif(stripos($ua,'iPad')!==false)$platform='iPad';
    elseif(stripos($ua,'Windows')!==false)$platform='Windows';
    elseif(stripos($ua,'Macintosh')!==false||stripos($ua,'Mac OS')!==false)$platform='Mac';
    elseif(stripos($ua,'Linux')!==false)$platform='Linux';
    if(stripos($ua,'Edg/')!==false)$browser='Microsoft Edge';
    elseif(stripos($ua,'Chrome/')!==false)$browser='Chrome';
    elseif(stripos($ua,'Firefox/')!==false)$browser='Firefox';
    elseif(stripos($ua,'Safari/')!==false)$browser='Safari';
    return $platform.' • '.$browser;
}
function account_when(?string $value): string
{
    if(!$value)return '—';
    $t=strtotime($value);return $t?date('d M Y, h:i A',$t):$value;
}

try{
    $pdo=role_portal_db();role_trusted_ensure($pdo);security_step17_session_start();
    $user=security_step17_session_user($pdo,true);
    if(!$user){header('Location: login.php');exit;}
    if((int)($user['must_change_password']??0)===1){header('Location: change_password.php?required=1');exit;}
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$currentSid=(int)($user['session_row_id']??0);$csrf=security_step17_csrf();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='profile'){
            $name=trim((string)($_POST['full_name']??''));$mobile=trim((string)($_POST['mobile']??''));
            if(strlen($name)<2||strlen($name)>150)throw new RuntimeException('Enter a valid name between 2 and 150 characters.');
            if(strlen($mobile)>30)throw new RuntimeException('Mobile number is too long.');
            $pdo->prepare('UPDATE system_users SET full_name=?,mobile=? WHERE id=?')->execute([$name,$mobile!==''?$mobile:null,(int)$user['id']]);
            security_step17_audit($pdo,(int)$user['id'],'security_profile_updated','system_user',(int)$user['id'],['fields'=>['full_name','mobile']]);
            $success='Profile updated. Your email/Login ID and role remain Administrator-controlled.';
        }elseif($action==='revoke_session'){
            $sid=(int)($_POST['session_id']??0);
            if($sid<=0)throw new RuntimeException('Session is invalid.');
            if($sid===$currentSid)throw new RuntimeException('Use Sign Out to close the device you are currently using.');
            $stmt=$pdo->prepare('SELECT id FROM security_sessions WHERE id=? AND organization_id=? AND user_id=? AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1');
            $stmt->execute([$sid,$orgId,(int)$user['id']]);
            if(!(int)$stmt->fetchColumn())throw new RuntimeException('That session is already closed or does not belong to your account.');
            $pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='User revoked own device session' WHERE id=? AND organization_id=? AND user_id=?")->execute([$sid,$orgId,(int)$user['id']]);
            security_step17_audit($pdo,(int)$user['id'],'security_self_session_revoked','security_session',$sid,['current_session'=>false]);
            $success='Selected device session was signed out.';
        }elseif($action==='revoke_others'){
            $stmt=$pdo->prepare("UPDATE security_sessions SET revoked_at=NOW(),revoke_reason='User signed out other devices' WHERE organization_id=? AND user_id=? AND id<>? AND revoked_at IS NULL AND expires_at>NOW()");
            $stmt->execute([$orgId,(int)$user['id'],$currentSid]);$count=$stmt->rowCount();
            security_step17_audit($pdo,(int)$user['id'],'security_other_sessions_revoked','system_user',(int)$user['id'],['revoked_count'=>$count,'current_session_preserved'=>true]);
            $success=$count>0?$count.' other active session(s) signed out.':'No other active sessions were found.';
        }
        $user=security_step17_session_user($pdo,true);
    }

    $trustedCurrent=role_trusted_current($pdo,$orgId,(int)$user['id'],true);$trustedCount=role_trusted_active_count($pdo,$orgId,(int)$user['id']);
    $stmt=$pdo->prepare("SELECT s.*,(s.id=?) is_current FROM security_sessions s WHERE s.organization_id=? AND s.user_id=? ORDER BY (s.revoked_at IS NULL AND s.expires_at>NOW()) DESC,s.last_seen_at DESC,s.id DESC LIMIT 60");
    $stmt->execute([$currentSid,$orgId,(int)$user['id']]);$sessions=$stmt->fetchAll();

    $identifierHash=security_step17_identifier_hash((string)$user['email']);
    $stmt=$pdo->prepare("SELECT id,ip_address,was_successful,failure_reason,attempted_at FROM security_login_attempts WHERE organization_id=? AND identifier_hash=? ORDER BY id DESC LIMIT 40");
    $stmt->execute([$orgId,$identifierHash]);$attempts=$stmt->fetchAll();
}catch(Throwable $e){$error=$e->getMessage();}

$home=$user?role_portal_home($user):'login.php';$role=strtoupper((string)($user['role_code']??''));
$activeCount=0;foreach($sessions as $s){if($s['revoked_at']===null&&strtotime((string)$s['expires_at'])>time())$activeCount++;}
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>My Account - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"><style>
.account-layout{display:grid;grid-template-columns:minmax(300px,.72fr) minmax(0,1.28fr);gap:14px}.account-section{border:1px solid var(--auth-line);border-radius:18px;background:rgba(255,255,255,.9);box-shadow:0 9px 27px rgba(36,72,52,.055);padding:19px}.account-section.mint{background:linear-gradient(145deg,#fff,var(--auth-mint))}.account-section.blue{background:linear-gradient(145deg,#fff,var(--auth-blue))}.account-section.lav{background:linear-gradient(145deg,#fff,var(--auth-lav))}.account-section.full{grid-column:1/-1}.account-section h2{margin:0;font-size:1.04rem}.account-section>p{margin:6px 0 0;color:var(--auth-muted);font-size:.78rem;line-height:1.55}.session-list{display:grid;gap:9px;margin-top:13px}.session-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:12px;border:1px solid #dfe8e3;border-radius:13px;background:rgba(255,255,255,.82)}.session-title{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.8rem}.session-meta{margin-top:5px;color:#6f7f77;font-size:.7rem;line-height:1.55}.small-pill{display:inline-flex;padding:5px 8px;border:1px solid #d7e6de;border-radius:999px;background:#eef9f3;color:#2f6c4d;font-size:.62rem;font-weight:850}.small-pill.muted{background:#f2f4f5;color:#68766f}.small-pill.warn{background:#fff6df;color:#7a6128}.inline-form{margin:0;align-self:center}.danger-soft{min-height:38px;padding:0 11px;border:1px solid #e5cfd2;border-radius:10px;background:#fff4f4;color:#8a474c;font-weight:800;cursor:pointer}.attempt-wrap{overflow:auto;margin-top:13px;border:1px solid #dfe8e3;border-radius:13px;background:#fff}.attempt-table{width:100%;min-width:680px;border-collapse:collapse;font-size:.73rem}.attempt-table th,.attempt-table td{padding:10px;border-bottom:1px solid #e9efec;text-align:left}.attempt-table th{background:#f7faf8;color:#74837b;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em}.attempt-table tr:last-child td{border-bottom:0}.account-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}@media(max-width:850px){.account-layout{grid-template-columns:1fr}.account-section.full{grid-column:auto}.session-item{grid-template-columns:1fr}.inline-form{justify-self:start}}
</style></head><body class="auth-page"><header class="auth-top"><a class="auth-brand" href="<?=$home?>"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Profile • Login History • Devices</small></span></a><nav class="auth-nav"><a class="auth-btn" href="<?=$home?>">My Portal</a><a class="auth-btn" href="trusted_devices.php">Trusted Devices</a><a class="auth-btn" href="security_alerts.php">Alerts</a><a class="auth-btn" href="change_password.php">Password</a><a class="auth-btn" href="logout.php">Sign Out</a></nav></header><main class="auth-shell"><section class="portal-head"><div><span class="role-badge"><?=security_step17_h($role)?></span><h1>My Account & Security</h1><p>Manage your profile, login sessions and trusted-device security. Role and Login ID remain controlled by the Administrator.</p></div><div class="account-list" style="min-width:220px"><div class="account-row"><span>Active sessions</span><b><?=$activeCount?></b></div><div class="account-row"><span>Trusted devices</span><b><?=$trustedCount?></b></div><div class="account-row"><span>Last login</span><b><?=security_step17_h(account_when((string)($user['last_login_at']??'')))?></b></div></div></section><?php if($error):?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif;?><?php if($success):?><div class="auth-alert good"><?=security_step17_h($success)?></div><?php endif;?>
<div class="account-layout"><section class="account-section mint"><h2>Profile</h2><p>You can update your display name and mobile number. Email/Login ID and role cannot be self-edited.</p><form class="auth-form" method="post"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><input type="hidden" name="action" value="profile"><div class="auth-field"><label>Full Name</label><input class="auth-input" name="full_name" value="<?=security_step17_h((string)($user['full_name']??''))?>" required maxlength="150"></div><div class="auth-field"><label>Mobile</label><input class="auth-input" name="mobile" value="<?=security_step17_h((string)($user['mobile']??''))?>" maxlength="30" inputmode="tel"></div><div class="auth-field"><label>Email / Login ID</label><input class="auth-input" value="<?=security_step17_h((string)($user['email']??''))?>" readonly aria-readonly="true"></div><div class="auth-field"><label>Role</label><input class="auth-input" value="<?=security_step17_h($role)?>" readonly aria-readonly="true"></div><button class="auth-submit">Save Profile</button></form></section>
<section class="account-section blue"><h2>Security Controls</h2><p>Keep the current device active while signing out sessions you no longer recognise or use.</p><div class="account-list"><div class="account-row"><span>Password</span><b>Protected by secure hash</b></div><div class="account-row"><span>Current session</span><b>#<?=(int)($user['session_row_id']??0)?></b></div><div class="account-row"><span>This browser</span><b><?=$trustedCurrent?'TRUSTED':'NOT TRUSTED'?></b></div><div class="account-row"><span>Session policy</span><b>Idle + absolute expiry</b></div></div><div class="account-actions"><a class="auth-btn primary" href="trusted_devices.php"><?=$trustedCurrent?'Manage Trusted Devices':'Trust This Device'?></a><a class="auth-btn" href="security_alerts.php">Security Alerts</a><a class="auth-btn" href="change_password.php">Change Password</a><form method="post" onsubmit="return confirm('Sign out every other active device while keeping this device signed in?')"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><input type="hidden" name="action" value="revoke_others"><button class="auth-btn" type="submit">Sign Out Other Devices</button></form></div><div class="auth-security"><strong>Current device safety:</strong> trusted-device recognition never bypasses your password or Administrator lock. You can revoke trust separately from the current login session.</div></section>
<section class="account-section lav full"><h2>Active & Recent Devices</h2><p>Sessions are server-verified. A revoked session stops working on its next protected request.</p><div class="session-list"><?php foreach($sessions as $s):$active=$s['revoked_at']===null&&strtotime((string)$s['expires_at'])>time();$current=(int)$s['is_current']===1;?><div class="session-item"><div><div class="session-title"><strong><?=security_step17_h(account_client_label((string)($s['user_agent']??'')))?></strong><?php if($current):?><span class="small-pill">CURRENT DEVICE</span><?php elseif($active):?><span class="small-pill">ACTIVE</span><?php else:?><span class="small-pill muted">CLOSED</span><?php endif;?></div><div class="session-meta">IP: <?=security_step17_h((string)($s['ip_address']?:'—'))?> • Signed in: <?=security_step17_h(account_when((string)$s['created_at']))?> • Last seen: <?=security_step17_h(account_when((string)$s['last_seen_at']))?> • Expires: <?=security_step17_h(account_when((string)$s['expires_at']))?><?php if($s['revoke_reason']):?><br>Closed reason: <?=security_step17_h((string)$s['revoke_reason'])?><?php endif;?></div></div><?php if($active&&!$current):?><form method="post" class="inline-form" onsubmit="return confirm('Sign out this device session?')"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><input type="hidden" name="action" value="revoke_session"><input type="hidden" name="session_id" value="<?=(int)$s['id']?>"><button class="danger-soft" type="submit">Sign Out Device</button></form><?php endif;?></div><?php endforeach;?><?php if(!$sessions):?><div class="empty-state">No session history recorded yet.</div><?php endif;?></div></section>
<section class="account-section full"><h2>Login History</h2><p>Recent successful and failed attempts for your Login ID. Passwords are never stored in this history.</p><div class="attempt-wrap"><table class="attempt-table"><thead><tr><th>Time</th><th>Result</th><th>IP</th><th>Reason</th></tr></thead><tbody><?php foreach($attempts as $a):?><tr><td><?=security_step17_h(account_when((string)$a['attempted_at']))?></td><td><span class="small-pill <?=(int)$a['was_successful']===1?'':'warn'?>"><?=(int)$a['was_successful']===1?'SUCCESS':'FAILED'?></span></td><td><?=security_step17_h((string)($a['ip_address']?:'—'))?></td><td><?=security_step17_h((string)($a['failure_reason']?:'—'))?></td></tr><?php endforeach;?><?php if(!$attempts):?><tr><td colspan="4" class="empty-state">No login attempts recorded yet.</td></tr><?php endif;?></tbody></table></div></section></div></main></body></html>
