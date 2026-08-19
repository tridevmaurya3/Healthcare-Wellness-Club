<?php
declare(strict_types=1);
/* STEP 24 builds on the completed STEP 23 role-aware dashboard. */
ob_start();
require __DIR__ . '/dashboard_step23.php';
$html = (string)ob_get_clean();
$replacements = [
    'Business OS • THROUGH STEP 23' => 'Business OS • THROUGH STEP 24',
    'BUSINESS OS • STEP 10 → 23' => 'BUSINESS OS • STEP 10 → 24',
    'The public product portal now hands verified order requests into the Business OS.' => 'The public product portal is now PWA-ready, mobile-optimized and discovery-ready.',
    'Visitors browse current MRP, build a cart and submit a traceable request. Staff review, customer linking, internal quote creation, stock allocation, payment and accounting remain controlled workflows.' => 'Visitors get a faster responsive storefront, installable-app plumbing, safe offline fallback, stronger keyboard/screen-reader support and SEO metadata. Checkout, tracking, pricing and Business OS controls remain server-governed.',
    'PUBLIC STOREFRONT FOUNDATION LIVE' => 'PWA + MOBILE FOUNDATION LIVE',
    '<a href="step23_audit.php"><i class="dot"></i>STEP 23 Audit</a>' => '<a href="step24_audit.php"><i class="dot"></i>STEP 24 Audit</a><a href="../shop/manifest.webmanifest" target="_blank"><i class="dot"></i>PWA Manifest</a><a href="../shop/sitemap.php" target="_blank"><i class="dot"></i>SEO Sitemap</a><a href="step23_audit.php"><i class="dot"></i>STEP 23 Audit</a>',
    '<strong>STEP 23 boundary:</strong> public checkout creates an order request, not a paid/final sale. Server-side MRP/VP recomputation and controlled internal handoff prevent client-side price manipulation or accounting bypass.' => '<strong>STEP 24 boundary:</strong> PWA, mobile, accessibility, performance and SEO improvements do not weaken STEP 23. Checkout remains an order request; sensitive checkout/tracking pages stay network-only and final business actions remain server-controlled.'
];
echo str_replace(array_keys($replacements), array_values($replacements), $html);
