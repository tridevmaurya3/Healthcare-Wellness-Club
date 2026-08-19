<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_portal_auth.php';
try{$pdo=role_portal_db();role_portal_ensure($pdo);security_step17_logout($pdo,'portal_user_logout');}catch(Throwable){security_step17_destroy_php_session();}
header('Location: login.php?logged_out=1');exit;
