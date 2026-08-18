<?php
declare(strict_types=1);
require_once __DIR__ . '/product_step12.php';

function product_step12_bridge_week(string $date): string
{
    try{$d=new DateTimeImmutable($date);$week=(int)ceil(((int)$d->format('j'))/7);return 'Week-'.min(4,max(1,$week));}
    catch(Throwable){return 'Week-1';}
}

function product_step12_bridge_vp(PDO $pdo, int $orderId, bool $active=true): void
{
    $ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $stmt=$pdo->prepare("SELECT o.id,o.member_id,o.order_date,o.volume_points,o.source_record_id,m.full_name member_name,q.id quote_id,q.quote_code,q.customer_name FROM orders o LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id LEFT JOIN product_quote_order_links l ON l.organization_id=o.organization_id AND l.order_id=o.id LEFT JOIN product_quotes q ON q.id=l.quote_id AND q.organization_id=o.organization_id WHERE o.organization_id=? AND o.id=? AND o.order_type='product_sale' LIMIT 1");
    $stmt->execute([$orgId,$orderId]);$o=$stmt->fetch();if(!$o)return;
    $name=trim((string)($o['member_name']?:$o['customer_name']?:'Product Sale Customer'));$sheet=$active?'Manual Entry':(defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed');$key='product-sale-vp:'.$orderId;
    $notes=product_step12_json(['bridge'=>'Product Sale VP -> six live derived reports','order_id'=>$orderId,'quote_id'=>(int)($o['quote_id']??0),'quote_code'=>(string)($o['quote_code']??''),'source_code'=>'PRODUCT-SALES','manual_edit_allowed'=>false]);
    $stmt=$pdo->prepare("INSERT INTO volume_point_entries(organization_id,club_id,member_id,member_name_snapshot,entry_date,level_label,week_label,volume_points,order_type,vp_from,ordered_by,vp_type,order_set,notes,source_record_id,source_sheet,source_key) VALUES(?,?,?,?,?,NULL,?,?,?,?,?,?,NULL,?,?,?,?,?) ON DUPLICATE KEY UPDATE member_id=VALUES(member_id),member_name_snapshot=VALUES(member_name_snapshot),entry_date=VALUES(entry_date),week_label=VALUES(week_label),volume_points=VALUES(volume_points),order_type=VALUES(order_type),vp_from=VALUES(vp_from),ordered_by=VALUES(ordered_by),vp_type=VALUES(vp_type),notes=VALUES(notes),source_record_id=VALUES(source_record_id),source_sheet=VALUES(source_sheet)");
    $stmt->execute([$orgId,$clubId,$o['member_id']!==null?(int)$o['member_id']:null,$name,(string)$o['order_date'],product_step12_bridge_week((string)$o['order_date']),$active?(float)$o['volume_points']:0,'Product Sale','Product & Price Pro',$name,'Product Sale VP',$notes,$o['source_record_id']!==null?(int)$o['source_record_id']:null,$sheet,$key]);
}

function product_step12_bridge_backfill(PDO $pdo): void
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("SELECT o.id,o.source_sheet,l.quote_id FROM orders o JOIN product_quote_order_links l ON l.organization_id=o.organization_id AND l.order_id=o.id LEFT JOIN product_sale_ledger sl ON sl.organization_id=o.organization_id AND sl.order_id=o.id WHERE o.organization_id=? AND o.order_type='product_sale' AND sl.id IS NULL ORDER BY o.id");$stmt->execute([$orgId]);
    foreach($stmt->fetchAll() as $r){$cancelled=(string)$r['source_sheet']===PRODUCT_SALE_REVERSED_SOURCE_SHEET;$pdo->prepare("INSERT IGNORE INTO product_sale_ledger(organization_id,order_id,quote_id,sale_status,payment_status,paid_amount,cost_status,finalized_at) SELECT organization_id,id,? ,?,'unpaid',0,'deferred',created_at FROM orders WHERE organization_id=? AND id=?")->execute([(int)$r['quote_id'],$cancelled?'cancelled':'active',$orgId,(int)$r['id']]);product_step12_recalculate_sale_profit($pdo,(int)$r['id']);product_step12_bridge_vp($pdo,(int)$r['id'],!$cancelled);}
    $stmt=$pdo->prepare("SELECT o.id,sl.sale_status FROM orders o JOIN product_sale_ledger sl ON sl.organization_id=o.organization_id AND sl.order_id=o.id WHERE o.organization_id=? AND o.order_type='product_sale'");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r)product_step12_bridge_vp($pdo,(int)$r['id'],$r['sale_status']==='active');
}

function product_step12_bridge_finalize(PDO $pdo, int $quoteId, ?int $memberId, string $orderDate): int
{
    $orderId=product_step12_finalize_quote($pdo,$quoteId,$memberId,$orderDate);product_step12_bridge_vp($pdo,$orderId,true);return $orderId;
}

function product_step12_bridge_cancel(PDO $pdo, int $orderId, string $reason): void
{
    $ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $stmt=$pdo->prepare("SELECT gross_amount,discount_amount,net_amount,profit_amount,volume_points FROM orders WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$before=$stmt->fetch()?:[];
    product_step12_cancel_sale($pdo,$orderId,$reason);
    $pdo->prepare("UPDATE orders SET gross_amount=0,discount_amount=0,net_amount=0,profit_amount=0,volume_points=0 WHERE organization_id=? AND id=? AND source_sheet=?")->execute([$orgId,$orderId,PRODUCT_SALE_REVERSED_SOURCE_SHEET]);
    product_step12_bridge_vp($pdo,$orderId,false);
    product_step12_audit($pdo,$orgId,$clubId,'product_sale_business_effect_removed','order',$orderId,['reason'=>trim($reason),'effective_before'=>$before,'effective_after'=>['gross_amount'=>0,'discount_amount'=>0,'net_amount'=>0,'profit_amount'=>0,'volume_points'=>0],'evidence_preserved_in'=>'quote + product_order_items + raw_source + lifecycle']);
}

function product_step12_bridge_restore(PDO $pdo, int $orderId, string $reason): void
{
    product_step12_restore_sale($pdo,$orderId,$reason);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $stmt=$pdo->prepare("SELECT q.* FROM product_quotes q JOIN product_quote_order_links l ON l.organization_id=q.organization_id AND l.quote_id=q.id WHERE l.organization_id=? AND l.order_id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$q=$stmt->fetch();if(!$q)throw new RuntimeException('Original quote snapshot was not found for restore.');
    $gross=(float)$q['subtotal_mrp']+(float)$q['delivery_charge'];$discount=(float)$q['saving_amount'];$net=(float)$q['grand_total'];$vp=(float)$q['total_vp'];
    $pdo->prepare("UPDATE orders SET source_sheet=?,gross_amount=?,discount_amount=?,net_amount=?,volume_points=? WHERE organization_id=? AND id=?")->execute([PRODUCT_SALE_SOURCE_SHEET,$gross,$discount,$net,$vp,$orgId,$orderId]);$profit=product_step12_recalculate_sale_profit($pdo,$orderId);product_step12_bridge_vp($pdo,$orderId,true);
    product_step12_audit($pdo,$orgId,$clubId,'product_sale_business_effect_restored','order',$orderId,['reason'=>trim($reason),'gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>$net,'volume_points'=>$vp,'cost_status'=>$profit['status'],'profit_total'=>$profit['profit_total']]);
}
