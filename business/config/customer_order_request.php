<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_membership.php';

const CUSTOMER_ORDER_REQUEST_VERSION = '2.0-member-pricing';

/**
 * Customer/public checkout remains a request workflow, not a stock allocation.
 * Prices are recalculated from product IDs and quantity on the server. A signed-in
 * verified Club Member receives only the exact tier assigned by Admin/Coach, plus
 * any explicitly configured numeric promotion that is currently eligible.
 */
function cor_cart_quote(PDO $pdo, int $orgId, array $cart, string $mode): array
{
    return cm_cart_quote($pdo,$orgId,$cart,$mode);
}

function cor_submit(PDO $pdo, array $in, array $server): array
{
    cm_ensure($pdo);
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

    $signedIn=$quote['customer_context']['user']??null;
    if($signedIn){
        // A signed-in Customer request is bound to the account contact identity so a browser cannot submit member pricing for another person.
        $accountEmail=strtolower(trim((string)($signedIn['email']??'')));
        $accountMobile=ps23_mobile((string)($signedIn['mobile']??''));
        if($accountEmail!==''&&$email!==''&&!hash_equals($accountEmail,$email))throw new RuntimeException('Use the email connected to your signed-in Customer account.');
        if($accountMobile!==''&&$mobile!==''&&!hash_equals($accountMobile,$mobile))throw new RuntimeException('Use the mobile number connected to your signed-in Customer account.');
    }

    $dup=ps23_dup($mobile,$email);
    $token=bin2hex(random_bytes(24));
    $code='WEB-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));

    $pdo->beginTransaction();
    try {
        $leadId=ps23_link_lead($pdo,$orgId,$name,$mobile,$email,$note,$dup,$ipHash,$ua);
        $s=$pdo->prepare("INSERT INTO public_order_requests(
            organization_id,order_code,lead_id,customer_user_id,customer_membership_id,customer_price_mode,discount_label_code,
            customer_name,mobile,email,address_text,postal_code,delivery_mode,subtotal_mrp,discount_amount,total_vp,delivery_charge,estimated_total,
            tax_status,order_status,payment_status,consent_to_contact,duplicate_key_hash,status_token_hash,ip_hash,user_agent,source_path,customer_note
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'not_calculated','submitted','not_requested',1,?,?,?,?, 'shop/checkout.php',?)");
        $s->execute([
            $orgId,$code,$leadId,$quote['customer_user_id'],$quote['customer_membership_id'],$quote['customer_price_mode'],$quote['discount_label_code'],
            $name,$mobile,$email?:null,$address?:null,$postal?:null,$quote['delivery_mode'],$quote['subtotal_mrp'],$quote['discount_amount'],$quote['total_vp'],$quote['delivery_charge'],$quote['estimated_total'],
            $dup,hash('sha256',$token),$ipHash,$ua?:null,$note?:null
        ]);
        $oid=(int)$pdo->lastInsertId();

        $ins=$pdo->prepare("INSERT INTO public_order_request_items(
            organization_id,public_order_id,product_id,listing_id,price_version_id,stock_no,product_name_snapshot,quantity,
            unit_mrp,unit_customer_price,unit_vp,line_mrp,line_customer_price,discount_amount,line_vp,availability_status,pricing_source,promotion_code
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($quote['items'] as $it) {
            $ins->execute([
                $orgId,$oid,$it['id'],$it['listing_id'],$it['price_version_id'],$it['sku'],$it['product_name'],$it['qty'],
                $it['mrp'],$it['unit_customer_price'],$it['volume_points'],$it['line_mrp'],$it['line_customer_price'],$it['line_discount'],$it['line_vp'],$it['availability_status'],$it['pricing_source'],$it['promotion_code']
            ]);
        }

        $pricingNote=!empty($quote['customer_context']['is_member'])
            ? 'Verified Club Member pricing applied from assigned exact tier '.$quote['customer_context']['tier_code'].'.'
            : 'Regular/public MRP pricing applied.';
        $deliveryNote=$quote['delivery_mode']==='home_delivery'
            ? ((float)$quote['total_vp']<CUSTOMER_FREE_DELIVERY_VP?' Home delivery below 100 VP includes ₹118 delivery charge.':' Home delivery at 100 VP or more has ₹0 delivery charge.')
            : ' Club pickup selected.';
        $eventNote='Customer order request captured. '.$pricingNote.$deliveryNote.(!empty($quote['availability_review_required'])?' Product availability requires staff review.':'').' No payment collected.';
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
            'discount_amount'=>$quote['discount_amount'],
            'delivery_charge'=>$quote['delivery_charge'],
            'customer_price_mode'=>$quote['customer_price_mode'],
            'discount_label_code'=>$quote['discount_label_code'],
            'availability_review_required'=>(bool)$quote['availability_review_required'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
