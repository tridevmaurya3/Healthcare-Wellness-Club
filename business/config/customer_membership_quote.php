<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_membership.php';

/**
 * Convert a customer request into an internal quote without losing the
 * server-verified customer/member price snapshot captured at checkout.
 */
function cm_create_request_quote(PDO $pdo,int $orgId,int $requestId,int $actorUserId): int
{
    cm_ensure($pdo);
    $o=ps23_order($pdo,$orgId,$requestId);
    if(!empty($o['quote_id']))return (int)$o['quote_id'];
    if((string)$o['order_status']==='cancelled')throw new RuntimeException('Cancelled request cannot create a quote.');

    $ctx=ps23_context($pdo);
    $code='QWEB-'.date('YmdHis').'-'.random_int(100,999);
    $discount=max(0,(float)($o['discount_amount']??0));
    $productPayable=max(0,round((float)$o['subtotal_mrp']-$discount,2));
    $delivery=(float)$o['delivery_charge'];
    $grand=round($productPayable+$delivery,2);
    $memberMode=str_starts_with((string)($o['customer_price_mode']??''),'member_');
    $customerType=$memberMode?'club_member':'public';
    $tier='MRP';
    if($memberMode&&!empty($o['customer_membership_id'])){
        $s=$pdo->prepare("SELECT l.pricing_tier_code FROM customer_membership_profiles m LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id WHERE m.organization_id=? AND m.id=? LIMIT 1");
        $s->execute([$orgId,(int)$o['customer_membership_id']]);$candidate=(string)($s->fetchColumn()?:'');if($candidate!=='')$tier=$candidate;
    }

    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("INSERT INTO product_quotes(organization_id,market_id,quote_code,customer_name,customer_type,pricing_tier_code,subtotal_mrp,payable_amount,saving_amount,total_vp,delivery_charge,grand_total,status,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'saved',?)");
        $note='Created from customer order request '.$o['order_code'].'. Customer price snapshot preserved from server-verified checkout. Final availability, tax and payment remain staff-confirmed.';
        $s->execute([$orgId,$ctx['market_id'],$code,$o['customer_name'],$customerType,$tier,$o['subtotal_mrp'],$productPayable,$discount,$o['total_vp'],$delivery,$grand,$note]);
        $qid=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO product_quote_items(quote_id,product_id,listing_id,price_version_id,stock_no,product_name,quantity,unit_mrp,unit_price,unit_vp,line_mrp,line_price,line_vp) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($o['items'] as $it){
            $unitCustomer=$it['unit_customer_price']!==null?(float)$it['unit_customer_price']:(float)$it['unit_mrp'];
            $lineCustomer=$it['line_customer_price']!==null?(float)$it['line_customer_price']:(float)$it['line_mrp'];
            $ins->execute([$qid,$it['product_id'],$it['listing_id'],$it['price_version_id'],$it['stock_no'],$it['product_name_snapshot'],$it['quantity'],$it['unit_mrp'],$unitCustomer,$it['unit_vp'],$it['line_mrp'],$lineCustomer,$it['line_vp']]);
        }
        $pdo->prepare("UPDATE public_order_requests SET quote_id=?,order_status='quote_ready',reviewed_by=?,reviewed_at=NOW() WHERE organization_id=? AND id=?")->execute([$qid,$actorUserId,$orgId,$requestId]);
        $event='Internal customer-price quote '.$code.' created; saved request pricing was preserved.';
        $pdo->prepare("INSERT INTO public_order_events(organization_id,public_order_id,event_type,old_status,new_status,note,actor_user_id) VALUES(?,?,'quote_created',?,'quote_ready',?,?)")->execute([$orgId,$requestId,$o['order_status'],$event,$actorUserId]);
        $pdo->commit();
        return $qid;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
