<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';
require_once __DIR__ . '/public_store_step23.php';

const STOREFRONT_ROLE_BRIDGE_VERSION='1.0-coach-admin-order-visibility';

/** Coach can view incoming product requests, while manage/export remain Admin/Manager controls. */
function srb_ensure(PDO $pdo): void
{
    role_portal_ensure($pdo);
    $ctx=security_step17_context($pdo);
    $orgId=(int)$ctx['organization_id'];

    $p=$pdo->prepare("INSERT INTO security_permissions(permission_code,permission_name,module_code,risk_level,description,is_active) VALUES('storefront.view','Public Store Orders: View','storefront','sensitive','View public product requests and storefront analytics.',1) ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_code=VALUES(module_code),risk_level=VALUES(risk_level),description=VALUES(description),is_active=1");
    $p->execute();

    $r=$pdo->prepare("INSERT INTO security_role_permissions(organization_id,role_code,permission_code,is_allowed) VALUES(?,'coach','storefront.view',1) ON DUPLICATE KEY UPDATE is_allowed=1");
    $r->execute([$orgId]);

    if (business_table_exists($pdo,'schema_meta')) {
        $s=$pdo->prepare("INSERT INTO schema_meta(meta_key,meta_value) VALUES('storefront_role_bridge_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $s->execute([STOREFRONT_ROLE_BRIDGE_VERSION]);
    }
}
