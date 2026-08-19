<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_trusted_devices.php';

$error=null;$user=null;$orgId=0;$currentTrusted=null;$activeTrusted=0;$historyTrusted=0;$cookiePresent=false;$cookieParsed=false;$recentEvents=[];
function tdd_when(?string $v):string{if(!$v)return '—';$t=strtotime($v);return $t?date('d M Y, h:i A',$t):$v;}
function tdd_label(string $v):string{return strtoupper(str_replace('_',' ',$v));}
try{
    $pdo=role_portal_db();role_trusted_ensure($pdo);security_step17_session_start();
    $user=security_step17_session_user($pdo,true);
    if(!$user){header('Location: login.php');exit;}
    if((int)($user['must_change_password']??0)===1){header('Location: change_password.php?required=1');exit;}
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$userId=(int)$user['id'];
    $cookieName=role_trusted_cookie_name($userId);
    $cookiePresent=array_key_exists($cookieName,$_COOKIE) && (string)$_COOKIE[$cookieName]!=='';
    $cookieParsed=role_trusted_cookie_parts($userId)!==null;
    $currentTrusted=role_trusted_current($pdo,$orgId,$userId,false);
    $activeTrusted=role_trusted_active_count($pdo,$orgId,$userId);
    $historyTrusted=role_trusted_history_count($pdo,$orgId,$userId);
    if(business_table_exists($pdo,'audit_logs')){
        $stmt=$pdo->prepare("SELECT event_type,details_json,ip_address,created_at FROM audit_logs WHERE organization_id=? AND user_id=? AND event_type IN ('security_login_success','security_trusted_device_login','security_untrusted_device_login','security_alert_created') ORDER BY id DESC LIMIT 12");
        $stmt->execute([$orgId,$userId]);$recentEvents=$stmt->fetchAll();
    }
}catch(Throwable $e){$error=$e->getMessage();}
$home=$user?role_portal_home($user):'login.php';
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Trusted Device Diagnostics - Healthcare Wellness Club</title><link rel="stylesheet" href="pages/auth.css"><style>
.diag-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.diag-card{border:1px solid var(--auth-line);border-radius:18px;background:#fff;padding:18px}.diag-card.full{grid-column:1/-1}.diag-card h2{margin:0;font-size:1rem}.diag-list{display:grid;gap:8px;margin-top:12px}.diag-row{display:flex;justify-content:space-between;gap:16px;padding:10px 12px;border:1px solid #dfe8e3;border-radius:12px;background:#f9fbfa;font-size:.78rem}.diag-row b{text-align:right}.event{padding:11px;border:1px solid #e1e9e4;border-radius:12px;background:#fff;margin-top:8px}.event strong{font-size:.77rem}.event small{display:block;margin-top:5px;color:#6f7f77;line-height:1.5}.ok{color:#2f6c4d}.warn{color:#9a6720}@media(max-width:760px){.diag-grid{grid-template-columns:1fr}.diag-card.full{grid-column:auto}.diag-row{flex-direction:column}.diag-row b{text-align:left}}</style></head><body class="auth-page"><header class="auth-top"><a class="auth-brand" href="<?=$home?>"><img src="img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Trusted Device Diagnostics</small></span></a><nav class="auth-nav"><a class="auth-btn" href="trusted_devices.php">Trusted Devices</a><a class="auth-btn" href="security_alerts.php">Security Alerts</a><a class="auth-btn" href="<?=$home?>">My Portal</a></nav></header><main class="auth-shell"><section class="portal-head"><div><span class="role-badge">DIAGNOSTICS</span><h1>Trusted Device Recognition Check</h1><p>This page shows recognition state only. It never displays the trusted-device secret token or its hash.</p></div></section><?php if($error):?><div class="auth-alert" role="alert"><?=security_step17_h($error)?></div><?php endif;?>
<div class="diag-grid"><section class="diag-card"><h2>Current Browser Signal</h2><div class="diag-list"><div class="diag-row"><span>Trusted cookie present</span><b class="<?=$cookiePresent?'ok':'warn'?>"><?=$cookiePresent?'YES':'NO'?></b></div><div class="diag-row"><span>Cookie format valid</span><b class="<?=$cookieParsed?'ok':'warn'?>"><?=$cookieParsed?'YES':'NO'?></b></div><div class="diag-row"><span>Valid trusted-device match</span><b class="<?=$currentTrusted?'ok':'warn'?>"><?=$currentTrusted?'YES':'NO'?></b></div><div class="diag-row"><span>Current browser label</span><b><?=security_step17_h(role_trusted_default_label((string)($_SERVER['HTTP_USER_AGENT']??'')))?></b></div></div></section>
<section class="diag-card"><h2>Server Trusted-Device Records</h2><div class="diag-list"><div class="diag-row"><span>Active trusted devices</span><b><?=$activeTrusted?></b></div><div class="diag-row"><span>Total trusted-device history</span><b><?=$historyTrusted?></b></div><div class="diag-row"><span>Expected recognition result</span><b><?php if($currentTrusted):?>TRUSTED LOGIN<?php elseif($historyTrusted>0):?>UNTRUSTED LOGIN ALERT<?php else:?>NO PRIOR TRUST HISTORY<?php endif;?></b></div><?php if($currentTrusted):?><div class="diag-row"><span>Matched trusted name</span><b><?=security_step17_h((string)$currentTrusted['device_label'])?></b></div><?php endif;?></div></section>
<section class="diag-card full"><h2>Recent Security Recognition Events</h2><p style="color:#6f7f77;font-size:.76rem">Newest first. Details are audit metadata only; no passwords or trusted-device secrets are stored here.</p><?php foreach($recentEvents as $e):?><div class="event"><strong><?=security_step17_h(tdd_label((string)$e['event_type']))?></strong><small><?=security_step17_h(tdd_when((string)$e['created_at']))?> • IP <?=security_step17_h((string)($e['ip_address']?:'—'))?></small><?php if($e['details_json']):?><small><?=security_step17_h((string)$e['details_json'])?></small><?php endif;?></div><?php endforeach;?><?php if(!$recentEvents):?><div class="event"><strong>No recognition audit events found yet.</strong></div><?php endif;?></section></div></main></body></html>
