<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_step13.php';

function inventory_step13_post_stock_count_atomic(PDO $pdo, int $productId, float $countedQuantity, string $countDate, string $reference = '', string $notes = ''): int
{
    inventory_step13_ensure($pdo);
    if ($countedQuantity < 0) throw new RuntimeException('Counted stock cannot be negative.');
    inventory_step13_date($countDate, 'stock count date');

    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $clubId = (int)$ctx['club_id'];
    $locationId = (int)$ctx['location_id'];
    $listing = inventory_step13_listing($pdo, $orgId, $productId);
    $listingId = (int)$listing['listing_id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM inventory_batches
             WHERE organization_id=? AND location_id=? AND listing_id=? AND status='active'
             ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,expiry_date,created_at,id
             FOR UPDATE"
        );
        $stmt->execute([$orgId, $locationId, $listingId]);
        $batches = $stmt->fetchAll();
        $systemQuantity = 0.0;
        foreach ($batches as $b) $systemQuantity += (float)$b['current_quantity'];
        $systemQuantity = round($systemQuantity, 3);
        $variance = round($countedQuantity - $systemQuantity, 3);

        $countStmt = $pdo->prepare(
            "INSERT INTO inventory_stock_counts(organization_id,location_id,count_date,reference_no,notes,status)
             VALUES(?,?,?,?,?,'posted')"
        );
        $countStmt->execute([$orgId, $locationId, $countDate, trim($reference) ?: null, trim($notes) ?: null]);
        $countId = (int)$pdo->lastInsertId();

        $rawId = inventory_step13_raw_event(
            $pdo,
            $orgId,
            $clubId,
            'Inventory Stocktake',
            'inventory-stocktake-' . $countId,
            [
                'stock_count_id' => $countId,
                'product_id' => $productId,
                'listing_id' => $listingId,
                'system_quantity' => $systemQuantity,
                'counted_quantity' => $countedQuantity,
                'variance_quantity' => $variance,
                'count_date' => $countDate,
                'reference' => trim($reference),
                'notes' => trim($notes),
            ],
            'inventory_stock_count',
            $countId
        );

        $lastTxId = null;
        if ($variance > 0.0005) {
            $batchCode = 'STOCKTAKE-' . $listingId;
            $batchStmt = $pdo->prepare(
                "SELECT * FROM inventory_batches
                 WHERE organization_id=? AND location_id=? AND listing_id=? AND batch_code=?
                 LIMIT 1 FOR UPDATE"
            );
            $batchStmt->execute([$orgId, $locationId, $listingId, $batchCode]);
            $batch = $batchStmt->fetch();
            if ($batch) {
                $batchId = (int)$batch['id'];
                $pdo->prepare(
                    "UPDATE inventory_batches
                     SET received_quantity=received_quantity+?,current_quantity=current_quantity+?,status='active'
                     WHERE organization_id=? AND id=?"
                )->execute([$variance, $variance, $orgId, $batchId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO inventory_batches
                     (organization_id,location_id,product_id,listing_id,batch_code,received_quantity,current_quantity,status)
                     VALUES(?,?,?,?,?,?,?,'active')"
                )->execute([$orgId, $locationId, $productId, $listingId, $batchCode, $variance, $variance]);
                $batchId = (int)$pdo->lastInsertId();
            }

            $tx = $pdo->prepare(
                "INSERT INTO inventory_transactions
                 (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,reference_type,reference_id,source_reference,notes,status,raw_source_id)
                 VALUES(?,?,?,?,?,?, 'stock_count', ?, ?, 'inventory_stock_count', ?, ?, ?, 'active', ?)"
            );
            $tx->execute([$orgId,$clubId,$locationId,$productId,$listingId,$batchId,$countDate,$variance,$countId,trim($reference)?:null,trim($notes)?:null,$rawId]);
            $lastTxId = (int)$pdo->lastInsertId();
        } elseif ($variance < -0.0005) {
            $remaining = abs($variance);
            foreach ($batches as $batch) {
                if ($remaining <= 0.0005) break;
                $available = max(0, (float)$batch['current_quantity']);
                if ($available <= 0.0005) continue;
                $take = min($remaining, $available);
                $newQty = round($available - $take, 3);
                $pdo->prepare("UPDATE inventory_batches SET current_quantity=? WHERE organization_id=? AND id=?")->execute([$newQty,$orgId,(int)$batch['id']]);
                $tx = $pdo->prepare(
                    "INSERT INTO inventory_transactions
                     (organization_id,club_id,location_id,product_id,listing_id,batch_id,movement_type,movement_date,quantity_delta,unit_cost,reference_type,reference_id,source_reference,notes,status,raw_source_id)
                     VALUES(?,?,?,?,?,?, 'stock_count', ?, ?, ?, 'inventory_stock_count', ?, ?, ?, 'active', ?)"
                );
                $tx->execute([$orgId,$clubId,$locationId,$productId,$listingId,(int)$batch['id'],$countDate,-$take,$batch['unit_cost']!==null?(float)$batch['unit_cost']:null,$countId,trim($reference)?:null,trim($notes)?:null,$rawId]);
                $lastTxId = (int)$pdo->lastInsertId();
                $remaining = round($remaining - $take, 3);
            }
            if ($remaining > 0.0005) throw new RuntimeException('Physical count variance exceeds system stock and cannot be allocated safely.');
        }

        $line = $pdo->prepare(
            "INSERT INTO inventory_stock_count_lines
             (stock_count_id,product_id,listing_id,system_quantity,counted_quantity,variance_quantity,adjustment_transaction_id)
             VALUES(?,?,?,?,?,?,?)"
        );
        $line->execute([$countId,$productId,$listingId,$systemQuantity,$countedQuantity,$variance,$lastTxId]);

        inventory_step13_audit($pdo,$orgId,$clubId,'inventory_stock_count_posted','inventory_stock_count',$countId,[
            'product_id'=>$productId,
            'system_quantity'=>$systemQuantity,
            'counted_quantity'=>$countedQuantity,
            'variance_quantity'=>$variance,
            'raw_source_id'=>$rawId,
        ]);

        $pdo->commit();
        return $countId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
