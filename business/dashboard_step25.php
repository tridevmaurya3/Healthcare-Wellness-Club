<?php
declare(strict_types=1);
/* STEP 25 is the final QA/UAT and production-launch layer over the completed STEP 24 dashboard. */
ob_start();
require __DIR__ . '/dashboard_step24.php';
$html=(string)ob_get_clean();
$replacements=[
    'Business OS • THROUGH STEP 24'=>'Business OS • THROUGH STEP 25',
    'BUSINESS OS • STEP 10 → 24'=>'BUSINESS OS • STEP 10 → 25',
    'The public product portal is now PWA-ready, mobile-optimized and discovery-ready.'=>'The Business OS is now at the final QA, UAT and production-launch gate.',
    'Visitors get a faster responsive storefront, installable-app plumbing, safe offline fallback, stronger keyboard/screen-reader support and SEO metadata. Checkout, tracking, pricing and Business OS controls remain server-governed.'=>'Automated regression checks, human UAT evidence, verified recovery and production health now converge into one controlled go-live path. No local test can silently mark the system deployed.',
    'PWA + MOBILE FOUNDATION LIVE'=>'FINAL QA + LAUNCH CONTROL LIVE',
    '<a href="step24_audit.php"><i class="dot"></i>STEP 24 Audit</a>'=>'<a href="final_launch_center.php"><i class="dot"></i>Final Launch Center</a><a href="step25_audit.php"><i class="dot"></i>STEP 25 Audit</a><a href="step24_audit.php"><i class="dot"></i>STEP 24 Audit</a>',
    '<strong>STEP 24 boundary:</strong> PWA, mobile, accessibility, performance and SEO improvements do not weaken STEP 23. Checkout remains an order request; sensitive checkout/tracking pages stay network-only and final business actions remain server-controlled.'=>'<strong>STEP 25 boundary:</strong> automated QA and UAT evidence do not equal a production deployment. A release is live only when the target production environment passes its gates and the authorized Deployment Releases workflow records it as deployed.'
];
echo str_replace(array_keys($replacements),array_values($replacements),$html);
