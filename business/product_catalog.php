<?php
declare(strict_types=1);

require_once __DIR__ . '/config/product_services.php';

$error = null;
$ctx = [];
$metrics = ['markets'=>0,'categories'=>0,'products'=>0,'listings'=>0,'price_versions'=>0,'discount_tiers'=>0,'images'=>0];
$markets = [];
$tiers = [];
$tables = [];

try {
    $pdo = business_db();
    product_ensure_foundation($pdo);
    $ctx = product_org_context($pdo);
    $organizationId = (int)$ctx['organization_id'];
    $metrics = product_foundation_metrics($pdo, $organizationId);

    foreach (product_foundation_tables() as $table) {
        $tables[] = ['name'=>$table, 'ready'=>business_table_exists($pdo, $table)];
    }

    $stmt = $pdo->prepare(
        "SELECT id,market_code,market_name,country_code,currency_code,locale,status
         FROM product_markets WHERE organization_id=? ORDER BY market_name,id"
    );
    $stmt->execute([$organizationId]);
    $markets = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT t.id,t.tier_code,t.tier_name,t.customer_type,t.discount_percent,t.sort_order,t.status,m.market_name,m.currency_code
         FROM product_discount_tiers t
         JOIN product_markets m ON m.id=t.market_id AND m.organization_id=t.organization_id
         WHERE t.organization_id=?
         ORDER BY m.market_name,t.sort_order,t.discount_percent"
    );
    $stmt->execute([$organizationId]);
    $tiers = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && count(array_filter($tables, static fn(array $t): bool => $t['ready'])) === 8;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Product & Price Pro - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/product_catalog.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Product & Price Pro • Foundation</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="global_search.php">⌕ Search</a>
      <a class="os-btn" href="operations_center.php">Operations</a>
      <a class="os-btn primary" href="index.php">Dashboard</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Product System</div>
    <nav class="os-nav">
      <a class="active" href="product_catalog.php"><i class="dot"></i>Product & Price Pro</a>
      <a href="index.php"><i class="dot"></i>Business Dashboard</a>
      <a href="operations_center.php"><i class="dot"></i>Operations</a>
      <a href="report_center.php"><i class="dot"></i>Reports</a>
      <a href="data_management.php"><i class="dot"></i>Data Management</a>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'STEP 11A foundation ready' : 'Foundation review required' ?></b>
      <span>No product or price is invented. Catalog remains empty until an authoritative market source is imported.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero pc-hero">
      <div class="os-kicker">STEP 11A • Product & Price Pro Foundation</div>
      <h1>One world-ready catalog model for products, markets, VP, prices, discount tiers and price history.</h1>
      <p>The catalog is market-aware from day one. A product can exist once, be listed in one or more countries/markets, carry market-specific SKU/VP/MRP, retain dated price versions and support both computed and explicit tier prices.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'PRODUCT FOUNDATION LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($metrics['markets']) ?> market</span>
        <span class="os-chip"><?= number_format($metrics['products']) ?> products</span>
        <span class="os-chip"><?= number_format($metrics['price_versions']) ?> price versions</span>
        <span class="os-chip"><?= number_format($metrics['discount_tiers']) ?> discount tiers</span>
      </div>
    </section>

    <?php if ($error !== null): ?>
      <div class="pc-alert bad"><strong>Product foundation diagnostic:</strong> <?= product_h($error) ?></div>
    <?php endif; ?>

    <?php if ($error === null): ?>
    <section class="os-grid">
      <article class="os-card os-kpi green"><small>Markets</small><strong><?= number_format($metrics['markets']) ?></strong><span>Country/currency aware</span></article>
      <article class="os-card os-kpi blue"><small>Products</small><strong><?= number_format($metrics['products']) ?></strong><span>No fake catalog seeded</span></article>
      <article class="os-card os-kpi gold"><small>Price Versions</small><strong><?= number_format($metrics['price_versions']) ?></strong><span>Effective-date history</span></article>
      <article class="os-card os-kpi violet"><small>Images</small><strong><?= number_format($metrics['images']) ?></strong><span>Multi-image ready</span></article>

      <article class="os-card pc-span-8">
        <div class="os-title-row">
          <div><h2>Product Architecture</h2><p>STEP 11A creates the structure only. Actual product data comes later from reviewed source documents.</p></div>
          <span class="pc-badge">8 TABLES READY</span>
        </div>
        <div class="pc-flow">
          <div><b>Market</b><span>Country + currency + locale</span></div><i>→</i>
          <div><b>Category</b><span>Flexible catalog grouping</span></div><i>→</i>
          <div><b>Product</b><span>Name + SKU + pack + image</span></div><i>→</i>
          <div><b>Listing</b><span>Market-specific availability</span></div><i>→</i>
          <div><b>Price Version</b><span>MRP + VP + effective dates</span></div><i>→</i>
          <div><b>Tier Price</b><span>Computed or explicit</span></div>
        </div>
        <div class="pc-table-grid">
          <?php foreach ($tables as $table): ?>
            <div class="pc-table-state"><span class="pc-dot <?= $table['ready'] ? 'ok' : 'bad' ?>"></span><b><?= product_h($table['name']) ?></b><em><?= $table['ready'] ? 'READY' : 'MISSING' ?></em></div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="os-card pc-span-4">
        <h2>Market Configuration</h2>
        <p>Markets are independent, so future worldwide expansion will not require redesigning product IDs or price history.</p>
        <div class="pc-market-list">
          <?php foreach ($markets as $market): ?>
            <div class="pc-market">
              <div><b><?= product_h($market['market_name']) ?></b><span><?= product_h($market['country_code']) ?> • <?= product_h($market['currency_code']) ?> • <?= product_h($market['locale']) ?></span></div>
              <em><?= product_h(strtoupper((string)$market['status'])) ?></em>
            </div>
          <?php endforeach; ?>
        </div>
      </aside>

      <article class="os-card pc-span-7">
        <div class="os-title-row"><div><h2>Discount Tier Configuration</h2><p>Tiers are configuration, not product prices. Price rows remain empty until source data is reviewed.</p></div></div>
        <div class="pc-tier-grid">
          <?php foreach ($tiers as $tier): ?>
            <div class="pc-tier">
              <small><?= product_h((string)$tier['tier_code']) ?></small>
              <strong><?= product_h(rtrim(rtrim(number_format((float)$tier['discount_percent'], 3, '.', ''), '0'), '.')) ?>%</strong>
              <b><?= product_h((string)$tier['tier_name']) ?></b>
              <span><?= product_h(str_replace('_', ' ', (string)$tier['customer_type'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <aside class="os-card pc-span-5">
        <h2>Source Safety Policy</h2>
        <div class="pc-policy"><b>No invented product data</b><span>Names, SKUs, MRP, VP and product images will only come from reviewed source material.</span></div>
        <div class="pc-policy"><b>Price history preserved</b><span>A price update creates a dated price version instead of overwriting historical values.</span></div>
        <div class="pc-policy"><b>Market-specific</b><span>The same product can have different SKU, currency, VP and price by market.</span></div>
        <div class="pc-policy"><b>PDF import ready</b><span>Future price-list imports can retain raw source trace through raw_source_records.</span></div>
      </aside>

      <article class="os-card pc-span-12 pc-next">
        <div>
          <span>Next</span>
          <h2>STEP 11B — Product Catalog Data Center</h2>
          <p>Next we will add controlled Category/Product entry, image handling, SKU/pack management and source-safe product editing before price-list PDF automation.</p>
        </div>
      </article>
    </section>
    <?php endif; ?>

    <div class="os-footer-note"><strong>STEP 11A rule:</strong> product structure is now global-ready, but the catalog is intentionally empty. Market-specific source data will be added only after review so the system never guesses product names, pricing, VP or health-related claims.</div>
  </main>
</div>
</body>
</html>
