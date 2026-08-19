<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/role_mfa.php';
try{$pdo=role_portal_db();role_mfa_ensure($pdo);role_mfa_logout($pdo,'portal_user_logout');}catch(Throwable){security_step17_destroy_php_session();}
header('Location: login.php?logged_out=1');exit;
