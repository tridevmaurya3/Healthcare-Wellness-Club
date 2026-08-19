<?php
declare(strict_types=1);

require_once __DIR__ . '/config/role_portal_auth.php';
require_once __DIR__ . '/config/public_store_step23.php';

$error = null;
$success = null;
$results = [];
$previewRows = [];
$csrf = '';

$batch = [
    'N387' => 'N387.webp',
    'N396' => 'N396.webp',
    '7994' => '7994.webp',
    '8144' => '8144.webp',
    '8847' => '8847.webp',
    '573S' => '573S.webp',
    '507K' => '507K.webp',
    '4114' => '4114.webp',
    '287K' => '287K.webp',
    '529K' => '529K.webp',
];

function pib5_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
    $csrf = security_step17_csrf();

    $findProduct = $pdo->prepare('SELECT id,sku,product_name,status FROM products WHERE organization_id=? AND sku=? LIMIT 1');
    foreach ($batch as $sku => $file) {
        $findProduct->execute([$orgId, $sku]);
        $product = $findProduct->fetch() ?: null;
        $diskPath = dirname(__DIR__) . '/img/product-images/' . $file;
        $previewRows[$sku] = [
            'sku' => $sku,
            'file' => $file,
            'product' => $product,
            'exists' => is_file($diskPath),
            'url' => '../img/product-images/' . $file,
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        security_step17_verify_csrf((string)($_POST['csrf'] ?? ''));
        if ((string)($_POST['action'] ?? '') !== 'install_batch5') {
            throw new RuntimeException('Unknown Batch 5 action.');
        }

        $updated = 0;
        $skipped = 0;

        foreach ($previewRows as $sku => $row) {
            $product = $row['product'];
            if (!$product) {
                $results[] = ['sku' => $sku, 'ok' => false, 'message' => 'Product SKU not found.'];
                $skipped++;
                continue;
            }
            if (!$row['exists']) {
                $results[] = ['sku' => $sku, 'ok' => false, 'message' => 'Repository image file missing.'];
                $skipped++;
                continue;
            }

            $productId = (int)$product['id'];
            $imageUrl = (string)$row['url'];
            $altText = (string)$product['product_name'] . ' product image';

            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=? AND is_primary=1')->execute([$productId]);

                $findImage = $pdo->prepare('SELECT id FROM product_images WHERE product_id=? AND image_url=? LIMIT 1');
                $findImage->execute([$productId, $imageUrl]);
                $imageId = (int)($findImage->fetchColumn() ?: 0);

                if ($imageId > 0) {
                    $save = $pdo->prepare("UPDATE product_images SET alt_text=?,is_primary=1,sort_order=0,source_page_url=NULL,source_name='Authorized cleaned product asset batch 5',verification_status='verified',verified_at=NOW() WHERE id=? AND product_id=?");
                    $save->execute([$altText, $imageId, $productId]);
                } else {
                    $save = $pdo->prepare("INSERT INTO product_images(product_id,image_url,alt_text,is_primary,sort_order,source_page_url,source_name,verification_status,verified_at) VALUES(?,?,?,1,0,NULL,'Authorized cleaned product asset batch 5','verified',NOW())");
                    $save->execute([$productId, $imageUrl, $altText]);
                    $imageId = (int)$pdo->lastInsertId();
                }

                security_step17_audit(
                    $pdo,
                    (int)$user['id'],
                    'product_image_batch5_installed',
                    'product_image',
                    $imageId,
                    ['sku' => $sku, 'product_id' => $productId, 'image_url' => $imageUrl]
                );

                $pdo->commit();
                $results[] = ['sku' => $sku, 'ok' => true, 'message' => 'Primary verified image set.'];
                $updated++;
            } catch (Throwable $itemError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results[] = ['sku' => $sku, 'ok' => false, 'message' => $itemError->getMessage()];
                $skipped++;
            }
        }

        $success = "Batch 5 finished: {$updated} updated, {$skipped} skipped.";
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Product Image Batch 5 - Healthcare Wellness Club</title>
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/product_pro.css">
<style>
body{background:#f5f8f6}.pi-wrap{max-width:1460px;margin:0 auto;padding:18px}.pi-hero{padding:22px;border:1px solid #dce8e1;border-radius:20px;background:linear-gradient(135deg,#f6fbf8,#f7f9ff)}.pi-hero h1{margin:6px 0;color:#173c2c}.pi-hero p{max-width:920px;color:#64766d;line-height:1.6}.pi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:16px}.pi-card{border:1px solid #dce8e1;border-radius:16px;background:#fff;padding:11px}.pi-image{aspect-ratio:1/1;display:grid;place-items:center;border-radius:12px;background:#fff;border:1px solid #edf1ef;overflow:hidden}.pi-image img{width:100%;height:100%;object-fit:contain}.pi-card b{display:block;margin-top:9px;color:#173c2c;font-size:.76rem}.pi-card small{display:block;margin-top:4px;color:#708078;font-size:.64rem;line-height:1.45}.pi-chip{display:inline-flex;margin-top:7px;padding:4px 7px;border-radius:999px;background:#edf7f1;color:#176f45;font-size:.56rem;font-weight:900}.pi-chip.bad{background:#fff0f0;color:#9c3f3f}.pi-action{margin-top:16px;padding:16px;border:1px solid #dce8e1;border-radius:16px;background:#fff}.pi-action button{border:0;border-radius:12px;background:#176f45;color:#fff;font-weight:900;padding:12px 18px;cursor:pointer}.pi-alert{margin-top:14px;padding:12px 14px;border-radius:12px}.pi-alert.good{background:#edf8f1;color:#176f45}.pi-alert.bad{background:#fff0f0;color:#9a3e3e}.pi-result{margin-top:12px;display:grid;gap:6px}.pi-result div{padding:8px 10px;border-radius:10px;background:#f5faf7;color:#355a48;font-size:.68rem}.pi-result div.bad{background:#fff2f2;color:#953e3e}.pi-note{margin-top:12px;color:#6c7b74;font-size:.67rem;line-height:1.55}@media(max-width:1100px){.pi-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.pi-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:440px){.pi-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="pi-wrap">
<section class="pi-hero">
<div class="os-kicker">PRODUCT IMAGE BATCH 5</div>
<h1>Review the next 10 SKU mappings before activation.</h1>
<p>Each card loads its Product Master name using the exact SKU in the image filename. Confirm image, SKU and product name before setting these files as primary images.</p>
</section>

<?php if ($error): ?><div class="pi-alert bad"><strong>Installer error:</strong> <?=pib5_h($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="pi-alert good"><strong><?=pib5_h($success)?></strong></div><?php endif; ?>

<?php if (!$error): ?>
<section class="pi-grid">
<?php foreach ($previewRows as $row): $product=$row['product']; ?>
<article class="pi-card">
<div class="pi-image"><img src="<?=pib5_h($row['url'])?>" alt="<?=pib5_h($product['product_name'] ?? $row['sku'])?>"></div>
<b><?=pib5_h($row['sku'])?> · <?=pib5_h($product['product_name'] ?? 'Product not found')?></b>
<small>Repository file: <?=pib5_h($row['file'])?></small>
<?php if ($product && $row['exists']): ?><span class="pi-chip">READY</span><?php else: ?><span class="pi-chip bad"><?=!$product?'SKU NOT FOUND':'FILE MISSING'?></span><?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="pi-action">
<form method="post">
<input type="hidden" name="csrf" value="<?=pib5_h($csrf)?>">
<input type="hidden" name="action" value="install_batch5">
<button type="submit">Set Batch 5 as Primary Images →</button>
</form>
<p class="pi-note">Only verified primary product-image mapping is changed. Product data, MRP, VP, discount tiers, inventory, orders and accounting are untouched.</p>
<?php if ($results): ?><div class="pi-result"><?php foreach($results as $result): ?><div class="<?=$result['ok']?'':'bad'?>"><strong><?=pib5_h($result['sku'])?>:</strong> <?=pib5_h($result['message'])?></div><?php endforeach; ?></div><?php endif; ?>
</section>
<?php endif; ?>
</div>
</body>
</html>
