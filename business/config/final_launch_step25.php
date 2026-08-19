<?php
declare(strict_types=1);

require_once __DIR__.'/public_store_step23.php';
require_once __DIR__.'/backup_step18.php';
require_once __DIR__.'/deployment_step19.php';

const FINAL_LAUNCH_STEP25_VERSION = '1.0-complete';

function step25_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function step25_uat_catalog(): array
{
    return [
        ['id'=>'auth','name'=>'Login & access control','test'=>'Sign in with the intended production user, open Dashboard, then verify an unauthorized role cannot open a restricted workspace.','expect'=>'Valid user enters normally; restricted access is denied without exposing data.'],
        ['id'=>'catalog','name'=>'Public catalog','test'=>'Open the public store, search a product, change category filter and open a product detail page.','expect'=>'64 active products remain source-backed; search/filter/detail work and no internal cost/stock quantity is exposed.'],
        ['id'=>'cart','name'=>'Cart flow','test'=>'Add two products, change quantity, remove one item and reload the page.','expect'=>'Cart persists locally, quantities remain 1–20 and totals use the current catalog MRP/VP.'],
        ['id'=>'checkout','name'=>'Order-request checkout','test'=>'Submit one real test order request using safe test contact data.','expect'=>'Server recalculates the cart, creates an order request (not a paid sale) and returns request code + private tracking key.'],
        ['id'=>'tracking','name'=>'Private tracking','test'=>'Track the test request with the generated code/key and try an incorrect key once.','expect'=>'Correct key shows public-safe status; incorrect key fails; staff-only notes never appear.'],
        ['id'=>'handoff','name'=>'Staff order handoff','test'=>'Open Public Orders as authorized staff, review the test request and verify the controlled customer/quote handoff path.','expect'=>'Request is reviewable and cannot silently bypass customer linking, quote or Sales finalization.'],
        ['id'=>'pwa','name'=>'PWA & offline boundary','test'=>'On a supported browser install/open the PWA, then test a normal cached/offline navigation and attempt Checkout while offline.','expect'=>'App shell/offline fallback works; checkout/submission/private tracking are never faked offline.'],
        ['id'=>'responsive','name'=>'Mobile/tablet/desktop UI','test'=>'Check storefront and Business OS at phone, tablet and desktop widths, including keyboard focus.','expect'=>'No clipped primary actions, readable cards/forms, usable 44px touch targets and visible keyboard focus.'],
        ['id'=>'recovery','name'=>'Backup & recovery evidence','test'=>'Open Backup/Recovery Center and confirm at least one current verified recovery package before production release.','expect'=>'Verified recovery point exists and is usable as the rollback boundary.'],
        ['id'=>'health','name'=>'Production health & launch gates','test'=>'On the target server run Production Health and confirm HTTPS, allowed host, dedicated DB credentials and private health token.','expect'=>'Production Health is PASS and no real secret is displayed in the UI/log output.'],
    ];
}

function step25_scalar(PDO $pdo, string $sql, array $args=[]): int
{
    $stmt=$pdo->prepare($sql);$stmt->execute($args);return (int)$stmt->fetchColumn();
}

function step25_latest_uat(PDO $pdo, int $orgId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM deployment_events WHERE organization_id=? AND event_type='step25_uat_signoff' AND event_status='pass' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orgId]);$row=$stmt->fetch();if(!$row)return null;
    $details=json_decode((string)($row['details_json']??''),true);$row['details']=is_array($details)?$details:[];return $row;
}

function step25_record_uat(PDO $pdo, array $user, array $selected, string $notes=''): void
{
    if(!security_step17_has_permission($pdo,'deployment.release',$user))throw new RuntimeException('Production Release permission is required to sign off STEP 25 UAT.');
    $required=array_column(step25_uat_catalog(),'id');
    $selected=array_values(array_unique(array_filter(array_map('strval',$selected))));
    $missing=array_values(array_diff($required,$selected));
    if($missing!==[])throw new RuntimeException('Complete every UAT case before sign-off. Missing: '.implode(', ',$missing));
    deployment_step19_log($pdo,'step25_uat_signoff','pass',[
        'version'=>FINAL_LAUNCH_STEP25_VERSION,
        'cases'=>$required,
        'case_count'=>count($required),
        'notes'=>trim($notes)?:null,
        'signed_at'=>date(DATE_ATOM),
    ],(int)($user['id']??0));
}

function step25_state(PDO $pdo): array
{
    backup_step18_ensure($pdo);deployment_step19_ensure($pdo);$ctx=deployment_step19_context($pdo);$orgId=(int)$ctx['organization_id'];
    $runtime=deployment_step19_runtime_checks($pdo);$health=deployment_step19_health($pdo,false);
    $stmt=$pdo->prepare("SELECT COUNT(*) total_rows,SUM(mapping_status='mapped') mapped_rows,SUM(mapping_status='pending') pending_rows FROM raw_source_records WHERE organization_id=? AND source_dataset IN ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");
    $stmt->execute([$orgId]);$legacy=$stmt->fetch()?:[];
    $products=step25_scalar($pdo,"SELECT COUNT(*) FROM products WHERE organization_id=? AND status='active'",[$orgId]);
    $catalog=count(ps23_catalog($pdo,$orgId));
    $verified=business_table_exists($pdo,'backup_records')?step25_scalar($pdo,"SELECT COUNT(*) FROM backup_records WHERE organization_id=? AND verification_status='verified' AND status<>'expired'",[$orgId]):0;
    $admins=step25_scalar($pdo,"SELECT COUNT(DISTINCT u.id) FROM system_users u JOIN organization_user_access a ON a.user_id=u.id AND a.organization_id=? AND a.is_active=1 AND a.role_code='admin' WHERE u.is_active=1",[$orgId]);
    $invalidOrders=business_table_exists($pdo,'public_order_requests')?step25_scalar($pdo,"SELECT COUNT(*) FROM public_order_requests WHERE organization_id=? AND (order_status NOT IN ('submitted','reviewing','confirmed','quote_ready','converted','cancelled') OR payment_status NOT IN ('not_requested','pending','paid_external','failed','not_applicable') OR tax_status<>'not_calculated')",[$orgId]):0;
    $latestRelease=null;if(business_table_exists($pdo,'deployment_releases')){$s=$pdo->prepare("SELECT * FROM deployment_releases WHERE organization_id=? ORDER BY id DESC LIMIT 1");$s->execute([$orgId]);$latestRelease=$s->fetch()?:null;}
    $uat=step25_latest_uat($pdo,$orgId);
    $legacyOk=(int)($legacy['total_rows']??0)===757&&(int)($legacy['mapped_rows']??0)===757&&(int)($legacy['pending_rows']??0)===0;
    $coreReady=$legacyOk&&$products===64&&$catalog===64&&$admins>=1&&$invalidOrders===0&&($health['status']??'review')==='pass';
    $prodGates=[
        ['name'=>'HTTPS App URL','ok'=>(bool)($runtime['production_url_ready']??false),'detail'=>'HWC_APP_URL uses https://'],
        ['name'=>'Dedicated DB credentials','ok'=>(bool)($runtime['production_db_credentials_ready']??false),'detail'=>'non-root DB user + non-empty password'],
        ['name'=>'Allowed host list','ok'=>(bool)($runtime['allowed_hosts_ready']??false),'detail'=>'HWC_ALLOWED_HOSTS configured'],
        ['name'=>'Private health token','ok'=>(bool)($runtime['health_token_ready']??false),'detail'=>'24+ character server-only token'],
        ['name'=>'Verified recovery point','ok'=>$verified>0,'detail'=>$verified.' verified backup(s)'],
        ['name'=>'UAT sign-off','ok'=>$uat!==null,'detail'=>$uat?'recorded '.$uat['created_at']:'not recorded yet'],
        ['name'=>'Production health','ok'=>(($health['status']??'review')==='pass'),'detail'=>(int)($health['passed']??0).' pass / '.(int)($health['review']??0).' review'],
    ];
    $allProd=true;foreach($prodGates as $g){if(!$g['ok']){$allProd=false;break;}}
    $isProduction=($runtime['environment']??'local')==='production';
    $deployed=$isProduction&&$latestRelease&&($latestRelease['release_status']??'')==='deployed';
    return [
        'organization_id'=>$orgId,'runtime'=>$runtime,'health'=>$health,'legacy'=>$legacy,'products'=>$products,'catalog'=>$catalog,
        'verified_backups'=>$verified,'admins'=>$admins,'invalid_orders'=>$invalidOrders,'latest_release'=>$latestRelease,'latest_uat'=>$uat,
        'core_ready'=>$coreReady,'production_gates'=>$prodGates,'production_ready'=>$isProduction&&$coreReady&&$allProd,'production_live'=>(bool)$deployed,
    ];
}
