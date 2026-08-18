<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/data_entry_smart.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST required.');
    }

    $sessionToken = (string)($_SESSION['business_entry_csrf'] ?? '');
    $requestToken = (string)($_POST['csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        throw new RuntimeException('Security token mismatch. Refresh Data Entry Center.');
    }

    $module = business_entry_smart_trim($_POST['module'] ?? '');
    if (!in_array($module, ['new_ums','vp','order','renewal','income','royalty'], true)) {
        throw new RuntimeException('Unknown entry module.');
    }

    $pdo = business_db();
    $organizationId = (int)$pdo->query("SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetchColumn();
    if ($organizationId <= 0) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    $result = business_entry_smart_duplicate($pdo, $organizationId, $module, $_POST);
    echo json_encode(['ok'=>true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'duplicate'=>false,'count'=>0,'message'=>$e->getMessage(),'matches'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
