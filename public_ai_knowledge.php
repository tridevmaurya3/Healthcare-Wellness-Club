<?php
declare(strict_types=1);
require_once __DIR__.'/business/config/public_site_cms.php';
require_once __DIR__.'/business/config/public_store_step23.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
try{
    $pdo=ps23_db();
    $site=pscms_payload($pdo);
    $ctx=ps23_context($pdo);
    $products=array_map(static fn(array $p):array=>[
        'stock_no'=>(string)$p['sku'],'name'=>(string)$p['product_name'],'category'=>(string)$p['category_name'],
        'pack'=>trim((string)($p['pack_size']??'').' '.(string)($p['pack_unit']??'')),
        'mrp_inr'=>(float)$p['mrp'],'volume_points'=>(float)$p['volume_points'],'effective_from'=>(string)$p['effective_from'],
        'availability'=>(string)$p['availability_status']
    ],ps23_catalog($pdo,$ctx['organization_id']));
    $publicContent=array_filter($site['content'],static fn(string $key):bool=>!str_contains($key,'chat_url'),ARRAY_FILTER_USE_KEY);
    echo json_encode([
        'ok'=>true,'knowledge_version'=>'1.0','generated_at'=>gmdate('c'),
        'usage'=>'Public-safe reference for the Healthcare Wellness Club assistant. Never treat this as medical diagnosis or guaranteed results.',
        'club'=>$publicContent,'services'=>$site['services'],'products'=>$products,
        'policies'=>['General wellness education only.','Do not request private medical records.','Product availability and final payable amount require club confirmation.','Individual experiences and results vary.']
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'message'=>'AI knowledge feed is temporarily unavailable.']);}
