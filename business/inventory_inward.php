<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/inventory_step13.php';

if (empty($_SESSION['inventory_inward_csrf'])) $_SESSION['inventory_inward_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['inventory_inward_csrf'];
$error = null; $success = null; $products = []; $recent = [];

try {
    $pdo = business_db(); inventory_step13_ensure($pdo); $ctx = inventory_step13_context($pdo); $orgId = (int)$ctx['organization_id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token mismatch.');
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 0);
        $date = (string)($_POST['movement_date'] ?? date('Y-m-d'));
        $type = (string)($_POST['movement_type'] ?? 'purchase');
        $batch = trim((string)($_POST['batch_code'] ?? ''));
        $expiry = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
        $mfg = trim((string)($_POST['manufacture_date'] ?? '')) ?: null;
        $unitCostRaw = trim((string)($_POST['unit_cost'] ?? ''));
        $unitCost = $unitCostRaw === '' ? null : (float)$unitCostRaw;
        $supplier = trim((string)($_POST['supplier_name'] ?? ''));
        $reference = trim((string)($_POST['purchase_reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $useAsCost = isset($_POST['use_as_profit_cost']);
        $txId = inventory_step13_add_stock($pdo,$productId,$qty,$date,$type,$batch,$expiry,$mfg,$unitCost,$supplier,$reference,$notes,$useAsCost);
        $success = 'Inventory inward transaction #' . $txId . ' posted.';
    }
    $stmt = $pdo->prepare("SELECT p.id,p.product_name,p.sku FROM products p JOIN product_market_listings l ON l.product_id=p.id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND p.status='active' AND l.status='active' ORDER BY p.product_name,p.id");
    $stmt->execute([$orgId]); $products = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT t.*,p.product_name,p.sku,b.batch_code FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN inventory_batches b ON b.id=t.batch_id WHERE t.organization_id=? AND t.movement_type IN ('opening','purchase','customer_return','adjustment_plus') ORDER BY t.id DESC LIMIT 60");
    $stmt->execute([$orgId]); $recent = $stmt->fetchAll();
} catch (Throwable $e) { $error = $e->getMessage(); }
function ii_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ii_n(mixed $v):string{$x=number_format((float)$v,3,'.','');return rtrim(rtrim($x,'0'),'.');}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Stock Inward - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/product_pro.css"></head><body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="inventory_center.php"><img src="../img/logo.png" alt="logo"><span><strong>Healthcare Wellness Club</strong><small>STEP 13 • Stock Inward</small></span></a><div class="os-top-actions"><a class="os-btn" href="inventory_center.php">Inventory</a><a class="os-btn" href="inventory_adjustments.php">Adjust</a><a class="os-btn primary" href="step13_audit.php">Audit</a></div></div></header>
<div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Inventory</div><nav class="os-nav"><a href="inventory_center.php"><i class="dot"></i>Inventory Center</a><a class="active" href="inventory_inward.php"><i class="dot"></i>Stock Inward</a><a href="inventory_adjustments.php"><i class="dot"></i>Damage / Returns</a><a href="inventory_stocktake.php"><i class="dot"></i>Physical Stocktake</a><a href="inventory_settings.php"><i class="dot"></i>Reorder Settings</a><a href="inventory_analytics.php"><i class="dot"></i>Analytics</a></nav></aside>
<main class="os-main"><section class="os-hero pp-hero"><div class="os-kicker">STEP 13A • STOCK INWARD</div><h1>Record real opening stock and purchase receipts with batch-level traceability.</h1><p>Quantity is never inferred from the catalog. Batch, expiry, supplier, invoice and unit cost are preserved as operational evidence.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'STOCK INWARD LIVE':'Review required' ?></span><span class="os-chip">Batch / expiry ready</span><span class="os-chip">Explicit cost only</span></div></section>
<?php if($error):?><div class="pp-alert bad"><strong>Inward diagnostic:</strong> <?= ii_h($error) ?></div><?php endif;?><?php if($success):?><div class="pp-alert good"><strong>Saved:</strong> <?= ii_h($success) ?></div><?php endif;?>
<?php if(!$error):?><section class="pp-grid" style="margin-top:14px"><article class="os-card pp-span-5"><h2>Add Stock</h2><form method="post" class="pp-form"><input type="hidden" name="csrf" value="<?= ii_h($csrf) ?>"><label>Movement Type<select name="movement_type"><option value="purchase">Purchase Receipt</option><option value="opening">Opening Stock</option><option value="customer_return">Customer Return to Stock</option><option value="adjustment_plus">Positive Adjustment</option></select></label><label>Product<select name="product_id" required><option value="">Choose product…</option><?php foreach($products as $p):?><option value="<?= (int)$p['id'] ?>"><?= ii_h(($p['sku']?:'—').' • '.$p['product_name']) ?></option><?php endforeach;?></select></label><label>Quantity<input type="number" min="0.001" step="0.001" name="quantity" required></label><label>Movement Date<input type="date" name="movement_date" value="<?= ii_h(date('Y-m-d')) ?>" required></label><label>Batch / Lot No.<input name="batch_code" maxlength="120" placeholder="Optional — system uses UNBATCHED if blank"></label><label>Manufacture Date<input type="date" name="manufacture_date"></label><label>Expiry Date<input type="date" name="expiry_date"></label><label>Actual Unit Cost<input type="number" min="0" step="0.01" name="unit_cost" placeholder="Optional"></label><label>Supplier<input name="supplier_name" maxlength="190"></label><label>Bill / Purchase Reference<input name="purchase_reference" maxlength="190"></label><label>Notes<textarea name="notes" rows="3"></textarea></label><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="use_as_profit_cost" value="1" style="width:auto;margin-top:3px"><span>Use this actual unit cost as dated Product Sale profit cost basis.</span></label><button>Post Stock Inward →</button></form></article><article class="os-card pp-span-7"><div class="os-title-row"><div><h2>Recent Inward Movements</h2><p>Opening, purchase, return and positive adjustments.</p></div></div><div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Date</th><th>Stock</th><th>Product</th><th>Batch</th><th>Type</th><th>Qty</th><th>Reference</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?= ii_h($r['movement_date']) ?></td><td><b><?= ii_h($r['sku']) ?></b></td><td><?= ii_h($r['product_name']) ?></td><td><?= ii_h($r['batch_code']?:'—') ?></td><td><?= ii_h(strtoupper(str_replace('_',' ',$r['movement_type']))) ?></td><td><b>+<?= ii_h(ii_n($r['quantity_delta'])) ?></b></td><td><?= ii_h($r['source_reference']?:'—') ?></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="7">No stock inward has been recorded yet.</td></tr><?php endif;?></tbody></table></div></article></section><?php endif;?>
<div class="os-footer-note"><strong>Cost rule:</strong> entering a unit cost here does not automatically become Business OS profit cost unless you explicitly select the checkbox.</div></main></div></body></html>