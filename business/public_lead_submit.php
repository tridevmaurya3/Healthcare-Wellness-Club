<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/lead_step20.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'message'=>'Method not allowed.']);
    exit;
}

try {
    $pdo=business_db();
    $result=lead_step20_public_capture($pdo,$_POST,$_SERVER);
    http_response_code(200);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Your enquiry could not be saved right now. Please try again shortly.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
