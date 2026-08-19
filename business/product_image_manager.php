<?php
declare(strict_types=1);

require_once __DIR__ . '/config/role_portal_auth.php';
require_once __DIR__ . '/config/public_store_step23.php';

$error=null;$success=null;$rows=[];$csrf='';$uploaded=[];$unmatched=[];$missing=0;$withImages=0;

function pim_norm(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]+/i','',$value)??'');
}
function pim_product_for_file(array $products,string $filename): ?array
{
    $stem=pim_norm((string)pathinfo($filename,PATHINFO_FILENAME));
    if($stem==='')return null;
    $best=null;$bestLen=0;
    foreach($products as $p){
        foreach([(string)($p['sku']??''),(string)($p['product_code']??'')] as $candidate){
            $key=pim_norm($candidate);if($key==='')continue;
            if($stem===$key || str_starts_with($stem,$key)){
                if(strlen($key)>$bestLen){$best=$p;$bestLen=strlen($key);}
            }
        }
    }
    return $best;
}
function pim_upload_rows(array $files): array
{
    $out=[];if(!isset($files['name'])||!is_array($files['name']))return $out;
    foreach($files['name'] as $i=>$name){$out[]=['name'=>(string)$name,'type'=>(string)($files['type'][$i]??''),'tmp_name'=>(string)($files['tmp_name'][$i]??''),'error'=>(int)($files['error'][$i]??UPLOAD_ERR_NO_FILE),'size'=>(int)($files['size'][$i]??0)];}
    return $out;
}

try{
    $pdo=role_portal_db();role_portal_ensure($pdo);ps23_ensure($pdo);security_step17_session_start();
    $user=security_step17_session_user($pdo,true);if(!$user){header('Location: ../login.php');exit;}
    if((int)($user['must_change_password']??0)===1){header('Location: ../change_password.php?required=1');exit;}
    if(!security_step17_has_permission($pdo,'products.manage',$user)){header('Location: access_denied.php?permission=products.manage');exit;}
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$csrf=security_step17_csrf();

    $load=function()use($pdo,$orgId):array{
        $s=$pdo->prepare("SELECT p.id,p.product_code,p.sku,p.product_name,c.category_name,
            (SELECT image_url FROM product_images i WHERE i.product_id=p.id AND i.is_primary=1 AND i.verification_status='verified' ORDER BY i.id DESC LIMIT 1) primary_image,
            (SELECT verified_at FROM product_images i WHERE i.product_id=p.id AND i.is_primary=1 AND i.verification_status='verified' ORDER BY i.id DESC LIMIT 1) verified_at
            FROM products p LEFT JOIN product_categories c ON c.id=p.category_id
            WHERE p.organization_id=? AND p.status='active' ORDER BY c.sort_order,p.product_name");
        $s->execute([$orgId]);return $s->fetchAll();
    };
    $rows=$load();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        security_step17_verify_csrf((string)($_POST['csrf']??''));
        if(empty($_POST['rights_confirm']))throw new RuntimeException('Confirm that you are authorized to use the uploaded product images.');
        $fileRows=pim_upload_rows($_FILES['images']??[]);
        if(!$fileRows)throw new RuntimeException('Choose one or more product image files.');
        if(count($fileRows)>100)throw new RuntimeException('Upload at most 100 images at one time.');

        $root=dirname(__DIR__);$uploadDir=$root.'/uploads/product-images';
        if(!is_dir($uploadDir)&&!mkdir($uploadDir,0755,true)&&!is_dir($uploadDir))throw new RuntimeException('Product image upload folder could not be created.');
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $finfo=new finfo(FILEINFO_MIME_TYPE);

        foreach($fileRows as $f){
            if($f['error']!==UPLOAD_ERR_OK){$unmatched[]=$f['name'].' — upload error';continue;}
            if($f['size']<=0||$f['size']>8*1024*1024){$unmatched[]=$f['name'].' — image must be 8 MB or smaller';continue;}
            $product=pim_product_for_file($rows,$f['name']);
            if(!$product){$unmatched[]=$f['name'].' — filename did not match a product SKU';continue;}
            $mime=(string)$finfo->file($f['tmp_name']);
            if(!isset($allowed[$mime])){$unmatched[]=$f['name'].' — only JPG, PNG or WEBP is allowed';continue;}
            $dims=@getimagesize($f['tmp_name']);
            if(!$dims||($dims[0]??0)<200||($dims[1]??0)<200){$unmatched[]=$f['name'].' — image is too small or invalid';continue;}
            $sku=pim_norm((string)($product['sku']?:$product['product_code']));
            $stored=strtolower($sku?:'product').'-'.bin2hex(random_bytes(6)).'.'.$allowed[$mime];
            $target=$uploadDir.'/'.$stored;
            if(!move_uploaded_file($f['tmp_name'],$target))throw new RuntimeException('Could not save '.$f['name'].'.');
            $publicPath='../uploads/product-images/'.$stored;

            $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=? AND is_primary=1')->execute([(int)$product['id']]);
                $s=$pdo->prepare("INSERT INTO product_images(product_id,image_url,alt_text,is_primary,sort_order,source_page_url,source_name,verification_status,verified_at) VALUES(?,?,?,1,0,NULL,'Authorized product asset upload','verified',NOW())");
                $s->execute([(int)$product['id'],$publicPath,(string)$product['product_name']]);
                $imageId=(int)$pdo->lastInsertId();
                $pdo->commit();
                security_step17_audit($pdo,(int)$user['id'],'product_primary_image_uploaded','product_image',$imageId,['product_id'=>(int)$product['id'],'sku'=>$product['sku'],'filename'=>$stored,'authorized_confirmation'=>true]);
                $uploaded[]=(string)$product['product_name'].' ('.(string)$product['sku'].')';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($target);throw $e;}
        }
        $success=count($uploaded).' product image(s) matched, verified and set as primary.';
        $rows=$load();
    }
    foreach($rows as $r){if(!empty($r['primary_image']))$withImages++;else$missing++;}
}catch(Throwable $e){$error=$e->getMessage();}
?>
<!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Product Images - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/product_pro.css"><link rel="stylesheet" href="assets/workspace_refresh.css"><style>
.pim-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}.pim-stat{padding:14px;border:1px solid #dfe9e3;border-radius:14px;background:#fff}.pim-stat small{display:block;color:#718178;font-size:.66rem}.pim-stat b{display:block;margin-top:5px;font-size:1.35rem}.pim-upload{padding:18px;border:1px solid #dce7e0;border-radius:17px;background:linear-gradient(145deg,#fff,#f2faf6)}.pim-upload input[type=file]{width:100%;padding:14px;border:1px dashed #bcd4c6;border-radius:12px;background:#fff}.pim-check{display:flex;gap:9px;align-items:flex-start;margin:12px 0;font-size:.75rem;color:#53695d}.pim-preview{width:54px;height:54px;object-fit:contain;border:1px solid #e0e8e4;border-radius:10px;background:#fff;padding:3px}.pim-none{display:grid;place-items:center;width:54px;height:54px;border:1px dashed #d9e3de;border-radius:10px;color:#8a978f;font-size:.6rem}.pim-list{margin-top:14px}.pim-list ul{margin:7px 0 0;padding-left:18px;font-size:.72rem;color:#607269}@media(max-width:700px){.pim-stats{grid-template-columns:1fr}}
</style></head><body><header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>Authorized Product Image Manager</small></span></a><div class="os-top-actions"><a class="os-btn" href="../feature_hub.php">All Features</a><a class="os-btn" href="../shop/index.php" target="_blank" rel="noopener">Open Storefront</a><a class="os-btn primary" href="product_catalog.php">Products</a></div></div></header><div class="os-layout"><aside class="os-sidebar"><div class="os-nav-label">Product Assets</div><nav class="os-nav"><a class="active" href="product_image_manager.php"><i class="dot"></i>Product Images</a><a href="product_catalog.php"><i class="dot"></i>Product Catalog</a><a href="product_import_center.php"><i class="dot"></i>Price Import</a><a href="../shop/index.php" target="_blank"><i class="dot"></i>Customer Storefront</a></nav><div class="os-sidebar-status"><b>Authorized assets only</b><span>Upload product packshots you are permitted to reproduce. The app does not scrape or copy third-party product images automatically.</span></div></aside><main class="os-main"><section class="os-hero"><div class="os-kicker">PRODUCT IMAGE LIBRARY</div><h1>Upload current product packshots in one batch.</h1><p>Name each file with its product Stock/SKU number, for example <b>082K.jpg</b> or <b>1248-front.png</b>. The system auto-matches active products, keeps history and publishes only the verified primary image to the customer storefront.</p></section><?php if($error):?><div class="pp-alert bad" style="margin-top:14px"><?=security_step17_h($error)?></div><?php endif;?><?php if($success):?><div class="pp-alert good" style="margin-top:14px"><?=security_step17_h($success)?></div><?php endif;?><div class="pim-stats"><div class="pim-stat"><small>Active Products</small><b><?=count($rows)?></b></div><div class="pim-stat"><small>With Verified Image</small><b><?=$withImages?></b></div><div class="pim-stat"><small>Missing Image</small><b><?=$missing?></b></div></div><section class="pim-upload"><h2 style="margin-top:0">Bulk Upload Original Packshots</h2><p class="meta">Choose JPG, PNG or WEBP files (max 8 MB each). Filenames are matched by SKU. Existing primary images remain in history but the newest authorized upload becomes primary.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=security_step17_h($csrf)?>"><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required><label class="pim-check"><input type="checkbox" name="rights_confirm" value="1" required><span>I confirm that I am authorized to use and reproduce these product images in this Healthcare Wellness Club application.</span></label><button class="os-btn primary" type="submit">Upload & Auto-Match Images</button></form><?php if($uploaded):?><div class="pim-list"><b>Updated</b><ul><?php foreach($uploaded as $v):?><li><?=security_step17_h($v)?></li><?php endforeach;?></ul></div><?php endif;?><?php if($unmatched):?><div class="pim-list"><b>Needs attention</b><ul><?php foreach($unmatched as $v):?><li><?=security_step17_h($v)?></li><?php endforeach;?></ul></div><?php endif;?></section><section class="os-card" style="margin-top:14px"><div class="os-title-row"><div><h2>All Active Products</h2><p>Use this list to prepare filenames before a bulk upload.</p></div></div><div class="pp-table-wrap" style="margin-top:12px"><table class="pp-table"><thead><tr><th>Image</th><th>Stock / SKU</th><th>Product</th><th>Category</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?php if($r['primary_image']):?><img class="pim-preview" src="<?=security_step17_h($r['primary_image'])?>" alt=""><?php else:?><span class="pim-none">NO IMAGE</span><?php endif;?></td><td><b><?=security_step17_h((string)$r['sku'])?></b><small><?=security_step17_h((string)$r['product_code'])?></small></td><td><?=security_step17_h((string)$r['product_name'])?></td><td><?=security_step17_h((string)($r['category_name']??''))?></td><td><?=$r['primary_image']?'<span class="pp-badge">VERIFIED</span>':'<span class="pp-badge">MISSING</span>'?></td></tr><?php endforeach;?></tbody></table></div></section></main></div></body></html>
