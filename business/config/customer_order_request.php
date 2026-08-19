<?php
declare(strict_types=1);

require_once __DIR__ . '/public_store_step23.php';

const CUSTOMER_ORDER_REQUEST_VERSION = '1.0-review-first';

/**
 * Public/customer checkout is a request workflow, not a stock allocation.
 * We therefore keep the live availability snapshot for staff review, but do
 * not reject a request merely because current tracked quantity is zero/low.
 * Final sale, stock allocation and payment remain inside Business OS.
 */
function cor_cart_quote(PDO $pdo, int $orgId, array $cart, string $mode): array
{
    $mode = in_array($mode, ['club_pickup','home_delivery'], true) ? $mode : 'club_pickup';
    $items=[];
    $subtotal=0.0;
    $vp=0.0;
    $hasNutrition=false;
    $seen=[];
    $needsAvailabilityReview=false;

    foreach ($cart as $raw) {
        $id=(int)($raw['product_id']??0);
        $qty=(int)($raw['qty']??0);
        if ($id<=0 || $qty<=0 || $qty>20 || isset($seen[$id])) continue;
        $seen[$id]=1;

        $p=ps23_product($pdo,$orgId,$id);
        $av=ps23_availability($pdo,$orgId,(int)$p['listing_id'],$qty);
        if ($av['status']!=='available' || ($av['qty']!==null && (float)$av['qty']<$qty)) {
            $needsAvailabilityReview=true;
        }

        $line=round((float)$p['mrp']*$qty,2);
        $lineVp=round((float)$p['volume_points']*$qty,3);
        $subtotal+=$line;
        $vp+=$lineVp;
        if (!in_array(strtoupper((string)$p['category_name']),['ART OF PROMOTION','APPLICATIONS'],true)) {
            $hasNutrition=true;
        }
        $items[]=$p+[
            'qty'=>$qty,
            'line_mrp'=>$line,
            'line_vp'=>$lineVp,
            'availability_status'=>$av['status'],
        ];
    }

    if (!$items) throw new RuntimeException('Add at least one valid product.');

    $delivery=($mode==='home_delivery' && $hasNutrition && $vp>0 && $vp<100) ? 100.0 : 0.0;
    return [
        'items'=>$items,
        'subtotal_mrp'=>round($subtotal,2),
        'total_vp'=>round($vp,3),
        'delivery_charge'=>$delivery,
        'estimated_total'=>round($subtotal+$delivery,2),
        'delivery_mode'=>$mode,
        'availability_review_required'=>$needsAvailabilityReview,
    ];
}

function cor_submit(PDO $pdo, array $in, array $server): array
{
    ps23_ensure($pdo);
    $c=ps23_context($pdo);
    $orgId=(int)$c['organization_id'];
    $set=ps23_settings($pdo,$orgId);
    if (empty($set['storefront_enabled'])) throw new RuntimeException('Online product requests are temporarily unavailable.');
    if (!ps23_origin_ok($server)) throw new RuntimeException('Request origin is not allowed.');

    $name=trim((string)($in['name']??''));
    $mobile=ps23_mobile((string)($in['mobile']??''));
    $email=strtolower(trim((string)($in['email']??'')));
    $address=trim((string)($in['address']??''));
    $postal=trim((string)($in['postal_code']??''));
    $note=trim((string)($in['note']??''));
    $consent=(string)($in['consent']??'')==='1';
    $hp=trim((string)($in['website']??''));
    $started=(int)($in['started_at']??0);
    $tooFast=$started>0 && ((int)floor(microtime(true)*1000)-$started)<1500;
    $ipHash=ps23_ip_hash((string)($server['REMOTE_ADDR']??''));
    $ua=substr((string)($server['HTTP_USER_AGENT']??''),0,500);
    $cart=json_decode((string)($in['cart_json']??'[]'),true);
    if (!is_array($cart)) $cart=[];

    $rate=$pdo->prepare("SELECT COUNT(*) FROM public_checkout_attempts WHERE organization_id=? AND ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $rate->execute([$orgId,$ipHash]);
    if ((int)$rate->fetchColumn()>=8) throw new RuntimeException('Too many recent checkout attempts. Please try again later.');

    if ($hp!=='' || $tooFast) {
        $pdo->prepare("INSERT INTO public_checkout_attempts(organization_id,ip_hash,attempt_status,reason_code,cart_line_count) VALUES(?,?,'blocked',?,?)")
            ->execute([$orgId,$ipHash,$hp!==''?'honeypot':'too_fast',count($cart)]);
        return ['ok'=>true,'message'=>'Thanks. Your request has been received.'];
    }

    if (strlen($name)<2 || strlen($name)>190) throw new RuntimeException('Enter a valid name.');
    if (strlen($mobile)<10) throw new RuntimeException('Enter a valid mobile number.');
    if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
    if (!$consent) throw new RuntimeException('Please allow the club to contact you about this order request.');
    if (count($cart)>30) throw new RuntimeException('Cart has too many product lines.');

    $quote=cor_cart_quote($pdo,$orgId,$cart,(string)($in['delivery_mode']??'club_pickup'));
    if ($quote['delivery_mode']==='home_delivery' && strlen($address)<5) throw new RuntimeException('Enter a delivery address.');

    $dup=ps23_dup($mobile,$email);
    $token=bin2hex(random_bytes(24));
    $code='WEB-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));

    $pdo->beginTransaction();
    try {
        $leadId=ps23_link_lead($pdo,$orgId,$name,$mobile,$email,$note,$dup,$ipHash,$ua);
        $s=$pdo->prepare("INSERT INTO public_order_requests(organization_id,order_code,lead_id,customer_name,mobile,email,address_text,postal_code,delivery_mode,subtotal_mrp,total_vp,delivery_charge,estimated_total,tax_status,order_status,payment_status,consent_to_contact,duplicate_key_hash,status_token_hash,ip_hash,user_agent,source_path,customer_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'not_calculated','submitted','not_requested',1,?,?,?,?, 'shop/checkout.php',?)");
        $s->execute([$orgId,$code,$leadId,$name,$mobile,$email?:null,$address?:null,$postal?:null,$quote['delivery_mode'],$quote['subtotal_mrp'],$quote['total_vp'],$quote['delivery_charge'],$quote['estimated_total'],$dup,hash('sha256',$token),$ipHash,$ua?:null,$note?:null]);
        $oid=(int)$pdo->lastInsertId();

        $ins=$pdo->prepare("INSERT INTO public_order_request_items(organization_id,public_order_id,product_id,listing_id,price_version_id,stock_no,product_name_snapshot,quantity,unit_mrp,unit_vp,line_mrp,line_vp,availability_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($quote['items'] as $it) {
            $ins->execute([$orgId,$oid,$it['id'],$it['listing_id'],$it['price_version_id'],$it['sku'],$it['product_name'],$it['qty'],$it['mrp'],$it['volume_points'],$it['line_mrp'],$it['line_vp'],$it['availability_status']]);
        }

        $eventNote=!empty($quote['availability_review_required'])
            ? 'Customer order request captured. Product availability requires staff review; no payment collected.'
            : 'Customer order request captured; availability snapshot available for review; no payment collected.';
        $pdo->prepare("INSERT INTO public_order_events(organization_id,public_order_id,event_type,new_status,note) VALUES(?,?,'submitted','submitted',?)")
            ->execute([$orgId,$oid,$eventNote]);

        $pdo->prepare("INSERT INTO public_payment_requests(organization_id,public_order_id,payment_mode,amount,currency_code,payment_status) VALUES(?,?,?,?,'INR','not_requested')")
            ->execute([$orgId,$oid,(string)($set['payment_mode']??'review_only'),$quote['estimated_total']]);
        $pdo->prepare("INSERT INTO public_checkout_attempts(organization_id,ip_hash,attempt_status,cart_line_count) VALUES(?,?,'accepted',?)")
            ->execute([$orgId,$ipHash,count($quote['items'])]);

        $pdo->commit();
        return [
            'ok'=>true,
            'message'=>'Your order request has been saved. Your Coach/Administrator can now review availability and final confirmation.',
            'order_code'=>$code,
            'status_token'=>$token,
            'estimated_total'=>$quote['estimated_total'],
            'availability_review_required'=>(bool)$quote['availability_review_required'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
