<?php
declare(strict_types=1);

require_once __DIR__ . '/report_runtime.php';

/**
 * PDO compatibility adapter used only by Correction Center.
 *
 * The original audit-history LIKE ... ESCAPE expression is valid in intent but the
 * PHP/MariaDB backslash layers can turn its ESCAPE literal into an unterminated SQL
 * string on some XAMPP/MariaDB builds. Replace only that one read query with an
 * equivalent REGEXP expression; all correction writes and all other SQL stay intact.
 */
class BusinessCorrectionPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (
            str_contains($query, 'FROM audit_logs')
            && str_contains($query, 'event_type LIKE')
            && str_contains($query, '_corrected')
            && str_contains($query, 'LIMIT 80')
        ) {
            $query = "SELECT id,event_type,entity_type,entity_id,details_json,created_at
                      FROM audit_logs
                      WHERE organization_id=?
                        AND event_type REGEXP '^manual_.*_corrected$'
                      ORDER BY id DESC
                      LIMIT 80";
        }

        return parent::prepare($query, $options);
    }
}

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

    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

    // Only the six workbook-derived reports use the read-only runtime adapter.
    // Correction Center gets a tiny MariaDB compatibility adapter for its audit-history query.
    // All other CRUD/import/data-entry pages continue to use plain PDO and unchanged SQL.
    if (business_report_runtime_enabled()) {
        $pdo = new BusinessReportPDO($dsn, $user, $pass, $pdoOptions);
    } elseif ($currentScript === 'correction_center.php') {
        $pdo = new BusinessCorrectionPDO($dsn, $user, $pass, $pdoOptions);
    } else {
        $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
    }

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
