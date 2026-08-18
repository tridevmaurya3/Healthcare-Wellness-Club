<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_step13.php';

const PURCHASE_STEP14_SOURCE_CODE = 'PURCHASES';

function purchase_step14_tables(): array
{
    return [
        'purchase_suppliers','purchase_orders','purchase_order_items','purchase_bills','purchase_bill_items',
        'purchase_receipts','purchase_receipt_items','purchase_payments','purchase_returns','purchase_return_items',
    ];
}

function purchase_step14_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/010_step14_complete_purchase_supplier.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 14 purchase migration is missing.');
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

function purchase_step14_ensure(PDO $pdo): void
{
    inventory_step13_ensure($pdo);
    foreach (purchase_step14_tables() as $table) {
        if (!business_table_exists($pdo, $table)) {
            purchase_step14_run_migration($pdo);
            break;
        }
    }
    foreach (purchase_step14_tables() as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException('STEP 14 table missing: ' . $table);
    }
    if (business_table_exists($pdo, 'schema_meta')) {
        $pdo->exec("INSERT INTO schema_meta(meta_key,meta_value) VALUES('purchase_step14_version','1.0-complete') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $pdo->exec("INSERT IGNORE INTO schema_meta(meta_key,meta_value) VALUES('purchase_step14_activated_at',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))");
    }
}

function purchase_step14_context(PDO $pdo): array
{
    purchase_step14_ensure($pdo);
    return inventory_step13_context($pdo);
}

function purchase_step14_source(PDO $pdo, int $organizationId): int
{
    $stmt = $pdo->prepare("INSERT INTO data_sources(organization_id,source_code,source_name,source_type,is_active) VALUES(?,?,'Purchase & Supplier Management','manual',1) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_type=VALUES(source_type),is_active=1");
    $stmt->execute([$organizationId, PURCHASE_STEP14_SOURCE_CODE]);
    $stmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code=? LIMIT 1");
    $stmt->execute([$organizationId, PURCHASE_STEP14_SOURCE_CODE]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Purchase data source could not be prepared.');
    return $id;
}

function purchase_step14_json(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Purchase JSON payload could not be encoded.');
    return $json;
}

function purchase_step14_raw_event(PDO $pdo, int $orgId, int $clubId, string $dataset, string $externalId, array $payload, string $entityType, ?int $entityId=null): int
{
    $sourceId = purchase_step14_source($pdo, $orgId);
    $rawJson = purchase_step14_json($payload);
    $stmt = $pdo->prepare("INSERT INTO raw_source_records(organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id) VALUES(?,?,?,?,?,NOW(),?,?,'mapped',?,?)");
    $stmt->execute([$orgId,$clubId,$sourceId,$dataset,$externalId,hash('sha256',$rawJson),$rawJson,$entityType,$entityId]);
    return (int)$pdo->lastInsertId();
}

function purchase_step14_audit(PDO $pdo, int $orgId, int $clubId, string $event, string $entityType, ?int $entityId, array $details): void
{
    if (!business_table_exists($pdo,'audit_logs')) return;
    $stmt=$pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$orgId,$clubId,$event,$entityType,$entityId,purchase_step14_json($details),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
}

function purchase_step14_date(?string $value, string $label, bool $allowBlank=false): ?string
{
    $value = trim((string)$value);
    if ($value === '' && $allowBlank) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$d || $d->format('Y-m-d') !== $value) throw new RuntimeException('Choose a valid ' . $label . '.');
    return $value;
}

function purchase_step14_code(string $prefix): string
{
    return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)),0,4));
}

function purchase_step14_supplier(PDO $pdo, int $orgId, int $supplierId): array
{
    $stmt=$pdo->prepare("SELECT * FROM purchase_suppliers WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$supplierId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Supplier was not found.');return $row;
}

function purchase_step14_save_supplier(PDO $pdo, int $supplierId, array $input): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $name=trim((string)($input['supplier_name']??''));if($name==='')throw new RuntimeException('Supplier name is required.');
    $code=trim((string)($input['supplier_code']??''));if($code==='')$code=purchase_step14_code('SUP');
    $terms=max(0,(int)($input['payment_terms_days']??0));$status=in_array(($input['status']??'active'),['active','inactive'],true)?(string)$input['status']:'active';
    $args=[$code,$name,trim((string)($input['contact_person']??''))?:null,trim((string)($input['mobile']??''))?:null,trim((string)($input['email']??''))?:null,trim((string)($input['gstin']??''))?:null,trim((string)($input['address_text']??''))?:null,$terms,trim((string)($input['notes']??''))?:null,$status];
    if($supplierId>0){$supplier=purchase_step14_supplier($pdo,$orgId,$supplierId);$stmt=$pdo->prepare("UPDATE purchase_suppliers SET supplier_code=?,supplier_name=?,contact_person=?,mobile=?,email=?,gstin=?,address_text=?,payment_terms_days=?,notes=?,status=? WHERE organization_id=? AND id=?");$stmt->execute([...$args,$orgId,$supplierId]);$event='purchase_supplier_updated';}
    else{$stmt=$pdo->prepare("INSERT INTO purchase_suppliers(organization_id,supplier_code,supplier_name,contact_person,mobile,email,gstin,address_text,payment_terms_days,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,...$args]);$supplierId=(int)$pdo->lastInsertId();$event='purchase_supplier_created';}
    $rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Supplier','supplier-'.$supplierId.'-'.time(),['supplier_id'=>$supplierId,'supplier_code'=>$code,'supplier_name'=>$name,'status'=>$status],'purchase_supplier',$supplierId);
    purchase_step14_audit($pdo,$orgId,$clubId,$event,'purchase_supplier',$supplierId,['raw_source_id'=>$rawId,'supplier_code'=>$code,'supplier_name'=>$name,'status'=>$status]);
    return $supplierId;
}

function purchase_step14_suppliers(PDO $pdo, int $orgId, bool $activeOnly=false): array
{
    $sql="SELECT s.*,COALESCE(x.billed,0) billed,COALESCE(x.returns,0) return_credit,COALESCE(x.paid,0) paid,COALESCE(x.outstanding,0) outstanding,COALESCE(x.open_bills,0) open_bills FROM purchase_suppliers s LEFT JOIN (SELECT b.supplier_id,SUM(CASE WHEN b.status='active' THEN b.total_amount ELSE 0 END) billed,SUM(CASE WHEN b.status='active' THEN b.return_credit ELSE 0 END) returns,SUM(CASE WHEN b.status='active' THEN b.paid_amount ELSE 0 END) paid,SUM(CASE WHEN b.status='active' THEN GREATEST(0,b.total_amount-b.return_credit-b.paid_amount) ELSE 0 END) outstanding,SUM(CASE WHEN b.status='active' AND b.payment_status<>'paid' THEN 1 ELSE 0 END) open_bills FROM purchase_bills b WHERE b.organization_id=? GROUP BY b.supplier_id) x ON x.supplier_id=s.id WHERE s.organization_id=?";
    if($activeOnly)$sql.=" AND s.status='active'";$sql.=" ORDER BY s.supplier_name,s.id";$stmt=$pdo->prepare($sql);$stmt->execute([$orgId,$orgId]);return $stmt->fetchAll();
}

function purchase_step14_create_order(PDO $pdo, int $supplierId, string $orderDate, ?string $expectedDate, string $notes=''): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$supplier=purchase_step14_supplier($pdo,$orgId,$supplierId);if($supplier['status']!=='active')throw new RuntimeException('Choose an active supplier.');
    $orderDate=(string)purchase_step14_date($orderDate,'order date');$expectedDate=purchase_step14_date($expectedDate,'expected date',true);$po=purchase_step14_code('PO');
    $stmt=$pdo->prepare("INSERT INTO purchase_orders(organization_id,club_id,supplier_id,po_number,order_date,expected_date,currency_code,notes,status) VALUES(?,?,?,?,?,?,'INR',?,'draft')");$stmt->execute([$orgId,$clubId,$supplierId,$po,$orderDate,$expectedDate,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();
    $rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Purchase Order','purchase-order-'.$id,['purchase_order_id'=>$id,'po_number'=>$po,'supplier_id'=>$supplierId,'order_date'=>$orderDate,'expected_date'=>$expectedDate],'purchase_order',$id);$pdo->prepare("UPDATE purchase_orders SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_order_created','purchase_order',$id,['po_number'=>$po,'supplier_id'=>$supplierId,'raw_source_id'=>$rawId]);return $id;
}

function purchase_step14_recalc_po(PDO $pdo, int $orgId, int $poId): float
{
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(line_amount),0) FROM purchase_order_items WHERE organization_id=? AND purchase_order_id=?");$stmt->execute([$orgId,$poId]);$total=round((float)$stmt->fetchColumn(),2);$pdo->prepare("UPDATE purchase_orders SET subtotal=? WHERE organization_id=? AND id=?")->execute([$total,$orgId,$poId]);return $total;
}

function purchase_step14_add_po_item(PDO $pdo, int $poId, int $productId, float $qty, ?float $estimatedCost, string $notes=''): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if($qty<=0)throw new RuntimeException('Ordered quantity must be greater than zero.');if($estimatedCost!==null&&$estimatedCost<0)throw new RuntimeException('Estimated cost cannot be negative.');
    $stmt=$pdo->prepare("SELECT * FROM purchase_orders WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$poId]);$po=$stmt->fetch();if(!$po||!in_array($po['status'],['draft','ordered'],true))throw new RuntimeException('Purchase Order is not editable.');$listing=inventory_step13_listing($pdo,$orgId,$productId);$line=round($qty*($estimatedCost??0),2);
    $stmt=$pdo->prepare("INSERT INTO purchase_order_items(organization_id,purchase_order_id,product_id,listing_id,product_name_snapshot,stock_no_snapshot,ordered_quantity,estimated_unit_cost,line_amount,notes) VALUES(?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$poId,$productId,(int)$listing['listing_id'],$listing['product_name'],$listing['sku'],$qty,$estimatedCost,$line,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();purchase_step14_recalc_po($pdo,$orgId,$poId);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_order_item_added','purchase_order_item',$id,['purchase_order_id'=>$poId,'product_id'=>$productId,'quantity'=>$qty,'estimated_unit_cost'=>$estimatedCost]);return $id;
}

function purchase_step14_set_po_status(PDO $pdo, int $poId, string $status): void
{
    $allowed=['draft','ordered','closed','cancelled'];if(!in_array($status,$allowed,true))throw new RuntimeException('Purchase Order status is invalid.');purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$pdo->prepare("UPDATE purchase_orders SET status=? WHERE organization_id=? AND id=?")->execute([$status,$orgId,$poId]);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_order_status_changed','purchase_order',$poId,['status'=>$status]);
}

function purchase_step14_create_bill(PDO $pdo, int $supplierId, ?int $poId, string $invoiceNo, string $invoiceDate, ?string $dueDate, float $otherCharges=0, string $notes=''): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$supplier=purchase_step14_supplier($pdo,$orgId,$supplierId);if($supplier['status']!=='active')throw new RuntimeException('Choose an active supplier.');$invoiceNo=trim($invoiceNo);if($invoiceNo==='')throw new RuntimeException('Supplier invoice number is required.');$invoiceDate=(string)purchase_step14_date($invoiceDate,'invoice date');$dueDate=purchase_step14_date($dueDate,'due date',true);if($dueDate===null&&(int)$supplier['payment_terms_days']>0)$dueDate=(new DateTimeImmutable($invoiceDate))->modify('+'.(int)$supplier['payment_terms_days'].' days')->format('Y-m-d');if($otherCharges<0)throw new RuntimeException('Other charges cannot be negative.');
    if($poId){$stmt=$pdo->prepare("SELECT supplier_id FROM purchase_orders WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$poId]);if((int)$stmt->fetchColumn()!==$supplierId)throw new RuntimeException('Selected PO does not belong to this supplier.');}
    $stmt=$pdo->prepare("INSERT INTO purchase_bills(organization_id,club_id,supplier_id,purchase_order_id,invoice_number,invoice_date,due_date,currency_code,other_charges,total_amount,notes,status) VALUES(?,?,?,?,?,?,?,'INR',?,?,?,'active')");$stmt->execute([$orgId,$clubId,$supplierId,$poId?:null,$invoiceNo,$invoiceDate,$dueDate,$otherCharges,$otherCharges,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Purchase Bill','purchase-bill-'.$id,['purchase_bill_id'=>$id,'supplier_id'=>$supplierId,'invoice_number'=>$invoiceNo,'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,'other_charges'=>$otherCharges],'purchase_bill',$id);$pdo->prepare("UPDATE purchase_bills SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_bill_created','purchase_bill',$id,['invoice_number'=>$invoiceNo,'supplier_id'=>$supplierId,'raw_source_id'=>$rawId]);return $id;
}

function purchase_step14_recalc_bill(PDO $pdo, int $orgId, int $billId): array
{
    $stmt=$pdo->prepare("SELECT b.*,COALESCE(x.subtotal,0) calc_subtotal,COALESCE(x.discount_amount,0) calc_discount,COALESCE(x.tax_amount,0) calc_tax,COALESCE(x.item_count,0) item_count,COALESCE(x.received_items,0) received_items,COALESCE(r.return_credit,0) calc_return,COALESCE(p.paid_amount,0) calc_paid FROM purchase_bills b LEFT JOIN (SELECT purchase_bill_id,SUM(billed_quantity*unit_cost) subtotal,SUM(line_discount) discount_amount,SUM(tax_amount) tax_amount,COUNT(*) item_count,SUM(received_quantity>=billed_quantity-0.0005) received_items FROM purchase_bill_items WHERE organization_id=? GROUP BY purchase_bill_id) x ON x.purchase_bill_id=b.id LEFT JOIN (SELECT purchase_bill_id,SUM(total_credit) return_credit FROM purchase_returns WHERE organization_id=? AND status='posted' GROUP BY purchase_bill_id) r ON r.purchase_bill_id=b.id LEFT JOIN (SELECT purchase_bill_id,SUM(amount) paid_amount FROM purchase_payments WHERE organization_id=? AND status='active' GROUP BY purchase_bill_id) p ON p.purchase_bill_id=b.id WHERE b.organization_id=? AND b.id=? LIMIT 1");$stmt->execute([$orgId,$orgId,$orgId,$orgId,$billId]);$b=$stmt->fetch();if(!$b)throw new RuntimeException('Purchase Bill was not found.');
    $subtotal=round((float)$b['calc_subtotal'],2);$discount=round((float)$b['calc_discount'],2);$tax=round((float)$b['calc_tax'],2);$total=round($subtotal-$discount+$tax+(float)$b['other_charges'],2);$return=round((float)$b['calc_return'],2);$paid=round((float)$b['calc_paid'],2);$net=max(0,round($total-$return,2));$items=(int)$b['item_count'];$receivedItems=(int)$b['received_items'];
    $hasReceived=0;$s=$pdo->prepare("SELECT COALESCE(SUM(received_quantity),0) FROM purchase_bill_items WHERE organization_id=? AND purchase_bill_id=?");$s->execute([$orgId,$billId]);$hasReceived=(float)$s->fetchColumn();$receiptStatus=$items===0?'pending':($receivedItems===$items?'received':($hasReceived>0?'partial':'pending'));$paymentStatus=$paid<=0.009?'unpaid':($paid>$net+0.009?'credit':($paid+0.009>=$net?'paid':'partial'));
    $pdo->prepare("UPDATE purchase_bills SET subtotal=?,discount_amount=?,tax_amount=?,total_amount=?,return_credit=?,paid_amount=?,receipt_status=?,payment_status=? WHERE organization_id=? AND id=?")->execute([$subtotal,$discount,$tax,$total,$return,$paid,$receiptStatus,$paymentStatus,$orgId,$billId]);
    return ['subtotal'=>$subtotal,'discount'=>$discount,'tax'=>$tax,'total'=>$total,'return_credit'=>$return,'paid'=>$paid,'net_payable'=>$net,'outstanding'=>max(0,round($net-$paid,2)),'receipt_status'=>$receiptStatus,'payment_status'=>$paymentStatus,'items'=>$items];
}

function purchase_step14_add_bill_item(PDO $pdo, int $billId, int $productId, float $qty, float $unitCost, float $discount=0, float $tax=0, ?int $poItemId=null, string $notes=''): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if($qty<=0)throw new RuntimeException('Billed quantity must be greater than zero.');if($unitCost<0||$discount<0||$tax<0)throw new RuntimeException('Cost, discount and tax cannot be negative.');$gross=round($qty*$unitCost,2);if($discount>$gross+0.01)throw new RuntimeException('Line discount cannot exceed gross line amount.');$lineTotal=round($gross-$discount+$tax,2);
    $stmt=$pdo->prepare("SELECT * FROM purchase_bills WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$billId]);$bill=$stmt->fetch();if(!$bill||$bill['status']!=='active')throw new RuntimeException('Purchase Bill is not active.');$listing=inventory_step13_listing($pdo,$orgId,$productId);
    if($poItemId){$stmt=$pdo->prepare("SELECT i.id FROM purchase_order_items i JOIN purchase_orders o ON o.id=i.purchase_order_id AND o.organization_id=i.organization_id WHERE i.organization_id=? AND i.id=? AND o.supplier_id=? LIMIT 1");$stmt->execute([$orgId,$poItemId,(int)$bill['supplier_id']]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('PO item is not valid for this supplier.');}
    $stmt=$pdo->prepare("INSERT INTO purchase_bill_items(organization_id,purchase_bill_id,purchase_order_item_id,product_id,listing_id,product_name_snapshot,stock_no_snapshot,billed_quantity,unit_cost,line_discount,tax_amount,line_total,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$billId,$poItemId?:null,$productId,(int)$listing['listing_id'],$listing['product_name'],$listing['sku'],$qty,$unitCost,$discount,$tax,$lineTotal,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();purchase_step14_recalc_bill($pdo,$orgId,$billId);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_bill_item_added','purchase_bill_item',$id,['purchase_bill_id'=>$billId,'product_id'=>$productId,'quantity'=>$qty,'unit_cost'=>$unitCost,'line_total'=>$lineTotal]);return $id;
}

function purchase_step14_receive_item(PDO $pdo, int $billItemId, float $qty, string $receiptDate, string $batchCode='', ?string $manufactureDate=null, ?string $expiryDate=null, string $deliveryReference='', string $notes='', bool $useAsProfitCost=false): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if($qty<=0)throw new RuntimeException('Receipt quantity must be greater than zero.');$receiptDate=(string)purchase_step14_date($receiptDate,'receipt date');$manufactureDate=purchase_step14_date($manufactureDate,'manufacture date',true);$expiryDate=purchase_step14_date($expiryDate,'expiry date',true);
    $stmt=$pdo->prepare("SELECT i.*,b.supplier_id,b.invoice_number,b.status bill_status,s.supplier_name FROM purchase_bill_items i JOIN purchase_bills b ON b.id=i.purchase_bill_id AND b.organization_id=i.organization_id JOIN purchase_suppliers s ON s.id=b.supplier_id AND s.organization_id=b.organization_id WHERE i.organization_id=? AND i.id=? LIMIT 1");$stmt->execute([$orgId,$billItemId]);$i=$stmt->fetch();if(!$i||$i['bill_status']!=='active')throw new RuntimeException('Active Purchase Bill item was not found.');$remaining=round((float)$i['billed_quantity']-(float)$i['received_quantity'],3);if($qty>$remaining+0.0005)throw new RuntimeException('Receipt quantity exceeds remaining billed quantity.');
    $batchCode=inventory_step13_batch_code((int)$i['listing_id'],$batchCode);$reference='Invoice '.$i['invoice_number'].($deliveryReference!==''?' • '.$deliveryReference:'');
    $inventoryTx=inventory_step13_add_stock($pdo,(int)$i['product_id'],$qty,$receiptDate,'purchase',$batchCode,$expiryDate,$manufactureDate,(float)$i['unit_cost'],(string)$i['supplier_name'],$reference,$notes,false);
    $stmt=$pdo->prepare("SELECT batch_id FROM inventory_transactions WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$inventoryTx]);$batchId=(int)$stmt->fetchColumn();if($batchId<=0)throw new RuntimeException('Inventory batch link was not created.');
    try{
        $pdo->beginTransaction();$receiptNo=purchase_step14_code('GRN');$stmt=$pdo->prepare("INSERT INTO purchase_receipts(organization_id,club_id,supplier_id,purchase_bill_id,receipt_number,receipt_date,delivery_reference,notes,status) VALUES(?,?,?,?,?,?,?,?, 'posted')");$stmt->execute([$orgId,$clubId,(int)$i['supplier_id'],(int)$i['purchase_bill_id'],$receiptNo,$receiptDate,trim($deliveryReference)?:null,trim($notes)?:null]);$receiptId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare("INSERT INTO purchase_receipt_items(organization_id,purchase_receipt_id,purchase_bill_item_id,product_id,listing_id,quantity_received,unit_cost,batch_code,manufacture_date,expiry_date,inventory_transaction_id,inventory_batch_id,use_as_profit_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$receiptId,$billItemId,(int)$i['product_id'],(int)$i['listing_id'],$qty,(float)$i['unit_cost'],$batchCode,$manufactureDate,$expiryDate,$inventoryTx,$batchId,$useAsProfitCost?1:0]);$receiptItemId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE purchase_bill_items SET received_quantity=received_quantity+? WHERE organization_id=? AND id=?")->execute([$qty,$orgId,$billItemId]);$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Purchase Receipt','purchase-receipt-'.$receiptId,['purchase_receipt_id'=>$receiptId,'receipt_number'=>$receiptNo,'purchase_bill_id'=>(int)$i['purchase_bill_id'],'bill_item_id'=>$billItemId,'product_id'=>(int)$i['product_id'],'quantity'=>$qty,'inventory_transaction_id'=>$inventoryTx,'inventory_batch_id'=>$batchId],'purchase_receipt',$receiptId);$pdo->prepare("UPDATE purchase_receipts SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$receiptId]);$pdo->prepare("UPDATE inventory_transactions SET reference_type='purchase_receipt_item',reference_id=?,source_reference=? WHERE organization_id=? AND id=?")->execute([$receiptItemId,$receiptNo.' • '.$reference,$orgId,$inventoryTx]);purchase_step14_recalc_bill($pdo,$orgId,(int)$i['purchase_bill_id']);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_receipt_posted','purchase_receipt',$receiptId,['bill_item_id'=>$billItemId,'quantity'=>$qty,'inventory_transaction_id'=>$inventoryTx,'raw_source_id'=>$rawId]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();try{inventory_step13_adjust_batch($pdo,$batchId,-$qty,$receiptDate,'adjustment_minus','STEP14 receipt compensation','Purchase receipt database posting failed; stock addition compensated.');}catch(Throwable){}throw $e;}
    if($useAsProfitCost){$sourceRef='Supplier invoice '.$i['invoice_number'].' • Receipt '.$receiptNo;product_step12_add_cost($pdo,(int)$i['product_id'],$receiptDate,(float)$i['unit_cost'],$sourceRef,'Explicit cost from STEP 14 supplier bill receipt.');}
    return $receiptId;
}

function purchase_step14_add_payment(PDO $pdo, int $billId, string $paymentDate, float $amount, string $method, string $reference='', string $notes=''): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$paymentDate=(string)purchase_step14_date($paymentDate,'payment date');if($amount<=0)throw new RuntimeException('Payment amount must be greater than zero.');$allowed=['cash','upi','bank','card','cheque','other'];if(!in_array($method,$allowed,true))$method='other';$state=purchase_step14_recalc_bill($pdo,$orgId,$billId);if($state['outstanding']<=0.009)throw new RuntimeException('This bill has no supplier amount outstanding.');if($amount>$state['outstanding']+0.01)throw new RuntimeException('Payment exceeds supplier outstanding amount.');$stmt=$pdo->prepare("SELECT supplier_id,status FROM purchase_bills WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$billId]);$b=$stmt->fetch();if(!$b||$b['status']!=='active')throw new RuntimeException('Purchase Bill is not active.');
    $stmt=$pdo->prepare("INSERT INTO purchase_payments(organization_id,supplier_id,purchase_bill_id,payment_date,amount,payment_method,reference_no,notes,status) VALUES(?,?,?,?,?,?,?,?,'active')");$stmt->execute([$orgId,(int)$b['supplier_id'],$billId,$paymentDate,$amount,$method,trim($reference)?:null,trim($notes)?:null]);$id=(int)$pdo->lastInsertId();$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Supplier Payment','supplier-payment-'.$id,['payment_id'=>$id,'purchase_bill_id'=>$billId,'amount'=>$amount,'payment_date'=>$paymentDate,'method'=>$method,'reference'=>trim($reference)],'purchase_payment',$id);$pdo->prepare("UPDATE purchase_payments SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$id]);$state=purchase_step14_recalc_bill($pdo,$orgId,$billId);purchase_step14_audit($pdo,$orgId,$clubId,'supplier_payment_added','purchase_payment',$id,['purchase_bill_id'=>$billId,'amount'=>$amount,'payment_status'=>$state['payment_status'],'raw_source_id'=>$rawId]);return $id;
}

function purchase_step14_reverse_payment(PDO $pdo, int $paymentId, string $reason): void
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear payment reversal reason.');$stmt=$pdo->prepare("SELECT * FROM purchase_payments WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$paymentId]);$p=$stmt->fetch();if(!$p)throw new RuntimeException('Supplier payment was not found.');if($p['status']!=='active')return;$pdo->prepare("UPDATE purchase_payments SET status='reversed',notes=CONCAT(COALESCE(notes,''),' | Reversed: ',?) WHERE organization_id=? AND id=?")->execute([trim($reason),$orgId,$paymentId]);$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Supplier Payment Reversal','supplier-payment-reversal-'.$paymentId.'-'.time(),['payment_id'=>$paymentId,'purchase_bill_id'=>(int)$p['purchase_bill_id'],'amount'=>(float)$p['amount'],'reason'=>trim($reason)],'purchase_payment',$paymentId);$state=purchase_step14_recalc_bill($pdo,$orgId,(int)$p['purchase_bill_id']);purchase_step14_audit($pdo,$orgId,$clubId,'supplier_payment_reversed','purchase_payment',$paymentId,['reason'=>trim($reason),'payment_status'=>$state['payment_status'],'raw_source_id'=>$rawId]);
}

function purchase_step14_return_received(PDO $pdo, int $receiptItemId, float $qty, string $returnDate, string $reason): int
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$returnDate=(string)purchase_step14_date($returnDate,'return date');if($qty<=0)throw new RuntimeException('Return quantity must be greater than zero.');if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear purchase return reason.');$stmt=$pdo->prepare("SELECT ri.*,r.supplier_id,r.purchase_bill_id,b.invoice_number FROM purchase_receipt_items ri JOIN purchase_receipts r ON r.id=ri.purchase_receipt_id AND r.organization_id=ri.organization_id JOIN purchase_bills b ON b.id=r.purchase_bill_id AND b.organization_id=r.organization_id WHERE ri.organization_id=? AND ri.id=? LIMIT 1");$stmt->execute([$orgId,$receiptItemId]);$ri=$stmt->fetch();if(!$ri)throw new RuntimeException('Purchase receipt item was not found.');$remaining=round((float)$ri['quantity_received']-(float)$ri['returned_quantity'],3);if($qty>$remaining+0.0005)throw new RuntimeException('Return quantity exceeds received quantity still available for return.');$credit=round($qty*(float)$ri['unit_cost'],2);
    $inventoryTx=inventory_step13_adjust_batch($pdo,(int)$ri['inventory_batch_id'],-$qty,$returnDate,'supplier_return','Invoice '.$ri['invoice_number'],trim($reason));
    try{$pdo->beginTransaction();$returnNo=purchase_step14_code('PR');$stmt=$pdo->prepare("INSERT INTO purchase_returns(organization_id,supplier_id,purchase_bill_id,return_number,return_date,reason,total_credit,status) VALUES(?,?,?,?,?,?,?,'posted')");$stmt->execute([$orgId,(int)$ri['supplier_id'],(int)$ri['purchase_bill_id'],$returnNo,$returnDate,trim($reason),$credit]);$returnId=(int)$pdo->lastInsertId();$stmt=$pdo->prepare("INSERT INTO purchase_return_items(organization_id,purchase_return_id,purchase_receipt_item_id,product_id,quantity_returned,unit_cost,credit_amount,inventory_transaction_id) VALUES(?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$returnId,$receiptItemId,(int)$ri['product_id'],$qty,(float)$ri['unit_cost'],$credit,$inventoryTx]);$pdo->prepare("UPDATE purchase_receipt_items SET returned_quantity=returned_quantity+? WHERE organization_id=? AND id=?")->execute([$qty,$orgId,$receiptItemId]);$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Purchase Return','purchase-return-'.$returnId,['purchase_return_id'=>$returnId,'return_number'=>$returnNo,'receipt_item_id'=>$receiptItemId,'quantity'=>$qty,'credit_amount'=>$credit,'inventory_transaction_id'=>$inventoryTx,'reason'=>trim($reason)],'purchase_return',$returnId);$pdo->prepare("UPDATE purchase_returns SET raw_source_id=? WHERE organization_id=? AND id=?")->execute([$rawId,$orgId,$returnId]);$state=purchase_step14_recalc_bill($pdo,$orgId,(int)$ri['purchase_bill_id']);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_return_posted','purchase_return',$returnId,['quantity'=>$qty,'credit_amount'=>$credit,'outstanding'=>$state['outstanding'],'inventory_transaction_id'=>$inventoryTx,'raw_source_id'=>$rawId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();try{inventory_step13_adjust_batch($pdo,(int)$ri['inventory_batch_id'],$qty,$returnDate,'adjustment_plus','STEP14 return compensation','Purchase return database posting failed; stock deduction compensated.');}catch(Throwable){}throw $e;}return $returnId;
}

function purchase_step14_cancel_bill(PDO $pdo, int $billId, string $reason): void
{
    purchase_step14_ensure($pdo);$ctx=purchase_step14_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear bill cancellation reason.');$stmt=$pdo->prepare("SELECT b.*, (SELECT COUNT(*) FROM purchase_receipts r WHERE r.organization_id=b.organization_id AND r.purchase_bill_id=b.id AND r.status='posted') receipts,(SELECT COUNT(*) FROM purchase_payments p WHERE p.organization_id=b.organization_id AND p.purchase_bill_id=b.id AND p.status='active') payments,(SELECT COUNT(*) FROM purchase_returns pr WHERE pr.organization_id=b.organization_id AND pr.purchase_bill_id=b.id AND pr.status='posted') returns_count FROM purchase_bills b WHERE b.organization_id=? AND b.id=? LIMIT 1");$stmt->execute([$orgId,$billId]);$b=$stmt->fetch();if(!$b)throw new RuntimeException('Purchase Bill was not found.');if($b['status']==='cancelled')return;if((int)$b['receipts']>0||(int)$b['payments']>0||(int)$b['returns_count']>0)throw new RuntimeException('A bill with receipt, payment or return history cannot be cancelled. Reverse/resolve linked activity first.');$pdo->prepare("UPDATE purchase_bills SET status='cancelled',notes=CONCAT(COALESCE(notes,''),' | Cancelled: ',?) WHERE organization_id=? AND id=?")->execute([trim($reason),$orgId,$billId]);$rawId=purchase_step14_raw_event($pdo,$orgId,$clubId,'Purchase Bill Cancellation','purchase-bill-cancel-'.$billId.'-'.time(),['purchase_bill_id'=>$billId,'reason'=>trim($reason)],'purchase_bill',$billId);purchase_step14_audit($pdo,$orgId,$clubId,'purchase_bill_cancelled','purchase_bill',$billId,['reason'=>trim($reason),'raw_source_id'=>$rawId]);
}

function purchase_step14_bill_rows(PDO $pdo, int $orgId, string $status='all'): array
{
    $sql="SELECT b.*,s.supplier_name,s.supplier_code,o.po_number,GREATEST(0,b.total_amount-b.return_credit-b.paid_amount) outstanding FROM purchase_bills b JOIN purchase_suppliers s ON s.id=b.supplier_id AND s.organization_id=b.organization_id LEFT JOIN purchase_orders o ON o.id=b.purchase_order_id AND o.organization_id=b.organization_id WHERE b.organization_id=?";$args=[$orgId];if($status==='active'){$sql.=" AND b.status='active'";}elseif($status==='cancelled'){$sql.=" AND b.status='cancelled'";}$sql.=" ORDER BY b.invoice_date DESC,b.id DESC";$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}

function purchase_step14_po_rows(PDO $pdo, int $orgId): array
{
    $stmt=$pdo->prepare("SELECT o.*,s.supplier_name,s.supplier_code,(SELECT COUNT(*) FROM purchase_order_items i WHERE i.organization_id=o.organization_id AND i.purchase_order_id=o.id) item_count FROM purchase_orders o JOIN purchase_suppliers s ON s.id=o.supplier_id AND s.organization_id=o.organization_id WHERE o.organization_id=? ORDER BY o.order_date DESC,o.id DESC");$stmt->execute([$orgId]);return $stmt->fetchAll();
}

function purchase_step14_receipt_rows(PDO $pdo, int $orgId): array
{
    $stmt=$pdo->prepare("SELECT r.*,s.supplier_name,b.invoice_number,COUNT(ri.id) item_count,COALESCE(SUM(ri.quantity_received),0) total_qty FROM purchase_receipts r JOIN purchase_suppliers s ON s.id=r.supplier_id AND s.organization_id=r.organization_id JOIN purchase_bills b ON b.id=r.purchase_bill_id AND b.organization_id=r.organization_id LEFT JOIN purchase_receipt_items ri ON ri.purchase_receipt_id=r.id AND ri.organization_id=r.organization_id WHERE r.organization_id=? GROUP BY r.id ORDER BY r.receipt_date DESC,r.id DESC");$stmt->execute([$orgId]);return $stmt->fetchAll();
}

function purchase_step14_return_rows(PDO $pdo, int $orgId): array
{
    $stmt=$pdo->prepare("SELECT r.*,s.supplier_name,b.invoice_number FROM purchase_returns r JOIN purchase_suppliers s ON s.id=r.supplier_id AND s.organization_id=r.organization_id JOIN purchase_bills b ON b.id=r.purchase_bill_id AND b.organization_id=r.organization_id WHERE r.organization_id=? ORDER BY r.return_date DESC,r.id DESC");$stmt->execute([$orgId]);return $stmt->fetchAll();
}
