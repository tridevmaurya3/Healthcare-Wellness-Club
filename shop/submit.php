<?php
declare(strict_types=1);

require_once __DIR__ . '/../business/config/customer_order_request.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'POST required.']);
    exit;
}

try {
    $pdo=ps23_db();
    $result=cor_submit($pdo,$_POST,$_SERVER);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
