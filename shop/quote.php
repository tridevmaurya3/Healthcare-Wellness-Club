<?php
declare(strict_types=1);
require_once __DIR__ . '/../business/config/customer_membership.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'message'=>'POST required.']);exit;}
try{
    $pdo=ps23_db();cm_ensure($pdo);$ctx=ps23_context($pdo);$cart=json_decode((string)($_POST['cart_json']??'[]'),true);if(!is_array($cart))$cart=[];$mode=(string)($_POST['delivery_mode']??'club_pickup');$q=cm_cart_quote($pdo,(int)$ctx['organization_id'],$cart,$mode);
    $items=[];foreach($q['items'] as $it)$items[]=['id'=>(int)$it['id'],'sku'=>(string)$it['sku'],'name'=>(string)$it['product_name'],'qty'=>(int)$it['qty'],'mrp'=>(float)$it['mrp'],'unit_price'=>(float)$it['unit_customer_price'],'line_mrp'=>(float)$it['line_mrp'],'line_price'=>(float)$it['line_customer_price'],'discount'=>(float)$it['line_discount'],'vp'=>(float)$it['volume_points'],'line_vp'=>(float)$it['line_vp'],'pricing_source'=>(string)$it['pricing_source'],'promotion_code'=>$it['promotion_code']?:null];
    echo json_encode(['ok'=>true,'items'=>$items,'subtotal_mrp'=>$q['subtotal_mrp'],'subtotal_customer'=>$q['subtotal_customer'],'discount_amount'=>$q['discount_amount'],'total_vp'=>$q['total_vp'],'delivery_charge'=>$q['delivery_charge'],'estimated_total'=>$q['estimated_total'],'delivery_mode'=>$q['delivery_mode'],'customer_price_mode'=>$q['customer_price_mode'],'discount_label_code'=>$q['discount_label_code'],'is_member'=>(bool)$q['customer_context']['is_member']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
