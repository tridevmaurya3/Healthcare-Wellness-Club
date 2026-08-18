<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function step10_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function step10_trim(mixed $value): string
{
    return trim((string)$value);
}

function step10_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', step10_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function step10_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('STEP 10 payload could not be encoded.');
    }
    return $json;
}

function step10_json_array(mixed $value): array
{
    $raw = step10_trim($value);
    if ($raw === '') return [];
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function step10_org_context(PDO $pdo): array
{
    $org = $pdo->query("SELECT id,default_currency_code,timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    $organizationId = (int)$org['id'];

    $stmt = $pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $stmt->execute([$organizationId]);
    $clubId = (int)$stmt->fetchColumn();
    if ($clubId <= 0) throw new RuntimeException('Ghazipur club was not found.');

    $timezone = (string)($org['timezone'] ?: 'Asia/Kolkata');
    @date_default_timezone_set($timezone);

    return [
        'organization_id' => $organizationId,
        'club_id' => $clubId,
        'currency_code' => (string)($org['default_currency_code'] ?: 'INR'),
        'timezone' => $timezone,
    ];
}

function step10_ensure_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS business_followups (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      club_id BIGINT UNSIGNED NULL,
      member_id BIGINT UNSIGNED NULL,
      followup_type VARCHAR(60) NOT NULL DEFAULT 'general',
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      due_date DATE NOT NULL,
      due_time TIME NULL,
      priority VARCHAR(20) NOT NULL DEFAULT 'normal',
      status VARCHAR(20) NOT NULL DEFAULT 'open',
      source_entity_type VARCHAR(80) NULL,
      source_entity_id BIGINT UNSIGNED NULL,
      created_by_user_id BIGINT UNSIGNED NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_followup_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
      CONSTRAINT fk_followup_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
      CONSTRAINT fk_followup_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
      CONSTRAINT fk_followup_user FOREIGN KEY (created_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL,
      KEY idx_followup_due (organization_id, status, due_date),
      KEY idx_followup_member (organization_id, member_id, status),
      KEY idx_followup_source (organization_id, source_entity_type, source_entity_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS business_saved_views (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL,
      view_name VARCHAR(120) NOT NULL,
      view_type VARCHAR(60) NOT NULL,
      target_page VARCHAR(160) NOT NULL,
      query_string VARCHAR(1000) NULL,
      is_favorite TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_saved_view_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
      CONSTRAINT fk_saved_view_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
      KEY idx_saved_view_org_type (organization_id, view_type, is_favorite)
    ) ENGINE=InnoDB");
}

function step10_legacy_state(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) total_rows,
            SUM(mapping_status='mapped') mapped_rows,
            SUM(mapping_status='pending') pending_rows
        FROM raw_source_records
        WHERE organization_id=? AND source_dataset IN
        ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')");
    $stmt->execute([$organizationId]);
    $row = $stmt->fetch() ?: [];
    return [
        'total'=>(int)($row['total_rows'] ?? 0),
        'mapped'=>(int)($row['mapped_rows'] ?? 0),
        'pending'=>(int)($row['pending_rows'] ?? 0),
    ];
}

function step10_audit(PDO $pdo, int $organizationId, int $clubId, string $eventType, string $entityType, ?int $entityId, array $details): void
{
    $stmt = $pdo->prepare("INSERT INTO audit_logs
        (organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent)
        VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $organizationId,$clubId,$eventType,$entityType,$entityId,
        step10_json($details),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500),
    ]);
}

function step10_active_source_clause(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $rev = defined('BUSINESS_REVERSED_SOURCE_SHEET') ? BUSINESS_REVERSED_SOURCE_SHEET : 'Manual Entry • Reversed';
    return "COALESCE({$prefix}source_sheet,'') <> " . "'" . str_replace("'", "''", $rev) . "'";
}

function step10_money(mixed $value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function step10_num(mixed $value, int $decimals = 3): string
{
    $text = number_format((float)$value, $decimals, '.', ',');
    return rtrim(rtrim($text, '0'), '.');
}

function step10_date(?string $value, string $format = 'd M Y'): string
{
    if (!$value) return '—';
    try { return (new DateTimeImmutable($value))->format($format); }
    catch (Throwable) { return (string)$value; }
}
