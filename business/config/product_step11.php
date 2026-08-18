<?php
declare(strict_types=1);

require_once __DIR__ . '/product_services.php';

const PRODUCT_STEP11_EFFECTIVE_DATE = '2026-04-15';
const PRODUCT_IMAGE_PLACEHOLDER = 'product_placeholder.php';

function product_step11_support_tables(): array
{
    return ['product_source_documents','product_delivery_rules','product_favorites','product_quotes','product_quote_items','product_import_jobs'];
}

function product_step11_ensure(PDO $pdo): void
{
    product_ensure_foundation($pdo);
    $migration = dirname(__DIR__, 2) . '/database/migrations/006_step11_complete_product_system.sql';
    foreach (product_step11_support_tables() as $table) {
        if (business_table_exists($pdo, $table)) continue;
        if (!is_file($migration)) throw new RuntimeException('STEP 11 support migration is missing.');
        $sql = (string)file_get_contents($migration);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) continue;
            try { $pdo->exec($statement); } catch (Throwable $e) {
                if (!str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
            }
        }
        break;
    }
    if (!business_column_exists($pdo,'product_images','source_page_url')) $pdo->exec("ALTER TABLE product_images ADD COLUMN source_page_url VARCHAR(700) NULL AFTER image_url");
    if (!business_column_exists($pdo,'product_images','source_name')) $pdo->exec("ALTER TABLE product_images ADD COLUMN source_name VARCHAR(160) NULL AFTER source_page_url");
    if (!business_column_exists($pdo,'product_images','verification_status')) $pdo->exec("ALTER TABLE product_images ADD COLUMN verification_status VARCHAR(30) NOT NULL DEFAULT 'needs_review' AFTER source_name");
    if (!business_column_exists($pdo,'product_images','verified_at')) $pdo->exec("ALTER TABLE product_images ADD COLUMN verified_at DATETIME NULL AFTER verification_status");
}

function product_step11_seed_data(): array
{
    $path = dirname(__DIR__) . '/data/product_seed_2026_04_15.json';
    if (!is_file($path)) throw new RuntimeException('Authoritative STEP 11 price seed is missing.');
    $raw = (string)file_get_contents($path);
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || count($data['products'] ?? []) < 60) throw new RuntimeException('Authoritative STEP 11 price seed is incomplete.');
    return $data;
}

function product_step11_slug(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function product_step11_market(PDO $pdo, int $organizationId): array
{
    $stmt=$pdo->prepare("SELECT * FROM product_markets WHERE organization_id=? AND market_code='IN' LIMIT 1");
    $stmt->execute([$organizationId]);
    $market=$stmt->fetch();
    if(!$market) throw new RuntimeException('India product market is missing.');
    return $market;
}

function product_step11_tiers(PDO $pdo, int $organizationId, int $marketId): array
{
    $legacy=['PC15','PC25','PC35','PC42','PC50'];
    $in=$pdo->prepare("UPDATE product_discount_tiers SET status='inactive' WHERE organization_id=? AND market_id=? AND tier_code IN ('PC15','PC25','PC35','PC42','PC50')");
    $in->execute([$organizationId,$marketId]);
    $tiers=[
      ['PC_BRONZE','Preferred - Bronze','preferred',0,10],
      ['PC_SILVER','Preferred - Silver','preferred',0,20],
      ['PC_GOLD','Preferred - Gold','preferred',0,30],
      ['ASSOC_RETAIL','Associate - Retail Price','associate',0,40],
      ['ASSOC_EARN_BASE','Associate - Earn Base','associate',0,50],
      ['ASSOC_25','Associate 25%','associate',25,60],
      ['ASSOC_35','Senior Consultant 35%','associate',35,70],
      ['ASSOC_42','Qualified Producer / Success Builder 42%','associate',42,80],
      ['ASSOC_50','Supervisor 50%','associate',50,90],
      ['PU_PRICE','PU Price','associate',0,100],
    ];
    $up=$pdo->prepare("INSERT INTO product_discount_tiers(organization_id,market_id,tier_code,tier_name,customer_type,discount_percent,sort_order,status) VALUES(?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name),customer_type=VALUES(customer_type),discount_percent=VALUES(discount_percent),sort_order=VALUES(sort_order),status='active'");
    foreach($tiers as $t) $up->execute([$organizationId,$marketId,...$t]);
    $stmt=$pdo->prepare("SELECT id,tier_code,tier_name,customer_type,sort_order FROM product_discount_tiers WHERE organization_id=? AND market_id=? AND status='active' ORDER BY sort_order,id");
    $stmt->execute([$organizationId,$marketId]);
    $out=[]; foreach($stmt->fetchAll() as $r) $out[$r['tier_code']]=$r;
    return $out;
}

function product_step11_online_images(): array
{
    return [
      'formula1'=>[
        'url'=>'https://www.jiomart.com/images/product/original/rvbdgktwwz/herbalife-nutrition-formula1-shake-mango-flavor-with-afresh-energy-drink-elaichi-ginger-flavor-product-images-orvbdgktwwz-p601050416-0-202305161207.jpg?im=Resize%3D%28420%2C420%29',
        'page'=>'https://www.jiomart.com/p/groceries/herbalife-nutrition-formula1-shake-mango-flavor-with-afresh-energy-drink-elaichi-ginger-flavor/601050416',
        'source'=>'JioMart - online packaging reference','status'=>'needs_review'],
      'afresh'=>[
        'url'=>'https://www.jiomart.com/images/product/original/rv7mdh68jv/herbalife-nutrition-ocular-defence-for-eye-health-200-g-afresh-cinnamon-product-images-orv7mdh68jv-p601430548-0-202305121528.jpg?im=Resize%3D%281000%2C1000%29',
        'page'=>'https://www.jiomart.com/p/groceries/herbalife-nutrition-ocular-defence-for-eye-health-200-g-afresh-cinnamon/601430548',
        'source'=>'JioMart - online packaging reference','status'=>'needs_review'],
      'ocular'=>[
        'url'=>'https://www.jiomart.com/images/product/original/rv7mdh68jv/herbalife-nutrition-ocular-defence-for-eye-health-200-g-afresh-cinnamon-product-images-orv7mdh68jv-p601430548-0-202305121528.jpg?im=Resize%3D%281000%2C1000%29',
        'page'=>'https://www.jiomart.com/p/groceries/herbalife-nutrition-ocular-defence-for-eye-health-200-g-afresh-cinnamon/601430548',
        'source'=>'JioMart - Ocular Defense packaging reference','status'=>'needs_review'],
      'niteworks'=>[
        'url'=>'https://www.jiomart.com/images/product/original/rvoebvkuna/herbalife-lemon-niteworks-drink-300g-product-images-orvoebvkuna-p602136788-0-202306041200.jpg?im=Resize%3D%28420%2C420%29',
        'page'=>'https://www.jiomart.com/p/groceries/herbalife-lemon-niteworks-drink-300g/602136788',
        'source'=>'JioMart - Niteworks 300g packaging reference','status'=>'needs_review'],
    ];
}

function product_step11_image_for(array $product): ?array
{
    $name=strtolower((string)$product['name']); $images=product_step11_online_images();
    if(str_contains($name,'formula 1')) return $images['formula1'];
    if(str_contains($name,'afresh')) return $images['afresh'];
    if(str_contains($name,'ocular defense')) return $images['ocular'];
    if(str_contains($name,'niteworks')) return $images['niteworks'];
    return null;
}

function product_step11_apply_seed(PDO $pdo): array
{
    product_step11_ensure($pdo);
    $data=product_step11_seed_data(); $ctx=product_org_context($pdo); $orgId=(int)$ctx['organization_id']; $market=product_step11_market($pdo,$orgId); $marketId=(int)$market['id'];
    $tiers=product_step11_tiers($pdo,$orgId,$marketId);
    $categoryIds=[]; $categories=[]; foreach($data['products'] as $p) $categories[(string)$p['category']]=true;
    $catStmt=$pdo->prepare("INSERT INTO product_categories(organization_id,category_code,category_name,sort_order,status) VALUES(?,?,?,?,'active') ON DUPLICATE KEY UPDATE category_name=VALUES(category_name),sort_order=VALUES(sort_order),status='active'");
    $catGet=$pdo->prepare("SELECT id FROM product_categories WHERE organization_id=? AND category_code=? LIMIT 1"); $sort=10;
    foreach(array_keys($categories) as $name){$code=product_step11_slug($name);$catStmt->execute([$orgId,$code,$name,$sort]);$catGet->execute([$orgId,$code]);$categoryIds[$name]=(int)$catGet->fetchColumn();$sort+=10;}

    $pdo->beginTransaction();
    try{
      $prodUp=$pdo->prepare("INSERT INTO products(organization_id,category_id,product_code,sku,product_name,short_name,brand_name,pack_size,pack_unit,status) VALUES(?,?,?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),sku=VALUES(sku),product_name=VALUES(product_name),pack_size=VALUES(pack_size),pack_unit=VALUES(pack_unit),status='active'");
      $prodGet=$pdo->prepare("SELECT id FROM products WHERE organization_id=? AND product_code=? LIMIT 1");
      $listUp=$pdo->prepare("INSERT INTO product_market_listings(organization_id,market_id,product_id,market_sku,source_reference,active_from,status) VALUES(?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE market_sku=VALUES(market_sku),source_reference=VALUES(source_reference),active_from=VALUES(active_from),status='active'");
      $listGet=$pdo->prepare("SELECT id FROM product_market_listings WHERE organization_id=? AND market_id=? AND product_id=? LIMIT 1");
      $priceUp=$pdo->prepare("INSERT INTO product_price_versions(organization_id,listing_id,effective_from,mrp,volume_points,currency_code,source_reference,status) VALUES(?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE mrp=VALUES(mrp),volume_points=VALUES(volume_points),currency_code=VALUES(currency_code),source_reference=VALUES(source_reference),status='active'");
      $priceGet=$pdo->prepare("SELECT id FROM product_price_versions WHERE organization_id=? AND listing_id=? AND effective_from=? LIMIT 1");
      $tierUp=$pdo->prepare("INSERT INTO product_tier_prices(price_version_id,discount_tier_id,price_amount,pricing_method) VALUES(?,?,?,'source_exact') ON DUPLICATE KEY UPDATE price_amount=VALUES(price_amount),pricing_method='source_exact'");
      $imgUp=$pdo->prepare("INSERT INTO product_images(product_id,image_url,source_page_url,source_name,verification_status,alt_text,is_primary,sort_order) VALUES(?,?,?,?,?,?,1,0)");
      $imgExists=$pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id=? AND is_primary=1");
      $count=0;$images=0;
      foreach($data['products'] as $p){
        $productCode='HL-IN-'.$p['stock']; $prodUp->execute([$orgId,$categoryIds[$p['category']]??null,$productCode,$p['stock'],$p['name'],$p['stock'],'Herbalife',$p['pack_size'],$p['pack_unit']]);
        $prodGet->execute([$orgId,$productCode]);$productId=(int)$prodGet->fetchColumn();
        $source='PDF price lists effective '.$data['effective_date'];$listUp->execute([$orgId,$marketId,$productId,$p['stock'],$source,$data['effective_date']]);$listGet->execute([$orgId,$marketId,$productId]);$listingId=(int)$listGet->fetchColumn();
        $a=$p['assoc'];$priceUp->execute([$orgId,$listingId,$data['effective_date'],$a[1],$a[0],'INR',$source]);$priceGet->execute([$orgId,$listingId,$data['effective_date']]);$priceId=(int)$priceGet->fetchColumn();
        $prices=['ASSOC_RETAIL'=>$a[2],'ASSOC_EARN_BASE'=>$a[3],'ASSOC_25'=>$a[4],'ASSOC_35'=>$a[5],'ASSOC_42'=>$a[6],'ASSOC_50'=>$a[7]];
        if(is_array($p['pref']??null)){$prices['PC_BRONZE']=$p['pref'][0];$prices['PC_SILVER']=$p['pref'][1];$prices['PC_GOLD']=$p['pref'][2];}
        if(($p['kind']??'')==='aop' && isset($p['pu_price'])) $prices['PU_PRICE']=$p['pu_price'];
        foreach($prices as $code=>$amount){if($amount===null||!isset($tiers[$code]))continue;$tierUp->execute([$priceId,(int)$tiers[$code]['id'],$amount]);}
        $image=product_step11_image_for($p); if($image){$imgExists->execute([$productId]);if((int)$imgExists->fetchColumn()===0){$imgUp->execute([$productId,$image['url'],$image['page'],$image['source'],$image['status'],$p['name']]);$images++;}}
        $count++;
      }
      $docUp=$pdo->prepare("INSERT INTO product_source_documents(organization_id,market_id,document_type,document_title,effective_date,file_sha256,source_reference,status) VALUES(?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE document_title=VALUES(document_title),effective_date=VALUES(effective_date),status='active'");
      $docUp->execute([$orgId,$marketId,'associate_price_list',$data['source_titles']['associate'],$data['effective_date'],$data['associate_pdf_sha256'],'User supplied PDF']);
      $docUp->execute([$orgId,$marketId,'preferred_price_list',$data['source_titles']['preferred'],$data['effective_date'],$data['preferred_pdf_sha256'],'User supplied PDF']);
      $ruleUp=$pdo->prepare("INSERT INTO product_delivery_rules(organization_id,market_id,rule_code,rule_name,applies_to,min_vp,max_vp,charge_amount,currency_code,note,status) VALUES(?,?,?,?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name),applies_to=VALUES(applies_to),min_vp=VALUES(min_vp),max_vp=VALUES(max_vp),charge_amount=VALUES(charge_amount),note=VALUES(note),status='active'");
      foreach($data['delivery_rules'] as $r)$ruleUp->execute([$orgId,$marketId,$r['rule_code'],$r['rule_name'],$r['applies_to'],$r['min_vp']??null,$r['max_vp']??null,$r['charge_amount'],'INR',$r['note']]);
      if(business_table_exists($pdo,'audit_logs')){$audit=$pdo->prepare("INSERT INTO audit_logs(organization_id,event_type,entity_type,details_json,ip_address,user_agent) VALUES(?,'product_catalog_pdf_seed_applied','product_catalog',?,?,?)");$audit->execute([$orgId,json_encode(['effective_date'=>$data['effective_date'],'products'=>$count,'online_images_added'=>$images,'associate_pdf_sha256'=>$data['associate_pdf_sha256'],'preferred_pdf_sha256'=>$data['preferred_pdf_sha256']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);}
      $pdo->commit(); return ['products'=>$count,'images_added'=>$images,'effective_date'=>$data['effective_date']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function product_step11_catalog_rows(PDO $pdo, int $organizationId, string $q='', string $category='ALL'): array
{
    $sql="SELECT p.id,p.product_code,p.sku,p.product_name,p.pack_size,p.pack_unit,p.status,c.category_name,l.id listing_id,l.market_sku,pv.id price_version_id,pv.effective_from,pv.mrp,pv.volume_points,pi.image_url,pi.verification_status FROM products p LEFT JOIN product_categories c ON c.id=p.category_id JOIN product_market_listings l ON l.product_id=p.id AND l.organization_id=p.organization_id JOIN product_price_versions pv ON pv.id=(SELECT pv2.id FROM product_price_versions pv2 WHERE pv2.organization_id=p.organization_id AND pv2.listing_id=l.id AND pv2.status='active' ORDER BY pv2.effective_from DESC,pv2.id DESC LIMIT 1) LEFT JOIN product_images pi ON pi.id=(SELECT pi2.id FROM product_images pi2 WHERE pi2.product_id=p.id ORDER BY pi2.is_primary DESC,pi2.sort_order,pi2.id LIMIT 1) WHERE p.organization_id=? AND p.status='active'";
    $args=[$organizationId]; if($category!=='ALL'){$sql.=" AND c.category_name=?";$args[]=$category;} if($q!==''){$sql.=" AND (p.product_name LIKE ? OR p.sku LIKE ? OR c.category_name LIKE ?)";$like='%'.$q.'%';array_push($args,$like,$like,$like);} $sql.=" ORDER BY c.sort_order,c.category_name,p.product_name,p.id";
    $stmt=$pdo->prepare($sql);$stmt->execute($args);$rows=$stmt->fetchAll();
    $tierStmt=$pdo->prepare("SELECT t.tier_code,tp.price_amount FROM product_tier_prices tp JOIN product_discount_tiers t ON t.id=tp.discount_tier_id WHERE tp.price_version_id=? AND t.status='active' ORDER BY t.sort_order");
    foreach($rows as &$r){$tierStmt->execute([(int)$r['price_version_id']]);$r['tiers']=[];foreach($tierStmt->fetchAll() as $t)$r['tiers'][$t['tier_code']]=(float)$t['price_amount'];}
    unset($r); return $rows;
}

function product_step11_price_for(array $row,string $tier): float
{
    if($tier==='MRP') return (float)$row['mrp'];
    return isset($row['tiers'][$tier])?(float)$row['tiers'][$tier]:(float)$row['mrp'];
}
