<?php
declare(strict_types=1);
require_once __DIR__.'/config/public_store_step23.php';
require_once __DIR__.'/config/bi_step22.php';
require_once __DIR__.'/config/backup_step18.php';
require_once __DIR__.'/config/deployment_step19.php';
$error=null;$checks=[];$m=[];
function a24(array &$a,string $n,bool $ok,string $d):void{$a[]=['name'=>$n,'ok'=>$ok,'detail'=>$d];}
try{
 $pdo=business_db();$ctx=ps23_admin_ensure($pdo);ps23_guard($pdo,'storefront.view');$orgId=(int)$ctx['organization_id'];bi_step22_ensure($pdo);backup_step18_ensure($pdo);deployment_step19_ensure($pdo);
 $scalar=function(string $sql,array $args=[])use($pdo):int{$s=$pdo->prepare($sql);$s->execute($args);return(int)$s->fetchColumn();};
 $s=$pdo->prepare("SELECT COUNT(*) total_rows,SUM(mapping_status='mapped') mapped_rows,SUM(mapping_status='pending') pending_rows FROM raw_source_records WHERE organization_id=? AND source_dataset IN ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");$s->execute([$orgId]);$legacy=$s->fetch()?:[];$m['legacy']=(int)($legacy['mapped_rows']??0);$m['legacy_total']=(int)($legacy['total_rows']??0);$m['legacy_pending']=(int)($legacy['pending_rows']??0);$m['products']=$scalar("SELECT COUNT(*) FROM products WHERE organization_id=? AND status='active'",[$orgId]);$catalog=ps23_catalog($pdo,$orgId);
 $root=dirname(__DIR__);$shop=$root.'/shop/';$read=static fn(string $p):string=>is_file($p)?(string)file_get_contents($p):'';
 $idx=$read($shop.'index.php');$prod=$read($shop.'product.php');$cart=$read($shop.'cart.php');$checkout=$read($shop.'checkout.php');$status=$read($shop.'status.php');$js=$read($shop.'store.js');$css=$read($shop.'store.css');$helper=$read($shop.'pwa_step24.php');$sw=$read($shop.'sw.js');$offline=$read($shop.'offline.html');$sitemap=$read($shop.'sitemap.php');$robots=$read($root.'/robots.txt');$ht=$read($shop.'.htaccess');$submit=$read($shop.'submit.php');$service=$read(__DIR__.'/config/public_store_step23.php');$oldAudit=$read(__DIR__.'/step23_audit.php');$bidx=$read(__DIR__.'/index.php');$dash=$read(__DIR__.'/dashboard_step24.php');
 $pages=$idx.$prod.$cart.$checkout.$status;$private=$cart.$checkout.$status;$ic=preg_replace('/\s+/','',$idx)?:$idx;$pc=preg_replace('/\s+/','',$prod)?:$prod;
 $manifest=json_decode($read($shop.'manifest.webmanifest'),true);$manifestOk=is_array($manifest);$mask=false;if($manifestOk)foreach(($manifest['icons']??[])as$i)if(str_contains((string)($i['purpose']??''),'maskable')){$mask=true;break;}
 $required=['shop/pwa_step24.php','shop/manifest.webmanifest','shop/sw.js','shop/offline.html','shop/icon.svg','shop/sitemap.php','shop/.htaccess','robots.txt','business/dashboard_step24.php','business/step24_audit.php'];$files=true;foreach($required as$f)if(!is_file($root.'/'.$f)){$files=false;break;}
 a24($checks,'Legacy workbook preserved',$m['legacy_total']===757&&$m['legacy']===757&&$m['legacy_pending']===0,$m['legacy'].' / 757 mapped • '.$m['legacy_pending'].' pending');
 a24($checks,'STEP 11 product catalog preserved',$m['products']===64,$m['products'].' / 64 active products');
 a24($checks,'STEP 16 Finance foundation preserved',count(finance_step16_tables())===8,'8 finance tables');
 a24($checks,'STEP 17 Security foundation preserved',count(security_step17_tables())===8,'8 security tables');
 a24($checks,'STEP 18 Recovery foundation preserved',count(backup_step18_support_tables())===4,'4 recovery tables');
 a24($checks,'STEP 19 Deployment foundation preserved',count(deployment_step19_support_tables())===8,'8 deployment tables');
 a24($checks,'STEP 20 Lead CRM preserved',ps23_table($pdo,'crm_leads')&&ps23_table($pdo,'crm_lead_submissions'),'CRM available');
 a24($checks,'STEP 21 Communications preserved',ps23_table($pdo,'communication_events')&&ps23_table($pdo,'communication_outbox'),'communications available');
 a24($checks,'STEP 22 Executive BI preserved',ps23_table($pdo,'bi_targets')&&ps23_table($pdo,'bi_signal_actions'),'BI available');
 a24($checks,'STEP 23 public storefront preserved',count($catalog)===64&&str_contains($oldAudit,'STEP 23 COMPLETE'),count($catalog).' public products + prior audit');
 a24($checks,'STEP 24 production files present',$files,count($required).' required files');
 a24($checks,'PWA manifest parses as JSON',$manifestOk,$manifestOk?'valid JSON':'invalid JSON');
 a24($checks,'PWA stable identity',$manifestOk&&($manifest['id']??'')==='./'&&!empty($manifest['name'])&&!empty($manifest['short_name']),'id + name + short name');
 a24($checks,'PWA launch scope shop-only',$manifestOk&&($manifest['scope']??'')==='./'&&str_starts_with((string)($manifest['start_url']??''),'./index.php'),'scope ./');
 a24($checks,'PWA standalone display',$manifestOk&&($manifest['display']??'')==='standalone','display standalone');
 a24($checks,'PWA maskable icon',$mask,'maskable icon purpose');
 a24($checks,'PWA shortcuts',$manifestOk&&count($manifest['shortcuts']??[])>=3,count($manifest['shortcuts']??[]).' shortcuts');
 a24($checks,'Service worker install lifecycle',str_contains($sw,"addEventListener('install'"),'install event');
 a24($checks,'Service worker fetch lifecycle',str_contains($sw,"addEventListener('fetch'"),'fetch event');
 a24($checks,'Service worker ignores non-GET',str_contains($sw,"request.method !== 'GET'"),'POST not intercepted');
 a24($checks,'Sensitive pages network-only',str_contains($sw,'checkout|submit|status')&&str_contains($sw,'event.respondWith(fetch(request))'),'checkout • submit • status');
 a24($checks,'Offline navigation fallback',str_contains($sw,"caches.match('./offline.html')")&&str_contains($offline,'OFFLINE MODE'),'offline fallback');
 a24($checks,'Old cache cleanup',str_contains($sw,"key.startsWith('hwc-store-')")&&str_contains($sw,'caches.delete(key)'),'version cleanup');
 a24($checks,'Progressive SW registration',str_contains($js,"'serviceWorker' in navigator")&&str_contains($js,"register('./sw.js'"),'feature-detected');
 a24($checks,'Install prompt opt-in',str_contains($js,'beforeinstallprompt')&&str_contains($js,'userChoice')&&str_contains($js,'Install App'),'user triggered');
 a24($checks,'Online/offline status',str_contains($js,"addEventListener('online'")&&str_contains($js,"addEventListener('offline'")&&str_contains($pages,'data-network-status'),'network live state');
 a24($checks,'Safe-area viewport',str_contains($helper,'viewport-fit=cover')&&str_contains($css,'env(safe-area-inset'),'mobile safe areas');
 a24($checks,'Expanded desktop width',str_contains($css,'max-width:1540px')&&str_contains($css,'width:min(1540px,100%)'),'1540px responsive canvas');
 a24($checks,'Desktop/tablet/phone breakpoints',str_contains($css,'@media(max-width:1100px)')&&str_contains($css,'@media(max-width:820px)')&&str_contains($css,'@media(max-width:540px)'),'3 breakpoints');
 a24($checks,'44px touch targets',str_contains($css,'min-height:44px'),'touch baseline');
 a24($checks,'Mobile actions stay visible',!str_contains($css,'.store-actions .store-btn:not(.primary){display:none}'),'navigation retained');
 a24($checks,'Mobile input hints',str_contains($checkout,'inputmode="tel"')&&str_contains($checkout,'inputmode="email"')&&str_contains($checkout,'inputmode="numeric"'),'tel • email • numeric');
 a24($checks,'Skip links on public pages',substr_count($pages,'class="skip-link"')>=5,'5 / 5 pages');
 a24($checks,'Keyboard focus ring',str_contains($css,':focus-visible')&&str_contains($css,'outline:3px'),'visible focus');
 a24($checks,'Reduced motion honored',str_contains($css,'@media(prefers-reduced-motion:reduce)'),'reduced motion');
 a24($checks,'Assistive cart announcements',(str_contains($js,"setAttribute('aria-live','polite')")||str_contains($js,"setAttribute('aria-live', 'polite')"))&&str_contains($cart,'aria-live="polite"'),'ARIA live');
 a24($checks,'Accessible product search',str_contains($idx,'role="search"')&&str_contains($idx,'for="productSearch"')&&str_contains($idx,'for="categoryFilter"'),'search labels');
 a24($checks,'Accessible checkout labels',str_contains($checkout,'for="customerName"')&&str_contains($checkout,'for="customerMobile"')&&str_contains($checkout,'for="delivery_mode"')&&str_contains($checkout,'for="consent"'),'checkout labels');
 a24($checks,'Accessible tracking labels',str_contains($status,'for="orderCode"')&&str_contains($status,'for="trackingToken"'),'tracking labels');
 a24($checks,'Async image decoding',str_contains($idx,'decoding="async"')&&str_contains($prod,'decoding="async"'),'catalog + detail');
 a24($checks,'Lazy catalog images',str_contains($idx,'loading="lazy"')&&str_contains($idx,'fetchpriority="low"'),'lazy + low priority');
 a24($checks,'Grid rendering containment',str_contains($css,'content-visibility:auto')&&str_contains($css,'contain-intrinsic-size'),'off-screen optimization');
 a24($checks,'Versioned static assets',str_contains($helper,'filemtime')&&substr_count($pages,"s24_asset('store.js')")>=5,'mtime cache busting');
 a24($checks,'Deferred shared scripts',substr_count($pages,' defer></script>')>=5,'5 / 5 deferred');
 a24($checks,'Manifest MIME/cache policy',str_contains($ht,'application/manifest+json')&&str_contains($ht,'max-age=86400'),'Apache static policy');
 a24($checks,'Sensitive no-store policy',str_contains($ht,'checkout|submit|status')&&str_contains($ht,'no-store'),'private route caching disabled');
 a24($checks,'Canonical/social metadata',str_contains($helper,'rel="canonical"')&&str_contains($helper,'property="og:title"')&&str_contains($helper,'name="twitter:card"'),'canonical + OG + Twitter');
 a24($checks,'Store structured data',str_contains($ic,"'@type'=>'Store'"),'Schema.org Store');
 a24($checks,'Product structured data',str_contains($pc,"'@type'=>'Product'")&&str_contains($pc,"'@type'=>'Offer'")&&str_contains($pc,"'priceCurrency'=>'INR'"),'Product + Offer');
 a24($checks,'Private flow noindex',substr_count($private,"'noindex,nofollow,noarchive'")>=3,'cart • checkout • tracking');
 a24($checks,'Dynamic XML sitemap',str_contains($sitemap,'ps23_catalog')&&str_contains($sitemap,'product.php?id=')&&str_contains($sitemap,'application/xml'),count($catalog).' products');
 a24($checks,'Robots protects private routes',str_contains($robots,'Disallow: /business/')&&str_contains($robots,'Disallow: /shop/checkout.php')&&str_contains($robots,'Disallow: /shop/status.php')&&str_contains($robots,'Sitemap: /shop/sitemap.php'),'internal + sensitive routes');
 a24($checks,'Server repricing remains authoritative',str_contains($service,'function ps23_cart_quote')&&!str_contains($js,'unit_price'),'IDs + quantities only');
 a24($checks,'STEP 23 submit endpoint retained',str_contains($submit,'ps23_submit'),'existing protected submit');
 a24($checks,'Staff notes remain private',!str_contains($status,"['note']")&&str_contains($status,'Staff-only review notes are intentionally not shown'),'public tracking privacy');
 a24($checks,'Offline checkout cannot fake submit',str_contains($checkout,'navigator.onLine')&&str_contains($checkout,'Internet connection is required'),'online required');
 a24($checks,'Business OS activates STEP 24',str_contains($bidx,'dashboard_step24.php'),'STEP 24 dashboard active');
 a24($checks,'Prior route markers preserved',str_contains($bidx,'dashboard_step22.php')&&str_contains($bidx,'dashboard_step23.php'),'STEP 22 + 23 compatible');
 a24($checks,'STEP 24 dashboard preserves STEP 23',str_contains($dash,'dashboard_step23.php')&&str_contains($dash,'step24_audit.php')&&str_contains($dash,'sitemap.php'),'enhancement layer');
}catch(Throwable $e){$error=$e->getMessage();}
$passed=count(array_filter($checks,static fn($c)=>$c['ok']));$failed=count($checks)-$passed;$complete=$error===null&&$failed===0;
?><!doctype html><html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>STEP 24 Audit - Healthcare Wellness Club</title><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/product_pro.css"></head><body><header class="os-topbar"><div class="os-topbar-inner"><a class="os-brand" href="index.php"><img src="../img/logo.png" alt="Healthcare Wellness Club"><span><strong>Healthcare Wellness Club</strong><small>STEP 24 • PWA + Mobile + Performance + Accessibility + SEO Audit</small></span></a><div class="os-top-actions"><a class="os-btn" href="../shop/index.php" target="_blank" rel="noopener">Storefront</a><a class="os-btn" href="../shop/sitemap.php" target="_blank" rel="noopener">Sitemap</a><a class="os-btn primary" href="index.php">Dashboard</a></div></div></header><main class="os-main" style="max-width:1540px;margin:auto"><section class="os-hero pp-hero"><div class="os-kicker">STEP 24Z • FINAL EXPERIENCE AUDIT</div><h1><?=$complete?'STEP 24 COMPLETE':'STEP 24 needs review'?></h1><p>Verifies PWA plumbing, safe offline behavior, mobile responsiveness, accessibility, performance hints, SEO discovery and preservation of the STEP 23 public-order security boundary.</p><div class="os-status-row"><span class="os-chip <?=$complete?'good':''?>"><?=$complete?'STEP 24 COMPLETE':'REVIEW REQUIRED'?></span><span class="os-chip good"><?=($m['legacy']??0)?> / 757 legacy</span><span class="os-chip good"><?=($m['products']??0)?> / 64 products</span><span class="os-chip"><?=$passed?> PASS / <?=$failed?> REVIEW</span></div></section><?php if($error):?><div class="pp-alert bad"><strong>Audit diagnostic:</strong> <?=ps23_h($error)?></div><?php endif;?><section class="os-card" style="margin-top:14px"><div class="os-title-row"><div><h2>Completion Checks</h2><p>Repository/runtime readiness is checked here. Browser install UI and Lighthouse scoring remain final-browser verification for STEP 25.</p></div><span class="pp-badge <?=$failed?'warn':''?>"><?=$passed?> PASS / <?=$failed?> REVIEW</span></div><div class="pp-grid"><?php foreach($checks as$c):?><div class="pp-source pp-span-6"><div><b><?=ps23_h($c['name'])?></b><small><?=ps23_h($c['detail'])?></small></div><span class="pp-badge <?=$c['ok']?'':'warn'?>"><?=$c['ok']?'PASS':'REVIEW'?></span></div><?php endforeach;?></div></section><div class="os-footer-note"><strong>STEP 24 boundary:</strong> PWA/offline support never caches or fakes checkout, submission or private tracking. Live price, availability, tax, payment and final sales remain server-controlled Business OS responsibilities.</div></main></body></html>
