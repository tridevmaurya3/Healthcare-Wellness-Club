<?php
declare(strict_types=1);

require_once __DIR__ . '/product_step11.php';

const PRODUCT_SALE_SOURCE_SHEET = 'Product Sale';
const PRODUCT_SALE_REVERSED_SOURCE_SHEET = 'Product Sale • Reversed';

function product_step12_tables(): array
{
    return [
        'product_quote_order_links',
        'product_order_items',
        'product_cost_versions',
        'product_sale_ledger',
        'product_sale_payments',
        'product_sale_lifecycle_events',
    ];
}

function product_step12_run_migration(PDO $pdo, string $file): void
{
    if (!is_file($file)) throw new RuntimeException('STEP 12 migration is missing: ' . basename($file));
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

function product_step12_ensure(PDO $pdo): void
{
    product_step11_ensure($pdo);

    if (!business_table_exists($pdo, 'product_quote_order_links') || !business_table_exists($pdo, 'product_order_items')) {
        product_step12_run_migration($pdo, dirname(__DIR__, 2) . '/database/migrations/007_product_sales_business_integration.sql');
    }

    foreach (['product_cost_versions','product_sale_ledger','product_sale_payments','product_sale_lifecycle_events'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            product_step12_run_migration($pdo, dirname(__DIR__, 2) . '/database/migrations/008_step12_complete_sales_integration.sql');
            break;
        }
    }

    if (!business_column_exists($pdo,'product_order_items','cost_version_id')) {
        $pdo->exec("ALTER TABLE product_order_items ADD COLUMN cost_version_id BIGINT UNSIGNED NULL AFTER price_version_id");
    }
    if (!business_column_exists($pdo,'product_order_items','unit_cost')) {
        $pdo->exec("ALTER TABLE product_order_items ADD COLUMN unit_cost DECIMAL(16,2) NULL AFTER unit_vp");
    }
    if (!business_column_exists($pdo,'product_order_items','line_cost')) {
        $pdo->exec("ALTER TABLE product_order_items ADD COLUMN line_cost DECIMAL(16,2) NULL AFTER line_vp");
    }
    if (!business_column_exists($pdo,'product_order_items','line_profit')) {
        $pdo->exec("ALTER TABLE product_order_items ADD COLUMN line_profit DECIMAL(16,2) NULL AFTER line_cost");
    }
    if (!business_column_exists($pdo,'product_order_items','profit_status')) {
        $pdo->exec("ALTER TABLE product_order_items ADD COLUMN profit_status VARCHAR(30) NOT NULL DEFAULT 'deferred' AFTER line_profit");
    }

    foreach (product_step12_tables() as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException('STEP 12 table missing: ' . $table);
    }
}

function product_step12_context(PDO $pdo): array
{
    $ctx = product_org_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $stmt = $pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $stmt->execute([$orgId]);
    $clubId = (int)$stmt->fetchColumn();
    if ($clubId <= 0) throw new RuntimeException('Ghazipur club was not found.');
    $ctx['club_id'] = $clubId;
    return $ctx;
}

function product_step12_source(PDO $pdo, int $organizationId): int
{
    $stmt = $pdo->prepare("INSERT INTO data_sources(organization_id,source_code,source_name,source_type,is_active)
        VALUES(?,'PRODUCT-SALES','Product & Price Pro Sales','manual',1)
        ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_type=VALUES(source_type),is_active=1");
    $stmt->execute([$organizationId]);
    $stmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='PRODUCT-SALES' LIMIT 1");
    $stmt->execute([$organizationId]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Product Sales data source could not be prepared.');
    return $id;
}

function product_step12_json(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('STEP 12 JSON payload could not be encoded.');
    return $json;
}

function product_step12_raw_event(PDO $pdo, int $orgId, int $clubId, string $dataset, string $externalId, array $payload, string $entityType='system', ?int $entityId=null): int
{
    $sourceId = product_step12_source($pdo,$orgId);
    $rawJson = product_step12_json($payload);
    $hash = hash('sha256',$rawJson);
    $stmt = $pdo->prepare("INSERT INTO raw_source_records
        (organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id)
        VALUES(?,?,?,?,?,NOW(),?,?,'mapped',?,?)");
    $stmt->execute([$orgId,$clubId,$sourceId,$dataset,$externalId,$hash,$rawJson,$entityType,$entityId]);
    return (int)$pdo->lastInsertId();
}

function product_step12_audit(PDO $pdo, int $orgId, int $clubId, string $event, string $entityType, ?int $entityId, array $details): void
{
    if (!business_table_exists($pdo,'audit_logs')) return;
    $stmt=$pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent)
        VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $orgId,$clubId,$event,$entityType,$entityId,product_step12_json($details),
        substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)
    ]);
}

function product_step12_quote(PDO $pdo, int $organizationId, int $quoteId): array
{
    $stmt = $pdo->prepare("SELECT q.*,l.order_id,m.full_name linked_member_name
        FROM product_quotes q
        LEFT JOIN product_quote_order_links l ON l.organization_id=q.organization_id AND l.quote_id=q.id
        LEFT JOIN orders o ON o.id=l.order_id AND o.organization_id=q.organization_id
        LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
        WHERE q.organization_id=? AND q.id=? LIMIT 1");
    $stmt->execute([$organizationId,$quoteId]);
    $quote = $stmt->fetch();
    if (!$quote) throw new RuntimeException('Saved product quote was not found.');

    $stmt = $pdo->prepare("SELECT * FROM product_quote_items WHERE quote_id=? ORDER BY id");
    $stmt->execute([$quoteId]);
    $quote['items'] = $stmt->fetchAll();
    return $quote;
}

function product_step12_members(PDO $pdo, int $organizationId): array
{
    $rev = defined('BUSINESS_REVERSED_SOURCE_SHEET') ? BUSINESS_REVERSED_SOURCE_SHEET : 'Manual Entry • Reversed';
    $stmt = $pdo->prepare("SELECT id,full_name,mobile,status FROM members
        WHERE organization_id=? AND COALESCE(source_sheet,'') NOT IN (?,?)
        ORDER BY full_name,id");
    $stmt->execute([$organizationId,$rev,PRODUCT_SALE_REVERSED_SOURCE_SHEET]);
    return $stmt->fetchAll();
}

function product_step12_cost_for(PDO $pdo, int $organizationId, int $listingId, string $date): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM product_cost_versions
        WHERE organization_id=? AND listing_id=? AND status='active' AND effective_from<=?
          AND (effective_to IS NULL OR effective_to>=?)
        ORDER BY effective_from DESC,id DESC LIMIT 1");
    $stmt->execute([$organizationId,$listingId,$date,$date]);
    $row=$stmt->fetch();
    return $row?:null;
}

function product_step12_ledger(PDO $pdo, int $organizationId, int $orderId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM product_sale_ledger WHERE organization_id=? AND order_id=? LIMIT 1");
    $stmt->execute([$organizationId,$orderId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function product_step12_recalculate_sale_profit(PDO $pdo, int $orderId): array
{
    $ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];
    $stmt=$pdo->prepare("SELECT id,order_date,source_sheet FROM orders WHERE organization_id=? AND id=? AND order_type='product_sale' LIMIT 1");
    $stmt->execute([$orgId,$orderId]);$order=$stmt->fetch();
    if(!$order)throw new RuntimeException('Product Sale order was not found for profit calculation.');

    $stmt=$pdo->prepare("SELECT * FROM product_order_items WHERE organization_id=? AND order_id=? ORDER BY id");
    $stmt->execute([$orgId,$orderId]);$items=$stmt->fetchAll();
    $covered=0;$costTotal=0.0;$profitTotal=0.0;
    $update=$pdo->prepare("UPDATE product_order_items SET cost_version_id=?,unit_cost=?,line_cost=?,line_profit=?,profit_status=? WHERE organization_id=? AND id=?");
    foreach($items as $i){
        $cost=product_step12_cost_for($pdo,$orgId,(int)$i['listing_id'],(string)$order['order_date']);
        if(!$cost){$update->execute([null,null,null,null,'deferred',$orgId,(int)$i['id']]);continue;}
        $qty=(float)$i['quantity'];$unit=(float)$cost['unit_cost'];$lineCost=round($unit*$qty,2);$lineProfit=round((float)$i['line_price']-$lineCost,2);
        $update->execute([(int)$cost['id'],$unit,$lineCost,$lineProfit,'explicit_cost',$orgId,(int)$i['id']]);
        $covered++;$costTotal+=$lineCost;$profitTotal+=$lineProfit;
    }
    $total=count($items);$complete=$total>0&&$covered===$total;$status=$complete?'complete':($covered>0?'partial':'deferred');
    $ledger=product_step12_ledger($pdo,$orgId,$orderId);
    if($ledger){
        $stmt=$pdo->prepare("UPDATE product_sale_ledger SET cost_status=?,cost_total=?,profit_total=? WHERE organization_id=? AND order_id=?");
        $stmt->execute([$status,$complete?round($costTotal,2):null,$complete?round($profitTotal,2):null,$orgId,$orderId]);
    }
    $stmt=$pdo->prepare("UPDATE orders SET profit_amount=? WHERE organization_id=? AND id=?");
    $stmt->execute([$complete?round($profitTotal,2):0,$orgId,$orderId]);
    return ['items'=>$total,'covered'=>$covered,'status'=>$status,'cost_total'=>$complete?round($costTotal,2):null,'profit_total'=>$complete?round($profitTotal,2):null];
}

function product_step12_add_cost(PDO $pdo, int $productId, string $effectiveFrom, float $unitCost, string $sourceReference, string $notes=''): int
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$effectiveFrom);if(!$date||$date->format('Y-m-d')!==$effectiveFrom)throw new RuntimeException('Choose a valid cost effective date.');
    if($unitCost<0)throw new RuntimeException('Unit cost cannot be negative.');
    if(trim($sourceReference)==='')throw new RuntimeException('Enter the real cost source/reference (purchase bill, invoice or approved cost sheet).');
    $stmt=$pdo->prepare("SELECT p.id,l.id listing_id,l.market_id FROM products p JOIN product_market_listings l ON l.product_id=p.id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND p.id=? AND l.status='active' LIMIT 1");
    $stmt->execute([$orgId,$productId]);$product=$stmt->fetch();if(!$product)throw new RuntimeException('Product listing was not found.');
    $stmt=$pdo->prepare("INSERT INTO product_cost_versions(organization_id,market_id,product_id,listing_id,effective_from,unit_cost,currency_code,basis_code,source_reference,notes,status)
        VALUES(?,?,?,?,?,?,'INR','explicit_cost',?,?,'active')
        ON DUPLICATE KEY UPDATE unit_cost=VALUES(unit_cost),source_reference=VALUES(source_reference),notes=VALUES(notes),status='active'");
    $stmt->execute([$orgId,(int)$product['market_id'],$productId,(int)$product['listing_id'],$effectiveFrom,$unitCost,trim($sourceReference),trim($notes)]);
    $stmt=$pdo->prepare("SELECT id FROM product_cost_versions WHERE organization_id=? AND listing_id=? AND effective_from=? LIMIT 1");$stmt->execute([$orgId,(int)$product['listing_id'],$effectiveFrom]);$costId=(int)$stmt->fetchColumn();
    $rawId=product_step12_raw_event($pdo,$orgId,$clubId,'Product Cost','product-cost-'.$costId.'-'.time(),['cost_version_id'=>$costId,'product_id'=>$productId,'listing_id'=>(int)$product['listing_id'],'effective_from'=>$effectiveFrom,'unit_cost'=>$unitCost,'currency'=>'INR','source_reference'=>trim($sourceReference),'notes'=>trim($notes)],'product_cost_version',$costId);
    product_step12_audit($pdo,$orgId,$clubId,'product_cost_version_saved','product_cost_version',$costId,['product_id'=>$productId,'effective_from'=>$effectiveFrom,'unit_cost'=>$unitCost,'source_reference'=>trim($sourceReference),'raw_source_id'=>$rawId]);
    $sales=$pdo->prepare("SELECT DISTINCT poi.order_id FROM product_order_items poi JOIN orders o ON o.id=poi.order_id AND o.organization_id=poi.organization_id WHERE poi.organization_id=? AND poi.listing_id=? AND o.order_date>=? AND o.source_sheet=?");
    $sales->execute([$orgId,(int)$product['listing_id'],$effectiveFrom,PRODUCT_SALE_SOURCE_SHEET]);foreach($sales->fetchAll(PDO::FETCH_COLUMN) as $oid)product_step12_recalculate_sale_profit($pdo,(int)$oid);
    return $costId;
}

function product_step12_finalize_quote(PDO $pdo, int $quoteId, ?int $memberId, string $orderDate): int
{
    product_step12_ensure($pdo);
    $ctx = product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$orderDate);if(!$date||$date->format('Y-m-d')!==$orderDate)throw new RuntimeException('Choose a valid order date.');
    $existing=$pdo->prepare("SELECT order_id FROM product_quote_order_links WHERE organization_id=? AND quote_id=? LIMIT 1");$existing->execute([$orgId,$quoteId]);$existingOrder=(int)$existing->fetchColumn();if($existingOrder>0)return $existingOrder;
    $quote=product_step12_quote($pdo,$orgId,$quoteId);if(!$quote['items'])throw new RuntimeException('This quote has no product line items.');
    if($memberId!==null){$stmt=$pdo->prepare("SELECT id FROM members WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$memberId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Selected member does not exist in this organization.');}

    $payload=['workflow'=>'Product & Price Pro quote -> Business OS order','quote_id'=>(int)$quote['id'],'quote_code'=>(string)$quote['quote_code'],'customer_name_snapshot'=>(string)($quote['customer_name']??''),'explicit_member_id'=>$memberId,'pricing_tier_code'=>(string)$quote['pricing_tier_code'],'subtotal_mrp'=>(float)$quote['subtotal_mrp'],'payable_amount'=>(float)$quote['payable_amount'],'saving_amount'=>(float)$quote['saving_amount'],'delivery_charge'=>(float)$quote['delivery_charge'],'grand_total'=>(float)$quote['grand_total'],'total_vp'=>(float)$quote['total_vp'],'profit_policy'=>'explicit_cost_only','items'=>array_map(static fn(array $i):array=>['product_id'=>(int)$i['product_id'],'stock_no'=>(string)$i['stock_no'],'product_name'=>(string)$i['product_name'],'quantity'=>(float)$i['quantity'],'unit_mrp'=>(float)$i['unit_mrp'],'unit_price'=>(float)$i['unit_price'],'unit_vp'=>(float)$i['unit_vp'],'line_mrp'=>(float)$i['line_mrp'],'line_price'=>(float)$i['line_price'],'line_vp'=>(float)$i['line_vp']],$quote['items'])];
    $sourceId=product_step12_source($pdo,$orgId);$rawJson=product_step12_json($payload);$hash=hash('sha256',$rawJson);$externalId='product-quote-'.(int)$quote['id'];
    $pdo->beginTransaction();
    try{
        $raw=$pdo->prepare("INSERT INTO raw_source_records(organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status) VALUES(?,?,?,?,?,NOW(),?,?,'pending')");$raw->execute([$orgId,$clubId,$sourceId,'Product Sale',$externalId,$hash,$rawJson]);$rawId=(int)$pdo->lastInsertId();
        $gross=(float)$quote['subtotal_mrp']+(float)$quote['delivery_charge'];$discount=(float)$quote['saving_amount'];$net=(float)$quote['grand_total'];
        $notes=product_step12_json(['quote_id'=>(int)$quote['id'],'quote_code'=>(string)$quote['quote_code'],'customer_name_snapshot'=>(string)($quote['customer_name']??''),'pricing_tier_code'=>(string)$quote['pricing_tier_code'],'product_payable'=>(float)$quote['payable_amount'],'delivery_charge'=>(float)$quote['delivery_charge'],'profit_basis'=>'explicit_cost_only','product_line_items'=>'product_order_items']);
        $order=$pdo->prepare("INSERT INTO orders(organization_id,club_id,member_id,order_date,order_type,invoice_no,description,gross_amount,discount_amount,net_amount,profit_amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $order->execute([$orgId,$clubId,$memberId,$orderDate,'product_sale',null,'Product & Price Pro sale • '.(string)$quote['quote_code'],$gross,$discount,$net,0,'INR',(float)$quote['total_vp'],$notes,$rawId,PRODUCT_SALE_SOURCE_SHEET,'product-sale-quote:'.(int)$quote['id']]);$orderId=(int)$pdo->lastInsertId();
        $item=$pdo->prepare("INSERT INTO product_order_items(organization_id,order_id,quote_id,product_id,listing_id,price_version_id,stock_no,product_name_snapshot,pricing_tier_code,quantity,unit_mrp,unit_price,unit_vp,line_mrp,line_price,line_vp,profit_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($quote['items'] as $i)$item->execute([$orgId,$orderId,(int)$quote['id'],(int)$i['product_id'],(int)$i['listing_id'],(int)$i['price_version_id'],(string)$i['stock_no'],(string)$i['product_name'],(string)$quote['pricing_tier_code'],(float)$i['quantity'],(float)$i['unit_mrp'],(float)$i['unit_price'],(float)$i['unit_vp'],(float)$i['line_mrp'],(float)$i['line_price'],(float)$i['line_vp'],'deferred']);
        $pdo->prepare("INSERT INTO product_quote_order_links(organization_id,quote_id,order_id) VALUES(?,?,?)")->execute([$orgId,(int)$quote['id'],$orderId]);
        $pdo->prepare("INSERT INTO product_sale_ledger(organization_id,order_id,quote_id,sale_status,payment_status,paid_amount,cost_status) VALUES(?,?,?,'active','unpaid',0,'deferred')")->execute([$orgId,$orderId,(int)$quote['id']]);$ledgerId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_sale_lifecycle_events(organization_id,sale_ledger_id,order_id,event_type,reason,snapshot_json) VALUES(?,?,?,'finalized',NULL,?)")->execute([$orgId,$ledgerId,$orderId,product_step12_json(['quote_id'=>(int)$quote['id'],'order_date'=>$orderDate,'member_id'=>$memberId,'net_amount'=>$net,'vp'=>(float)$quote['total_vp']])]);
        $pdo->prepare("UPDATE product_quotes SET status='converted' WHERE organization_id=? AND id=?")->execute([$orgId,(int)$quote['id']]);
        $pdo->prepare("UPDATE raw_source_records SET mapping_status='mapped',mapped_entity_type='order',mapped_entity_id=? WHERE id=? AND organization_id=?")->execute([$orderId,$rawId,$orgId]);
        $profit=product_step12_recalculate_sale_profit($pdo,$orderId);
        product_step12_audit($pdo,$orgId,$clubId,'product_quote_finalized_to_order','order',$orderId,['quote_id'=>(int)$quote['id'],'quote_code'=>(string)$quote['quote_code'],'explicit_member_id'=>$memberId,'order_date'=>$orderDate,'gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>$net,'volume_points'=>(float)$quote['total_vp'],'profit_policy'=>'explicit_cost_only','cost_status'=>$profit['status'],'raw_source_id'=>$rawId]);
        $pdo->commit();return $orderId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step12_sync_payment_status(PDO $pdo, int $orderId): array
{
    $ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];
    $ledger=product_step12_ledger($pdo,$orgId,$orderId);if(!$ledger)throw new RuntimeException('Product sale ledger was not found.');
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM product_sale_payments WHERE organization_id=? AND order_id=? AND status='active'");$stmt->execute([$orgId,$orderId]);$paid=round((float)$stmt->fetchColumn(),2);
    $stmt=$pdo->prepare("SELECT net_amount FROM orders WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$orderId]);$due=round((float)$stmt->fetchColumn(),2);
    $status=$paid<=0.009?'unpaid':($paid+0.009>=$due?'paid':'partial');
    $pdo->prepare("UPDATE product_sale_ledger SET paid_amount=?,payment_status=? WHERE organization_id=? AND order_id=?")->execute([$paid,$status,$orgId,$orderId]);
    return ['paid'=>$paid,'due'=>$due,'outstanding'=>max(0,round($due-$paid,2)),'status'=>$status];
}

function product_step12_add_payment(PDO $pdo, int $orderId, string $date, float $amount, string $method, string $reference='', string $notes=''): int
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new RuntimeException('Choose a valid payment date.');if($amount<=0)throw new RuntimeException('Payment amount must be greater than zero.');
    $allowed=['cash','upi','bank','card','other'];if(!in_array($method,$allowed,true))$method='other';
    $ledger=product_step12_ledger($pdo,$orgId,$orderId);if(!$ledger||$ledger['sale_status']!=='active')throw new RuntimeException('Only an active Product Sale can receive payment.');
    $state=product_step12_sync_payment_status($pdo,$orderId);if($amount>$state['outstanding']+0.01)throw new RuntimeException('Payment exceeds the current outstanding amount.');
    $pdo->beginTransaction();try{
        $stmt=$pdo->prepare("INSERT INTO product_sale_payments(organization_id,sale_ledger_id,order_id,payment_date,amount,payment_method,reference_no,notes,status) VALUES(?,?,?,?,?,?,?,?,'active')");$stmt->execute([$orgId,(int)$ledger['id'],$orderId,$date,$amount,$method,trim($reference),trim($notes)]);$paymentId=(int)$pdo->lastInsertId();
        $rawId=product_step12_raw_event($pdo,$orgId,$clubId,'Product Sale Payment','product-sale-payment-'.$paymentId,['payment_id'=>$paymentId,'order_id'=>$orderId,'payment_date'=>$date,'amount'=>$amount,'method'=>$method,'reference'=>trim($reference),'notes'=>trim($notes)],'product_sale_payment',$paymentId);
        $pdo->prepare("INSERT INTO product_sale_lifecycle_events(organization_id,sale_ledger_id,order_id,event_type,reason,snapshot_json) VALUES(?,?,?,'payment_added',NULL,?)")->execute([$orgId,(int)$ledger['id'],$orderId,product_step12_json(['payment_id'=>$paymentId,'amount'=>$amount,'method'=>$method,'raw_source_id'=>$rawId])]);
        $state=product_step12_sync_payment_status($pdo,$orderId);product_step12_audit($pdo,$orgId,$clubId,'product_sale_payment_added','product_sale_payment',$paymentId,['order_id'=>$orderId,'amount'=>$amount,'method'=>$method,'payment_status'=>$state['status'],'raw_source_id'=>$rawId]);
        $pdo->commit();return $paymentId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step12_reverse_payment(PDO $pdo, int $paymentId, string $reason): void
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear payment reversal reason.');
    $stmt=$pdo->prepare("SELECT p.*,l.sale_status FROM product_sale_payments p JOIN product_sale_ledger l ON l.id=p.sale_ledger_id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND p.id=? LIMIT 1");$stmt->execute([$orgId,$paymentId]);$p=$stmt->fetch();if(!$p)throw new RuntimeException('Payment was not found.');if($p['status']!=='active')return;
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE product_sale_payments SET status='reversed' WHERE organization_id=? AND id=?")->execute([$orgId,$paymentId]);$rawId=product_step12_raw_event($pdo,$orgId,$clubId,'Product Sale Payment Reversal','product-payment-reversal-'.$paymentId.'-'.time(),['payment_id'=>$paymentId,'order_id'=>(int)$p['order_id'],'reason'=>trim($reason),'amount'=>(float)$p['amount']],'product_sale_payment',$paymentId);$pdo->prepare("INSERT INTO product_sale_lifecycle_events(organization_id,sale_ledger_id,order_id,event_type,reason,snapshot_json) VALUES(?,?,?,'payment_reversed',?,?)")->execute([$orgId,(int)$p['sale_ledger_id'],(int)$p['order_id'],trim($reason),product_step12_json(['payment_id'=>$paymentId,'amount'=>(float)$p['amount'],'raw_source_id'=>$rawId])]);$state=product_step12_sync_payment_status($pdo,(int)$p['order_id']);product_step12_audit($pdo,$orgId,$clubId,'product_sale_payment_reversed','product_sale_payment',$paymentId,['order_id'=>(int)$p['order_id'],'reason'=>trim($reason),'payment_status'=>$state['status'],'raw_source_id'=>$rawId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step12_cancel_sale(PDO $pdo, int $orderId, string $reason): void
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear cancellation reason.');
    $ledger=product_step12_ledger($pdo,$orgId,$orderId);if(!$ledger)throw new RuntimeException('Product sale ledger was not found.');if($ledger['sale_status']==='cancelled')return;
    $stmt=$pdo->prepare("SELECT * FROM orders WHERE organization_id=? AND id=? AND order_type='product_sale' LIMIT 1");$stmt->execute([$orgId,$orderId]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('Product Sale order was not found.');
    $snapshot=['order_id'=>$orderId,'source_sheet'=>$order['source_sheet'],'net_amount'=>(float)$order['net_amount'],'profit_amount'=>(float)$order['profit_amount'],'volume_points'=>(float)$order['volume_points'],'payment_status'=>$ledger['payment_status'],'paid_amount'=>(float)$ledger['paid_amount']];
    $pdo->beginTransaction();try{
        $rawId=product_step12_raw_event($pdo,$orgId,$clubId,'Product Sale Cancellation','product-sale-cancel-'.$orderId.'-'.time(),['reason'=>trim($reason),'before'=>$snapshot],'order',$orderId);
        $pdo->prepare("UPDATE orders SET source_sheet=? WHERE organization_id=? AND id=?")->execute([PRODUCT_SALE_REVERSED_SOURCE_SHEET,$orgId,$orderId]);
        $pdo->prepare("UPDATE product_sale_ledger SET sale_status='cancelled',cancelled_at=NOW(),cancellation_reason=? WHERE organization_id=? AND order_id=?")->execute([trim($reason),$orgId,$orderId]);
        $pdo->prepare("UPDATE product_quotes q JOIN product_quote_order_links l ON l.quote_id=q.id AND l.organization_id=q.organization_id SET q.status='cancelled_sale' WHERE l.organization_id=? AND l.order_id=?")->execute([$orgId,$orderId]);
        $pdo->prepare("INSERT INTO product_sale_lifecycle_events(organization_id,sale_ledger_id,order_id,event_type,reason,snapshot_json) VALUES(?,?,?,'cancelled',?,?)")->execute([$orgId,(int)$ledger['id'],$orderId,trim($reason),product_step12_json($snapshot+['raw_source_id'=>$rawId])]);
        product_step12_audit($pdo,$orgId,$clubId,'product_sale_cancelled','order',$orderId,['reason'=>trim($reason),'before'=>$snapshot,'raw_source_id'=>$rawId]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step12_restore_sale(PDO $pdo, int $orderId, string $reason): void
{
    product_step12_ensure($pdo);$ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear restore reason.');
    $ledger=product_step12_ledger($pdo,$orgId,$orderId);if(!$ledger)throw new RuntimeException('Product sale ledger was not found.');if($ledger['sale_status']==='active')return;
    $stmt=$pdo->prepare("SELECT id,source_sheet FROM orders WHERE organization_id=? AND id=? AND order_type='product_sale' LIMIT 1");$stmt->execute([$orgId,$orderId]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('Product Sale order was not found.');
    $pdo->beginTransaction();try{
        $rawId=product_step12_raw_event($pdo,$orgId,$clubId,'Product Sale Restore','product-sale-restore-'.$orderId.'-'.time(),['reason'=>trim($reason),'previous_source_sheet'=>$order['source_sheet']],'order',$orderId);
        $pdo->prepare("UPDATE orders SET source_sheet=? WHERE organization_id=? AND id=?")->execute([PRODUCT_SALE_SOURCE_SHEET,$orgId,$orderId]);
        $pdo->prepare("UPDATE product_sale_ledger SET sale_status='active',restored_at=NOW(),cancelled_at=NULL,cancellation_reason=NULL WHERE organization_id=? AND order_id=?")->execute([$orgId,$orderId]);
        $pdo->prepare("UPDATE product_quotes q JOIN product_quote_order_links l ON l.quote_id=q.id AND l.organization_id=q.organization_id SET q.status='converted' WHERE l.organization_id=? AND l.order_id=?")->execute([$orgId,$orderId]);
        $pdo->prepare("INSERT INTO product_sale_lifecycle_events(organization_id,sale_ledger_id,order_id,event_type,reason,snapshot_json) VALUES(?,?,?,'restored',?,?)")->execute([$orgId,(int)$ledger['id'],$orderId,trim($reason),product_step12_json(['raw_source_id'=>$rawId])]);product_step12_recalculate_sale_profit($pdo,$orderId);
        product_step12_audit($pdo,$orgId,$clubId,'product_sale_restored','order',$orderId,['reason'=>trim($reason),'raw_source_id'=>$rawId]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step12_sales_rows(PDO $pdo, int $organizationId, string $status='all'): array
{
    $sql="SELECT o.id order_id,o.order_date,o.member_id,o.net_amount,o.gross_amount,o.discount_amount,o.profit_amount,o.volume_points,o.source_sheet,m.full_name member_name,q.id quote_id,q.quote_code,q.customer_name,q.pricing_tier_code,l.id ledger_id,l.sale_status,l.payment_status,l.paid_amount,l.cost_status,l.cost_total,l.profit_total,l.finalized_at,l.cancelled_at,l.cancellation_reason
        FROM product_sale_ledger l JOIN orders o ON o.id=l.order_id AND o.organization_id=l.organization_id LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id LEFT JOIN product_quotes q ON q.id=l.quote_id AND q.organization_id=l.organization_id WHERE l.organization_id=?";
    $args=[$organizationId];if(in_array($status,['active','cancelled'],true)){$sql.=" AND l.sale_status=?";$args[]=$status;}$sql.=" ORDER BY o.order_date DESC,o.id DESC";$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}
