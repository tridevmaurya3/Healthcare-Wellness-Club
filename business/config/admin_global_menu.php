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
            [$business.'business_help_center.php','Help & User Guide',['business_help_center.php'],'?'],
        ]],
        ['Customers', [
            [$business.'customer_center.php','Customer Center',['customer_center.php','customer_detail.php'],'◎'],
            [$business.'customer_membership_manager.php','Club Members & Offers',['customer_membership_manager.php'],'★'],
            [$business.'coach_network_manager.php','Coach Network & Levels',['coach_network_manager.php'],'♟'],
            [$business.'public_order_center.php','Customer Orders',['public_order_center.php','public_order_detail.php'],'▤'],
            [$business.'lead_center.php','Leads & Enquiries',['lead_center.php','lead_followups.php','lead_appointments.php'],'↗'],
        ]],
        ['Daily Data Entry', [
            [$business.'data_entry_center.php','Data Entry Center',['data_entry_center.php'],'✎'],
            [$business.'data_entry_center.php?tab=new_ums','New UMS',['data_entry_center.php'],'＋'],
            [$business.'data_entry_center.php?tab=vp','Volume Points',['data_entry_center.php'],'VP'],
            [$business.'data_entry_center.php?tab=order','Orders',['data_entry_center.php'],'▤'],
            [$business.'data_entry_center.php?tab=renewal','Renewal',['data_entry_center.php'],'↻'],
            [$business.'data_entry_center.php?tab=income','Income',['data_entry_center.php'],'₹'],
            [$business.'data_entry_center.php?tab=royalty','Royalty',['data_entry_center.php'],'◆'],
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
            [$business.'form_designer.php','Smart Form Designer',['form_designer.php'],'✎'],
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
        .'<img class="hwc-admin-brand-logo" src="'.htmlspecialchars($root.'img/logo.png', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'" alt=""><span><b>Healthcare Wellness Club</b><small>Business Admin • Online</small></span></a>';

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
:root{--hwc-admin-menu-w:232px}.hwc-admin-global-sidebar{position:fixed;inset:0 auto 0 0;width:var(--hwc-admin-menu-w);z-index:2147483000;background:linear-gradient(180deg,#fff 0%,#f8f8fe 100%);border-right:1px solid #e3e4ed;box-shadow:7px 0 24px rgba(52,45,110,.055);padding:13px 10px 22px;overflow-y:auto;overscroll-behavior:contain;font-family:Inter,Segoe UI,Arial,sans-serif}.hwc-admin-brand{display:flex;align-items:center;gap:10px;padding:8px 7px 14px;text-decoration:none;border-bottom:1px solid #e7e7ef;margin-bottom:7px}.hwc-admin-brand-logo{width:38px;height:38px;border:1px solid #dedcf1;border-radius:11px;object-fit:cover;box-shadow:0 4px 12px rgba(73,58,164,.12);flex:none}.hwc-admin-brand b{display:block;color:#29234b;font-size:.74rem;line-height:1.25}.hwc-admin-brand small{display:block;color:#858696;font-size:.59rem;margin-top:3px}.hwc-admin-nav-group{margin-top:6px}.hwc-admin-nav-title{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:40px;padding:5px 5px 5px 8px;border:0;border-radius:10px;background:transparent;color:#7d7f8e;font:900 .53rem/1.2 Inter,Segoe UI,Arial,sans-serif;letter-spacing:.085em;text-align:left;text-transform:uppercase;cursor:pointer}.hwc-admin-nav-title:hover{background:#f2f0ff;color:#4c3eb7}.hwc-admin-nav-title i{position:relative;display:grid;place-items:center;flex:0 0 27px;width:27px;height:27px;margin:0;border:1px solid #dcd9ec;border-radius:8px;background:#fff;box-shadow:0 3px 9px rgba(58,47,129,.05)}.hwc-admin-nav-title i:after{content:"";display:block;width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translate(-1px,-1px);transform-origin:center;transition:transform .18s ease}.hwc-admin-nav-title[aria-expanded=true] i:after{transform:rotate(225deg) translate(-1px,-1px)}.hwc-admin-nav-items{padding:3px 0 5px}.hwc-admin-nav-items[hidden]{display:none!important}.hwc-admin-nav-link{display:flex;align-items:center;gap:8px;padding:8px 8px;margin:2px 0;border-radius:9px;text-decoration:none;color:#535766;font-size:.67rem;font-weight:750;line-height:1.22;border:1px solid transparent;transition:.16s ease}.hwc-admin-nav-link:hover{background:#f7f6ff;border-color:#e0ddf4;color:#4334b5}.hwc-admin-nav-link.active{background:linear-gradient(135deg,#eeeaff,#f5f3ff);border-color:#d5d0f4;color:#4433bc;box-shadow:inset 3px 0 #6250dd}.hwc-admin-nav-icon{display:grid;place-items:center;width:23px;height:23px;border-radius:7px;background:#f1f1f7;color:#6e7080;font-size:.65rem;font-weight:900;flex:none}.hwc-admin-nav-link.active .hwc-admin-nav-icon{background:#ded8ff;color:#4433bc}.hwc-admin-menu-toggle{display:none;position:fixed;left:10px;top:10px;z-index:2147483002;border:1px solid #d5d1ed;background:#fff;color:#4433bc;border-radius:10px;padding:8px 11px;font:800 .72rem/1 Inter,Segoe UI,Arial,sans-serif;box-shadow:0 6px 20px rgba(61,49,139,.15);cursor:pointer}.hwc-admin-menu-backdrop{display:none;position:fixed;inset:0;z-index:2147482999;border:0;background:rgba(22,18,51,.32)}
.hwc-membership-candidates{margin:14px 0;padding:17px;border:1px solid #dce8e1;border-radius:18px;background:linear-gradient(135deg,#f8fcfa,#fbfcff)}.hwc-membership-candidates-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin-bottom:12px}.hwc-membership-candidates h2{margin:0;color:#173c2c;font-size:1.04rem}.hwc-membership-candidates p{margin:4px 0 0;color:#6e7f76;font-size:.7rem;line-height:1.5}.hwc-membership-count{display:inline-flex;padding:5px 8px;border-radius:999px;background:#edf7f1;color:#176f45;font-size:.6rem;font-weight:900;white-space:nowrap}.hwc-membership-candidate-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.hwc-membership-candidate{padding:12px;border:1px solid #dde8e2;border-radius:13px;background:#fff}.hwc-membership-candidate b{display:block;color:#173c2c;font-size:.78rem}.hwc-membership-candidate small{display:block;margin-top:3px;color:#74847c;font-size:.62rem;word-break:break-word}.hwc-membership-candidate-meta{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.hwc-membership-pill{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef3f0;color:#52675e;font-size:.55rem;font-weight:850}.hwc-membership-pill.member{background:#e8f6ed;color:#176f45}.hwc-membership-candidate button,.hwc-membership-candidate a.hwc-assign-link{display:inline-flex;margin-top:9px;padding:7px 9px;border:0;border-radius:9px;background:#176f45;color:#fff;text-decoration:none;font-size:.62rem;font-weight:850;cursor:pointer}.hwc-membership-candidate a.hwc-assign-link{background:#eef6f1;color:#176f45;border:1px solid #d2e4d9}
.quick{position:sticky!important;top:0!important;z-index:80!important;display:flex!important;gap:7px!important;overflow-x:auto!important;overflow-y:hidden!important;margin:0 -4px 13px!important;padding:11px 4px!important;background:rgba(246,248,253,.96)!important;border-bottom:1px solid #dfe6e2!important;box-shadow:0 8px 18px rgba(39,61,50,.07)!important;backdrop-filter:blur(12px);scrollbar-width:thin}.quick a{flex:0 0 auto!important;padding:8px 11px!important;box-shadow:0 3px 9px rgba(39,61,50,.04)}.quick a:hover,.quick a:focus{background:#5744db!important;color:#fff!important;border-color:#5744db!important}.guide{scroll-margin-top:76px!important}
@media(min-width:1101px){body{padding-left:var(--hwc-admin-menu-w)!important;box-sizing:border-box}.os-layout{grid-template-columns:minmax(0,1fr)!important}.os-layout>.os-sidebar{display:none!important}}
@media(max-width:1100px){.hwc-admin-global-sidebar{transform:translateX(-103%);transition:transform .2s ease;width:min(86vw,285px)}.hwc-admin-menu-toggle{display:block}.hwc-admin-menu-open .hwc-admin-global-sidebar{transform:translateX(0)}.hwc-admin-menu-open .hwc-admin-menu-backdrop{display:block}.hwc-admin-menu-open{overflow:hidden}.os-layout>.os-sidebar{display:none!important}.os-layout{grid-template-columns:minmax(0,1fr)!important}body{padding-left:0!important}.hwc-membership-candidate-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.hwc-membership-candidate-grid{grid-template-columns:1fr}.hwc-membership-candidates-head{align-items:flex-start;flex-direction:column}}
@media print{.hwc-admin-global-sidebar,.hwc-admin-menu-toggle,.hwc-admin-menu-backdrop{display:none!important}body{padding-left:0!important}}
.hwc-form-fab{top:auto!important;bottom:22px!important;right:18px!important}.hwc-form-drawer{top:auto!important;bottom:76px!important;right:18px!important;max-height:calc(100vh - 104px)!important}
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

function business_professional_labels_script(): string
{
    return <<<'HTML'
<script id="hwc-professional-labels-js">
(()=>{const clean=value=>value
 .replace(/\bBUSINESS\s+OS\s*[•·]\s*(?:THROUGH\s+)?STEP\s*\d+[A-Z]?(?:\s*[→–-]\s*\d+[A-Z]?)?/gi,'Business Management Platform')
 .replace(/\b(?:THROUGH\s+)?STEP\s*\d+[A-Z]?(?:\s*[→–-]\s*\d+[A-Z]?)?\s*[•·:–-]?\s*/gi,'')
 .replace(/^\s*[•·:–-]\s*/,'').replace(/\s{2,}/g,' ').trim();
 const selectors='.os-kicker,.os-brand small,.auth-brand small,.portal-head .role-badge,.os-footer-note strong,.s10-card>h2,.os-card>h2,.pp-hero h1,.os-hero h1';
 document.querySelectorAll(selectors).forEach(node=>{if(node.children.length)return;const next=clean(node.textContent||'');if(next&&next!==node.textContent.trim())node.textContent=next});
 document.title=clean(document.title);
})();
</script>
HTML;
}

function admin_global_forms_markup(bool $insideBusiness, string $role): string
{
    $base=$insideBusiness?'':'business/';
    $csrf=$role==='admin'&&function_exists('security_step17_csrf')?security_step17_csrf():'';
    $forms=[
      'new_ums'=>['New UMS','data_entry_center.php?tab=new_ums&form_modal=1'],'volume_points'=>['Volume Points','data_entry_center.php?tab=vp&form_modal=1'],
      'orders'=>['Orders','data_entry_center.php?tab=order&form_modal=1'],'renewal'=>['Renewal','data_entry_center.php?tab=renewal&form_modal=1'],
      'income'=>['Income','data_entry_center.php?tab=income&form_modal=1'],'royalty'=>['Royalty','data_entry_center.php?tab=royalty&form_modal=1'],
      'customers'=>['Customer Management','customer_center.php?form_modal=1'],'memberships'=>['Club Membership','customer_membership_manager.php?form_modal=1'],
      'leads'=>['Leads & Enquiries','lead_center.php?form_modal=1'],'appointments'=>['Appointments','lead_appointments.php?form_modal=1'],
      'products'=>['Product Master','product_master_manager.php?form_modal=1'],'inventory'=>['Inventory Inward','inventory_inward.php?form_modal=1'],
      'purchases'=>['Purchase Order','purchase_orders.php?form_modal=1'],
      'custom_forms'=>['Custom Data Forms','custom_forms_center.php?form_modal=1']
    ];
    $hubTitle=$role==='admin'?'Data Entry &amp; Form Design':'Coach Data Entry';
    $html='<div class="hwc-form-hub" data-designer="'.$base.'form_designer.php" data-schema="'.$base.'dynamic_form_schema.php" data-discovery="'.$base.'dynamic_form_discovery.php" data-csrf="'.htmlspecialchars($csrf,ENT_QUOTES,'UTF-8').'"><button class="hwc-form-fab" type="button" aria-expanded="false"><b>＋</b><span>Forms</span></button><section class="hwc-form-drawer" hidden><header><div><small>SMART FORM CENTER</small><strong>'.$hubTitle.'</strong></div><button type="button" data-form-close aria-label="Close forms">×</button></header><div class="hwc-form-list">';
    foreach($forms as $key=>[$label,$url]){$html.='<article><strong>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</strong><div><button class="entry" type="button" data-entry-key="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" data-entry-url="'.$base.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" data-entry-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'">Entry</button>'.($role==='admin'&&$key!=='custom_forms'?'<button type="button" data-form-key="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" data-form-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'">Design</button>':'').'</div></article>';}
    return $html.'</div></section><div class="hwc-form-modal" hidden><button class="hwc-form-modal-bg" type="button" aria-label="Close form dialog"></button><section><header><strong data-form-modal-title>Smart Form</strong><button type="button" data-form-modal-close>×</button></header><iframe title="Smart Form"></iframe></section></div></div>';
}

function admin_global_forms_styles(): string
{
    return <<<'HTML'
<style id="hwc-global-form-hub-css">
.hwc-form-hub{font-family:Inter,Segoe UI,Arial,sans-serif}.hwc-form-fab{position:fixed;z-index:2147483010;right:18px;top:82px;display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #cfc8fa;border-radius:14px;background:linear-gradient(135deg,#5c49df,#4934c3);color:#fff;box-shadow:0 12px 30px rgba(77,57,190,.25);font-weight:900;cursor:pointer}.hwc-form-fab b{font-size:1rem}.hwc-form-drawer{position:fixed;z-index:2147483011;right:18px;top:132px;width:min(390px,calc(100vw - 28px));max-height:calc(100vh - 152px);overflow:auto;padding:12px;border:1px solid #dedbed;border-radius:18px;background:rgba(255,255,255,.98);box-shadow:0 22px 56px rgba(36,28,87,.2)}.hwc-form-drawer[hidden],.hwc-form-modal[hidden]{display:none!important}.hwc-form-drawer header,.hwc-form-modal section>header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 4px 11px}.hwc-form-drawer header small,.hwc-form-drawer header strong{display:block}.hwc-form-drawer header small{color:#7567d6;font-size:.55rem;font-weight:900;letter-spacing:.1em}.hwc-form-drawer header strong{color:#263b31;font-size:.9rem;margin-top:3px}.hwc-form-drawer header button,.hwc-form-modal header button{width:30px;height:30px;border:1px solid #dedbea;border-radius:9px;background:#fff;color:#5547b9;font-size:1rem;cursor:pointer}.hwc-form-list{display:grid;gap:7px}.hwc-form-list article{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;padding:9px;border:1px solid #e1e5e2;border-radius:11px;background:linear-gradient(145deg,#fbfdfc,#f4f3ff)}.hwc-form-list article>strong{color:#40534a;font-size:.68rem}.hwc-form-list article>div{display:flex;gap:5px}.hwc-form-list article button{min-height:31px;padding:6px 8px;border:1px solid #d7d2f4;border-radius:8px;background:#fff;color:#5343bd;font-size:.61rem;font-weight:850;cursor:pointer}.hwc-form-list article button.entry{border-color:#cbe3d4;background:#eaf8ef;color:#176f45}.hwc-form-list article button:hover{filter:brightness(.97)}.hwc-form-modal{position:fixed;inset:0;z-index:2147483020;display:grid;place-items:center;padding:18px}.hwc-form-modal-bg{position:absolute;inset:0;border:0;background:rgba(27,23,52,.42);backdrop-filter:blur(4px)}.hwc-form-modal>section{position:relative;width:min(1180px,97vw);height:min(820px,94vh);display:grid;grid-template-rows:auto 1fr;overflow:hidden;border:1px solid #dcd9ed;border-radius:20px;background:#fff;box-shadow:0 30px 90px rgba(28,22,68,.3)}.hwc-form-modal iframe{width:100%;height:100%;border:0;background:#f7f8fd}@media(max-width:680px){.hwc-form-fab{top:auto;bottom:18px;right:12px}.hwc-form-drawer{top:auto;bottom:70px;right:12px;max-height:70vh}.hwc-form-list article{grid-template-columns:1fr}.hwc-form-modal{padding:5px}.hwc-form-modal>section{width:100%;height:96vh;border-radius:15px}}@media print{.hwc-form-hub{display:none!important}}
</style>
HTML;
}

function admin_global_forms_script(): string
{
    return <<<'HTML'
<script id="hwc-global-form-hub-js">
(()=>{const hub=document.querySelector('.hwc-form-hub');if(!hub)return;const fab=hub.querySelector('.hwc-form-fab'),drawer=hub.querySelector('.hwc-form-drawer'),modal=hub.querySelector('.hwc-form-modal'),frame=modal.querySelector('iframe'),title=modal.querySelector('[data-form-modal-title]');let entryKey='';const closeDrawer=()=>{drawer.hidden=true;fab.setAttribute('aria-expanded','false')};const closeModal=()=>{modal.hidden=true;frame.src='about:blank';entryKey=''};const openModal=(src,label,key='')=>{entryKey=key;title.textContent=label;frame.title=label;frame.src=src;modal.hidden=false;closeDrawer()};fab.addEventListener('click',()=>{drawer.hidden=!drawer.hidden;fab.setAttribute('aria-expanded',drawer.hidden?'false':'true')});hub.querySelector('[data-form-close]').addEventListener('click',closeDrawer);hub.querySelector('[data-form-modal-close]').addEventListener('click',closeModal);hub.querySelector('.hwc-form-modal-bg').addEventListener('click',closeModal);hub.querySelectorAll('[data-entry-url]').forEach(b=>b.addEventListener('click',()=>openModal(b.dataset.entryUrl,(b.dataset.entryLabel||'Form')+' • Data Entry',b.dataset.entryKey||'')));hub.querySelectorAll('[data-form-key]').forEach(b=>b.addEventListener('click',()=>openModal(hub.dataset.designer+'?form='+encodeURIComponent(b.dataset.formKey),(b.dataset.formLabel||'Form')+' • Designer')));frame.addEventListener('load',()=>{if(!entryKey||!hub.dataset.csrf||frame.src==='about:blank')return;try{const doc=frame.contentDocument;if(!doc)return;const fields=[...doc.querySelectorAll('form select[name]')].filter(select=>select.options.length>1&&select.options.length<=100).map(select=>{const labelNode=select.closest('label')?.querySelector(':scope > span, :scope > small')||select.closest('.de-field')?.querySelector('label')||doc.querySelector('label[for="'+CSS.escape(select.id||'')+'"]');return{name:select.name,label:(labelNode?.textContent||select.name).trim(),options:[...select.options].filter(o=>o.value!=='').map(o=>({value:o.value,label:o.textContent.trim()}))}}).filter(field=>field.options.length);if(!fields.length)return;fetch(hub.dataset.discovery,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:hub.dataset.csrf,form_key:entryKey,fields})}).catch(()=>{})}catch(e){}});document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(!modal.hidden)closeModal();else closeDrawer()}});
const page=location.pathname.split('/').pop()||'';fetch(hub.dataset.schema+'?page='+encodeURIComponent(page),{credentials:'same-origin',cache:'no-store'}).then(r=>r.ok?r.json():null).then(data=>{if(!data?.ok)return;(data.forms||[]).forEach(form=>{const byId={};(form.fields||[]).forEach(f=>{byId[String(f.id)]=f});(form.fields||[]).forEach(field=>{const control=document.querySelector('[name="'+CSS.escape(field.field_key)+'"]');if(!control)return;control.dataset.dynamicField=field.id;if(control.tagName==='SELECT'&&field.options?.length){const old=control.value,blank=control.querySelector('option[value=""]')?.textContent||'Choose option';control.replaceChildren(new Option(blank,''));field.options.forEach(o=>control.add(new Option(o.option_label,o.option_value)));if([...control.options].some(o=>o.value===old))control.value=old}const parent=field.parent_field_id?byId[String(field.parent_field_id)]:null;if(parent){const parentControl=document.querySelector('[name="'+CSS.escape(parent.field_key)+'"]');if(parentControl&&control.tagName==='SELECT'){const all=field.options||[];const refresh=()=>{const chosen=parentControl.value,old=control.value,blank=control.options[0]?.textContent||'Choose option';control.replaceChildren(new Option(blank,''));all.filter(o=>!o.parent_option_value||o.parent_option_value===chosen).forEach(o=>control.add(new Option(o.option_label,o.option_value)));if([...control.options].some(o=>o.value===old))control.value=old};parentControl.addEventListener('change',refresh);refresh()}}})})}).catch(()=>{})})();
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
    if (defined('HWC_SKIP_GLOBAL_SHELL') && HWC_SKIP_GLOBAL_SHELL) return $html;
    if ((string)($_GET['form_modal'] ?? '') === '1') return $html;
    if ($html === '' || stripos($html, '<html') === false || stripos($html, '<body') === false) return $html;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $role=(string)($_SESSION['hwc_role'] ?? '');
    if (!in_array($role,['admin','coach'],true) || (int)($_SESSION['hwc_user_id'] ?? 0) <= 0) return $html;

    $path = str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    $script = basename($path);
    $insideBusiness = str_contains($path, '/business/');
    $allowedRoot = ['feature_hub.php','coach_portal.php','account.php','security_alerts.php','trusted_devices.php','trusted_device_diagnostics.php','mfa_settings.php','change_password.php'];
    if (!$insideBusiness && !in_array($script, $allowedRoot, true)) return $html;
    if (str_contains($path, '/shop/')) return $html;

    if (str_contains($html, 'class="hwc-form-hub"')) return $html;

    $styles=admin_global_forms_styles().($role==='admin'?admin_global_menu_styles():'');
    $styles.='<style id="hwc-form-bottom-position">.hwc-form-fab{top:auto!important;bottom:22px!important;right:18px!important}.hwc-form-drawer{top:auto!important;bottom:76px!important;right:18px!important;max-height:calc(100vh - 104px)!important}@media(max-width:680px){.hwc-form-fab{bottom:16px!important;right:12px!important}.hwc-form-drawer{bottom:68px!important;right:12px!important;max-height:72vh!important}}</style>';
    if($script==='business_help_center.php')$styles.='<style id="hwc-help-sticky-nav">.quick{position:sticky!important;top:0!important;z-index:80!important;overflow-x:auto!important;overflow-y:hidden!important;margin:0 -4px 13px!important;padding:11px 4px!important;background:rgba(246,248,253,.96)!important;border-bottom:1px solid #dfe6e2!important;box-shadow:0 8px 18px rgba(39,61,50,.07)!important;backdrop-filter:blur(12px)}.quick a{flex:0 0 auto!important;padding:8px 11px!important}.quick a:hover,.quick a:focus{background:#5744db!important;color:#fff!important;border-color:#5744db!important}.guide{scroll-margin-top:76px!important}</style>';
    $html = preg_replace('/<\/head>/i', $styles.'</head>', $html, 1) ?? $html;
    $markup=($role==='admin'?admin_global_menu_markup($insideBusiness,$script):'').admin_global_forms_markup($insideBusiness,$role);
    $html = preg_replace('/<body([^>]*)>/i', '<body$1>'.$markup, $html, 1) ?? $html;

    if ($script === 'customer_membership_manager.php' && str_contains($html, '<section class="cm-grid">')) {
        $panel = admin_global_menu_membership_candidates_panel();
        if ($panel !== '') $html = preg_replace('/<section class="cm-grid">/', $panel.'<section class="cm-grid">', $html, 1) ?? $html;
    }

    $scripts=($role==='admin'?admin_global_menu_script():'').admin_global_forms_script().business_professional_labels_script();
    $html = preg_replace('/<\/body>/i', $scripts.'</body>', $html, 1) ?? $html;
    return $html;
}
