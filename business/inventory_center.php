<?php
declare(strict_types=1);

require_once __DIR__ . '/config/inventory_step13.php';

$error = null;
$rows = [];
$batches = [];
$pendingSales = [];
$q = trim((string)($_GET['q'] ?? ''));
$metrics = [
    'products' => 0,
    'tracked' => 0,
    'units' => 0.0,
    'sellable' => 0.0,
    'expired' => 0.0,
    'known_value' => 0.0,
    'low' => 0,
    'out' => 0,
    'expiring' => 0,
    'unvalued_batches' => 0,
];

try {
    $pdo = business_db();
    inventory_step13_ensure($pdo);
    $ctx = inventory_step13_context($pdo);
    $orgId = (int)$ctx['organization_id'];
    $locationId = (int)$ctx['location_id'];
    $rows = inventory_step13_stock_rows($pdo, $orgId, $locationId);

    foreach ($rows as $row) {
        $metrics['products']++;
        if ((bool)$row['track_stock']) $metrics['tracked']++;
        $metrics['units'] += (float)$row['stock_total'];
        $metrics['sellable'] += (float)$row['sellable_stock'];
        $metrics['expired'] += (float)$row['expired_stock'];
        $metrics['known_value'] += (float)$row['known_value'];
        $metrics['unvalued_batches'] += (int)$row['unvalued_batches'];

        if ((bool)$row['track_stock'] && (float)$row['sellable_stock'] <= 0.0005) {
            $metrics['out']++;
        }
        if ((bool)$row['track_stock'] && (float)$row['reorder_level'] > 0 && (float)$row['sellable_stock'] <= (float)$row['reorder_level'] + 0.0005) {
            $metrics['low']++;
        }
        if ($row['next_expiry']) {
            $days = (int)floor(((new DateTimeImmutable((string)$row['next_expiry']))->getTimestamp() - (new DateTimeImmutable('today'))->getTimestamp()) / 86400);
            if ($days >= 0 && $days <= (int)$row['expiry_alert_days']) $metrics['expiring']++;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT b.*,p.product_name,p.sku
         FROM inventory_batches b
         JOIN products p ON p.id=b.product_id AND p.organization_id=b.organization_id
         WHERE b.organization_id=? AND b.location_id=? AND b.status='active' AND b.current_quantity>0
         ORDER BY CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END,b.expiry_date,b.id
         LIMIT 120"
    );
    $stmt->execute([$orgId, $locationId]);
    $batches = $stmt->fetchAll();
    $pendingSales = inventory_step13_pending_sales($pdo, $orgId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function inv_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function inv_num(mixed $v): string { $x = number_format((float)$v, 3, '.', ''); return rtrim(rtrim($x, '0'), '.'); }
function inv_money(mixed $v): string { return '₹' . number_format((float)$v, 2, '.', ','); }
function inv_match(array $row, string $q): bool {
    if ($q === '') return true;
    return str_contains(strtolower((string)$row['product_name'] . ' ' . (string)$row['sku']), strtolower($q));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Inventory Center - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/step10.css"><link rel="stylesheet" href="assets/product_pro.css">
</head>
<body>
<header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="logo"><span><strong>Healthcare Wellness Club</strong><small>STEP 13 • Inventory & Stock</small></span></a><div class="os-top-actions"><a class="os-btn" href="inventory_inward.php">+ Stock Inward</a><a class="os-btn" href="inventory_adjustments.php">Adjust</a><a class="os-btn primary" href="step13_audit.php">STEP 13 Audit</a></div></div></header>
<div class="os-layout">
<aside class="os-sidebar"><div class="os-nav-label">Inventory</div><nav class="os-nav"><a class="active" href="inventory_center.php"><i class="dot"></i>Inventory Center</a><a href="inventory_inward.php"><i class="dot"></i>Stock Inward</a><a href="inventory_adjustments.php"><i class="dot"></i>Damage / Returns</a><a href="inventory_stocktake.php"><i class="dot"></i>Physical Stocktake</a><a href="inventory_settings.php"><i class="dot"></i>Reorder Settings</a><a href="inventory_sales_sync.php"><i class="dot"></i>Sale Stock Sync</a><a href="inventory_analytics.php"><i class="dot"></i>Inventory Analytics</a><a href="inventory_export.php"><i class="dot"></i>Export / Print</a><a href="step13_audit.php"><i class="dot"></i>STEP 13 Audit</a></nav><div class="os-sidebar-status"><b>Ledger-first inventory</b><span>FEFO sale allocation • expired batches blocked • no negative stock by default.</span></div></aside>
<main class="os-main">
<section class="os-hero pp-hero"><div class="os-kicker">STEP 13 • INVENTORY COMMAND CENTER</div><h1>Know what is in stock, what can be sold, what is expiring and what needs action.</h1><p>Every quantity is backed by an inventory movement. Sale deduction is automatic for tracked products; expired batches are excluded from sellable stock and sale allocation.</p><div class="os-status-row"><span class="os-chip <?= $error===null?'good':'' ?>"><?= $error===null?'INVENTORY CENTER LIVE':'Review required' ?></span><span class="os-chip"><?= number_format($metrics['tracked']) ?> tracked products</span><span class="os-chip"><?= number_format($metrics['low']) ?> low stock</span><span class="os-chip"><?= number_format(count($pendingSales)) ?> sale sync pending</span></div></section>
<?php if($error):?><div class="pp-alert bad"><strong>Inventory diagnostic:</strong> <?= inv_h($error) ?></div><?php else:?>
<section class="s10-kpis" style="margin-top:14px"><div class="s10-kpi"><small>Sellable Units</small><strong><?= inv_h(inv_num($metrics['sellable'])) ?></strong><span><?= inv_h(inv_num($metrics['units'])) ?> total on hand</span></div><div class="s10-kpi"><small>Known Stock Value</small><strong><?= inv_money($metrics['known_value']) ?></strong><span><?= number_format($metrics['unvalued_batches']) ?> unvalued batches</span></div><div class="s10-kpi"><small>Out / Low</small><strong><?= number_format($metrics['out']) ?> / <?= number_format($metrics['low']) ?></strong><span>Threshold-driven alerts</span></div><div class="s10-kpi"><small>Expiry</small><strong><?= number_format($metrics['expiring']) ?></strong><span><?= inv_h(inv_num($metrics['expired'])) ?> expired units isolated</span></div></section>
<section class="os-card" style="margin-top:14px"><div class="os-title-row"><div><h2>Product Stock</h2><p>Search by Stock No. or product name. Reorder alerts appear only after you configure a real threshold.</p></div><form method="get"><input name="q" value="<?= inv_h($q) ?>" placeholder="Search product / Stock No."></form></div><div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Stock</th><th>Product</th><th>Sellable</th><th>Total</th><th>Expired</th><th>Next Expiry</th><th>Reorder</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r):if(!inv_match($r,$q))continue;$isOut=(bool)$r['track_stock']&&(float)$r['sellable_stock']<=0.0005;$isLow=(bool)$r['track_stock']&&(float)$r['reorder_level']>0&&(float)$r['sellable_stock']<=(float)$r['reorder_level']+0.0005;?><tr><td><b><?= inv_h($r['sku']?:'—') ?></b></td><td><?= inv_h($r['product_name']) ?><small><?= number_format((int)$r['live_batches']) ?> live batch(es)</small></td><td><b><?= inv_h(inv_num($r['sellable_stock'])) ?></b></td><td><?= inv_h(inv_num($r['stock_total'])) ?></td><td><?= inv_h(inv_num($r['expired_stock'])) ?></td><td><?= inv_h($r['next_expiry']?:'—') ?></td><td><?= (float)$r['reorder_level']>0?inv_h(inv_num($r['reorder_level'])):'Not set' ?></td><td><span class="pp-badge <?= $isOut||$isLow?'warn':'' ?>"><?= !$r['track_stock']?'NOT TRACKED':($isOut?'OUT':($isLow?'LOW':'OK')) ?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="pp-grid" style="margin-top:14px"><article class="os-card pp-span-7"><div class="os-title-row"><div><h2>Live Batches / Lots</h2><p>FEFO allocation uses the nearest valid expiry first. Expired stock remains visible but cannot be sold.</p></div><a class="os-btn" href="inventory_inward.php">Add Inward</a></div><div class="pp-table-wrap"><table class="pp-table"><thead><tr><th>Stock</th><th>Product</th><th>Batch</th><th>Qty</th><th>Expiry</th><th>Unit Cost</th></tr></thead><tbody><?php foreach($batches as $b):?><tr><td><?= inv_h($b['sku']) ?></td><td><?= inv_h($b['product_name']) ?></td><td><b><?= inv_h($b['batch_code']) ?></b></td><td><?= inv_h(inv_num($b['current_quantity'])) ?></td><td><?= inv_h($b['expiry_date']?:'No expiry') ?></td><td><?= $b['unit_cost']!==null?inv_money($b['unit_cost']):'Unvalued' ?></td></tr><?php endforeach;?><?php if(!$batches):?><tr><td colspan="6">No stock batches yet. Add real opening/purchase stock in Stock Inward.</td></tr><?php endif;?></tbody></table></div></article><aside class="os-card pp-span-5"><h2>Action Queue</h2><div class="s10-list"><div class="s10-row"><div><b>Out of Stock</b><small>Tracked products with zero sellable stock</small></div><strong><?= number_format($metrics['out']) ?></strong></div><div class="s10-row"><div><b>Low Stock</b><small>Configured reorder threshold reached</small></div><strong><?= number_format($metrics['low']) ?></strong></div><div class="s10-row"><div><b>Expiring Soon</b><small>Within product alert window</small></div><strong><?= number_format($metrics['expiring']) ?></strong></div><div class="s10-row"><div><b>Sale Sync Pending</b><small>Post-activation active sales without allocation</small></div><strong><?= number_format(count($pendingSales)) ?></strong></div></div><?php if($pendingSales):?><a class="os-btn primary" style="margin-top:12px" href="inventory_sales_sync.php">Review Sale Sync →</a><?php endif;?></aside></section>
<?php endif;?>
<div class="os-footer-note"><strong>Inventory policy:</strong> stock is never inferred from Product Price PDFs or sales history. Opening/purchase quantities must come from real physical/business stock evidence.</div>
</main></div><script src="assets/business-collapsible.js?v=20260820-1" defer></script></body></html>
