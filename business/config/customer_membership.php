<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';
require_once __DIR__ . '/public_store_step23.php';

const CUSTOMER_MEMBERSHIP_VERSION = '1.0-club-member-pricing';
const CUSTOMER_HOME_DELIVERY_CHARGE = 118.00;
const CUSTOMER_FREE_DELIVERY_VP = 100.00;

function cm_ensure(PDO $pdo): void
{
    role_portal_ensure($pdo);
    ps23_ensure($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_discount_labels (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        label_code VARCHAR(80) NOT NULL,
        label_name VARCHAR(120) NOT NULL,
        badge_text VARCHAR(120) NULL,
        pricing_tier_code VARCHAR(80) NULL,
        headline VARCHAR(190) NULL,
        description TEXT NULL,
        card_style VARCHAR(30) NOT NULL DEFAULT 'green',
        sort_order INT NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_cm_label_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        UNIQUE KEY uq_cm_label_code (organization_id,label_code),
        KEY idx_cm_label_status (organization_id,status,sort_order)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_membership_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        crm_customer_id BIGINT UNSIGNED NULL,
        member_code VARCHAR(60) NOT NULL,
        coach_user_id BIGINT UNSIGNED NULL,
        discount_label_id BIGINT UNSIGNED NULL,
        membership_status VARCHAR(30) NOT NULL DEFAULT 'pending',
        joined_at DATE NULL,
        verified_at DATETIME NULL,
        assigned_by BIGINT UNSIGNED NULL,
        notes VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_cm_profile_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_cm_profile_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_cm_profile_coach FOREIGN KEY (coach_user_id) REFERENCES system_users(id) ON DELETE SET NULL,
        CONSTRAINT fk_cm_profile_label FOREIGN KEY (discount_label_id) REFERENCES customer_discount_labels(id) ON DELETE SET NULL,
        CONSTRAINT fk_cm_profile_actor FOREIGN KEY (assigned_by) REFERENCES system_users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_cm_profile_user (organization_id,user_id),
        UNIQUE KEY uq_cm_profile_code (organization_id,member_code),
        KEY idx_cm_profile_status (organization_id,membership_status,coach_user_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_promotions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        promotion_code VARCHAR(80) NOT NULL,
        promotion_type VARCHAR(30) NOT NULL DEFAULT 'join_club',
        customer_scope VARCHAR(30) NOT NULL DEFAULT 'regular',
        product_id BIGINT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        badge_text VARCHAR(120) NULL,
        subtitle VARCHAR(255) NULL,
        description TEXT NULL,
        min_qty INT NOT NULL DEFAULT 1,
        min_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
        discount_type VARCHAR(30) NOT NULL DEFAULT 'informational',
        discount_value DECIMAL(16,2) NOT NULL DEFAULT 0,
        display_style VARCHAR(30) NOT NULL DEFAULT 'green',
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_cm_promo_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_cm_promo_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        UNIQUE KEY uq_cm_promo_code (organization_id,promotion_code),
        KEY idx_cm_promo_active (organization_id,status,promotion_type,sort_order)
    ) ENGINE=InnoDB");

    $ctx = security_step17_context($pdo);
    $orgId = (int)$ctx['organization_id'];

    $seed = $pdo->prepare("INSERT INTO customer_discount_labels
        (organization_id,label_code,label_name,badge_text,pricing_tier_code,headline,description,card_style,sort_order,status)
        VALUES(?,?,?,?,?,?,?,?,?,'active')
        ON DUPLICATE KEY UPDATE label_name=VALUES(label_name),badge_text=VALUES(badge_text),pricing_tier_code=VALUES(pricing_tier_code),headline=VALUES(headline),description=VALUES(description),card_style=VALUES(card_style),sort_order=VALUES(sort_order)");
    $seed->execute([$orgId,'BRONZE','Club Bronze','BRONZE MEMBER','PC_BRONZE','Your Club Bronze pricing is active.','Eligible products use the exact Bronze price stored from the approved price list.','bronze',10]);
    $seed->execute([$orgId,'SILVER','Club Silver','SILVER MEMBER','PC_SILVER','Your Club Silver pricing is active.','Eligible products use the exact Silver price stored from the approved price list.','silver',20]);
    $seed->execute([$orgId,'GOLD','Club Gold','GOLD MEMBER','PC_GOLD','Your Club Gold pricing is active.','Eligible products use the exact Gold price stored from the approved price list.','gold',30]);

    $promo = $pdo->prepare("INSERT INTO customer_promotions
        (organization_id,promotion_code,promotion_type,customer_scope,title,badge_text,subtitle,description,min_qty,min_vp,discount_type,discount_value,display_style,sort_order,status)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,? ,?,?,'active')
        ON DUPLICATE KEY UPDATE title=VALUES(title),badge_text=VALUES(badge_text),subtitle=VALUES(subtitle),description=VALUES(description),display_style=VALUES(display_style),sort_order=VALUES(sort_order)");
    $promo->execute([$orgId,'JOIN-CLUB','join_club','regular','Join the Wellness Club','MEMBER BENEFITS','Unlock your assigned member price level after verification.','Ask the club about membership, coaching support and member-only product pricing. Your member level is assigned only by an Administrator or your Coach.',1,0,'informational',0,'green',10]);
    $promo->execute([$orgId,'BULK-OFFER','bulk','regular','Bulk Product Offer','BULK SAVINGS','Ask about current quantity-based offers.','Bulk discount rules are controlled by the Administrator. When an active numeric offer is configured, the server applies it automatically to eligible order requests.',2,0,'informational',0,'gold',20]);

    if (business_table_exists($pdo,'public_order_requests')) {
        if (!business_column_exists($pdo,'public_order_requests','customer_user_id')) $pdo->exec("ALTER TABLE public_order_requests ADD COLUMN customer_user_id BIGINT UNSIGNED NULL AFTER lead_id");
        if (!business_column_exists($pdo,'public_order_requests','customer_membership_id')) $pdo->exec("ALTER TABLE public_order_requests ADD COLUMN customer_membership_id BIGINT UNSIGNED NULL AFTER customer_user_id");
        if (!business_column_exists($pdo,'public_order_requests','customer_price_mode')) $pdo->exec("ALTER TABLE public_order_requests ADD COLUMN customer_price_mode VARCHAR(80) NOT NULL DEFAULT 'mrp' AFTER customer_membership_id");
        if (!business_column_exists($pdo,'public_order_requests','discount_label_code')) $pdo->exec("ALTER TABLE public_order_requests ADD COLUMN discount_label_code VARCHAR(80) NULL AFTER customer_price_mode");
        if (!business_column_exists($pdo,'public_order_requests','discount_amount')) $pdo->exec("ALTER TABLE public_order_requests ADD COLUMN discount_amount DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER subtotal_mrp");
    }
    if (business_table_exists($pdo,'public_order_request_items')) {
        if (!business_column_exists($pdo,'public_order_request_items','unit_customer_price')) $pdo->exec("ALTER TABLE public_order_request_items ADD COLUMN unit_customer_price DECIMAL(16,2) NULL AFTER unit_mrp");
        if (!business_column_exists($pdo,'public_order_request_items','line_customer_price')) $pdo->exec("ALTER TABLE public_order_request_items ADD COLUMN line_customer_price DECIMAL(16,2) NULL AFTER line_mrp");
        if (!business_column_exists($pdo,'public_order_request_items','discount_amount')) $pdo->exec("ALTER TABLE public_order_request_items ADD COLUMN discount_amount DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER line_customer_price");
        if (!business_column_exists($pdo,'public_order_request_items','pricing_source')) $pdo->exec("ALTER TABLE public_order_request_items ADD COLUMN pricing_source VARCHAR(100) NULL AFTER discount_amount");
        if (!business_column_exists($pdo,'public_order_request_items','promotion_code')) $pdo->exec("ALTER TABLE public_order_request_items ADD COLUMN promotion_code VARCHAR(80) NULL AFTER pricing_source");
    }

    if (business_table_exists($pdo,'schema_meta')) {
        $s=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('customer_membership_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $s->execute([CUSTOMER_MEMBERSHIP_VERSION]);
    }
}

function cm_generate_member_code(PDO $pdo, int $orgId): string
{
    do {
        $code='HWC-'.date('y').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
        $s=$pdo->prepare("SELECT COUNT(*) FROM customer_membership_profiles WHERE organization_id=? AND member_code=?");
        $s->execute([$orgId,$code]);
    } while ((int)$s->fetchColumn()>0);
    return $code;
}

function cm_user_membership(PDO $pdo, int $orgId, int $userId): ?array
{
    cm_ensure($pdo);
    $s=$pdo->prepare("SELECT m.*,l.label_code,l.label_name,l.badge_text,l.pricing_tier_code,l.headline,l.description label_description,l.card_style,
        u.full_name customer_name,u.email customer_email,u.mobile customer_mobile,c.full_name coach_name
        FROM customer_membership_profiles m
        JOIN system_users u ON u.id=m.user_id
        LEFT JOIN customer_discount_labels l ON l.id=m.discount_label_id
        LEFT JOIN system_users c ON c.id=m.coach_user_id
        WHERE m.organization_id=? AND m.user_id=? LIMIT 1");
    $s->execute([$orgId,$userId]);$r=$s->fetch();return $r?:null;
}

function cm_session_customer(PDO $pdo): ?array
{
    try {
        security_step17_session_start();
        $u=security_step17_session_user($pdo,false);
        if (!$u || (string)($u['role_code']??'')!=='customer') return null;
        return $u;
    } catch (Throwable) {
        return null;
    }
}

function cm_customer_context(PDO $pdo, int $orgId, ?array $user=null): array
{
    $user=$user ?? cm_session_customer($pdo);
    if (!$user) return ['scope'=>'guest','is_member'=>false,'user'=>null,'membership'=>null,'label'=>null];
    $membership=cm_user_membership($pdo,$orgId,(int)$user['id']);
    $active=$membership && (string)$membership['membership_status']==='active' && !empty($membership['pricing_tier_code']);
    return [
        'scope'=>$active?'member':'regular',
        'is_member'=>$active,
        'user'=>$user,
        'membership'=>$membership,
        'label'=>$active?(string)$membership['label_code']:null,
        'tier_code'=>$active?(string)$membership['pricing_tier_code']:null,
    ];
}

function cm_promotions(PDO $pdo, int $orgId, string $scope='all', ?string $type=null): array
{
    cm_ensure($pdo);
    $sql="SELECT p.*,pr.product_name,pr.sku FROM customer_promotions p LEFT JOIN products pr ON pr.id=p.product_id
          WHERE p.organization_id=? AND p.status='active'
          AND (p.starts_at IS NULL OR p.starts_at<=NOW()) AND (p.ends_at IS NULL OR p.ends_at>=NOW())";
    $args=[$orgId];
    if ($scope!=='all') {$sql.=" AND p.customer_scope IN ('all',?)";$args[]=$scope;}
    if ($type!==null) {$sql.=" AND p.promotion_type=?";$args[]=$type;}
    $sql.=" ORDER BY p.sort_order,p.id";
    $s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();
}

function cm_exact_tier_price(PDO $pdo, int $priceVersionId, string $tierCode): ?float
{
    if ($priceVersionId<=0 || $tierCode==='') return null;
    $s=$pdo->prepare("SELECT tp.price_amount FROM product_tier_prices tp JOIN product_discount_tiers t ON t.id=tp.discount_tier_id
        WHERE tp.price_version_id=? AND t.tier_code=? AND t.status='active' LIMIT 1");
    $s->execute([$priceVersionId,$tierCode]);$v=$s->fetchColumn();return $v===false||$v===null?null:(float)$v;
}

function cm_best_promotion(PDO $pdo, int $orgId, array $product, int $qty, float $orderVp, string $scope, float $baseUnit): ?array
{
    $promos=cm_promotions($pdo,$orgId,$scope,null);$best=null;$bestUnit=$baseUnit;
    foreach($promos as $p){
        if(!in_array((string)$p['promotion_type'],['product','bulk'],true))continue;
        if($p['product_id']!==null && (int)$p['product_id']!==(int)$product['id'])continue;
        if($qty<(int)$p['min_qty'])continue;
        if($orderVp<(float)$p['min_vp'])continue;
        $type=(string)$p['discount_type'];$value=max(0,(float)$p['discount_value']);$candidate=$baseUnit;
        if($type==='percent' && $value>0 && $value<=100)$candidate=round($baseUnit*(1-($value/100)),2);
        elseif($type==='fixed' && $value>0)$candidate=max(0,round($baseUnit-$value,2));
        else continue;
        if($candidate<$bestUnit){$bestUnit=$candidate;$best=$p;$best['unit_price']=$candidate;}
    }
    return $best;
}

function cm_catalog_price(PDO $pdo, int $orgId, array $product, array $customerContext, int $qty=1, float $orderVp=0): array
{
    $mrp=(float)$product['mrp'];$unit=$mrp;$source='MRP';$tier=null;
    if(!empty($customerContext['is_member'])){
        $tier=(string)($customerContext['tier_code']??'');
        $exact=cm_exact_tier_price($pdo,(int)$product['price_version_id'],$tier);
        if($exact!==null){$unit=$exact;$source='MEMBER:'.$tier;}
    }
    $promo=cm_best_promotion($pdo,$orgId,$product,$qty,$orderVp,(string)$customerContext['scope'],$unit);
    if($promo){$unit=(float)$promo['unit_price'];$source='PROMO:'.(string)$promo['promotion_code'];}
    return [
        'mrp'=>$mrp,
        'unit_price'=>round($unit,2),
        'saving'=>round(max(0,$mrp-$unit),2),
        'pricing_source'=>$source,
        'tier_code'=>$tier,
        'promotion'=>$promo,
    ];
}

function cm_cart_quote(PDO $pdo, int $orgId, array $cart, string $mode): array
{
    cm_ensure($pdo);
    $mode=in_array($mode,['club_pickup','home_delivery'],true)?$mode:'club_pickup';
    $rawItems=[];$totalVp=0.0;$hasNutrition=false;$seen=[];$needsAvailabilityReview=false;
    foreach($cart as $raw){
        $id=(int)($raw['product_id']??0);$qty=(int)($raw['qty']??0);
        if($id<=0||$qty<=0||$qty>20||isset($seen[$id]))continue;$seen[$id]=1;
        $p=ps23_product($pdo,$orgId,$id);$av=ps23_availability($pdo,$orgId,(int)$p['listing_id'],$qty);
        if($av['status']!=='available'||($av['qty']!==null&&(float)$av['qty']<$qty))$needsAvailabilityReview=true;
        $lineVp=round((float)$p['volume_points']*$qty,3);$totalVp+=$lineVp;
        if(!in_array(strtoupper((string)$p['category_name']),['ART OF PROMOTION','APPLICATIONS'],true))$hasNutrition=true;
        $rawItems[]=['product'=>$p,'qty'=>$qty,'line_vp'=>$lineVp,'availability_status'=>$av['status']];
    }
    if(!$rawItems)throw new RuntimeException('Add at least one valid product.');

    $customer=cm_customer_context($pdo,$orgId);$items=[];$subtotalMrp=0.0;$subtotalCustomer=0.0;
    foreach($rawItems as $raw){
        $p=$raw['product'];$qty=(int)$raw['qty'];$price=cm_catalog_price($pdo,$orgId,$p,$customer,$qty,$totalVp);
        $lineMrp=round((float)$p['mrp']*$qty,2);$lineCustomer=round((float)$price['unit_price']*$qty,2);
        $subtotalMrp+=$lineMrp;$subtotalCustomer+=$lineCustomer;
        $items[]=$p+[
            'qty'=>$qty,
            'line_mrp'=>$lineMrp,
            'line_customer_price'=>$lineCustomer,
            'unit_customer_price'=>(float)$price['unit_price'],
            'line_discount'=>round(max(0,$lineMrp-$lineCustomer),2),
            'line_vp'=>$raw['line_vp'],
            'availability_status'=>$raw['availability_status'],
            'pricing_source'=>$price['pricing_source'],
            'promotion_code'=>$price['promotion']['promotion_code']??null,
        ];
    }
    $delivery=($mode==='home_delivery'&&$hasNutrition&&$totalVp>0&&$totalVp<CUSTOMER_FREE_DELIVERY_VP)?CUSTOMER_HOME_DELIVERY_CHARGE:0.0;
    $membership=$customer['membership']??null;
    return [
        'items'=>$items,
        'subtotal_mrp'=>round($subtotalMrp,2),
        'subtotal_customer'=>round($subtotalCustomer,2),
        'discount_amount'=>round(max(0,$subtotalMrp-$subtotalCustomer),2),
        'total_vp'=>round($totalVp,3),
        'delivery_charge'=>$delivery,
        'estimated_total'=>round($subtotalCustomer+$delivery,2),
        'delivery_mode'=>$mode,
        'availability_review_required'=>$needsAvailabilityReview,
        'customer_context'=>$customer,
        'customer_user_id'=>(int)($customer['user']['id']??0)?:null,
        'customer_membership_id'=>(int)($membership['id']??0)?:null,
        'discount_label_code'=>$customer['label']??null,
        'customer_price_mode'=>!empty($customer['is_member'])?'member_'.strtolower((string)($customer['tier_code']??'tier')):($customer['scope']==='regular'?'regular_mrp':'public_mrp'),
    ];
}
