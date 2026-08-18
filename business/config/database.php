<?php
declare(strict_types=1);

/**
 * Healthcare Wellness Club Business OS database connection.
 *
 * Local XAMPP defaults:
 *   host: 127.0.0.1
 *   database: healthcare_wellness_club
 *   user: root
 *   password: empty
 *
 * Online/cloud deployments use the same application schema. Production credentials
 * must be supplied through DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS environment
 * variables rather than hard-coding server secrets in this repository.
 */

function business_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'healthcare_wellness_club';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function business_table_exists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute([$tableName]);
    return (int)$statement->fetchColumn() > 0;
}

function business_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$tableName, $columnName]);
    return (int)$statement->fetchColumn() > 0;
}

function business_safe_count(PDO $pdo, string $tableName): int
{
    if (!business_table_exists($pdo, $tableName)) {
        return 0;
    }

    $allowed = [
        'organizations',
        'clubs',
        'data_sources',
        'report_definitions',
        'calculation_rules',
        'members',
        'raw_source_records',
    ];

    if (!in_array($tableName, $allowed, true)) {
        return 0;
    }

    return (int)$pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
}

function business_db_status(): array
{
    try {
        $pdo = business_db();
        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $tableCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();

        $schemaVersion = 'foundation';
        if (business_table_exists($pdo, 'schema_meta')) {
            $statement = $pdo->prepare('SELECT meta_value FROM schema_meta WHERE meta_key = ? LIMIT 1');
            $statement->execute(['schema_version']);
            $value = $statement->fetchColumn();
            if ($value !== false) {
                $schemaVersion = (string)$value;
            }
        }

        return [
            'connected' => true,
            'database' => $databaseName,
            'table_count' => $tableCount,
            'schema_version' => $schemaVersion,
            'organization_count' => business_safe_count($pdo, 'organizations'),
            'club_count' => business_safe_count($pdo, 'clubs'),
            'source_count' => business_safe_count($pdo, 'data_sources'),
            'derived_report_count' => business_safe_count($pdo, 'report_definitions'),
            'calculation_rule_count' => business_safe_count($pdo, 'calculation_rules'),
            'member_count' => business_safe_count($pdo, 'members'),
            'raw_record_count' => business_safe_count($pdo, 'raw_source_records'),
            'message' => 'Database connection is ready.',
        ];
    } catch (Throwable $e) {
        return [
            'connected' => false,
            'database' => 'healthcare_wellness_club',
            'table_count' => 0,
            'schema_version' => 'pending',
            'organization_count' => 0,
            'club_count' => 0,
            'source_count' => 0,
            'derived_report_count' => 0,
            'calculation_rule_count' => 0,
            'member_count' => 0,
            'raw_record_count' => 0,
            'message' => 'Database setup is pending.',
        ];
    }
}
