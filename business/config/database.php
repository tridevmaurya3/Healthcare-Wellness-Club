<?php
declare(strict_types=1);

require_once __DIR__ . '/report_runtime.php';
require_once __DIR__ . '/deployment_step19.php';
require_once __DIR__ . '/security_step17.php';

const BUSINESS_REVERSED_SOURCE_SHEET = 'Manual Entry • Reversed';

/**
 * PDO compatibility adapter used only by Correction Center.
 * Replaces its MariaDB-sensitive audit LIKE/ESCAPE read with an equivalent REGEXP.
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

        // Reversed manual members must not be offered as active correction/sponsor targets.
        $query = str_replace(
            'SELECT id,full_name,mobile,status FROM members WHERE organization_id=? ORDER BY full_name,id',
            "SELECT id,full_name,mobile,status FROM members WHERE organization_id=? AND COALESCE(source_sheet,'') <> '" . BUSINESS_REVERSED_SOURCE_SHEET . "' ORDER BY full_name,id",
            $query
        );

        return parent::prepare($query, $options);
    }
}

/**
 * Keeps reversed MANUAL facts out of normal operational workspaces without deleting them.
 * The raw source and normalized values remain available to Reversal/Audit Center.
 */
class BusinessReversalAwarePDO extends PDO
{
    private string $script;

    public function __construct(string $dsn, string $user, string $pass, array $options, string $script)
    {
        $this->script = $script;
        parent::__construct($dsn, $user, $pass, $options);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $rev = BUSINESS_REVERSED_SOURCE_SHEET;

        if ($this->script === 'operations_center.php') {
            if (str_contains($query, 'FROM orders o') && str_contains($query, 'WHERE o.organization_id=?')) {
                $query = str_replace('WHERE o.organization_id=?', "WHERE o.organization_id=? AND COALESCE(o.source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'FROM volume_point_entries v') && str_contains($query, 'WHERE v.organization_id=?')) {
                $query = str_replace('WHERE v.organization_id=?', "WHERE v.organization_id=? AND COALESCE(v.source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'FROM income_entries') && str_contains($query, 'WHERE organization_id=?')) {
                $query = str_replace('WHERE organization_id=?', "WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'FROM royalty_entries') && str_contains($query, 'WHERE organization_id=?')) {
                $query = str_replace('WHERE organization_id=?', "WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'SELECT id, full_name, mobile FROM members WHERE organization_id=?')) {
                $query = str_replace('FROM members WHERE organization_id=?', "FROM members WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
        }

        if ($this->script === 'members.php') {
            $query = str_replace(
                'WHERE o.organization_id=m.organization_id AND o.member_id=m.id',
                "WHERE o.organization_id=m.organization_id AND o.member_id=m.id AND COALESCE(o.source_sheet,'') <> '{$rev}'",
                $query
            );
            $query = str_replace(
                'WHERE n.organization_id=m.organization_id AND n.member_id=m.id',
                "WHERE n.organization_id=m.organization_id AND n.member_id=m.id AND COALESCE(n.source_sheet,'') <> '{$rev}'",
                $query
            );
            $query = str_replace(
                'WHERE v.organization_id=m.organization_id AND v.member_id=m.id',
                "WHERE v.organization_id=m.organization_id AND v.member_id=m.id AND COALESCE(v.source_sheet,'') <> '{$rev}'",
                $query
            );
            $query = str_replace(
                'WHERE u2.organization_id=m.organization_id AND u2.member_id=m.id',
                "WHERE u2.organization_id=m.organization_id AND u2.member_id=m.id AND COALESCE(u2.source_sheet,'') <> '{$rev}'",
                $query
            );
            if (str_contains($query, 'FROM members m') && str_contains($query, 'WHERE m.organization_id=?')) {
                $query = str_replace('WHERE m.organization_id=?', "WHERE m.organization_id=? AND COALESCE(m.source_sheet,'') <> '{$rev}'", $query);
            }
            foreach (['orders','renewals','volume_point_entries'] as $table) {
                if (str_contains($query, "FROM {$table} WHERE organization_id=? AND member_id=?")) {
                    $query = str_replace(
                        "FROM {$table} WHERE organization_id=? AND member_id=?",
                        "FROM {$table} WHERE organization_id=? AND member_id=? AND COALESCE(source_sheet,'') <> '{$rev}'",
                        $query
                    );
                }
            }
        }

        if ($this->script === 'member_profile.php') {
            if (str_contains($query, 'FROM members WHERE organization_id=?')) {
                $query = str_replace('FROM members WHERE organization_id=?', "FROM members WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'FROM members m') && str_contains($query, 'WHERE m.organization_id=?')) {
                $query = str_replace('WHERE m.organization_id=?', "WHERE m.organization_id=? AND COALESCE(m.source_sheet,'') <> '{$rev}'", $query);
            }
            foreach (['ums_records','volume_point_entries','orders','renewals'] as $table) {
                if (str_contains($query, "FROM {$table}") && str_contains($query, 'WHERE organization_id=? AND member_id=?')) {
                    $query = str_replace(
                        'WHERE organization_id=? AND member_id=?',
                        "WHERE organization_id=? AND member_id=? AND COALESCE(source_sheet,'') <> '{$rev}'",
                        $query
                    );
                }
            }
            if (str_contains($query, 'FROM volume_point_entries') && str_contains($query, 'member_id IS NULL')) {
                $query = str_replace('WHERE organization_id=? AND member_id IS NULL', "WHERE organization_id=? AND member_id IS NULL AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
            if (str_contains($query, 'FROM renewals') && str_contains($query, 'member_id IS NULL')) {
                $query = str_replace('WHERE organization_id=? AND member_id IS NULL', "WHERE organization_id=? AND member_id IS NULL AND COALESCE(source_sheet,'') <> '{$rev}'", $query);
            }
        }

        if ($this->script === 'sponsor_network.php') {
            if (str_contains($query, 'FROM members m') && str_contains($query, 'WHERE m.organization_id=?')) {
                $query = str_replace('WHERE m.organization_id=?', "WHERE m.organization_id=? AND COALESCE(m.source_sheet,'') <> '{$rev}'", $query);
            }
        }

        if ($this->script === 'data_entry_center.php') {
            $query = str_replace(
                'FROM members WHERE organization_id=? ORDER BY full_name,id',
                "FROM members WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}' ORDER BY full_name,id",
                $query
            );
        }

        if ($this->script === 'index.php') {
            $query = str_replace(
                'SELECT COUNT(*) FROM members WHERE organization_id=?',
                "SELECT COUNT(*) FROM members WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'",
                $query
            );
            $query = str_replace(
                'SELECT COUNT(*) FROM orders WHERE organization_id=?',
                "SELECT COUNT(*) FROM orders WHERE organization_id=? AND COALESCE(source_sheet,'') <> '{$rev}'",
                $query
            );
        }

        return parent::prepare($query, $options);
    }
}

/**
 * Healthcare Wellness Club Business OS database connection.
 * Local XAMPP defaults are used only when environment variables are absent.
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
    $reversalAware = [
        'index.php',
        'operations_center.php',
        'members.php',
        'member_profile.php',
        'sponsor_network.php',
        'data_entry_center.php',
    ];

    if (business_report_runtime_enabled()) {
        $pdo = new BusinessReportPDO($dsn, $user, $pass, $pdoOptions);
    } elseif ($currentScript === 'correction_center.php') {
        $pdo = new BusinessCorrectionPDO($dsn, $user, $pass, $pdoOptions);
    } elseif (in_array($currentScript, $reversalAware, true)) {
        $pdo = new BusinessReversalAwarePDO($dsn, $user, $pass, $pdoOptions, $currentScript);
    } else {
        $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
    }

    deployment_step19_preflight_request($pdo);
    security_step17_session_start();
    $mfaPublic=['login.php','setup_admin.php','logout.php','access_denied.php','maintenance.php','healthz.php','public_lead_submit.php'];
    if(!security_step17_is_cli()&&!empty($_SESSION['hwc_mfa_pending'])&&!in_array($currentScript,$mfaPublic,true)){
        header('Location: ../mfa_verify.php');
        exit;
    }
    security_step17_guard_request($pdo);
    deployment_step19_guard_request($pdo);
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
        'organizations','clubs','data_sources','report_definitions','calculation_rules','members','raw_source_records',
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
        $tableCount = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $schemaVersion = 'foundation';

        if (business_table_exists($pdo, 'schema_meta')) {
            $statement = $pdo->prepare('SELECT meta_value FROM schema_meta WHERE meta_key = ? LIMIT 1');
            $statement->execute(['schema_version']);
            $value = $statement->fetchColumn();
            if ($value !== false) $schemaVersion = (string)$value;
        }

        return [
            'connected'=>true,
            'database'=>$databaseName,
            'table_count'=>$tableCount,
            'schema_version'=>$schemaVersion,
            'organization_count'=>business_safe_count($pdo,'organizations'),
            'club_count'=>business_safe_count($pdo,'clubs'),
            'source_count'=>business_safe_count($pdo,'data_sources'),
            'derived_report_count'=>business_safe_count($pdo,'report_definitions'),
            'calculation_rule_count'=>business_safe_count($pdo,'calculation_rules'),
            'member_count'=>business_safe_count($pdo,'members'),
            'raw_record_count'=>business_safe_count($pdo,'raw_source_records'),
            'message'=>'Database connection is ready.',
        ];
    } catch (Throwable $e) {
        return [
            'connected'=>false,'database'=>'healthcare_wellness_club','table_count'=>0,'schema_version'=>'pending',
            'organization_count'=>0,'club_count'=>0,'source_count'=>0,'derived_report_count'=>0,'calculation_rule_count'=>0,
            'member_count'=>0,'raw_record_count'=>0,'message'=>'Database setup is pending.',
        ];
    }
}
