<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function product_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function product_foundation_tables(): array
{
    return [
        'product_markets',
        'product_categories',
        'products',
        'product_images',
        'product_market_listings',
        'product_price_versions',
        'product_discount_tiers',
        'product_tier_prices',
    ];
}

function product_foundation_ready(PDO $pdo): bool
{
    foreach (product_foundation_tables() as $table) {
        if (!business_table_exists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

function product_apply_foundation(PDO $pdo): void
{
    $migration = dirname(__DIR__, 2) . '/database/migrations/005_product_price_foundation.sql';
    if (!is_file($migration)) {
        throw new RuntimeException('STEP 11A product migration file is missing.');
    }

    $sql = file_get_contents($migration);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('STEP 11A product migration file is empty.');
    }

    // This migration is repository-controlled and intentionally contains no stored procedures.
    // Splitting at statement-ending semicolons keeps local XAMPP/MariaDB setup simple.
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    if (!product_foundation_ready($pdo)) {
        throw new RuntimeException('Product foundation migration finished but one or more required tables are still missing.');
    }
}

function product_ensure_foundation(PDO $pdo): void
{
    if (!product_foundation_ready($pdo)) {
        product_apply_foundation($pdo);
    }
}

function product_org_context(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id,organization_name,country_code,default_currency_code,timezone,locale
         FROM organizations WHERE organization_code='HWC-001' LIMIT 1"
    );
    $org = $stmt->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }

    return [
        'organization_id' => (int)$org['id'],
        'organization_name' => (string)$org['organization_name'],
        'country_code' => (string)$org['country_code'],
        'currency_code' => (string)$org['default_currency_code'],
        'timezone' => (string)$org['timezone'],
        'locale' => (string)$org['locale'],
    ];
}

function product_foundation_metrics(PDO $pdo, int $organizationId): array
{
    $metrics = [
        'markets' => 0,
        'categories' => 0,
        'products' => 0,
        'listings' => 0,
        'price_versions' => 0,
        'discount_tiers' => 0,
        'images' => 0,
    ];

    $queries = [
        'markets' => "SELECT COUNT(*) FROM product_markets WHERE organization_id=?",
        'categories' => "SELECT COUNT(*) FROM product_categories WHERE organization_id=?",
        'products' => "SELECT COUNT(*) FROM products WHERE organization_id=?",
        'listings' => "SELECT COUNT(*) FROM product_market_listings WHERE organization_id=?",
        'price_versions' => "SELECT COUNT(*) FROM product_price_versions WHERE organization_id=?",
        'discount_tiers' => "SELECT COUNT(*) FROM product_discount_tiers WHERE organization_id=?",
    ];

    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$organizationId]);
        $metrics[$key] = (int)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM product_images pi
         JOIN products p ON p.id=pi.product_id
         WHERE p.organization_id=?"
    );
    $stmt->execute([$organizationId]);
    $metrics['images'] = (int)$stmt->fetchColumn();

    return $metrics;
}
