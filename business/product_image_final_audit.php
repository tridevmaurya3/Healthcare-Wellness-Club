<?php
declare(strict_types=1);

require_once __DIR__ . '/config/role_portal_auth.php';
require_once __DIR__ . '/config/public_store_step23.php';

function pifa_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$expectedSkus = [
    'N387','N396','7994','8144','8847','1B64','573S','1291','1294','1295','1296','1238','230K','080K','507K','1247',
    '1248','1249','1269','1239','4114','082K','154K','287K','529K','406K','407K','408K','409K','1233','1569','183K',
    '0064','175K','127K','109K','275K','315K','276K','277K','046K','316K','115K','1458','031K','1279','1236','1278',
    '2865','1293','0006','025K','186K','0020','0555','2637','0065','051K','1232','3123','0111','0077','426K','505K'
];

$error = null;
$rows = [];
$duplicates = [];
$summary = [
    'expected' => count($expectedSkus),
    'pass' => 0,
    'review' => 0,
    'products_found' => 0,
    'files_found' => 0,
    'verified_primary' => 0,
    'square_1200' => 0,
];

try {
    $pdo = role_portal_db();
    role_portal_ensure($pdo);
    ps23_ensure($pdo);
    security_step17_session_start();

    $user = security_step17_session_user($pdo, true);
    if (!$user) {
        header('Location: ../login.php');
        exit;
    }
    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header('Location: ../change_password.php?required=1');
        exit;
    }
    if ((string)($user['role_code'] ?? '') !== 'admin' || !security_step17_has_permission($pdo, 'products.manage', $user)) {
        header('Location: access_denied.php?permission=products.manage');
        exit;
    }

    $ctx = security_step17_context($pdo);
    $orgId = (int)($ctx['organization_id'] ?? 0);

    $findProducts = $pdo->prepare('SELECT id,sku,product_name,status FROM products WHERE organization_id=? AND sku=? ORDER BY id');
    $findPrimary = $pdo->prepare('SELECT id,image_url,verification_status,verified_at FROM product_images WHERE product_id=? AND is_primary=1 ORDER BY id');

    foreach ($expectedSkus as $sku) {
        $findProducts->execute([$orgId, $sku]);
        $products = $findProducts->fetchAll();
        $product = count($products) === 1 ? $products[0] : null;

        $expectedFile = $sku . '.webp';
        $expectedUrl = '../img/product-images/' . $expectedFile;
        $diskPath = dirname(__DIR__) . '/img/product-images/' . $expectedFile;
        $fileExists = is_file($diskPath);
        $fileSize = $fileExists ? (int)filesize($diskPath) : 0;
        $width = null;
        $height = null;
        if ($fileExists) {
            $size = @getimagesize($diskPath);
            if (is_array($size)) {
                $width = (int)$size[0];
                $height = (int)$size[1];
            }
        }

        $primaryRows = [];
        if ($product) {
            $findPrimary->execute([(int)$product['id']]);
            $primaryRows = $findPrimary->fetchAll();
        }
        $primary = count($primaryRows) === 1 ? $primaryRows[0] : null;

        $checks = [
            'unique_product' => count($products) === 1,
            'file_exists' => $fileExists,
            'file_nonempty' => $fileSize >= 5000,
            'dimensions' => $width === 1200 && $height === 1200,
            'one_primary' => count($primaryRows) === 1,
            'url_match' => $primary && (string)$primary['image_url'] === $expectedUrl,
            'verified' => $primary && (string)$primary['verification_status'] === 'verified',
        ];

        $pass = !in_array(false, $checks, true);
        $summary[$pass ? 'pass' : 'review']++;
        if ($product) $summary['products_found']++;
        if ($fileExists) $summary['files_found']++;
        if ($primary && (string)$primary['verification_status'] === 'verified' && (string)$primary['image_url'] === $expectedUrl) $summary['verified_primary']++;
        if ($width === 1200 && $height === 1200) $summary['square_1200']++;

        $issues = [];
        if (count($products) === 0) $issues[] = 'Product SKU not found in Product Master.';
        if (count($products) > 1) $issues[] = 'Duplicate Product Master SKU rows found.';
        if (!$fileExists) $issues[] = 'Repository WEBP file missing.';
        elseif ($fileSize < 5000) $issues[] = 'Repository image file is unexpectedly small.';
        if ($fileExists && ($width !== 1200 || $height !== 1200)) $issues[] = 'Image canvas is not 1200×1200.';
        if ($product && count($primaryRows) === 0) $issues[] = 'No primary image assigned.';
        if ($product && count($primaryRows) > 1) $issues[] = 'More than one primary image assigned.';
        if ($primary && (string)$primary['image_url'] !== $expectedUrl) $issues[] = 'Primary image URL does not match the SKU filename.';
        if ($primary && (string)$primary['verification_status'] !== 'verified') $issues[] = 'Primary image is not verified.';

        $rows[] = [
            'sku' => $sku,
            'product' => $product,
            'product_count' => count($products),
            'file' => $expectedFile,
            'url' => $expectedUrl,
            'file_exists' => $fileExists,
            'file_size' => $fileSize,
            'width' => $width,
            'height' => $height,
            'primary_rows' => $primaryRows,
            'primary' => $primary,
            'pass' => $pass,
            'issues' => $issues,
        ];
    }

    $dupSql = "SELECT image_url,COUNT(DISTINCT product_id) product_count,GROUP_CONCAT(DISTINCT product_id ORDER BY product_id) product_ids
               FROM product_images
               WHERE is_primary=1 AND image_url LIKE '../img/product-images/%'
               GROUP BY image_url
               HAVING COUNT(DISTINCT product_id)>1
               ORDER BY image_url";
    $duplicates = $pdo->query($dupSql)->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$overallPass = !$error && $summary['pass'] === $summary['expected'] && count($duplicates) === 0;
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Final Product Image Audit - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<style>
body{background:#f5f8f6;color:#173c2c}.fa-wrap{max-width:1540px;margin:0 auto;padding:18px}.fa-hero{padding:22px;border:1px solid #dce8e1;border-radius:20px;background:linear-gradient(135deg,#f5fbf7,#f7f9ff)}.fa-hero h1{margin:5px 0 7px}.fa-hero p{margin:0;color:#687970;line-height:1.6;max-width:1000px}.fa-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}.fa-stat{background:#fff;border:1px solid #dce8e1;border-radius:14px;padding:12px}.fa-stat b{display:block;font-size:1.25rem}.fa-stat span{display:block;margin-top:4px;font-size:.65rem;color:#708078}.fa-banner{margin-top:14px;padding:14px 16px;border-radius:14px;font-weight:900}.fa-banner.pass{background:#eaf8ef;color:#176f45;border:1px solid #ccead8}.fa-banner.review{background:#fff3e8;color:#915319;border:1px solid #f2d5b8}.fa-banner.error{background:#fff0f0;color:#963c3c;border:1px solid #f0caca}.fa-table-wrap{margin-top:14px;border:1px solid #dce8e1;border-radius:16px;background:#fff;overflow:auto}.fa-table{width:100%;border-collapse:collapse;min-width:1080px}.fa-table th,.fa-table td{padding:9px 10px;border-bottom:1px solid #edf1ef;text-align:left;vertical-align:middle;font-size:.68rem}.fa-table th{position:sticky;top:0;background:#f7faf8;color:#506159;font-size:.62rem;text-transform:uppercase;letter-spacing:.04em}.fa-thumb{width:56px;height:56px;border:1px solid #edf1ef;border-radius:10px;object-fit:contain;background:#fff}.fa-chip{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:.57rem;font-weight:900}.fa-chip.pass{background:#eaf8ef;color:#176f45}.fa-chip.review{background:#fff0f0;color:#963c3c}.fa-issues{color:#963c3c;line-height:1.45}.fa-muted{color:#78877f}.fa-dupes{margin-top:14px;padding:14px;border:1px solid #f0caca;border-radius:14px;background:#fff7f7}.fa-note{margin-top:14px;padding:13px 15px;border:1px solid #dce8e1;border-radius:14px;background:#fff;color:#687970;font-size:.68rem;line-height:1.6}@media(max-width:1050px){.fa-summary{grid-template-columns:repeat(3,1fr)}}@media(max-width:600px){.fa-summary{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="fa-wrap">
<section class="fa-hero">
<div class="os-kicker">FINAL PRODUCT IMAGE AUDIT</div>
<h1>64-product image integration check</h1>
<p>This audit verifies exact SKU coverage, repository WEBP presence, 1200×1200 canvas, one primary image per product, exact SKU-to-image URL mapping and verified status. It does not alter any product, price, VP, inventory, order or image record.</p>
</section>

<?php if ($error): ?>
<div class="fa-banner error">Audit error: <?=pifa_h($error)?></div>
<?php else: ?>
<section class="fa-summary">
<div class="fa-stat"><b><?=pifa_h($summary['expected'])?></b><span>Expected SKUs</span></div>
<div class="fa-stat"><b><?=pifa_h($summary['products_found'])?></b><span>Products found</span></div>
<div class="fa-stat"><b><?=pifa_h($summary['files_found'])?></b><span>Repository images</span></div>
<div class="fa-stat"><b><?=pifa_h($summary['verified_primary'])?></b><span>Verified primary mappings</span></div>
<div class="fa-stat"><b><?=pifa_h($summary['square_1200'])?></b><span>1200×1200 images</span></div>
<div class="fa-stat"><b><?=pifa_h($summary['pass'])?> PASS / <?=pifa_h($summary['review'])?> REVIEW</b><span>Final audit result</span></div>
</section>

<?php if ($overallPass): ?>
<div class="fa-banner pass">FINAL IMAGE AUDIT PASS — all 64 expected products have the correct technical SKU mapping, repository image, verified primary assignment and 1200×1200 image canvas.</div>
<?php else: ?>
<div class="fa-banner review">FINAL IMAGE AUDIT NEEDS REVIEW — <?=pifa_h($summary['review'])?> product row(s) or <?=pifa_h(count($duplicates))?> duplicate primary URL group(s) require attention.</div>
<?php endif; ?>

<div class="fa-table-wrap">
<table class="fa-table">
<thead><tr><th>Status</th><th>Image</th><th>SKU / Product</th><th>Repository file</th><th>Canvas</th><th>Primary mapping</th><th>Verification</th><th>Notes</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): $product=$row['product']; $primary=$row['primary']; ?>
<tr>
<td><span class="fa-chip <?=$row['pass']?'pass':'review'?>"><?=$row['pass']?'PASS':'REVIEW'?></span></td>
<td><?php if($row['file_exists']): ?><img class="fa-thumb" src="<?=pifa_h($row['url'])?>" alt="<?=pifa_h($row['sku'])?>"><?php else: ?><span class="fa-muted">Missing</span><?php endif; ?></td>
<td><strong><?=pifa_h($row['sku'])?></strong><br><span class="fa-muted"><?=pifa_h($product['product_name'] ?? 'Product not found')?></span></td>
<td><?=pifa_h($row['file'])?><br><span class="fa-muted"><?=$row['file_exists']?number_format($row['file_size']/1024,1).' KB':'Missing'?></span></td>
<td><?=($row['width']&&$row['height'])?pifa_h($row['width'].'×'.$row['height']):'—'?></td>
<td><?php if($primary): ?><span class="fa-muted"><?=pifa_h($primary['image_url'])?></span><?php else: ?>—<?php endif; ?></td>
<td><?php if($primary): ?><span class="fa-chip <?=((string)$primary['verification_status']==='verified')?'pass':'review'?>"><?=pifa_h(strtoupper((string)$primary['verification_status']))?></span><?php else: ?>—<?php endif; ?></td>
<td><?php if($row['issues']): ?><div class="fa-issues"><?=pifa_h(implode(' ', $row['issues']))?></div><?php else: ?><span class="fa-muted">No technical mapping issue.</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($duplicates): ?>
<section class="fa-dupes"><strong>Duplicate primary image URLs detected:</strong><ul><?php foreach($duplicates as $dup): ?><li><?=pifa_h($dup['image_url'])?> → product IDs <?=pifa_h($dup['product_ids'])?></li><?php endforeach; ?></ul></section>
<?php endif; ?>

<div class="fa-note"><strong>Visual identity check:</strong> the technical audit cannot identify whether the printed label/artwork inside a photo belongs to the intended product. That was intentionally handled by the per-batch preview step before activation. This page is the final database/repository consistency audit.</div>
<?php endif; ?>
</div>
<script src="assets/business-collapsible.js?v=20260820-1" defer></script>
</body>
</html>
