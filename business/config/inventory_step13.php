<?php
declare(strict_types=1);

require_once __DIR__ . '/product_step12.php';

const INVENTORY_STEP13_LOCATION_CODE = 'MAIN';
const INVENTORY_STEP13_SOURCE_CODE = 'INVENTORY';

function inventory_step13_tables(): array
{
    return [
        'inventory_locations',
        'inventory_product_settings',
        'inventory_batches',
        'inventory_transactions',
        'inventory_sale_allocations',
        'inventory_stock_counts',
        'inventory_stock_count_lines',
    ];
}

function inventory_step13_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/009_step13_complete_inventory.sql';
    if (!is_file($file)) {
        throw new RuntimeException('STEP 13 inventory migration is missing.');
    }

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

function inventory_step13_ensure(PDO $pdo): void
{
    product_step12_ensure($pdo);

    foreach (inventory_step13_tables() as $table) {
        if (!business_table_exists($pdo, $table)) {
            inventory_step13_run_migration($pdo);
            break;
        }
    }

    foreach (inventory_step13_tables() as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException('STEP 13 table missing: ' . $table);
        }
    }

    $ctx = product_step12_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];

    $stmt = $pdo->prepare(
        "INSERT INTO inventory_locations
         (organization_id,club_id,location_code,location_name,location_type,status)
         VALUES(?,?,?,'Main Club Stock','store','active')
         ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),club_id=VALUES(club_id),status='active'"
    );
    $stmt->execute([$orgId, $clubId, INVENTORY_STEP13_LOCATION_CODE]);

    $locationId = inventory_step13_location_id($pdo, $orgId);
    $stmt = $pdo->prepare(
        "INSERT INTO inventory_product_settings
         (organization_id,location_id,product_id,listing_id,track_stock,allow_negative,reorder_level,target_stock,expiry_alert_days)
         SELECT l.organization_id, ?, l.product_id, l.id, 1, 0, 0, 0, 60
         FROM product_market_listings l
         WHERE l.organization_id=? AND l.status='active'
         ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)"
    );
    $stmt->execute([$locationId, $orgId]);

    if (business_table_exists($pdo, 'schema_meta')) {
        $pdo->exec(
            "INSERT INTO schema_meta(meta_key,meta_value)
             VALUES('inventory_step13_version','1.0-complete')
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
        );
        $pdo->exec(
            "INSERT IGNORE INTO schema_meta(meta_key,meta_value)
             VALUES('inventory_step13_activated_at', DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))"
        );
    }
}

function inventory_step13_context(PDO $pdo): array
{
    inventory_step13_ensure($pdo);
    $ctx = product_step12_context($pdo);
    $ctx['location_id'] = inventory_step13_location_id($pdo, (int)$ctx['organization_id']);
    return $ctx;
}

function inventory_step13_location_id(PDO $pdo, int $organizationId): int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM inventory_locations
         WHERE organization_id=? AND location_code=? AND status='active'
         LIMIT 1"
    );
    $stmt->execute([$organizationId, INVENTORY_STEP13_LOCATION_CODE]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Main inventory location was not found.');
    return $id;
}

function inventory_step13_source(PDO $pdo, int $organizationId): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO data_sources(organization_id,source_code,source_name,source_type,is_active)
         VALUES(?,?,'Inventory & Stock Management','manual',1)
         ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_type=VALUES(source_type),is_active=1"
    );
    $stmt->execute([$organizationId, INVENTORY_STEP13_SOURCE_CODE]);

    $stmt = $pdo->prepare(
        "SELECT id FROM data_sources WHERE organization_id=? AND source_code=? LIMIT 1"
    );
    $stmt->execute([$organizationId, INVENTORY_STEP13_SOURCE_CODE]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Inventory data source could not be prepared.');
    return $id;
}

function inventory_step13_json(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Inventory JSON payload could not be encoded.');
    return $json;
}

function inventory_step13_raw_event(
    PDO $pdo,
    int $orgId,
    int $clubId,
    string $dataset,
    string $externalId,
    array $payload,
    string $entityType,
    ?int $entityId = null
): int {
    $sourceId = inventory_step13_source($pdo, $orgId);
    $rawJson = inventory_step13_json($payload);
    $hash = hash('sha256', $rawJson);
    $stmt = $pdo->prepare(
        "INSERT INTO raw_source_records
         (organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id)
         VALUES(?,?,?,?,?,NOW(),?,?,'mapped',?,?)"
    );
    $stmt->execute([$orgId, $clubId, $sourceId, $dataset, $externalId, $hash, $rawJson, $entityType, $entityId]);
    return (int)$pdo->lastInsertId();
}

function inventory_step13_audit(PDO $pdo, int $orgId, int $clubId, string $event, string $entityType, ?int $entityId, array $details): void
{
    if (!business_table_exists($pdo, 'audit_logs')) return;
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs
         (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent)
         VALUES(?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $orgId,
        $clubId,
        $event,
        $entityType,
        $entityId,
        inventory_step13_json($details),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

function inventory_step13_date(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Choose a valid ' . $label . '.');
    }
    return $value;
}

function inventory_step13_listing(PDO $pdo, int $orgId, int $productId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id product_id,p.product_name,p.sku,l.id listing_id,l.market_id
         FROM products p
         JOIN product_market_listings l ON l.organization_id=p.organization_id AND l.product_id=p.id
         WHERE p.organization_id=? AND p.id=? AND p.status='active' AND l.status='active'
         LIMIT 1"
    );
    $stmt->execute([$orgId, $productId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Active product listing was not found.');
    return $row;
}

function inventory_step13_setting(PDO $pdo, int $orgId, int $locationId, int $listingId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM inventory_product_settings
         WHERE organization_id=? AND location_id=? AND listing_id=? LIMIT 1"
    );
    $stmt->execute([$orgId, $locationId, $listingId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Inventory product setting was not found.');
    return $row;
}

function inventory_step13_stock_for_listing(PDO $pdo, int $orgId, int $locationId, int $listingId, ?string $asOfDate = null): float
{
    $asOfDate = $asOfDate ?: date('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(current_quantity),0)
         FROM inventory_batches
         WHERE organization_id=? AND location_id=? AND listing_id=? AND status='active'
           AND current_quantity>0
           AND (expiry_date IS NULL OR expiry_date>=?)"
    );
    $stmt->execute([$orgId, $locationId, $listingId, $asOfDate]);
    return round((float)$stmt->fetchColumn(), 3);
}

function inventory_step13_all_stock_for_listing(PDO $pdo, int $orgId, int $locationId, int $listingId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(current_quantity),0)
         FROM inventory_batches
         WHERE organization_id=? AND location_id=? AND listing_id=? AND status='active'"
    );
    $stmt->execute([$orgId, $locationId, $listingId]);
    return round((float)$stmt->fetchColumn(), 3);
}

function inventory_step13_stock_rows(PDO $pdo, int $orgId, int $locationId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id product_id,p.product_name,p.sku,p.pack_size,p.pack_unit,l.id listing_id,
                s.track_stock,s.allow_negative,s.reorder_level,s.target_stock,s.expiry_alert_days,
                COALESCE(SUM(CASE WHEN b.status='active' THEN b.current_quantity ELSE 0 END),0) stock_total,
                COALESCE(SUM(CASE WHEN b.status='active' AND b.current_quantity>0 AND (b.expiry_date IS NULL OR b.expiry_date>=CURDATE()) THEN b.current_quantity ELSE 0 END),0) sellable_stock,
                COALESCE(SUM(CASE WHEN b.status='active' AND b.current_quantity>0 AND b.expiry_date<CURDATE() THEN b.current_quantity ELSE 0 END),0) expired_stock,
                MIN(CASE WHEN b.status='active' AND b.current_quantity>0 AND b.expiry_date>=CURDATE() THEN b.expiry_date END) next_expiry,
                SUM(CASE WHEN b.status='active' AND b.current_quantity>0 THEN 1 ELSE 0 END) live_batches,
                SUM(CASE WHEN b.status='active' AND b.current_quantity>0 AND b.unit_cost IS NOT NULL THEN b.current_quantity*b.unit_cost ELSE 0 END) known_value,
                SUM(CASE WHEN b.status='active' AND b.current_quantity>0 AND b.unit_cost IS NULL THEN 1 ELSE 0 END) unvalued_batches
         FROM inventory_product_settings s
         JOIN product_market_listings l ON l.id=s.listing_id AND l.organization_id=s.organization_id
         JOIN products p ON p.id=s.product_id AND p.organization_id=s.organization_id
         LEFT JOIN inventory_batches b ON b.organization_id=s.organization_id AND b.location_id=s.location_id AND b.listing_id=s.listing_id
         WHERE s.organization_id=? AND s.location_id=?
         GROUP BY p.id,p.product_name,p.sku,p.pack_size,p.pack_unit,l.id,s.track_stock,s.allow_negative,s.reorder_level,s.target_stock,s.expiry_alert_days
         ORDER BY p.product_name,p.id"
    );
    $stmt->execute([$orgId, $locationId]);
    return $stmt->fetchAll();
}

function inventory_step13_batch_code(int $listingId, string $batchCode): string
{
    $batchCode = trim($batchCode);
    return $batchCode !== '' ? (function_exists('mb_substr') ? mb_substr($batchCode, 0, 120, 'UTF-8') : substr($batchCode, 0, 120)) : 'UNBATCHED-' . $listingId;
}

function inventory_step13_add_stock(
    PDO $pdo,
    int $productId,
    float $quantity,
    string $movementDate,
    string $movementType,
    string $batchCode = '',
    ?string $expiryDate = null,
    ?string $manufactureDate = null,
    ?float $unitCost = null,
    string $supplierName = '',
    string $purchaseReference = '',
    string $notes = '',
    bool $useAsProfitCost = false
): int {
    inventory_step13_ensure($pdo);
    if ($quantity <= 0) throw new RuntimeException('Stock quantity must be greater than zero.');
    if (!in_array($movementType, ['opening','purchase','customer_return','adjustment_plus'], true)) {
        throw new RuntimeException('Positive inventory movement type is invalid.');
    }
    inventory_step13_date($movementDate, 'movement date');
    if ($expiryDate !== null && trim($expiryDate) !== '') inventory_step13_date($expiryDate, 'expiry date');
    else $expiryDate = null;
    if ($manufactureDate !== null && trim($manufactureDate) !== '') inventory_step13_date($manufactureDate, 'manufacture date');
    else $manufactureDate = null;
    if ($unitCost !== null && $unitCost < 0) throw new RuntimeException('Unit cost cannot be negative.');

    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];
    $locationId = (int)$ctx['location_id'];
    $listing = inventory_step13_listing($pdo, $orgId, $productId);
    $listingId = (int)$listing['listing_id'];
    $batchCode = inventory_step13_batch_code($listingId, $batchCode);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM inventory_batches
             WHERE organization_id=? AND location_id=? AND listing_id=? AND batch_code=?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$orgId, $locationId, $listingId, $batchCode]);
        $batch = $stmt->fetch();

        if ($batch) {
            $batchId = (int)$batch['id'];
            $stmt = $pdo->prepare(
                "UPDATE inventory_batches
                 SET received_quantity=received_quantity+?,current_quantity=current_quantity+?,
                     manufacture_date=COALESCE(?,manufacture_date),expiry_date=COALESCE(?,expiry_date),
                     supplier_name=CASE WHEN ?<>'' THEN ? ELSE supplier_name END,
                     purchase_reference=CASE WHEN ?<>'' THEN ? ELSE purchase_reference END,
                     unit_cost=COALESCE(?,unit_cost),status='active'
                 WHERE id=? AND organization_id=?"
            );
            $stmt->execute([
                $quantity,
                $quantity,
                $manufactureDate,
                $expiryDate,
                $supplierName,
                $supplierName,
                $purchaseReference,
                $purchaseReference,
                $unitCost,
                $batchId,
                $orgId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO inventory_batches
                 (organization_id,location_id,product_id,listing_id,batch_code,manufacture_date,expiry_date,supplier_name,purchase_reference,unit_cost,received_quantity,current_quantity,status)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'active')"
            );
            $stmt->execute([
                $orgId,
                $locationId,
                $productId,
                $listingId,
                $batchCode,
                $manufactureDate,
                $expiryDate,
                trim($supplierName) ?: null,
                trim($purchaseReference) ?: null,
                $unitCost,
                $quantity,
                $quantity,
            ]);
            $batchId = (int)$pdo->lastInsertId();
        }

        $rawId = inventory_step13_raw_event(
            $pdo,
            $orgId,
            $clubId,
            'Inventory Inward',
            'inventory-inward-' . $batchId . '-' . time(),
            [
                'movement_type' => $movementType,
                'product_id' => $productId,
                'listing_id' => $listingId,
                'batch_id' => $batchId,
                'batch_code' => $batchCode,
                'quantity' => $quantity,
                'movement_date' => $movementDate,
                'expiry_date' => $expiryDate,
                'manufacture_date' => $manufactureDate,
                'unit_cost' => $unitCost,
                'supplier_name' => trim($supplierName),
                'purchase_reference' => trim($purchaseReference),
                'notes' => trim($notes),
            ],
            'inventory_batch',
            $batchId
        );

        $stmt = $pdo->prepare(
            "INSERT INTO inventory_transactions
             (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,unit_cost,reference_type,reference_id,source_reference,notes,status,raw_source_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?)"
        );
        $stmt->execute([
            $orgId,
            $clubId,
            $locationId,
            $productId,
            $listingId,
            $batchId,
            $movementType,
            $movementDate,
            $quantity,
            $unitCost,
            'inventory_batch',
            $batchId,
            trim($purchaseReference) ?: null,
            trim($notes) ?: null,
            $rawId,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        inventory_step13_audit($pdo, $orgId, $clubId, 'inventory_stock_added', 'inventory_transaction', $transactionId, [
            'product_id' => $productId,
            'batch_id' => $batchId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'raw_source_id' => $rawId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($useAsProfitCost && $unitCost !== null) {
        $sourceRef = trim($purchaseReference) !== '' ? trim($purchaseReference) : ('Inventory inward transaction #' . $transactionId);
        product_step12_add_cost($pdo, $productId, $movementDate, $unitCost, $sourceRef, 'Created from explicit STEP 13 inventory inward cost.');
    }

    return $transactionId;
}

function inventory_step13_adjust_batch(PDO $pdo, int $batchId, float $quantityDelta, string $movementDate, string $movementType, string $reference = '', string $notes = ''): int
{
    inventory_step13_ensure($pdo);
    if (abs($quantityDelta) < 0.0005) throw new RuntimeException('Adjustment quantity cannot be zero.');
    inventory_step13_date($movementDate, 'adjustment date');
    $allowed = ['damage','loss','supplier_return','adjustment_minus','adjustment_plus','customer_return','stock_count'];
    if (!in_array($movementType, $allowed, true)) throw new RuntimeException('Inventory adjustment type is invalid.');

    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT b.*,s.allow_negative
             FROM inventory_batches b
             JOIN inventory_product_settings s ON s.organization_id=b.organization_id AND s.location_id=b.location_id AND s.listing_id=b.listing_id
             WHERE b.organization_id=? AND b.id=? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$orgId, $batchId]);
        $batch = $stmt->fetch();
        if (!$batch) throw new RuntimeException('Inventory batch was not found.');

        $newQty = round((float)$batch['current_quantity'] + $quantityDelta, 3);
        if ($newQty < -0.0005 && !(bool)$batch['allow_negative']) {
            throw new RuntimeException('Adjustment would create negative stock.');
        }

        $rawId = inventory_step13_raw_event(
            $pdo,
            $orgId,
            $clubId,
            'Inventory Adjustment',
            'inventory-adjust-' . $batchId . '-' . time(),
            [
                'batch_id' => $batchId,
                'product_id' => (int)$batch['product_id'],
                'listing_id' => (int)$batch['listing_id'],
                'movement_type' => $movementType,
                'quantity_delta' => $quantityDelta,
                'before_quantity' => (float)$batch['current_quantity'],
                'after_quantity' => $newQty,
                'reference' => trim($reference),
                'notes' => trim($notes),
            ],
            'inventory_batch',
            $batchId
        );

        $pdo->prepare(
            "UPDATE inventory_batches SET current_quantity=? WHERE organization_id=? AND id=?"
        )->execute([$newQty, $orgId, $batchId]);

        $stmt = $pdo->prepare(
            "INSERT INTO inventory_transactions
             (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,unit_cost,reference_type,reference_id,source_reference,notes,status,raw_source_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?)"
        );
        $stmt->execute([
            $orgId,
            $clubId,
            (int)$batch['location_id'],
            (int)$batch['product_id'],
            (int)$batch['listing_id'],
            $batchId,
            $movementType,
            $movementDate,
            $quantityDelta,
            $batch['unit_cost'] !== null ? (float)$batch['unit_cost'] : null,
            'inventory_batch',
            $batchId,
            trim($reference) ?: null,
            trim($notes) ?: null,
            $rawId,
        ]);
        $txId = (int)$pdo->lastInsertId();

        inventory_step13_audit($pdo, $orgId, $clubId, 'inventory_stock_adjusted', 'inventory_transaction', $txId, [
            'batch_id' => $batchId,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta,
            'before_quantity' => (float)$batch['current_quantity'],
            'after_quantity' => $newQty,
            'raw_source_id' => $rawId,
        ]);
        $pdo->commit();
        return $txId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function inventory_step13_quote_shortages(PDO $pdo, int $quoteId, string $asOfDate): array
{
    inventory_step13_ensure($pdo);
    inventory_step13_date($asOfDate, 'sale date');
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $locationId = (int)$ctx['location_id'];

    $stmt = $pdo->prepare(
        "SELECT qi.listing_id,qi.product_id,qi.product_name,SUM(qi.quantity) required_qty,
                s.track_stock,s.allow_negative
         FROM product_quote_items qi
         JOIN product_quotes q ON q.id=qi.quote_id
         JOIN inventory_product_settings s ON s.organization_id=q.organization_id AND s.location_id=? AND s.listing_id=qi.listing_id
         WHERE q.organization_id=? AND q.id=?
         GROUP BY qi.listing_id,qi.product_id,qi.product_name,s.track_stock,s.allow_negative"
    );
    $stmt->execute([$locationId, $orgId, $quoteId]);

    $shortages = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!(bool)$row['track_stock'] || (bool)$row['allow_negative']) continue;
        $available = inventory_step13_stock_for_listing($pdo, $orgId, $locationId, (int)$row['listing_id'], $asOfDate);
        $required = round((float)$row['required_qty'], 3);
        if ($available + 0.0005 < $required) {
            $shortages[] = [
                'listing_id' => (int)$row['listing_id'],
                'product_id' => (int)$row['product_id'],
                'product_name' => (string)$row['product_name'],
                'required' => $required,
                'available' => $available,
                'shortage' => round($required - $available, 3),
            ];
        }
    }
    return $shortages;
}

function inventory_step13_assert_quote_stock(PDO $pdo, int $quoteId, string $asOfDate): void
{
    $shortages = inventory_step13_quote_shortages($pdo, $quoteId, $asOfDate);
    if (!$shortages) return;
    $first = $shortages[0];
    throw new RuntimeException(
        'Insufficient stock for ' . $first['product_name'] . '. Required ' . $first['required'] . ', available ' . $first['available'] . '. Add stock before finalizing this sale.'
    );
}

function inventory_step13_active_allocated_quantity(PDO $pdo, int $orgId, int $orderItemId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity),0) FROM inventory_sale_allocations
         WHERE organization_id=? AND order_item_id=? AND status='active'"
    );
    $stmt->execute([$orgId, $orderItemId]);
    return round((float)$stmt->fetchColumn(), 3);
}

function inventory_step13_post_sale(PDO $pdo, int $orderId): void
{
    inventory_step13_ensure($pdo);
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];
    $locationId = (int)$ctx['location_id'];

    $stmt = $pdo->prepare(
        "SELECT o.id,o.order_date,o.source_sheet,l.quote_id
         FROM orders o
         JOIN product_quote_order_links l ON l.organization_id=o.organization_id AND l.order_id=o.id
         WHERE o.organization_id=? AND o.id=? AND o.order_type='product_sale' LIMIT 1"
    );
    $stmt->execute([$orgId, $orderId]);
    $order = $stmt->fetch();
    if (!$order) throw new RuntimeException('Product Sale order was not found for inventory posting.');
    if ((string)$order['source_sheet'] === PRODUCT_SALE_REVERSED_SOURCE_SHEET) return;

    inventory_step13_assert_quote_stock($pdo, (int)$order['quote_id'], (string)$order['order_date']);

    $stmt = $pdo->prepare(
        "SELECT i.*,s.track_stock,s.allow_negative
         FROM product_order_items i
         JOIN inventory_product_settings s ON s.organization_id=i.organization_id AND s.location_id=? AND s.listing_id=i.listing_id
         WHERE i.organization_id=? AND i.order_id=? ORDER BY i.id"
    );
    $stmt->execute([$locationId, $orgId, $orderId]);
    $items = $stmt->fetchAll();

    $alreadyComplete = true;
    foreach ($items as $item) {
        if (!(bool)$item['track_stock']) continue;
        $allocated = inventory_step13_active_allocated_quantity($pdo, $orgId, (int)$item['id']);
        if (abs($allocated - (float)$item['quantity']) > 0.0005) {
            $alreadyComplete = false;
            break;
        }
    }
    if ($alreadyComplete) return;

    $pdo->beginTransaction();
    try {
        $rawId = inventory_step13_raw_event(
            $pdo,
            $orgId,
            $clubId,
            'Inventory Sale Issue',
            'inventory-sale-' . $orderId,
            ['order_id' => $orderId, 'quote_id' => (int)$order['quote_id'], 'movement_date' => (string)$order['order_date']],
            'order',
            $orderId
        );

        $allocationSummary = [];
        foreach ($items as $item) {
            if (!(bool)$item['track_stock']) continue;
            $orderItemId = (int)$item['id'];
            $required = round((float)$item['quantity'], 3);
            $allocated = inventory_step13_active_allocated_quantity($pdo, $orgId, $orderItemId);
            if ($allocated > 0.0005) {
                if (abs($allocated - $required) <= 0.0005) continue;
                throw new RuntimeException('Partial inventory allocation already exists for Order Item #' . $orderItemId . '.');
            }

            $remaining = $required;
            $batchStmt = $pdo->prepare(
                "SELECT * FROM inventory_batches
                 WHERE organization_id=? AND location_id=? AND listing_id=? AND status='active'
                   AND current_quantity>0
                   AND (expiry_date IS NULL OR expiry_date>=?)
                 ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date, created_at, id
                 FOR UPDATE"
            );
            $batchStmt->execute([$orgId, $locationId, (int)$item['listing_id'], (string)$order['order_date']]);
            $batches = $batchStmt->fetchAll();

            foreach ($batches as $batch) {
                if ($remaining <= 0.0005) break;
                $available = (float)$batch['current_quantity'];
                if ($available <= 0.0005) continue;
                $take = min($remaining, $available);
                $newQty = round($available - $take, 3);

                $pdo->prepare(
                    "UPDATE inventory_batches SET current_quantity=? WHERE organization_id=? AND id=?"
                )->execute([$newQty, $orgId, (int)$batch['id']]);

                $tx = $pdo->prepare(
                    "INSERT INTO inventory_transactions
                     (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,unit_cost,reference_type,reference_id,source_reference,notes,status,raw_source_id)
                     VALUES(?,?,?,?,?,?, 'sale', ?, ?, ?, 'order', ?, ?, ?, 'active', ?)"
                );
                $tx->execute([
                    $orgId,
                    $clubId,
                    $locationId,
                    (int)$item['product_id'],
                    (int)$item['listing_id'],
                    (int)$batch['id'],
                    (string)$order['order_date'],
                    -$take,
                    $batch['unit_cost'] !== null ? (float)$batch['unit_cost'] : null,
                    $orderId,
                    'Product Sale Order #' . $orderId,
                    'Automatic FEFO stock deduction',
                    $rawId,
                ]);
                $txId = (int)$pdo->lastInsertId();

                $alloc = $pdo->prepare(
                    "INSERT INTO inventory_sale_allocations
                     (organization_id,order_id,order_item_id,batch_id,inventory_transaction_id,quantity,status)
                     VALUES(?,?,?,?,?,?,'active')"
                );
                $alloc->execute([$orgId, $orderId, $orderItemId, (int)$batch['id'], $txId, $take]);

                $allocationSummary[] = [
                    'order_item_id' => $orderItemId,
                    'batch_id' => (int)$batch['id'],
                    'quantity' => $take,
                ];
                $remaining = round($remaining - $take, 3);
            }

            if ($remaining > 0.0005 && !(bool)$item['allow_negative']) {
                throw new RuntimeException('Inventory changed during sale posting and stock is now insufficient. No stock movement was committed.');
            }
        }

        inventory_step13_audit($pdo, $orgId, $clubId, 'inventory_sale_posted', 'order', $orderId, [
            'order_id' => $orderId,
            'allocation_count' => count($allocationSummary),
            'allocations' => $allocationSummary,
            'raw_source_id' => $rawId,
            'allocation_policy' => 'FEFO',
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function inventory_step13_reverse_sale(PDO $pdo, int $orderId, string $reason): void
{
    inventory_step13_ensure($pdo);
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];

    $stmt = $pdo->prepare(
        "SELECT a.*,t.location_id,t.product_id,t.listing_id,t.unit_cost
         FROM inventory_sale_allocations a
         JOIN inventory_transactions t ON t.id=a.inventory_transaction_id AND t.organization_id=a.organization_id
         WHERE a.organization_id=? AND a.order_id=? AND a.status='active'
         ORDER BY a.id FOR UPDATE"
    );

    $pdo->beginTransaction();
    try {
        $stmt->execute([$orgId, $orderId]);
        $allocations = $stmt->fetchAll();
        if (!$allocations) {
            $pdo->commit();
            return;
        }

        $rawId = inventory_step13_raw_event(
            $pdo,
            $orgId,
            $clubId,
            'Inventory Sale Reversal',
            'inventory-sale-reversal-' . $orderId . '-' . time(),
            ['order_id' => $orderId, 'reason' => trim($reason)],
            'order',
            $orderId
        );

        foreach ($allocations as $a) {
            $pdo->prepare(
                "UPDATE inventory_batches SET current_quantity=current_quantity+? WHERE organization_id=? AND id=?"
            )->execute([(float)$a['quantity'], $orgId, (int)$a['batch_id']]);

            $tx = $pdo->prepare(
                "INSERT INTO inventory_transactions
                 (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,unit_cost,reference_type,reference_id,source_reference,notes,status,reversal_of_id,raw_source_id)
                 VALUES(?,?,?,?,?,?, 'sale_reversal', CURDATE(), ?, ?, 'order', ?, ?, ?, 'active', ?, ?)"
            );
            $tx->execute([
                $orgId,
                $clubId,
                (int)$a['location_id'],
                (int)$a['product_id'],
                (int)$a['listing_id'],
                (int)$a['batch_id'],
                (float)$a['quantity'],
                $a['unit_cost'] !== null ? (float)$a['unit_cost'] : null,
                $orderId,
                'Product Sale Order #' . $orderId,
                trim($reason),
                (int)$a['inventory_transaction_id'],
                $rawId,
            ]);
            $pdo->prepare(
                "UPDATE inventory_sale_allocations SET status='reversed' WHERE organization_id=? AND id=?"
            )->execute([$orgId, (int)$a['id']]);
        }

        inventory_step13_audit($pdo, $orgId, $clubId, 'inventory_sale_reversed', 'order', $orderId, [
            'order_id' => $orderId,
            'reason' => trim($reason),
            'allocation_count' => count($allocations),
            'raw_source_id' => $rawId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function inventory_step13_restore_sale(PDO $pdo, int $orderId): void
{
    inventory_step13_ensure($pdo);
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $stmt = $pdo->prepare(
        "SELECT q.id quote_id,o.order_date
         FROM orders o
         JOIN product_quote_order_links l ON l.organization_id=o.organization_id AND l.order_id=o.id
         JOIN product_quotes q ON q.organization_id=l.organization_id AND q.id=l.quote_id
         WHERE o.organization_id=? AND o.id=? LIMIT 1"
    );
    $stmt->execute([$orgId, $orderId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Original Product Sale quote was not found for stock restore.');
    inventory_step13_assert_quote_stock($pdo, (int)$row['quote_id'], (string)$row['order_date']);
    inventory_step13_post_sale($pdo, $orderId);
}

function inventory_step13_activation_time(PDO $pdo): ?string
{
    if (!business_table_exists($pdo, 'schema_meta')) return null;
    $stmt = $pdo->prepare("SELECT meta_value FROM schema_meta WHERE meta_key='inventory_step13_activated_at' LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function inventory_step13_pending_sales(PDO $pdo, int $orgId): array
{
    $activation = inventory_step13_activation_time($pdo);
    if ($activation === null) return [];
    $locationId = inventory_step13_location_id($pdo, $orgId);
    $stmt = $pdo->prepare(
        "SELECT DISTINCT o.id order_id,o.order_date,q.quote_code,q.customer_name,o.created_at
         FROM orders o
         JOIN product_quote_order_links ql ON ql.organization_id=o.organization_id AND ql.order_id=o.id
         JOIN product_quotes q ON q.organization_id=ql.organization_id AND q.id=ql.quote_id
         JOIN product_order_items i ON i.organization_id=o.organization_id AND i.order_id=o.id
         JOIN inventory_product_settings s ON s.organization_id=i.organization_id AND s.location_id=? AND s.listing_id=i.listing_id AND s.track_stock=1
         WHERE o.organization_id=? AND o.order_type='product_sale' AND o.source_sheet=?
           AND o.created_at>=?
           AND NOT EXISTS(
               SELECT 1 FROM inventory_sale_allocations a
               WHERE a.organization_id=o.organization_id AND a.order_id=o.id AND a.status='active'
           )
         ORDER BY o.id"
    );
    $stmt->execute([$locationId, $orgId, PRODUCT_SALE_SOURCE_SHEET, $activation]);
    return $stmt->fetchAll();
}

function inventory_step13_update_setting(PDO $pdo, int $productId, bool $trackStock, float $reorderLevel, float $targetStock, int $expiryAlertDays): void
{
    inventory_step13_ensure($pdo);
    if ($reorderLevel < 0 || $targetStock < 0) throw new RuntimeException('Reorder and target stock cannot be negative.');
    if ($expiryAlertDays < 0 || $expiryAlertDays > 3650) throw new RuntimeException('Expiry alert days must be between 0 and 3650.');
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $locationId = (int)$ctx['location_id'];
    $listing = inventory_step13_listing($pdo, $orgId, $productId);
    $pdo->prepare(
        "UPDATE inventory_product_settings
         SET track_stock=?,reorder_level=?,target_stock=?,expiry_alert_days=?
         WHERE organization_id=? AND location_id=? AND listing_id=?"
    )->execute([
        $trackStock ? 1 : 0,
        $reorderLevel,
        $targetStock,
        $expiryAlertDays,
        $orgId,
        $locationId,
        (int)$listing['listing_id'],
    ]);
    inventory_step13_audit($pdo, $orgId, (int)$ctx['club_id'], 'inventory_setting_updated', 'product', $productId, [
        'track_stock' => $trackStock,
        'reorder_level' => $reorderLevel,
        'target_stock' => $targetStock,
        'expiry_alert_days' => $expiryAlertDays,
    ]);
}

function inventory_step13_post_stock_count(PDO $pdo, int $productId, float $countedQuantity, string $countDate, string $reference = '', string $notes = ''): int
{
    inventory_step13_ensure($pdo);
    if ($countedQuantity < 0) throw new RuntimeException('Counted stock cannot be negative.');
    inventory_step13_date($countDate, 'stock count date');
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $locationId = (int)$ctx['location_id'];
    $listing = inventory_step13_listing($pdo, $orgId, $productId);
    $listingId = (int)$listing['listing_id'];
    $systemQuantity = inventory_step13_all_stock_for_listing($pdo, $orgId, $locationId, $listingId);
    $variance = round($countedQuantity - $systemQuantity, 3);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO inventory_stock_counts(organization_id,location_id,count_date,reference_no,notes,status)
             VALUES(?,?,?,?,?,'posted')"
        );
        $stmt->execute([$orgId, $locationId, $countDate, trim($reference) ?: null, trim($notes) ?: null]);
        $countId = (int)$pdo->lastInsertId();

        $adjustmentTxId = null;
        if ($variance > 0.0005) {
            $pdo->commit();
            $adjustmentTxId = inventory_step13_add_stock(
                $pdo,
                $productId,
                $variance,
                $countDate,
                'adjustment_plus',
                'STOCKTAKE-' . $listingId,
                null,
                null,
                null,
                '',
                trim($reference),
                'Physical stock count increase. ' . trim($notes),
                false
            );
            $pdo->beginTransaction();
        } elseif ($variance < -0.0005) {
            $remaining = abs($variance);
            $stmt = $pdo->prepare(
                "SELECT id,current_quantity FROM inventory_batches
                 WHERE organization_id=? AND location_id=? AND listing_id=? AND status='active' AND current_quantity>0
                 ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date, created_at, id"
            );
            $stmt->execute([$orgId, $locationId, $listingId]);
            foreach ($stmt->fetchAll() as $batch) {
                if ($remaining <= 0.0005) break;
                $take = min($remaining, (float)$batch['current_quantity']);
                $pdo->commit();
                $adjustmentTxId = inventory_step13_adjust_batch(
                    $pdo,
                    (int)$batch['id'],
                    -$take,
                    $countDate,
                    'stock_count',
                    trim($reference),
                    'Physical stock count decrease. ' . trim($notes)
                );
                $remaining = round($remaining - $take, 3);
                $pdo->beginTransaction();
            }
            if ($remaining > 0.0005) throw new RuntimeException('Stock count variance could not be fully allocated.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO inventory_stock_count_lines
             (stock_count_id,product_id,listing_id,system_quantity,counted_quantity,variance_quantity,adjustment_transaction_id)
             VALUES(?,?,?,?,?,?,?)"
        );
        $stmt->execute([$countId, $productId, $listingId, $systemQuantity, $countedQuantity, $variance, $adjustmentTxId]);
        $pdo->commit();
        return $countId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
