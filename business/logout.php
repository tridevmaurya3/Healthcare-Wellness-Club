<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
try{$pdo=business_db();security_step17_ensure($pdo);security_step17_logout($pdo,'user_logout');}catch(Throwable){security_step17_destroy_php_session();}
header('Location: login.php?logged_out=1');exit;
