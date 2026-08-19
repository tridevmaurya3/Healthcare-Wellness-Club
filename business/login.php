<?php
declare(strict_types=1);
$target='../login.php';
if(isset($_GET['logged_out']))$target.='?logged_out=1';
header('Location: '.$target, true, 302);
exit;
