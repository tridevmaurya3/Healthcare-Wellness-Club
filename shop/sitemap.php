<?php
declare(strict_types=1);
require_once __DIR__ . '/../business/config/public_store_step23.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

function s24_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
$scheme = $https ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/shop/sitemap.php'));
$shopPath = rtrim(str_replace('\\', '/', dirname($script)), '/');
$base = $scheme . '://' . $host . ($shopPath === '' || $shopPath === '.' ? '/shop' : $shopPath);

$rows = [];
try {
    $pdo = ps23_db();
    ps23_ensure($pdo);
    $ctx = ps23_context($pdo);
    $rows = ps23_catalog($pdo, (int)$ctx['organization_id']);
} catch (Throwable $e) {
    $rows = [];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo "  <url><loc>" . s24_xml($base . '/index.php') . "</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) continue;
    $lastmod = substr((string)($row['effective_from'] ?? ''), 0, 10);
    echo "  <url><loc>" . s24_xml($base . '/product.php?id=' . $id) . "</loc>";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod)) echo '<lastmod>' . s24_xml($lastmod) . '</lastmod>';
    echo "<changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
}
echo "</urlset>\n";
