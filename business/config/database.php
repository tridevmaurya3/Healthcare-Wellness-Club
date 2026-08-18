<?php
declare(strict_types=1);

/**
 * Healthcare Wellness Club Business OS database connection.
 *
 * Local XAMPP defaults:
 *   host: localhost
 *   database: healthcare_wellness_club
 *   user: root
 *   password: empty
 *
 * Production servers should set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS
 * as environment variables instead of editing this file.
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

function business_db_status(): array
{
    try {
        $pdo = business_db();
        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $tableCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchColumn();

        return [
            'connected' => true,
            'database' => $databaseName,
            'table_count' => $tableCount,
            'message' => 'Database connection is ready.',
        ];
    } catch (Throwable $e) {
        return [
            'connected' => false,
            'database' => 'healthcare_wellness_club',
            'table_count' => 0,
            'message' => 'Database setup is pending.',
        ];
    }
}
