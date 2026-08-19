<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_portal_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try{
    $pdo=role_portal_db();role_portal_ensure($pdo);$user=security_step17_session_user($pdo,false);
    if(!$user){echo json_encode(['authenticated'=>false],JSON_UNESCAPED_SLASHES);exit;}
    echo json_encode(['authenticated'=>true,'name'=>(string)$user['full_name'],'role'=>(string)$user['role_code'],'portal'=>((int)$user['must_change_password']===1?'change_password.php?required=1':role_portal_home($user))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable){echo json_encode(['authenticated'=>false],JSON_UNESCAPED_SLASHES);}
