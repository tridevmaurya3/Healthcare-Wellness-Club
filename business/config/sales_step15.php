<?php
declare(strict_types=1);

require_once __DIR__ . '/product_step12_bridge.php';

const SALES_STEP15_SOURCE_CODE = 'CUSTOMER-SALES';

function sales_step15_tables(): array
{
    return [
        'crm_customers','crm_customer_addresses','sales_customer_links','sales_invoices','sales_deliveries',
        'sales_delivery_items','sales_returns','sales_return_items','sales_refunds','crm_interactions','sales_fulfillment_ledger',
    ];
}

function sales_step15_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/011_step15_customer_crm_fulfillment.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 15 migration is missing.');
    $sql = (string)file_get_contents($file);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        $statement = preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement) ?? $statement;
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^USE\s+/i', $statement)) continue;
        $pdo->exec($statement);
    }
}

function sales_step15_ensure(PDO $pdo): void
{
    inventory_step13_ensure($pdo);
    foreach (sales_step15_tables() as $table) {
        if (!business_table_exists($pdo, $table)) { sales_step15_run_migration($pdo); break; }
    }
    foreach (sales_step15_tables() as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException('STEP 15 table missing: ' . $table);
    }
    if (business_table_exists($pdo,'schema_meta')) {
        $pdo->exec("INSERT INTO schema_meta(meta_key,meta_value) VALUES('sales_step15_version','1.0-complete') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $pdo->exec("INSERT IGNORE INTO schema_meta(meta_key,meta_value) VALUES('sales_step15_activated_at',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))");
    }
}

function sales_step15_context(PDO $pdo): array
{
    sales_step15_ensure($pdo);
    return product_step12_context($pdo);
}

function sales_step15_source(PDO $pdo, int $orgId): int
{
    $stmt=$pdo->prepare("INSERT INTO data_sources(organization_id,source_code,source_name,source_type,is_active) VALUES(?,?,'Customer CRM & Sales Fulfillment','manual',1) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_type=VALUES(source_type),is_active=1");
    $stmt->execute([$orgId,SALES_STEP15_SOURCE_CODE]);
    $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code=? LIMIT 1");$stmt->execute([$orgId,SALES_STEP15_SOURCE_CODE]);$id=(int)$stmt->fetchColumn();
    if($id<=0)throw new RuntimeException('Customer Sales data source could not be prepared.');return $id;
}

function sales_step15_json(array $data): string
{
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if($json===false)throw new RuntimeException('STEP 15 JSON payload could not be encoded.');return $json;
}

function sales_step15_raw_event(PDO $pdo,int $orgId,int $clubId,string $dataset,string $externalId,array $payload,string $entityType,?int $entityId=null): int
{
    $sourceId=sales_step15_source($pdo,$orgId);$raw=sales_step15_json($payload);
    $stmt=$pdo->prepare("INSERT INTO raw_source_records(organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id) VALUES(?,?,?,?,?,NOW(),?,?,'mapped',?,?)");
    $stmt->execute([$orgId,$clubId,$sourceId,$dataset,$externalId,hash('sha256',$raw),$raw,$entityType,$entityId]);return(int)$pdo->lastInsertId();
}

function sales_step15_audit(PDO $pdo,int $orgId,int $clubId,string $event,string $entityType,?int $entityId,array $details): void
{
    if(!business_table_exists($pdo,'audit_logs'))return;
    $stmt=$pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$clubId,$event,$entityType,$entityId,sales_step15_json($details),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
}

function sales_step15_date(?string $value,string $label,bool $blank=false): ?string
{
    $value=trim((string)$value);if($value===''&&$blank)return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d||$d->format('Y-m-d')!==$value)throw new RuntimeException('Choose a valid '.$label.'.');return $value;
}

function sales_step15_code(string $prefix): string
{
    return $prefix.'-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));
}

function sales_step15_customer(PDO $pdo,int $orgId,int $customerId): array
{
    $stmt=$pdo->prepare("SELECT * FROM crm_customers WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$customerId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Customer was not found.');return $row;
}

function sales_step15_customer_address_text(PDO $pdo,int $orgId,int $customerId): string
{
    $stmt=$pdo->prepare("SELECT * FROM crm_customer_addresses WHERE organization_id=? AND customer_id=? AND status='active' ORDER BY is_default DESC,id DESC LIMIT 1");$stmt->execute([$orgId,$customerId]);$a=$stmt->fetch();if(!$a)return '';
    return implode(', ',array_filter([(string)$a['address_line1'],(string)($a['address_line2']??''),(string)($a['city']??''),(string)($a['district']??''),(string)($a['state_name']??''),(string)($a['postal_code']??'')]));
}

function sales_step15_save_customer(PDO $pdo,int $customerId,array $input): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $name=trim((string)($input['customer_name']??''));if($name==='')throw new RuntimeException('Customer name is required.');
    $memberRaw=trim((string)($input['member_id']??''));$memberId=$memberRaw===''?null:(int)$memberRaw;
    if($memberId!==null){$stmt=$pdo->prepare("SELECT id FROM members WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$memberId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Verified member link was not found.');}
    $type=(string)($input['customer_type']??'retail');if(!in_array($type,['retail','preferred','associate','member','other'],true))$type='other';
    $status=in_array(($input['status']??'active'),['active','inactive'],true)?(string)$input['status']:'active';
    $mobile=trim((string)($input['mobile']??''));$email=trim((string)($input['email']??''));$notes=trim((string)($input['notes']??''));
    if($customerId>0){$old=sales_step15_customer($pdo,$orgId,$customerId);$stmt=$pdo->prepare("UPDATE crm_customers SET member_id=?,customer_name=?,mobile=?,email=?,customer_type=?,notes=?,status=? WHERE organization_id=? AND id=?");$stmt->execute([$memberId,$name,$mobile?:null,$email?:null,$type,$notes?:null,$status,$orgId,$customerId]);$event='crm_customer_updated';$code=(string)$old['customer_code'];}
    else{$code=sales_step15_code('CUS');$stmt=$pdo->prepare("INSERT INTO crm_customers(organization_id,member_id,customer_code,customer_name,mobile,email,customer_type,notes,status) VALUES(?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$memberId,$code,$name,$mobile?:null,$email?:null,$type,$notes?:null,$status]);$customerId=(int)$pdo->lastInsertId();$event='crm_customer_created';}
    $rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'CRM Customer','crm-customer-'.$customerId.'-'.time(),['customer_id'=>$customerId,'customer_code'=>$code,'customer_name'=>$name,'member_id'=>$memberId,'mobile'=>$mobile,'customer_type'=>$type,'status'=>$status],'crm_customer',$customerId);
    sales_step15_audit($pdo,$orgId,$clubId,$event,'crm_customer',$customerId,['customer_code'=>$code,'member_id'=>$memberId,'raw_source_id'=>$rawId]);return $customerId;
}

function sales_step15_save_address(PDO $pdo,int $customerId,array $input): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];sales_step15_customer($pdo,$orgId,$customerId);
    $line1=trim((string)($input['address_line1']??''));if($line1==='')throw new RuntimeException('Address line 1 is required.');$isDefault=!empty($input['is_default']);
    if($isDefault)$pdo->prepare("UPDATE crm_customer_addresses SET is_default=0 WHERE organization_id=? AND customer_id=?")->execute([$orgId,$customerId]);
    $stmt=$pdo->prepare("INSERT INTO crm_customer_addresses(organization_id,customer_id,address_type,address_line1,address_line2,city,district,state_name,postal_code,country_code,is_default,status) VALUES(?,?,?,?,?,?,?,?,?,'IN',?,'active')");
    $stmt->execute([$orgId,$customerId,trim((string)($input['address_type']??'delivery'))?:'delivery',$line1,trim((string)($input['address_line2']??''))?:null,trim((string)($input['city']??''))?:null,trim((string)($input['district']??''))?:null,trim((string)($input['state_name']??''))?:null,trim((string)($input['postal_code']??''))?:null,$isDefault?1:0]);$id=(int)$pdo->lastInsertId();
    $rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'CRM Address','crm-address-'.$id,['address_id'=>$id,'customer_id'=>$customerId,'address'=>sales_step15_customer_address_text($pdo,$orgId,$customerId)],'crm_customer_address',$id);sales_step15_audit($pdo,$orgId,$clubId,'crm_customer_address_added','crm_customer_address',$id,['customer_id'=>$customerId,'raw_source_id'=>$rawId]);return $id;
}

function sales_step15_customers(PDO $pdo,int $orgId): array
{
    $stmt=$pdo->prepare("SELECT c.*,m.full_name member_name,COALESCE(x.sales_count,0) sales_count,COALESCE(x.original_charge,0) original_charge,COALESCE(x.receivable,0) receivable,COALESCE(x.credit_due,0) credit_due FROM crm_customers c LEFT JOIN members m ON m.id=c.member_id AND m.organization_id=c.organization_id LEFT JOIN (SELECT customer_id,COUNT(*) sales_count,SUM(original_charge) original_charge,SUM(receivable_amount) receivable,SUM(customer_credit_due) credit_due FROM sales_fulfillment_ledger WHERE organization_id=? AND customer_id IS NOT NULL GROUP BY customer_id) x ON x.customer_id=c.id WHERE c.organization_id=? ORDER BY c.customer_name,c.id");$stmt->execute([$orgId,$orgId]);return $stmt->fetchAll();
}

function sales_step15_order(PDO $pdo,int $orgId,int $orderId): array
{
    $stmt=$pdo->prepare("SELECT o.*,sl.sale_status,sl.payment_status,sl.paid_amount,q.customer_name quote_customer,q.quote_code FROM orders o JOIN product_sale_ledger sl ON sl.organization_id=o.organization_id AND sl.order_id=o.id LEFT JOIN product_quote_order_links ql ON ql.organization_id=o.organization_id AND ql.order_id=o.id LEFT JOIN product_quotes q ON q.id=ql.quote_id AND q.organization_id=o.organization_id WHERE o.organization_id=? AND o.id=? AND o.order_type='product_sale' LIMIT 1");$stmt->execute([$orgId,$orderId]);$o=$stmt->fetch();if(!$o)throw new RuntimeException('Product Sale order was not found.');return $o;
}

function sales_step15_link_customer(PDO $pdo,int $orderId,?int $customerId): void
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$o=sales_step15_order($pdo,$orgId,$orderId);
    $name=trim((string)($o['quote_customer']??''));$mobile='';$email='';$address='';
    if($customerId!==null){$c=sales_step15_customer($pdo,$orgId,$customerId);if($c['status']!=='active')throw new RuntimeException('Choose an active customer.');$name=(string)$c['customer_name'];$mobile=(string)($c['mobile']??'');$email=(string)($c['email']??'');$address=sales_step15_customer_address_text($pdo,$orgId,$customerId);}
    $stmt=$pdo->prepare("INSERT INTO sales_customer_links(organization_id,order_id,customer_id,customer_name_snapshot,mobile_snapshot,email_snapshot,address_snapshot) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),customer_name_snapshot=VALUES(customer_name_snapshot),mobile_snapshot=VALUES(mobile_snapshot),email_snapshot=VALUES(email_snapshot),address_snapshot=VALUES(address_snapshot),linked_at=NOW()");$stmt->execute([$orgId,$orderId,$customerId,$name?:null,$mobile?:null,$email?:null,$address?:null]);
    $rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Sale Customer Link','sale-customer-link-'.$orderId.'-'.time(),['order_id'=>$orderId,'customer_id'=>$customerId,'customer_name_snapshot'=>$name],'order',$orderId);sales_step15_audit($pdo,$orgId,$clubId,'sale_customer_linked','order',$orderId,['customer_id'=>$customerId,'raw_source_id'=>$rawId]);sales_step15_sync_ledger($pdo,$orderId);
}

function sales_step15_backfill(PDO $pdo): void
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("SELECT o.id,q.customer_name FROM orders o JOIN product_sale_ledger sl ON sl.organization_id=o.organization_id AND sl.order_id=o.id LEFT JOIN product_quote_order_links ql ON ql.organization_id=o.organization_id AND ql.order_id=o.id LEFT JOIN product_quotes q ON q.id=ql.quote_id AND q.organization_id=o.organization_id LEFT JOIN sales_customer_links cl ON cl.organization_id=o.organization_id AND cl.order_id=o.id WHERE o.organization_id=? AND o.order_type='product_sale' AND cl.id IS NULL ORDER BY o.id");$stmt->execute([$orgId]);
    foreach($stmt->fetchAll() as $r){$pdo->prepare("INSERT IGNORE INTO sales_customer_links(organization_id,order_id,customer_id,customer_name_snapshot) VALUES(?,?,NULL,?)")->execute([$orgId,(int)$r['id'],trim((string)($r['customer_name']??''))?:null]);sales_step15_sync_ledger($pdo,(int)$r['id']);}
    $stmt=$pdo->prepare("SELECT order_id FROM product_sale_ledger WHERE organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)sales_step15_sync_ledger($pdo,(int)$id);
}

function sales_step15_sync_ledger(PDO $pdo,int $orderId): array
{
    sales_step15_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$o=sales_step15_order($pdo,$orgId,$orderId);
    $stmt=$pdo->prepare("SELECT customer_id FROM sales_customer_links WHERE organization_id=? AND order_id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$customerId=$stmt->fetchColumn();$customerId=$customerId===false||$customerId===null?null:(int)$customerId;
    $stmt=$pdo->prepare("SELECT id FROM sales_invoices WHERE organization_id=? AND order_id=? AND status='active' LIMIT 1");$stmt->execute([$orgId,$orderId]);$invoiceId=$stmt->fetchColumn();$invoiceId=$invoiceId? (int)$invoiceId:null;
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(total_credit),0) FROM sales_returns WHERE organization_id=? AND order_id=? AND status='posted'");$stmt->execute([$orgId,$orderId]);$returnCredit=round((float)$stmt->fetchColumn(),2);
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM product_sale_payments WHERE organization_id=? AND order_id=? AND status='active'");$stmt->execute([$orgId,$orderId]);$grossCollected=round((float)$stmt->fetchColumn(),2);
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_refunds WHERE organization_id=? AND order_id=? AND status='active'");$stmt->execute([$orgId,$orderId]);$refund=round((float)$stmt->fetchColumn(),2);
    $original=round((float)$o['net_amount'],2);$effective=max(0,round($original-$returnCredit,2));$netCollected=max(0,round($grossCollected-$refund,2));$receivable=max(0,round($effective-$netCollected,2));$creditDue=max(0,round($netCollected-$effective,2));
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(di.quantity_dispatched),0) FROM sales_delivery_items di JOIN sales_deliveries d ON d.id=di.delivery_id AND d.organization_id=di.organization_id WHERE di.organization_id=? AND d.order_id=? AND d.status IN ('dispatched','delivered')");$stmt->execute([$orgId,$orderId]);$dispatched=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(di.quantity_dispatched),0) FROM sales_delivery_items di JOIN sales_deliveries d ON d.id=di.delivery_id AND d.organization_id=di.organization_id WHERE di.organization_id=? AND d.order_id=? AND d.status='delivered'");$stmt->execute([$orgId,$orderId]);$delivered=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_order_items WHERE organization_id=? AND order_id=?");$stmt->execute([$orgId,$orderId]);$sold=(float)$stmt->fetchColumn();
    $deliveryStatus=$delivered+0.0005>=$sold&&$sold>0?'delivered':($dispatched+0.0005>=$sold&&$sold>0?'dispatched':($dispatched>0?'partial':'pending'));
    $stmt=$pdo->prepare("INSERT INTO sales_fulfillment_ledger(organization_id,order_id,customer_id,invoice_id,delivery_status,original_charge,return_credit,gross_collected,refund_amount,effective_charge,net_collected,receivable_amount,customer_credit_due) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),invoice_id=VALUES(invoice_id),delivery_status=VALUES(delivery_status),original_charge=VALUES(original_charge),return_credit=VALUES(return_credit),gross_collected=VALUES(gross_collected),refund_amount=VALUES(refund_amount),effective_charge=VALUES(effective_charge),net_collected=VALUES(net_collected),receivable_amount=VALUES(receivable_amount),customer_credit_due=VALUES(customer_credit_due)");
    $stmt->execute([$orgId,$orderId,$customerId,$invoiceId,$deliveryStatus,$original,$returnCredit,$grossCollected,$refund,$effective,$netCollected,$receivable,$creditDue]);
    return ['customer_id'=>$customerId,'invoice_id'=>$invoiceId,'delivery_status'=>$deliveryStatus,'original_charge'=>$original,'return_credit'=>$returnCredit,'gross_collected'=>$grossCollected,'refund'=>$refund,'effective_charge'=>$effective,'net_collected'=>$netCollected,'receivable'=>$receivable,'credit_due'=>$creditDue];
}

function sales_step15_generate_invoice(PDO $pdo,int $orderId,string $invoiceDate): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$invoiceDate=(string)sales_step15_date($invoiceDate,'invoice date');$o=sales_step15_order($pdo,$orgId,$orderId);if($o['sale_status']!=='active')throw new RuntimeException('Only an active Product Sale can generate an invoice.');
    $stmt=$pdo->prepare("SELECT id FROM sales_invoices WHERE organization_id=? AND order_id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$existing=(int)$stmt->fetchColumn();if($existing>0)return $existing;
    $stmt=$pdo->prepare("SELECT * FROM sales_customer_links WHERE organization_id=? AND order_id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$link=$stmt->fetch()?:[];$invoiceNo=sales_step15_code('INV');
    $stmt=$pdo->prepare("INSERT INTO sales_invoices(organization_id,order_id,customer_id,invoice_number,invoice_date,customer_name_snapshot,mobile_snapshot,address_snapshot,gross_amount,discount_amount,tax_amount,net_amount,volume_points,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,0,?,?,?,'active')");
    $stmt->execute([$orgId,$orderId,$link['customer_id']??null,$invoiceNo,$invoiceDate,$link['customer_name_snapshot']??$o['quote_customer'],$link['mobile_snapshot']??null,$link['address_snapshot']??null,(float)$o['gross_amount'],(float)$o['discount_amount'],(float)$o['net_amount'],(float)$o['volume_points'],'Original Product Sale snapshot; tax not inferred by STEP 15.']);$id=(int)$pdo->lastInsertId();
    $rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Sales Invoice','sales-invoice-'.$id,['invoice_id'=>$id,'invoice_number'=>$invoiceNo,'order_id'=>$orderId,'invoice_date'=>$invoiceDate,'gross'=>(float)$o['gross_amount'],'discount'=>(float)$o['discount_amount'],'tax'=>0,'net'=>(float)$o['net_amount'],'vp'=>(float)$o['volume_points']],'sales_invoice',$id);$pdo->prepare("UPDATE sales_invoices SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);sales_step15_audit($pdo,$orgId,$clubId,'sales_invoice_generated','sales_invoice',$id,['order_id'=>$orderId,'raw_source_id'=>$rawId]);sales_step15_sync_ledger($pdo,$orderId);return $id;
}

function sales_step15_dispatch_remaining(PDO $pdo,int $orderId,string $dispatchDate,string $mode,string $carrier='',string $tracking='',string $notes=''): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$dispatchDate=(string)sales_step15_date($dispatchDate,'dispatch date');$o=sales_step15_order($pdo,$orgId,$orderId);if($o['sale_status']!=='active')throw new RuntimeException('Only an active Product Sale can be dispatched.');
    $allowed=['club_pickup','local_delivery','courier','other'];if(!in_array($mode,$allowed,true))$mode='other';
    $stmt=$pdo->prepare("SELECT i.id order_item_id,i.product_id,i.quantity,COALESCE(x.dispatched,0) dispatched FROM product_order_items i LEFT JOIN (SELECT di.order_item_id,SUM(di.quantity_dispatched) dispatched FROM sales_delivery_items di JOIN sales_deliveries d ON d.id=di.delivery_id AND d.organization_id=di.organization_id WHERE di.organization_id=? AND d.order_id=? AND d.status IN ('dispatched','delivered') GROUP BY di.order_item_id) x ON x.order_item_id=i.id WHERE i.organization_id=? AND i.order_id=? ORDER BY i.id");$stmt->execute([$orgId,$orderId,$orgId,$orderId]);$remaining=[];foreach($stmt->fetchAll() as $i){$r=round((float)$i['quantity']-(float)$i['dispatched'],3);if($r>0.0005)$remaining[]=['order_item_id'=>(int)$i['order_item_id'],'product_id'=>(int)$i['product_id'],'qty'=>$r];}
    if(!$remaining)throw new RuntimeException('No undispatched product quantity remains for this sale.');$stmt=$pdo->prepare("SELECT customer_id FROM sales_customer_links WHERE organization_id=? AND order_id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$cid=$stmt->fetchColumn();$cid=$cid===false?null:$cid;
    $pdo->beginTransaction();try{$dispatchNo=sales_step15_code('DSP');$stmt=$pdo->prepare("INSERT INTO sales_deliveries(organization_id,order_id,customer_id,dispatch_number,dispatch_date,delivery_mode,carrier_name,tracking_number,status,notes) VALUES(?,?,?,?,?,?,?,?, 'dispatched',?)");$stmt->execute([$orgId,$orderId,$cid,$dispatchNo,$dispatchDate,$mode,trim($carrier)?:null,trim($tracking)?:null,trim($notes)?:null]);$deliveryId=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO sales_delivery_items(organization_id,delivery_id,order_item_id,product_id,quantity_dispatched) VALUES(?,?,?,?,?)");foreach($remaining as $r)$ins->execute([$orgId,$deliveryId,$r['order_item_id'],$r['product_id'],$r['qty']]);$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Sales Dispatch','sales-dispatch-'.$deliveryId,['delivery_id'=>$deliveryId,'dispatch_number'=>$dispatchNo,'order_id'=>$orderId,'dispatch_date'=>$dispatchDate,'mode'=>$mode,'carrier'=>trim($carrier),'tracking'=>trim($tracking),'items'=>$remaining],'sales_delivery',$deliveryId);$pdo->prepare("UPDATE sales_deliveries SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$deliveryId]);sales_step15_audit($pdo,$orgId,$clubId,'sales_dispatch_created','sales_delivery',$deliveryId,['order_id'=>$orderId,'raw_source_id'=>$rawId]);$pdo->commit();sales_step15_sync_ledger($pdo,$orderId);return $deliveryId;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sales_step15_set_delivery_status(PDO $pdo,int $deliveryId,string $status,?string $deliveredDate=null,string $notes=''): void
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$allowed=['dispatched','delivered','failed','cancelled'];if(!in_array($status,$allowed,true))throw new RuntimeException('Delivery status is invalid.');if($status==='delivered')$deliveredDate=(string)sales_step15_date($deliveredDate?:date('Y-m-d'),'delivered date');else$deliveredDate=null;
    $stmt=$pdo->prepare("SELECT * FROM sales_deliveries WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$deliveryId]);$d=$stmt->fetch();if(!$d)throw new RuntimeException('Delivery was not found.');$pdo->prepare("UPDATE sales_deliveries SET status=?,delivered_date=?,notes=CASE WHEN ?<>'' THEN CONCAT(COALESCE(notes,''),' | ',?) ELSE notes END WHERE organization_id=? AND id=?")->execute([$status,$deliveredDate,trim($notes),trim($notes),$orgId,$deliveryId]);$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Delivery Status','delivery-status-'.$deliveryId.'-'.time(),['delivery_id'=>$deliveryId,'order_id'=>(int)$d['order_id'],'status'=>$status,'delivered_date'=>$deliveredDate,'notes'=>trim($notes)],'sales_delivery',$deliveryId);sales_step15_audit($pdo,$orgId,$clubId,'sales_delivery_status_changed','sales_delivery',$deliveryId,['status'=>$status,'raw_source_id'=>$rawId]);sales_step15_sync_ledger($pdo,(int)$d['order_id']);
}

function sales_step15_returnable_allocations(PDO $pdo,int $orgId): array
{
    $stmt=$pdo->prepare("SELECT a.id allocation_id,a.order_id,a.order_item_id,a.batch_id,a.quantity allocated_quantity,COALESCE(r.returned,0) returned_quantity,(a.quantity-COALESCE(r.returned,0)) returnable_quantity,i.product_id,i.stock_no,i.product_name_snapshot,b.batch_code,b.current_quantity batch_stock,o.order_date,q.customer_name,cl.customer_name_snapshot,c.customer_name linked_customer FROM inventory_sale_allocations a JOIN product_order_items i ON i.id=a.order_item_id AND i.organization_id=a.organization_id JOIN inventory_batches b ON b.id=a.batch_id AND b.organization_id=a.organization_id JOIN orders o ON o.id=a.order_id AND o.organization_id=a.organization_id JOIN product_sale_ledger sl ON sl.order_id=o.id AND sl.organization_id=o.organization_id LEFT JOIN product_quote_order_links ql ON ql.order_id=o.id AND ql.organization_id=o.organization_id LEFT JOIN product_quotes q ON q.id=ql.quote_id AND q.organization_id=o.organization_id LEFT JOIN sales_customer_links cl ON cl.order_id=o.id AND cl.organization_id=o.organization_id LEFT JOIN crm_customers c ON c.id=cl.customer_id AND c.organization_id=o.organization_id LEFT JOIN (SELECT inventory_allocation_id,SUM(quantity_returned) returned FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.sales_return_id AND sr.organization_id=sri.organization_id WHERE sri.organization_id=? AND sr.status='posted' GROUP BY inventory_allocation_id) r ON r.inventory_allocation_id=a.id WHERE a.organization_id=? AND a.status='active' AND sl.sale_status='active' AND a.quantity-COALESCE(r.returned,0)>0.0005 ORDER BY o.order_date DESC,a.id DESC");$stmt->execute([$orgId,$orgId]);return $stmt->fetchAll();
}

function sales_step15_post_return(PDO $pdo,int $allocationId,float $qty,string $returnDate,float $creditAmount,string $reason): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$returnDate=(string)sales_step15_date($returnDate,'return date');if($qty<=0)throw new RuntimeException('Return quantity must be greater than zero.');if($creditAmount<0)throw new RuntimeException('Customer return credit cannot be negative.');if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear customer return reason.');
    $stmt=$pdo->prepare("SELECT a.*,i.product_id,i.product_name_snapshot,i.stock_no,b.batch_code,sl.sale_status,cl.customer_id FROM inventory_sale_allocations a JOIN product_order_items i ON i.id=a.order_item_id AND i.organization_id=a.organization_id JOIN inventory_batches b ON b.id=a.batch_id AND b.organization_id=a.organization_id JOIN product_sale_ledger sl ON sl.order_id=a.order_id AND sl.organization_id=a.organization_id LEFT JOIN sales_customer_links cl ON cl.order_id=a.order_id AND cl.organization_id=a.organization_id WHERE a.organization_id=? AND a.id=? AND a.status='active' LIMIT 1");$stmt->execute([$orgId,$allocationId]);$a=$stmt->fetch();if(!$a||$a['sale_status']!=='active')throw new RuntimeException('Active sale allocation was not found.');
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(sri.quantity_returned),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.sales_return_id AND sr.organization_id=sri.organization_id WHERE sri.organization_id=? AND sri.inventory_allocation_id=? AND sr.status='posted'");$stmt->execute([$orgId,$allocationId]);$already=(float)$stmt->fetchColumn();$remaining=round((float)$a['quantity']-$already,3);if($qty>$remaining+0.0005)throw new RuntimeException('Return quantity exceeds the still-returnable sold quantity.');
    $inventoryTx=inventory_step13_adjust_batch($pdo,(int)$a['batch_id'],$qty,$returnDate,'customer_return','Product Sale #'.(int)$a['order_id'],trim($reason));
    try{$pdo->beginTransaction();$returnNo=sales_step15_code('CRN');$stmt=$pdo->prepare("INSERT INTO sales_returns(organization_id,order_id,customer_id,return_number,return_date,reason,total_credit,status) VALUES(?,?,?,?,?,?,?,'posted')");$stmt->execute([$orgId,(int)$a['order_id'],$a['customer_id']??null,$returnNo,$returnDate,trim($reason),round($creditAmount,2)]);$returnId=(int)$pdo->lastInsertId();$stmt=$pdo->prepare("INSERT INTO sales_return_items(organization_id,sales_return_id,order_item_id,inventory_allocation_id,product_id,quantity_returned,credit_amount,inventory_transaction_id) VALUES(?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$returnId,(int)$a['order_item_id'],$allocationId,(int)$a['product_id'],$qty,round($creditAmount,2),$inventoryTx]);$returnItemId=(int)$pdo->lastInsertId();$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Customer Return','customer-return-'.$returnId,['return_id'=>$returnId,'return_number'=>$returnNo,'order_id'=>(int)$a['order_id'],'allocation_id'=>$allocationId,'product_id'=>(int)$a['product_id'],'quantity'=>$qty,'credit_amount'=>round($creditAmount,2),'inventory_transaction_id'=>$inventoryTx,'reason'=>trim($reason)],'sales_return',$returnId);$pdo->prepare("UPDATE sales_returns SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$returnId]);$pdo->prepare("UPDATE inventory_transactions SET reference_type='sales_return_item',reference_id=?,source_reference=? WHERE organization_id=? AND id=?")->execute([$returnItemId,$returnNo,$orgId,$inventoryTx]);sales_step15_audit($pdo,$orgId,$clubId,'customer_return_posted','sales_return',$returnId,['order_id'=>(int)$a['order_id'],'quantity'=>$qty,'credit_amount'=>round($creditAmount,2),'raw_source_id'=>$rawId]);$pdo->commit();sales_step15_sync_ledger($pdo,(int)$a['order_id']);return $returnId;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();try{inventory_step13_adjust_batch($pdo,(int)$a['batch_id'],-$qty,$returnDate,'adjustment_minus','STEP15 return compensation','Customer return database posting failed; stock restoration compensated.');}catch(Throwable){}throw $e;}
}

function sales_step15_add_refund(PDO $pdo,int $orderId,?int $returnId,string $date,float $amount,string $method,string $reference,string $reason): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$date=(string)sales_step15_date($date,'refund date');if($amount<=0)throw new RuntimeException('Refund amount must be greater than zero.');if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear refund reason.');$allowed=['cash','upi','bank','card','other'];if(!in_array($method,$allowed,true))$method='other';sales_step15_order($pdo,$orgId,$orderId);
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM product_sale_payments WHERE organization_id=? AND order_id=? AND status='active'");$stmt->execute([$orgId,$orderId]);$paid=round((float)$stmt->fetchColumn(),2);$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_refunds WHERE organization_id=? AND order_id=? AND status='active'");$stmt->execute([$orgId,$orderId]);$refunded=round((float)$stmt->fetchColumn(),2);if($amount>$paid-$refunded+0.01)throw new RuntimeException('Refund exceeds the customer payment still available to refund.');
    if($returnId!==null){$stmt=$pdo->prepare("SELECT total_credit FROM sales_returns WHERE organization_id=? AND id=? AND order_id=? AND status='posted' LIMIT 1");$stmt->execute([$orgId,$returnId,$orderId]);$credit=$stmt->fetchColumn();if($credit===false)throw new RuntimeException('Selected customer return does not belong to this sale.');$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_refunds WHERE organization_id=? AND sales_return_id=? AND status='active'");$stmt->execute([$orgId,$returnId]);$used=(float)$stmt->fetchColumn();if($amount>(float)$credit-$used+0.01)throw new RuntimeException('Refund exceeds the remaining Credit Note value for this return.');}
    $stmt=$pdo->prepare("INSERT INTO sales_refunds(organization_id,order_id,sales_return_id,refund_date,amount,refund_method,reference_no,reason,status) VALUES(?,?,?,?,?,?,?,?, 'active')");$stmt->execute([$orgId,$orderId,$returnId,$date,$amount,$method,trim($reference)?:null,trim($reason)]);$id=(int)$pdo->lastInsertId();$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Customer Refund','customer-refund-'.$id,['refund_id'=>$id,'order_id'=>$orderId,'return_id'=>$returnId,'refund_date'=>$date,'amount'=>$amount,'method'=>$method,'reference'=>trim($reference),'reason'=>trim($reason)],'sales_refund',$id);$pdo->prepare("UPDATE sales_refunds SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);sales_step15_audit($pdo,$orgId,$clubId,'customer_refund_added','sales_refund',$id,['order_id'=>$orderId,'amount'=>$amount,'raw_source_id'=>$rawId]);sales_step15_sync_ledger($pdo,$orderId);return $id;
}

function sales_step15_reverse_refund(PDO $pdo,int $refundId,string $reason): void
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear refund reversal reason.');$stmt=$pdo->prepare("SELECT * FROM sales_refunds WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$refundId]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Refund was not found.');if($r['status']!=='active')return;$pdo->prepare("UPDATE sales_refunds SET status='reversed',reason=CONCAT(reason,' | Reversed: ',?) WHERE organization_id=? AND id=?")->execute([trim($reason),$orgId,$refundId]);$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'Customer Refund Reversal','customer-refund-reversal-'.$refundId.'-'.time(),['refund_id'=>$refundId,'order_id'=>(int)$r['order_id'],'amount'=>(float)$r['amount'],'reason'=>trim($reason)],'sales_refund',$refundId);sales_step15_audit($pdo,$orgId,$clubId,'customer_refund_reversed','sales_refund',$refundId,['reason'=>trim($reason),'raw_source_id'=>$rawId]);sales_step15_sync_ledger($pdo,(int)$r['order_id']);
}

function sales_step15_add_interaction(PDO $pdo,int $customerId,?int $orderId,string $type,string $dateTime,string $subject,string $notes,?string $nextFollowup): int
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];sales_step15_customer($pdo,$orgId,$customerId);if(trim($notes)==='')throw new RuntimeException('Interaction notes are required.');$allowed=['call','whatsapp','visit','note','followup','complaint','support'];if(!in_array($type,$allowed,true))$type='note';
    $dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$dateTime);if(!$dt)throw new RuntimeException('Choose a valid interaction date/time.');$interactionAt=$dt->format('Y-m-d H:i:s');$follow=null;if(trim((string)$nextFollowup)!==''){$f=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',(string)$nextFollowup);if(!$f)throw new RuntimeException('Choose a valid next follow-up time.');$follow=$f->format('Y-m-d H:i:s');}
    if($orderId!==null)sales_step15_order($pdo,$orgId,$orderId);$status=$follow?'open':'done';$stmt=$pdo->prepare("INSERT INTO crm_interactions(organization_id,customer_id,order_id,interaction_type,interaction_date,subject,notes,next_followup_at,status) VALUES(?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$customerId,$orderId,$type,$interactionAt,trim($subject)?:null,trim($notes),$follow,$status]);$id=(int)$pdo->lastInsertId();$rawId=sales_step15_raw_event($pdo,$orgId,$clubId,'CRM Interaction','crm-interaction-'.$id,['interaction_id'=>$id,'customer_id'=>$customerId,'order_id'=>$orderId,'type'=>$type,'interaction_at'=>$interactionAt,'next_followup'=>$follow,'status'=>$status],'crm_interaction',$id);$pdo->prepare("UPDATE crm_interactions SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);sales_step15_audit($pdo,$orgId,$clubId,'crm_interaction_added','crm_interaction',$id,['customer_id'=>$customerId,'raw_source_id'=>$rawId]);return $id;
}

function sales_step15_complete_interaction(PDO $pdo,int $interactionId): void
{
    sales_step15_ensure($pdo);$ctx=sales_step15_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$stmt=$pdo->prepare("SELECT * FROM crm_interactions WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$interactionId]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('CRM interaction was not found.');$pdo->prepare("UPDATE crm_interactions SET status='done' WHERE organization_id=? AND id=?")->execute([$orgId,$interactionId]);sales_step15_audit($pdo,$orgId,$clubId,'crm_followup_completed','crm_interaction',$interactionId,['customer_id'=>(int)$r['customer_id']]);
}

function sales_step15_assert_cancellable(PDO $pdo,int $orderId): void
{
    if(!business_table_exists($pdo,'sales_returns'))return;$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$stmt=$pdo->prepare("SELECT (SELECT COUNT(*) FROM sales_returns WHERE organization_id=? AND order_id=? AND status='posted') returns_count,(SELECT COUNT(*) FROM sales_refunds WHERE organization_id=? AND order_id=? AND status='active') refunds_count,(SELECT COUNT(*) FROM sales_deliveries WHERE organization_id=? AND order_id=? AND status='delivered') delivered_count");$stmt->execute([$orgId,$orderId,$orgId,$orderId,$orgId,$orderId]);$r=$stmt->fetch()?:[];if((int)($r['returns_count']??0)>0||(int)($r['refunds_count']??0)>0||(int)($r['delivered_count']??0)>0)throw new RuntimeException('This sale has STEP 15 delivery/return/refund history and cannot be cancelled. Use the after-sales workflow so stock and customer balances stay traceable.');
}

function sales_step15_finalize_quote(PDO $pdo,int $quoteId,?int $memberId,string $orderDate): int
{
    $orderId=product_step12_bridge_finalize($pdo,$quoteId,$memberId,$orderDate);sales_step15_ensure($pdo);sales_step15_backfill($pdo);sales_step15_sync_ledger($pdo,$orderId);return $orderId;
}

function sales_step15_cancel_sale(PDO $pdo,int $orderId,string $reason): void
{
    sales_step15_assert_cancellable($pdo,$orderId);product_step12_bridge_cancel($pdo,$orderId,$reason);if(business_table_exists($pdo,'sales_invoices'))$pdo->prepare("UPDATE sales_invoices SET status='cancelled' WHERE organization_id=? AND order_id=?")->execute([(int)product_step12_context($pdo)['organization_id'],$orderId]);if(business_table_exists($pdo,'sales_fulfillment_ledger'))sales_step15_sync_ledger($pdo,$orderId);
}

function sales_step15_restore_sale(PDO $pdo,int $orderId,string $reason): void
{
    product_step12_bridge_restore($pdo,$orderId,$reason);sales_step15_ensure($pdo);$pdo->prepare("UPDATE sales_invoices SET status='active' WHERE organization_id=? AND order_id=?")->execute([(int)product_step12_context($pdo)['organization_id'],$orderId]);sales_step15_backfill($pdo);sales_step15_sync_ledger($pdo,$orderId);
}

function sales_step15_sales_rows(PDO $pdo,int $orgId): array
{
    sales_step15_backfill($pdo);$stmt=$pdo->prepare("SELECT f.*,o.order_date,o.source_sheet,sl.sale_status,sl.payment_status,q.quote_code,COALESCE(c.customer_name,cl.customer_name_snapshot,q.customer_name,'Unlinked customer') customer_name,si.invoice_number FROM sales_fulfillment_ledger f JOIN orders o ON o.id=f.order_id AND o.organization_id=f.organization_id JOIN product_sale_ledger sl ON sl.order_id=o.id AND sl.organization_id=o.organization_id LEFT JOIN sales_customer_links cl ON cl.order_id=o.id AND cl.organization_id=o.organization_id LEFT JOIN crm_customers c ON c.id=cl.customer_id AND c.organization_id=o.organization_id LEFT JOIN product_quote_order_links ql ON ql.order_id=o.id AND ql.organization_id=o.organization_id LEFT JOIN product_quotes q ON q.id=ql.quote_id AND q.organization_id=o.organization_id LEFT JOIN sales_invoices si ON si.id=f.invoice_id AND si.organization_id=f.organization_id WHERE f.organization_id=? ORDER BY o.order_date DESC,o.id DESC");$stmt->execute([$orgId]);return $stmt->fetchAll();
}
