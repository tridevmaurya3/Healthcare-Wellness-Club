<?php
declare(strict_types=1);

/**
 * Universal Administrator navigation shell.
 *
 * The menu is injected only into authenticated Administrator HTML pages. JSON,
 * downloads, redirects, public storefront pages and non-admin roles are left untouched.
 */

function admin_global_menu_start(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') return;
    if (defined('HWC_ADMIN_GLOBAL_MENU_STARTED')) return;
    define('HWC_ADMIN_GLOBAL_MENU_STARTED', true);
    ob_start('admin_global_menu_inject');
}

function admin_global_menu_active(string $script, array $scripts): string
{
    return in_array($script, $scripts, true) ? ' active' : '';
}

function admin_global_menu_link(string $href, string $label, string $script, array $scripts, string $icon): string
{
    $active = admin_global_menu_active($script, $scripts);
    return '<a class="hwc-admin-nav-link'.$active.'" href="'.htmlspecialchars($href, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'">'
        .'<span class="hwc-admin-nav-icon" aria-hidden="true">'.htmlspecialchars($icon, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span>'
        .'<span>'.htmlspecialchars($label, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span></a>';
}

function admin_global_menu_markup(bool $insideBusiness, string $script): string
{
    $business = $insideBusiness ? '' : 'business/';
    $root = $insideBusiness ? '../' : '';

    $groups = [
        ['Workspace', [
            [$business.'index.php','Dashboard',['index.php','dashboard_step25.php'],'⌂'],
            [$root.'feature_hub.php','All Features',['feature_hub.php'],'▦'],
        ]],
        ['Customers', [
            [$business.'customer_center.php','Customer Center',['customer_center.php','customer_detail.php'],'◎'],
            [$business.'customer_membership_manager.php','Club Members & Offers',['customer_membership_manager.php'],'★'],
            [$business.'public_order_center.php','Customer Orders',['public_order_center.php','public_order_detail.php'],'▤'],
            [$business.'lead_center.php','Leads & Enquiries',['lead_center.php','lead_followups.php','lead_appointments.php'],'↗'],
        ]],
        ['Products & Sales', [
            [$business.'product_catalog.php','Product Catalog',['product_catalog.php','product_detail.php'],'□'],
            [$business.'product_master_manager.php','New / Update Product',['product_master_manager.php'],'＋'],
            [$business.'product_image_manager.php','Product Images',['product_image_manager.php','product_images.php'],'▧'],
            [$business.'product_sales_center.php','Sales Center',['product_sales_center.php','product_sale_detail.php','product_quotes.php','product_payments.php'],'₹'],
        ]],
        ['Operations', [
            [$business.'inventory_center.php','Inventory',['inventory_center.php','inventory_stocktake.php','inventory_batches.php'],'◫'],
            [$business.'purchase_center.php','Purchases & Suppliers',['purchase_center.php','purchase_orders.php','supplier_center.php'],'⇄'],
            [$business.'report_center.php','Reports & Analytics',['report_center.php','insights_center.php','master_tracking.php','sp_house.php','name_wise_tracking.php','master_business_tracking.php','ums_renewal.php','ums_active_duration.php'],'▥'],
            [$business.'finance_center.php','Finance',['finance_center.php','finance_cashbook.php','finance_profit_loss.php','finance_cash_flow.php','finance_reconciliation.php'],'₹'],
        ]],
        ['Administration', [
            [$business.'customer_site_manager.php','Customer Site Manager',['customer_site_manager.php'],'◉'],
            [$business.'security_center.php','Security Center',['security_center.php','security_audit.php','security_sessions.php','account_security.php'],'◆'],
            [$business.'user_management.php','Users & Roles',['user_management.php','permission_matrix.php','role_accounts.php'],'♙'],
            [$business.'backup_center.php','Backup & Recovery',['backup_center.php','backup_create.php','backup_history.php','backup_policy.php','backup_restore.php','disaster_recovery.php'],'↺'],
            [$business.'deployment_center.php','Deployment',['deployment_center.php','production_health.php','deployment_releases.php','maintenance_center.php','migration_center.php','scheduler_center.php'],'⇧'],
        ]],
        ['My Account', [
            [$root.'account.php','My Account',['account.php'],'●'],
            [$root.'security_alerts.php','Security Alerts',['security_alerts.php'],'!'],
            [$root.'trusted_devices.php','Trusted Devices',['trusted_devices.php'],'▣'],
            [$root.'mfa_settings.php','Two-Step Verification',['mfa_settings.php'],'✓'],
            [$root.'logout.php','Sign Out',['logout.php'],'→'],
        ]],
    ];

    $html = '<aside class="hwc-admin-global-sidebar" id="hwcAdminGlobalSidebar" aria-label="Administrator navigation">'
        .'<a class="hwc-admin-brand" href="'.htmlspecialchars($business.'index.php', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'">'
        .'<span class="hwc-admin-brand-mark">HWC</span><span><b>Healthcare Wellness Club</b><small>Administrator Portal</small></span></a>';

    foreach ($groups as $groupIndex => [$title, $links]) {
        $groupId = 'hwcAdminNavGroup'.(int)$groupIndex;
        $html .= '<div class="hwc-admin-nav-group"><button class="hwc-admin-nav-title" type="button" aria-expanded="false" aria-controls="'.$groupId.'"><span>'.htmlspecialchars($title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span><i aria-hidden="true"></i></button><div class="hwc-admin-nav-items" id="'.$groupId.'" hidden>';
        foreach ($links as [$href,$label,$scripts,$icon]) {
            $html .= admin_global_menu_link($href,$label,$script,$scripts,$icon);
        }
        $html .= '</div></div>';
    }

    $html .= '</aside><button class="hwc-admin-menu-toggle" id="hwcAdminMenuToggle" type="button" aria-controls="hwcAdminGlobalSidebar" aria-expanded="false">☰ Menu</button><button class="hwc-admin-menu-backdrop" id="hwcAdminMenuBackdrop" type="button" aria-label="Close Administrator menu"></button>';
    return $html;
}

function admin_global_menu_styles(): string
{
    return <<<'HTML'
<style id="hwc-admin-global-menu-css">
:root{--hwc-admin-menu-w:244px}.hwc-admin-global-sidebar{position:fixed;inset:0 auto 0 0;width:var(--hwc-admin-menu-w);z-index:2147483000;background:linear-gradient(180deg,#f8fbfa 0%,#f5f8fb 100%);border-right:1px solid #dbe5e0;box-shadow:8px 0 28px rgba(31,59,48,.07);padding:14px 11px 22px;overflow-y:auto;overscroll-behavior:contain;font-family:Inter,Segoe UI,Arial,sans-serif}.hwc-admin-brand{display:flex;align-items:center;gap:10px;padding:9px 8px 14px;text-decoration:none;border-bottom:1px solid #e1e8e4;margin-bottom:8px}.hwc-admin-brand-mark{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#173f2e;color:#fff;font-size:.68rem;font-weight:900;letter-spacing:.05em;flex:none}.hwc-admin-brand b{display:block;color:#183a2d;font-size:.75rem;line-height:1.25}.hwc-admin-brand small{display:block;color:#74847c;font-size:.61rem;margin-top:2px}.hwc-admin-nav-group{margin-top:7px}.hwc-admin-nav-title{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:42px;padding:6px 5px 6px 9px;border:0;border-radius:11px;background:transparent;color:#718078;font:900 .55rem/1.2 Inter,Segoe UI,Arial,sans-serif;letter-spacing:.09em;text-align:left;text-transform:uppercase;cursor:pointer}.hwc-admin-nav-title:hover{background:#edf5f1;color:#315b48}.hwc-admin-nav-title i{position:relative;display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;margin:0;border:1px solid #cfe1d7;border-radius:9px;background:linear-gradient(180deg,#fff,#f3f8f5);box-shadow:0 4px 12px rgba(31,72,51,.06)}.hwc-admin-nav-title i:after{content:"";display:block;width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translate(-1px,-1px);transform-origin:center;transition:transform .18s ease}.hwc-admin-nav-title[aria-expanded=true] i:after{transform:rotate(225deg) translate(-1px,-1px)}.hwc-admin-nav-items{padding:3px 0 5px}.hwc-admin-nav-items[hidden]{display:none!important}.hwc-admin-nav-link{display:flex;align-items:center;gap:9px;padding:8px 9px;margin:2px 0;border-radius:10px;text-decoration:none;color:#42574e;font-size:.69rem;font-weight:760;line-height:1.22;border:1px solid transparent;transition:.16s ease}.hwc-admin-nav-link:hover{background:#fff;border-color:#dbe5e0;color:#163b2c}.hwc-admin-nav-link.active{background:#e9f5ee;border-color:#cce3d5;color:#12613c}.hwc-admin-nav-icon{display:grid;place-items:center;width:23px;height:23px;border-radius:8px;background:#edf2ef;color:#51675d;font-size:.68rem;font-weight:900;flex:none}.hwc-admin-nav-link.active .hwc-admin-nav-icon{background:#d9eee1;color:#12613c}.hwc-admin-menu-toggle{display:none;position:fixed;left:10px;top:10px;z-index:2147483002;border:1px solid #ccdcd3;background:#fff;color:#173f2e;border-radius:10px;padding:8px 11px;font:800 .72rem/1 Inter,Segoe UI,Arial,sans-serif;box-shadow:0 6px 20px rgba(23,63,46,.15);cursor:pointer}.hwc-admin-menu-backdrop{display:none;position:fixed;inset:0;z-index:2147482999;border:0;background:rgba(13,31,24,.34)}
.hwc-membership-candidates{margin:14px 0;padding:17px;border:1px solid #dce8e1;border-radius:18px;background:linear-gradient(135deg,#f8fcfa,#fbfcff)}.hwc-membership-candidates-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin-bottom:12px}.hwc-membership-candidates h2{margin:0;color:#173c2c;font-size:1.04rem}.hwc-membership-candidates p{margin:4px 0 0;color:#6e7f76;font-size:.7rem;line-height:1.5}.hwc-membership-count{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#176f45;font-size:.6rem;font-weight:900;white-space:nowrap}.hwc-membership-candidate-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.hwc-membership-candidate{padding:12px;border:1px solid #dde8e2;border-radius:13px;background:#fff}.hwc-membership-candidate b{display:block;color:#173c2c;font-size:.78rem}.hwc-membership-candidate small{display:block;margin-top:3px;color:#74847c;font-size:.62rem;word-break:break-word}.hwc-membership-candidate-meta{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.hwc-membership-pill{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef3f0;color:#52675e;font-size:.55rem;font-weight:850}.hwc-membership-pill.member{background:#e8f6ed;color:#176f45}.hwc-membership-candidate button,.hwc-membership-candidate a.hwc-assign-link{display:inline-flex;margin-top:9px;padding:7px 9px;border:0;border-radius:9px;background:#176f45;color:#fff;text-decoration:none;font-size:.62rem;font-weight:850;cursor:pointer}.hwc-membership-candidate a.hwc-assign-link{background:#eef6f1;color:#176f45;border:1px solid #d2e4d9}
@media(min-width:1101px){body{padding-left:var(--hwc-admin-menu-w)!important;box-sizing:border-box}.os-layout{grid-template-columns:minmax(0,1fr)!important}.os-layout>.os-sidebar{display:none!important}}
@media(max-width:1100px){.hwc-admin-global-sidebar{transform:translateX(-103%);transition:transform .2s ease;width:min(86vw,285px)}.hwc-admin-menu-toggle{display:block}.hwc-admin-menu-open .hwc-admin-global-sidebar{transform:translateX(0)}.hwc-admin-menu-open .hwc-admin-menu-backdrop{display:block}.hwc-admin-menu-open{overflow:hidden}.os-layout>.os-sidebar{display:none!important}.os-layout{grid-template-columns:minmax(0,1fr)!important}body{padding-left:0!important}.hwc-membership-candidate-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.hwc-membership-candidate-grid{grid-template-columns:1fr}.hwc-membership-candidates-head{align-items:flex-start;flex-direction:column}}
@media print{.hwc-admin-global-sidebar,.hwc-admin-menu-toggle,.hwc-admin-menu-backdrop{display:none!important}body{padding-left:0!important}}
</style>
HTML;
}

function admin_global_menu_script(): string
{
    return <<<'HTML'
<script id="hwc-admin-global-menu-js">
(()=>{const b=document.body,t=document.getElementById('hwcAdminMenuToggle'),d=document.getElementById('hwcAdminMenuBackdrop');if(!b||!t)return;const close=()=>{b.classList.remove('hwc-admin-menu-open');t.setAttribute('aria-expanded','false')};t.addEventListener('click',()=>{const open=!b.classList.contains('hwc-admin-menu-open');b.classList.toggle('hwc-admin-menu-open',open);t.setAttribute('aria-expanded',open?'true':'false')});d?.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});document.getElementById('hwcAdminGlobalSidebar')?.addEventListener('click',e=>{const group=e.target.closest('.hwc-admin-nav-title');if(group){const items=document.getElementById(group.getAttribute('aria-controls')||'');if(items){const open=group.getAttribute('aria-expanded')==='true';group.setAttribute('aria-expanded',open?'false':'true');items.hidden=open}return}if(e.target.closest('a')&&matchMedia('(max-width:1100px)').matches)close()});document.addEventListener('click',e=>{const btn=e.target.closest('[data-hwc-membership-user]');if(!btn)return;const select=document.querySelector('select[name="user_id"]');if(!select)return;select.value=btn.getAttribute('data-hwc-membership-user')||'';select.dispatchEvent(new Event('change',{bubbles:true}));select.focus();select.scrollIntoView({behavior:'smooth',block:'center'})})})();
</script>
HTML;
}

function admin_global_menu_membership_candidates_panel(): string
{
    $customers = $GLOBALS['customers'] ?? [];
    $memberships = $GLOBALS['memberships'] ?? [];
    if (!is_array($customers) || !$customers) return '';

    $memberByUser = [];
    foreach ($memberships as $membership) {
        if (!is_array($membership)) continue;
        $uid = (int)($membership['user_id'] ?? 0);
        if ($uid > 0) $memberByUser[$uid] = $membership;
    }

    $regularCount = 0;
    foreach ($customers as $customer) {
        if (!is_array($customer)) continue;
        $uid = (int)($customer['id'] ?? 0);
        if ($uid > 0 && !isset($memberByUser[$uid])) $regularCount++;
    }

    $h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $html = '<section class="hwc-membership-candidates" id="hwcMembershipCustomerAccounts">'
        .'<div class="hwc-membership-candidates-head"><div><h2>Customer Login Accounts</h2><p>New website sign-ups appear here immediately. Regular customers can be selected below and converted to Club Members without creating a duplicate account.</p></div>'
        .'<span class="hwc-membership-count">'.$regularCount.' regular • '.count($memberByUser).' member</span></div>'
        .'<div class="hwc-membership-candidate-grid">';

    foreach ($customers as $customer) {
        if (!is_array($customer)) continue;
        $uid = (int)($customer['id'] ?? 0);
        if ($uid <= 0) continue;
        $membership = $memberByUser[$uid] ?? null;
        $name = trim((string)($customer['full_name'] ?? 'Customer'));
        $email = trim((string)($customer['email'] ?? ''));
        $mobile = trim((string)($customer['mobile'] ?? ''));

        $html .= '<article class="hwc-membership-candidate"><b>'.$h($name).'</b><small>'.$h($email).'</small>';
        if ($mobile !== '') $html .= '<small>'.$h($mobile).'</small>';
        $html .= '<div class="hwc-membership-candidate-meta">';
        if (is_array($membership)) {
            $html .= '<span class="hwc-membership-pill member">CLUB MEMBER</span>';
            if (!empty($membership['label_name'])) $html .= '<span class="hwc-membership-pill">'.$h($membership['label_name']).'</span>';
            if (!empty($membership['member_code'])) $html .= '<span class="hwc-membership-pill">'.$h($membership['member_code']).'</span>';
        } else {
            $html .= '<span class="hwc-membership-pill">REGULAR CUSTOMER</span><span class="hwc-membership-pill">NO MEMBER ID</span>';
        }
        $html .= '</div>';
        if (is_array($membership) && (int)($membership['id'] ?? 0) > 0) {
            $html .= '<a class="hwc-assign-link" href="?edit_membership='.(int)$membership['id'].'">Edit Membership</a>';
        } else {
            $html .= '<button type="button" data-hwc-membership-user="'.$uid.'">Assign Club Membership</button>';
        }
        $html .= '</article>';
    }

    return $html.'</div></section>';
}

function admin_global_menu_inject(string $html): string
{
    if ($html === '' || stripos($html, '<html') === false || stripos($html, '<body') === false) return $html;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if ((string)($_SESSION['hwc_role'] ?? '') !== 'admin' || (int)($_SESSION['hwc_user_id'] ?? 0) <= 0) return $html;

    $path = str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    $script = basename($path);
    $insideBusiness = str_contains($path, '/business/');
    $allowedRoot = ['feature_hub.php','account.php','security_alerts.php','trusted_devices.php','trusted_device_diagnostics.php','mfa_settings.php','change_password.php'];
    if (!$insideBusiness && !in_array($script, $allowedRoot, true)) return $html;
    if (str_contains($path, '/shop/')) return $html;

    if (str_contains($html, 'id="hwcAdminGlobalSidebar"')) return $html;

    $html = preg_replace('/<\/head>/i', admin_global_menu_styles().'</head>', $html, 1) ?? $html;
    $markup = admin_global_menu_markup($insideBusiness, $script);
    $html = preg_replace('/<body([^>]*)>/i', '<body$1>'.$markup, $html, 1) ?? $html;

    if ($script === 'customer_membership_manager.php' && str_contains($html, '<section class="cm-grid">')) {
        $panel = admin_global_menu_membership_candidates_panel();
        if ($panel !== '') $html = preg_replace('/<section class="cm-grid">/', $panel.'<section class="cm-grid">', $html, 1) ?? $html;
    }

    $html = preg_replace('/<\/body>/i', admin_global_menu_script().'</body>', $html, 1) ?? $html;
    return $html;
}
