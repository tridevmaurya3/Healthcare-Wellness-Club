<?php
declare(strict_types=1);

require_once __DIR__ . '/product_step11.php';

function product_step12_tables(): array
{
    return ['product_quote_order_links','product_order_items'];
}

function product_step12_ensure(PDO $pdo): void
{
    product_step11_ensure($pdo);
    $missing = false;
    foreach (product_step12_tables() as $table) {
        if (!business_table_exists($pdo, $table)) { $missing = true; break; }
    }
    if (!$missing) return;

    $migration = dirname(__DIR__, 2) . '/database/migrations/007_product_sales_business_integration.sql';
    if (!is_file($migration)) throw new RuntimeException('STEP 12A integration migration is missing.');
    $sql = (string)file_get_contents($migration);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        $statement = preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement) ?? $statement;
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^USE\s+/i', $statement)) continue;
        $pdo->exec($statement);
    }
    foreach (product_step12_tables() as $table) {
        if (!business_table_exists($pdo, $table)) throw new RuntimeException('STEP 12A table missing: ' . $table);
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
        WHERE organization_id=? AND COALESCE(source_sheet,'')<>?
        ORDER BY full_name,id");
    $stmt->execute([$organizationId,$rev]);
    return $stmt->fetchAll();
}

function product_step12_finalize_quote(PDO $pdo, int $quoteId, ?int $memberId, string $orderDate): int
{
    product_step12_ensure($pdo);
    $ctx = product_step12_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $orderDate);
    if (!$date || $date->format('Y-m-d') !== $orderDate) throw new RuntimeException('Choose a valid order date.');

    $existing = $pdo->prepare("SELECT order_id FROM product_quote_order_links WHERE organization_id=? AND quote_id=? LIMIT 1");
    $existing->execute([$orgId,$quoteId]);
    $existingOrder = (int)$existing->fetchColumn();
    if ($existingOrder > 0) return $existingOrder;

    $quote = product_step12_quote($pdo,$orgId,$quoteId);
    if (!$quote['items']) throw new RuntimeException('This quote has no product line items.');

    if ($memberId !== null) {
        $stmt = $pdo->prepare("SELECT id,full_name FROM members WHERE organization_id=? AND id=? LIMIT 1");
        $stmt->execute([$orgId,$memberId]);
        $member = $stmt->fetch();
        if (!$member) throw new RuntimeException('Selected member does not exist in this organization.');
    }

    $sourceId = product_step12_source($pdo,$orgId);
    $payload = [
        'workflow'=>'Product & Price Pro quote -> Business OS order',
        'quote_id'=>(int)$quote['id'],
        'quote_code'=>(string)$quote['quote_code'],
        'customer_name_snapshot'=>(string)($quote['customer_name'] ?? ''),
        'explicit_member_id'=>$memberId,
        'pricing_tier_code'=>(string)$quote['pricing_tier_code'],
        'subtotal_mrp'=>(float)$quote['subtotal_mrp'],
        'payable_amount'=>(float)$quote['payable_amount'],
        'saving_amount'=>(float)$quote['saving_amount'],
        'delivery_charge'=>(float)$quote['delivery_charge'],
        'grand_total'=>(float)$quote['grand_total'],
        'total_vp'=>(float)$quote['total_vp'],
        'profit_amount'=>0,
        'profit_policy'=>'deferred_no_cost_basis_guess',
        'items'=>array_map(static fn(array $i): array => [
            'product_id'=>(int)$i['product_id'],'stock_no'=>(string)$i['stock_no'],'product_name'=>(string)$i['product_name'],
            'quantity'=>(float)$i['quantity'],'unit_mrp'=>(float)$i['unit_mrp'],'unit_price'=>(float)$i['unit_price'],
            'unit_vp'=>(float)$i['unit_vp'],'line_mrp'=>(float)$i['line_mrp'],'line_price'=>(float)$i['line_price'],'line_vp'=>(float)$i['line_vp'],
        ], $quote['items']),
    ];
    $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if ($rawJson === false) throw new RuntimeException('Product sale source payload could not be encoded.');
    $externalId = 'product-quote-' . (int)$quote['id'];
    $hash = hash('sha256',$rawJson);

    $pdo->beginTransaction();
    try {
        $raw = $pdo->prepare("INSERT INTO raw_source_records
            (organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status)
            VALUES(?,?,?,?,?,NOW(),?,?,'pending')");
        $raw->execute([$orgId,$clubId,$sourceId,'Product Sale',$externalId,$hash,$rawJson]);
        $rawId = (int)$pdo->lastInsertId();

        $gross = (float)$quote['subtotal_mrp'] + (float)$quote['delivery_charge'];
        $discount = (float)$quote['saving_amount'];
        $net = (float)$quote['grand_total'];
        $notes = json_encode([
            'quote_id'=>(int)$quote['id'],'quote_code'=>(string)$quote['quote_code'],
            'customer_name_snapshot'=>(string)($quote['customer_name'] ?? ''),
            'pricing_tier_code'=>(string)$quote['pricing_tier_code'],
            'product_payable'=>(float)$quote['payable_amount'],'delivery_charge'=>(float)$quote['delivery_charge'],
            'profit_basis'=>'deferred_no_guess','product_line_items'=>'product_order_items'
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $order = $pdo->prepare("INSERT INTO orders
            (organization_id,club_id,member_id,order_date,order_type,invoice_no,description,gross_amount,discount_amount,net_amount,profit_amount,currency_code,volume_points,notes,source_record_id,source_sheet,source_key)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $order->execute([
            $orgId,$clubId,$memberId,$orderDate,'product_sale',null,
            'Product & Price Pro sale • ' . (string)$quote['quote_code'],
            $gross,$discount,$net,0,'INR',(float)$quote['total_vp'],$notes,$rawId,'Product Sale','product-sale-quote:' . (int)$quote['id']
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $item = $pdo->prepare("INSERT INTO product_order_items
            (organization_id,order_id,quote_id,product_id,listing_id,price_version_id,stock_no,product_name_snapshot,pricing_tier_code,quantity,unit_mrp,unit_price,unit_vp,line_mrp,line_price,line_vp)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($quote['items'] as $i) {
            $item->execute([
                $orgId,$orderId,(int)$quote['id'],(int)$i['product_id'],(int)$i['listing_id'],(int)$i['price_version_id'],
                (string)$i['stock_no'],(string)$i['product_name'],(string)$quote['pricing_tier_code'],(float)$i['quantity'],
                (float)$i['unit_mrp'],(float)$i['unit_price'],(float)$i['unit_vp'],(float)$i['line_mrp'],(float)$i['line_price'],(float)$i['line_vp']
            ]);
        }

        $link = $pdo->prepare("INSERT INTO product_quote_order_links(organization_id,quote_id,order_id) VALUES(?,?,?)");
        $link->execute([$orgId,(int)$quote['id'],$orderId]);
        $pdo->prepare("UPDATE product_quotes SET status='converted' WHERE organization_id=? AND id=?")->execute([$orgId,(int)$quote['id']]);
        $pdo->prepare("UPDATE raw_source_records SET mapping_status='mapped',mapped_entity_type='order',mapped_entity_id=? WHERE id=? AND organization_id=?")->execute([$orderId,$rawId,$orgId]);

        if (business_table_exists($pdo,'audit_logs')) {
            $auditDetails = json_encode([
                'quote_id'=>(int)$quote['id'],'quote_code'=>(string)$quote['quote_code'],'order_id'=>$orderId,
                'explicit_member_id'=>$memberId,'order_date'=>$orderDate,'gross_amount'=>$gross,'discount_amount'=>$discount,
                'net_amount'=>$net,'volume_points'=>(float)$quote['total_vp'],'profit_amount'=>0,
                'profit_policy'=>'deferred_no_cost_basis_guess','source_record_id'=>$rawId
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $audit = $pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent)
                VALUES(?,?,'product_quote_finalized_to_order','order',?,?,?,?)");
            $audit->execute([$orgId,$clubId,$orderId,$auditDetails,substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
        }

        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
