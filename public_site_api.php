<?php
declare(strict_types=1);
require_once __DIR__ . '/business/config/public_site_cms.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
try{
    $pdo=role_portal_db();
    echo json_encode(['ok'=>true]+pscms_payload($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code(503);
    echo json_encode(['ok'=>false,'message'=>'Public site content is temporarily unavailable.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
