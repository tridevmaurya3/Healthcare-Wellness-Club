<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

function de_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function de_trim(mixed $value): string
{
    return trim((string)$value);
}

function de_json(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Entry payload could not be encoded.');
    }
    return $json;
}

function de_decimal(mixed $value, bool $allowBlank = false): ?float
{
    $raw = str_replace([',', '₹', ' '], '', de_trim($value));
    if ($raw === '') {
        if ($allowBlank) {
            return null;
        }
        throw new RuntimeException('A required numeric value is missing.');
    }
    if (!is_numeric($raw)) {
        throw new RuntimeException('A numeric field contains an invalid value.');
    }
    return (float)$raw;
}

function de_date(mixed $value): string
{
    $raw = de_trim($value);
    if ($raw === '') {
        throw new RuntimeException('Date is required.');
    }
    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Throwable) {
        throw new RuntimeException('Date is invalid.');
    }
}

function de_member(PDO $pdo, int $organizationId, int $memberId): array
{
    if ($memberId <= 0) {
        throw new RuntimeException('Select a member.');
    }
    $stmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? AND id=? LIMIT 1');
    $stmt->execute([$organizationId, $memberId]);
    $member = $stmt->fetch();
    if (!$member) {
        throw new RuntimeException('Selected member does not exist in this organization.');
    }
    return $member;
}

function de_external_id(string $module): string
{
    $safe = preg_replace('/[^a-z0-9_-]+/i', '-', $module) ?: 'entry';
    return 'manual-' . strtolower($safe) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}

function de_create_raw(
    PDO $pdo,
    int $organizationId,
    int $clubId,
    int $sourceId,
    string $dataset,
    string $externalId,
    array $payload
): int {
    $json = de_json($payload);
    $hash = hash('sha256', $json);
    $stmt = $pdo->prepare(
        "INSERT INTO raw_source_records
         (organization_id, club_id, data_source_id, import_batch_id, source_dataset,
          external_record_id, source_row, captured_at, record_hash, raw_json,
          mapping_status, mapped_entity_type, mapped_entity_id, error_message)
         VALUES (?, ?, ?, NULL, ?, ?, NULL, NOW(), ?, ?, 'pending', NULL, NULL, NULL)"
    );
    $stmt->execute([$organizationId, $clubId, $sourceId, $dataset, $externalId, $hash, $json]);
    return (int)$pdo->lastInsertId();
}

function de_map_raw(PDO $pdo, int $rawId, string $entityType, int $entityId): void
{
    $stmt = $pdo->prepare(
        "UPDATE raw_source_records
         SET mapping_status='mapped', mapped_entity_type=?, mapped_entity_id=?, error_message=NULL, updated_at=NOW()
         WHERE id=? AND mapping_status='pending'"
    );
    $stmt->execute([$entityType, $entityId, $rawId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Raw source trace changed unexpectedly while saving the entry.');
    }
}

function de_audit(PDO $pdo, int $organizationId, int $clubId, string $eventType, string $entityType, int $entityId, array $details): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs
         (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $organizationId,
        $clubId,
        $eventType,
        $entityType,
        $entityId,
        de_json($details),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

if (empty($_SESSION['business_entry_csrf'])) {
    $_SESSION['business_entry_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['business_entry_csrf'];

$modules = [
    'new_ums' => ['label'=>'New UMS','short'=>'UMS','desc'=>'Create a new member and UMS lifecycle record together.'],
    'vp' => ['label'=>'Volume Points','short'=>'VP','desc'=>'Add a verified member-linked Volume Point fact.'],
    'order' => ['label'=>'Order','short'=>'ORD','desc'=>'Add an order with value, profit and VP.'],
    'renewal' => ['label'=>'Renewal','short'=>'REN','desc'=>'Record a verified member UMS renewal.'],
    'income' => ['label'=>'Income','short'=>'INC','desc'=>'Record Retail, Check, Club or Other income.'],
    'royalty' => ['label'=>'Royalty','short'=>'ROY','desc'=>'Record a royalty amount and optional VP.'],
];

$tab = de_trim($_GET['tab'] ?? ($_POST['module'] ?? 'new_ums'));
if (!isset($modules[$tab])) {
    $tab = 'new_ums';
}

$error = null;
$organizationId = 0;
$clubId = 0;
$manualSourceId = 0;
$currencyCode = 'INR';
$sourceTotal = 0;
$sourceMapped = 0;
$sourcePending = 0;
$members = [];
$recentManual = [];
$manualCounts = ['members'=>0,'vp'=>0,'orders'=>0,'renewals'=>0,'income'=>0,'royalty'=>0];
$success = null;
$today = date('Y-m-d');

try {
    $pdo = business_db();

    foreach (['organizations','clubs','data_sources','raw_source_records','members','ums_records','volume_point_entries','orders','renewals','income_entries','royalty_entries','audit_logs'] as $table) {
        if (!business_table_exists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    $orgStmt = $pdo->query("SELECT id, default_currency_code, timezone FROM organizations WHERE organization_code='HWC-001' LIMIT 1");
    $org = $orgStmt->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }
    $organizationId = (int)$org['id'];
    $currencyCode = (string)($org['default_currency_code'] ?: 'INR');
    $timezone = (string)($org['timezone'] ?: 'Asia/Kolkata');
    if (@date_default_timezone_set($timezone)) {
        $today = date('Y-m-d');
    }

    $clubStmt = $pdo->prepare("SELECT id FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $clubStmt->execute([$organizationId]);
    $clubId = (int)$clubStmt->fetchColumn();
    if ($clubId <= 0) {
        throw new RuntimeException('Ghazipur club was not found.');
    }

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='MANUAL' AND is_active=1 LIMIT 1");
    $sourceStmt->execute([$organizationId]);
    $manualSourceId = (int)$sourceStmt->fetchColumn();
    if ($manualSourceId <= 0) {
        throw new RuntimeException('MANUAL data source is not active.');
    }

    $stateStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows,
                SUM(mapping_status='mapped') mapped_rows,
                SUM(mapping_status='pending') pending_rows
         FROM raw_source_records
         WHERE organization_id=? AND source_dataset IN
         ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $stateStmt->execute([$organizationId]);
    $state = $stateStmt->fetch() ?: [];
    $sourceTotal = (int)($state['total_rows'] ?? 0);
    $sourceMapped = (int)($state['mapped_rows'] ?? 0);
    $sourcePending = (int)($state['pending_rows'] ?? 0);
    if ($sourceTotal !== 757 || $sourceMapped !== 757 || $sourcePending !== 0) {
        throw new RuntimeException('Legacy operational source must remain reconciled at 757/757 before manual data entry is enabled.');
    }

    $memberStmt = $pdo->prepare("SELECT id, full_name FROM members WHERE organization_id=? ORDER BY full_name, id");
    $memberStmt->execute([$organizationId]);
    $members = $memberStmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Refresh the page and try again.');
        }
        $module = de_trim($_POST['module'] ?? '');
        if (!isset($modules[$module])) {
            throw new RuntimeException('Unknown entry module.');
        }

        $externalId = de_external_id($module);
        $sourceKey = str_replace('manual-', 'manual:', $externalId);
        $entityId = 0;
        $entityType = '';
        $dataset = 'Manual Business Entry • ' . $modules[$module]['label'];

        $pdo->beginTransaction();
        try {
            if ($module === 'new_ums') {
                $name = de_trim($_POST['full_name'] ?? '');
                if ($name === '') {
                    throw new RuntimeException('Member name is required.');
                }
                $mobile = de_trim($_POST['mobile'] ?? '');
                $umsDate = de_date($_POST['ums_date'] ?? '');
                $umsType = de_trim($_POST['ums_type'] ?? '');
                $team = de_trim($_POST['team'] ?? '');
                $status = de_trim($_POST['status'] ?? 'active');
                if (!in_array($status, ['active','inactive'], true)) {
                    throw new RuntimeException('UMS status is invalid.');
                }
                $sponsorId = isset($_POST['sponsor_member_id']) && is_numeric($_POST['sponsor_member_id']) ? (int)$_POST['sponsor_member_id'] : 0;
                $sponsor = null;
                if ($sponsorId > 0) {
                    $sponsor = de_member($pdo, $organizationId, $sponsorId);
                }

                $payload = [
                    'channel'=>'business-os-manual', 'module'=>'new_ums', 'full_name'=>$name, 'mobile'=>$mobile,
                    'ums_date'=>$umsDate, 'ums_type'=>$umsType, 'team'=>$team, 'status'=>$status,
                    'sponsor_member_id'=>$sponsorId > 0 ? $sponsorId : null,
                    'sponsor_name'=>$sponsor['full_name'] ?? null,
                ];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $memberNotes = de_json(['entry_channel'=>'Business OS Manual', 'team_source'=>$team, 'identity_policy'=>'new-manual-member-row']);
                $memberInsert = $pdo->prepare(
                    "INSERT INTO members
                     (organization_id, primary_club_id, full_name, mobile, country_code, sponsor_member_id,
                      member_type, join_date, status, notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, 'IN', ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $memberInsert->execute([
                    $organizationId, $clubId, $name, $mobile !== '' ? $mobile : null,
                    $sponsorId > 0 ? $sponsorId : null, $umsType !== '' ? $umsType : null,
                    $umsDate, $status, $memberNotes, $rawId, $sourceKey . ':member',
                ]);
                $memberId = (int)$pdo->lastInsertId();

                $umsInsert = $pdo->prepare(
                    "INSERT INTO ums_records
                     (organization_id, club_id, member_id, set_type, start_date, status,
                      amount, currency_code, volume_points, notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, 'Manual Entry', ?)"
                );
                $umsInsert->execute([
                    $organizationId, $clubId, $memberId, $umsType !== '' ? $umsType : null,
                    $umsDate, $status, $currencyCode,
                    de_json(['entry_channel'=>'Business OS Manual','team_source'=>$team]),
                    $rawId, $sourceKey . ':ums',
                ]);

                $entityId = $memberId;
                $entityType = 'member';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_new_ums_created', $entityType, $entityId, ['source_record_id'=>$rawId,'ums_id'=>(int)$pdo->lastInsertId()]);
            }

            if ($module === 'vp') {
                $member = de_member($pdo, $organizationId, (int)($_POST['member_id'] ?? 0));
                $entryDate = de_date($_POST['entry_date'] ?? '');
                $vp = de_decimal($_POST['volume_points'] ?? '');
                $orderType = de_trim($_POST['order_type'] ?? '');
                $vpFrom = de_trim($_POST['vp_from'] ?? '');
                $orderedBy = de_trim($_POST['ordered_by'] ?? '');
                $vpType = de_trim($_POST['vp_type'] ?? '');
                $level = de_trim($_POST['level_label'] ?? '');
                $week = de_trim($_POST['week_label'] ?? '');

                $payload = [
                    'channel'=>'business-os-manual','module'=>'vp','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],
                    'entry_date'=>$entryDate,'volume_points'=>$vp,'order_type'=>$orderType,'vp_from'=>$vpFrom,
                    'ordered_by'=>$orderedBy,'vp_type'=>$vpType,'level_label'=>$level,'week_label'=>$week,
                ];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $insert = $pdo->prepare(
                    "INSERT INTO volume_point_entries
                     (organization_id, club_id, member_id, member_name_snapshot, entry_date, level_label, week_label,
                      volume_points, order_type, vp_from, ordered_by, vp_type, notes,
                      source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $insert->execute([
                    $organizationId,$clubId,(int)$member['id'],$member['full_name'],$entryDate,
                    $level !== '' ? $level : null,$week !== '' ? $week : null,$vp,
                    $orderType !== '' ? $orderType : null,$vpFrom !== '' ? $vpFrom : null,
                    $orderedBy !== '' ? $orderedBy : null,$vpType !== '' ? $vpType : null,
                    de_json(['entry_channel'=>'Business OS Manual']),$rawId,$sourceKey,
                ]);
                $entityId = (int)$pdo->lastInsertId();
                $entityType = 'volume_point_entry';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_vp_created', $entityType, $entityId, ['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'vp'=>$vp]);
            }

            if ($module === 'order') {
                $member = de_member($pdo, $organizationId, (int)($_POST['member_id'] ?? 0));
                $orderDate = de_date($_POST['order_date'] ?? '');
                $orderType = de_trim($_POST['order_type'] ?? 'regular');
                if ($orderType === '') $orderType = 'regular';
                $description = de_trim($_POST['description'] ?? '');
                $gross = de_decimal($_POST['gross_amount'] ?? '');
                $discount = de_decimal($_POST['discount_amount'] ?? '0');
                $net = de_decimal($_POST['net_amount'] ?? '', true);
                if ($net === null) $net = $gross - $discount;
                $profit = de_decimal($_POST['profit_amount'] ?? '0');
                $vp = de_decimal($_POST['volume_points'] ?? '0');
                if ($gross < 0 || $discount < 0 || $net < 0) {
                    throw new RuntimeException('Order gross, discount and net amounts cannot be negative.');
                }

                $payload = [
                    'channel'=>'business-os-manual','module'=>'order','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],
                    'order_date'=>$orderDate,'order_type'=>$orderType,'description'=>$description,
                    'gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>$net,'profit_amount'=>$profit,'volume_points'=>$vp,
                ];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $insert = $pdo->prepare(
                    "INSERT INTO orders
                     (organization_id, club_id, member_id, order_date, order_type, description,
                      gross_amount, discount_amount, net_amount, profit_amount, currency_code, volume_points,
                      notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $insert->execute([
                    $organizationId,$clubId,(int)$member['id'],$orderDate,$orderType,
                    $description !== '' ? mb_substr($description,0,255,'UTF-8') : 'Manual Business Order',
                    $gross,$discount,$net,$profit,$currencyCode,$vp,
                    de_json(['entry_channel'=>'Business OS Manual','member_name_snapshot'=>$member['full_name']]),
                    $rawId,$sourceKey,
                ]);
                $entityId = (int)$pdo->lastInsertId();
                $entityType = 'order';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_order_created', $entityType, $entityId, ['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'net_amount'=>$net,'profit'=>$profit,'vp'=>$vp]);
            }

            if ($module === 'renewal') {
                $member = de_member($pdo, $organizationId, (int)($_POST['member_id'] ?? 0));
                $renewalDate = de_date($_POST['renewal_date'] ?? '');
                $monthsRaw = de_trim($_POST['period_months'] ?? '');
                $periodMonths = null;
                if ($monthsRaw !== '') {
                    if (!ctype_digit($monthsRaw) || (int)$monthsRaw <= 0 || (int)$monthsRaw > 120) {
                        throw new RuntimeException('Renewal period must be a valid number of months.');
                    }
                    $periodMonths = (int)$monthsRaw;
                }
                $amount = de_decimal($_POST['amount'] ?? '0');
                $vp = de_decimal($_POST['volume_points'] ?? '0');
                if ($amount < 0) throw new RuntimeException('Renewal amount cannot be negative.');

                $umsStmt = $pdo->prepare("SELECT id FROM ums_records WHERE organization_id=? AND member_id=? ORDER BY start_date DESC, id DESC LIMIT 1");
                $umsStmt->execute([$organizationId,(int)$member['id']]);
                $umsId = $umsStmt->fetchColumn();
                $umsId = $umsId !== false ? (int)$umsId : null;

                $payload = [
                    'channel'=>'business-os-manual','module'=>'renewal','member_id'=>(int)$member['id'],'member_name'=>$member['full_name'],
                    'renewal_date'=>$renewalDate,'period_months'=>$periodMonths,'amount'=>$amount,'volume_points'=>$vp,'ums_record_id'=>$umsId,
                ];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $insert = $pdo->prepare(
                    "INSERT INTO renewals
                     (organization_id, club_id, member_id, ums_record_id, renewal_date, period_months,
                      amount, currency_code, volume_points, notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $insert->execute([
                    $organizationId,$clubId,(int)$member['id'],$umsId,$renewalDate,$periodMonths,
                    $amount,$currencyCode,$vp,de_json(['entry_channel'=>'Business OS Manual','member_name_snapshot'=>$member['full_name']]),
                    $rawId,$sourceKey,
                ]);
                $entityId = (int)$pdo->lastInsertId();
                $entityType = 'renewal';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_renewal_created', $entityType, $entityId, ['source_record_id'=>$rawId,'member_id'=>(int)$member['id'],'amount'=>$amount,'vp'=>$vp]);
            }

            if ($module === 'income') {
                $incomeDate = de_date($_POST['income_date'] ?? '');
                $incomeType = de_trim($_POST['income_type'] ?? '');
                if ($incomeType === '') throw new RuntimeException('Income type is required.');
                $amount = de_decimal($_POST['amount'] ?? '');
                $notesText = de_trim($_POST['notes'] ?? '');
                $periodKey = (new DateTimeImmutable($incomeDate))->format('Y-m');

                $payload = ['channel'=>'business-os-manual','module'=>'income','income_date'=>$incomeDate,'income_type'=>$incomeType,'amount'=>$amount,'period_key'=>$periodKey,'notes'=>$notesText];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $insert = $pdo->prepare(
                    "INSERT INTO income_entries
                     (organization_id, club_id, income_date, member_id, income_type, amount, currency_code,
                      period_key, notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $insert->execute([$organizationId,$clubId,$incomeDate,$incomeType,$amount,$currencyCode,$periodKey,de_json(['entry_channel'=>'Business OS Manual','user_notes'=>$notesText]),$rawId,$sourceKey]);
                $entityId = (int)$pdo->lastInsertId();
                $entityType = 'income_entry';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_income_created', $entityType, $entityId, ['source_record_id'=>$rawId,'income_type'=>$incomeType,'amount'=>$amount]);
            }

            if ($module === 'royalty') {
                $royaltyDate = de_date($_POST['royalty_date'] ?? '');
                $periodLabel = de_trim($_POST['period_label'] ?? '');
                $amount = de_decimal($_POST['amount'] ?? '');
                $vp = de_decimal($_POST['volume_points'] ?? '0');
                $notesText = de_trim($_POST['notes'] ?? '');

                $payload = ['channel'=>'business-os-manual','module'=>'royalty','royalty_date'=>$royaltyDate,'period_label'=>$periodLabel,'amount'=>$amount,'volume_points'=>$vp,'notes'=>$notesText];
                $rawId = de_create_raw($pdo, $organizationId, $clubId, $manualSourceId, $dataset, $externalId, $payload);

                $insert = $pdo->prepare(
                    "INSERT INTO royalty_entries
                     (organization_id, club_id, royalty_date, period_label, amount, currency_code, volume_points,
                      notes, source_record_id, source_sheet, source_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual Entry', ?)"
                );
                $insert->execute([$organizationId,$clubId,$royaltyDate,$periodLabel !== '' ? $periodLabel : null,$amount,$currencyCode,$vp,de_json(['entry_channel'=>'Business OS Manual','user_notes'=>$notesText]),$rawId,$sourceKey]);
                $entityId = (int)$pdo->lastInsertId();
                $entityType = 'royalty_entry';
                de_map_raw($pdo, $rawId, $entityType, $entityId);
                de_audit($pdo, $organizationId, $clubId, 'manual_royalty_created', $entityType, $entityId, ['source_record_id'=>$rawId,'amount'=>$amount,'vp'=>$vp]);
            }

            if ($entityId <= 0 || $entityType === '') {
                throw new RuntimeException('Entry could not be mapped to a normalized entity.');
            }

            $pdo->commit();
            header('Location: data_entry_center.php?tab=' . rawurlencode($module) . '&saved=' . rawurlencode($entityType) . '&id=' . $entityId);
            exit;
        } catch (Throwable $writeError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $writeError;
        }
    }

    if (isset($_GET['saved'], $_GET['id']) && is_numeric($_GET['id'])) {
        $success = 'Saved successfully: ' . de_trim($_GET['saved']) . ' #' . (int)$_GET['id'] . '. Raw source trace and audit log were created in the same transaction.';
    }

    $countQueries = [
        'members' => "SELECT COUNT(*) FROM members WHERE organization_id=? AND source_sheet='Manual Entry'",
        'vp' => "SELECT COUNT(*) FROM volume_point_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'orders' => "SELECT COUNT(*) FROM orders WHERE organization_id=? AND source_sheet='Manual Entry'",
        'renewals' => "SELECT COUNT(*) FROM renewals WHERE organization_id=? AND source_sheet='Manual Entry'",
        'income' => "SELECT COUNT(*) FROM income_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
        'royalty' => "SELECT COUNT(*) FROM royalty_entries WHERE organization_id=? AND source_sheet='Manual Entry'",
    ];
    foreach ($countQueries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$organizationId]);
        $manualCounts[$key] = (int)$stmt->fetchColumn();
    }

    $recentStmt = $pdo->prepare(
        "SELECT id, source_dataset, mapped_entity_type, mapped_entity_id, captured_at
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=?
         ORDER BY id DESC LIMIT 12"
    );
    $recentStmt->execute([$organizationId,$manualSourceId]);
    $recentManual = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$ready = $error === null && $sourceTotal === 757 && $sourceMapped === 757 && $sourcePending === 0 && $manualSourceId > 0;
$manualTotal = array_sum($manualCounts);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Smart Data Entry Center - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/data_entry.css">
</head>
<body>
<header class="os-topbar">
  <div class="os-topbar-inner">
    <a class="os-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Smart Data Entry Center</small></span>
    </a>
    <div class="os-top-actions">
      <a class="os-btn" href="operations_center.php">Operations</a>
      <a class="os-btn" href="members.php">Members</a>
      <a class="os-btn primary" href="index.php">Dashboard</a>
    </div>
  </div>
</header>

<div class="os-layout">
  <aside class="os-sidebar">
    <div class="os-nav-label">Business OS</div>
    <nav class="os-nav">
      <a href="index.php"><i class="dot"></i>Dashboard</a>
      <a class="active" href="data_entry_center.php"><i class="dot"></i>Data Entry Center</a>
      <a href="operations_center.php"><i class="dot"></i>Operations Center</a>
      <a href="members.php"><i class="dot"></i>Members & Network</a>
      <a href="member_profile.php"><i class="dot"></i>Member Profile 360°</a>
      <a href="sponsor_network.php"><i class="dot"></i>Sponsor Network</a>
      <a href="report_center.php"><i class="dot"></i>Report Center</a>
    </nav>
    <div class="os-sidebar-status">
      <b><?= $ready ? 'Manual entry channel ready' : 'Review required' ?></b>
      <span><?= number_format($sourceMapped) ?> / 757 legacy source mapped • every new entry gets its own raw trace.</span>
    </div>
  </aside>

  <main class="os-main">
    <section class="os-hero de-hero">
      <div class="os-kicker">Step 10F • Smart Business Data Entry</div>
      <h1>Daily business data can now enter the same trusted system without going back to Excel.</h1>
      <p>Create New UMS, VP, Orders, Renewals, Income and Royalty from one workspace. Every save writes a MANUAL raw-source record first, then its normalized fact, then an audit event — all inside one transaction.</p>
      <div class="os-status-row">
        <span class="os-chip <?= $ready ? 'good' : '' ?>"><?= $ready ? 'DATA ENTRY LIVE' : 'Review required' ?></span>
        <span class="os-chip good"><?= number_format($sourceMapped) ?> / 757 legacy source mapped</span>
        <span class="os-chip"><?= number_format($manualTotal) ?> normalized manual facts</span>
        <span class="os-chip">Source: MANUAL</span>
      </div>
    </section>

    <?php if ($success !== null): ?><div class="de-alert good"><strong>Saved:</strong> <?= de_h($success) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="de-alert bad"><strong>Data Entry diagnostic:</strong> <?= de_h($error) ?></div><?php endif; ?>

    <?php if ($error === null): ?>
      <section class="os-grid de-kpis">
        <article class="os-card os-kpi green"><small>Manual New UMS</small><strong><?= number_format($manualCounts['members']) ?></strong><span>Member + UMS created together</span></article>
        <article class="os-card os-kpi blue"><small>Manual VP</small><strong><?= number_format($manualCounts['vp']) ?></strong><span>Verified member-linked VP facts</span></article>
        <article class="os-card os-kpi gold"><small>Manual Orders</small><strong><?= number_format($manualCounts['orders']) ?></strong><span>Value, profit and VP</span></article>
        <article class="os-card os-kpi violet"><small>Other Facts</small><strong><?= number_format($manualCounts['renewals'] + $manualCounts['income'] + $manualCounts['royalty']) ?></strong><span>Renewal + Income + Royalty</span></article>
      </section>

      <section class="de-layout">
        <aside class="de-tabs">
          <h3>Choose Entry Type</h3>
          <div class="de-tab-list">
            <?php foreach ($modules as $key => $module): ?>
              <a class="de-tab <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= de_h($key) ?>"><i><?= de_h($module['short']) ?></i><?= de_h($module['label']) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="de-safety"><strong>Trace-first policy:</strong> Save is transactional. If normalized write or audit fails, the raw entry is rolled back too—no half-saved business facts.</div>
        </aside>

        <div class="de-main">
          <article class="os-card de-form-card">
            <div class="de-form-head">
              <div><h2><?= de_h($modules[$tab]['label']) ?></h2><p><?= de_h($modules[$tab]['desc']) ?></p></div>
              <span class="de-form-badge">RAW → FACT → AUDIT</span>
            </div>

            <?php if ($tab === 'new_ums'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="new_ums">
                <div class="de-field wide"><label>Member Name</label><input name="full_name" required autocomplete="off" placeholder="Full member name"></div>
                <div class="de-field"><label>Mobile</label><input name="mobile" autocomplete="off" placeholder="Optional mobile"></div>
                <div class="de-field"><label>UMS Date</label><input type="date" name="ums_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>UMS Type</label><input name="ums_type" placeholder="e.g. Active / type label"></div>
                <div class="de-field"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div class="de-field half"><label>Team</label><input name="team" placeholder="Source team label"></div>
                <div class="de-field half"><label>Verified Sponsor</label><select name="sponsor_member_id"><option value="">No verified sponsor yet</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= de_h($m['full_name']) ?> • #<?= (int)$m['id'] ?></option><?php endforeach; ?></select><span class="de-help">Choosing from this list creates an explicit sponsor_member_id link.</span></div>
                <div class="de-actions"><span>Creates one new Member + one UMS record. It never merges by name or mobile.</span><button type="submit">Save New UMS →</button></div>
              </form>
            <?php endif; ?>

            <?php if ($tab === 'vp'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="vp">
                <div class="de-field half"><label>Member</label><select name="member_id" required><option value="">Select verified member</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= de_h($m['full_name']) ?> • #<?= (int)$m['id'] ?></option><?php endforeach; ?></select></div>
                <div class="de-field"><label>Date</label><input type="date" name="entry_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>Volume Points</label><input type="number" step="0.001" name="volume_points" required></div>
                <div class="de-field"><label>Order Type</label><input name="order_type" placeholder="New UMS / Renewal / Personal…"></div>
                <div class="de-field"><label>VP From</label><input name="vp_from" placeholder="UMS / 1st Line / etc."></div>
                <div class="de-field"><label>Ordered By</label><input name="ordered_by" placeholder="PC / AS / etc."></div>
                <div class="de-field"><label>VP Type</label><input name="vp_type" placeholder="Personal / Team VP"></div>
                <div class="de-field"><label>Level</label><input name="level_label" placeholder="Optional"></div>
                <div class="de-field"><label>Week</label><input name="week_label" placeholder="Week 1 / Week 2…"></div>
                <div class="de-actions"><span>Member selection is explicit; no name-based auto-link is performed.</span><button type="submit">Save VP →</button></div>
              </form>
            <?php endif; ?>

            <?php if ($tab === 'order'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="order">
                <div class="de-field half"><label>Member</label><select name="member_id" required><option value="">Select verified member</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= de_h($m['full_name']) ?> • #<?= (int)$m['id'] ?></option><?php endforeach; ?></select></div>
                <div class="de-field"><label>Order Date</label><input type="date" name="order_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>Order Type</label><input name="order_type" value="regular"></div>
                <div class="de-field full"><label>Description</label><input name="description" placeholder="Order note / set / purpose"></div>
                <div class="de-field"><label>Gross Amount</label><input type="number" step="0.01" name="gross_amount" required></div>
                <div class="de-field"><label>Discount</label><input type="number" step="0.01" name="discount_amount" value="0" required></div>
                <div class="de-field"><label>Net Amount</label><input type="number" step="0.01" name="net_amount" placeholder="Blank = Gross - Discount"></div>
                <div class="de-field"><label>Profit</label><input type="number" step="0.01" name="profit_amount" value="0" required></div>
                <div class="de-field"><label>Volume Points</label><input type="number" step="0.001" name="volume_points" value="0" required></div>
                <div class="de-actions"><span>Net may be left blank; then it is calculated as Gross − Discount.</span><button type="submit">Save Order →</button></div>
              </form>
            <?php endif; ?>

            <?php if ($tab === 'renewal'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="renewal">
                <div class="de-field half"><label>Member</label><select name="member_id" required><option value="">Select verified member</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= de_h($m['full_name']) ?> • #<?= (int)$m['id'] ?></option><?php endforeach; ?></select></div>
                <div class="de-field"><label>Renewal Date</label><input type="date" name="renewal_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>Period Months</label><input type="number" min="1" max="120" name="period_months" placeholder="Optional"></div>
                <div class="de-field half"><label>Amount</label><input type="number" step="0.01" name="amount" value="0" required></div>
                <div class="de-field half"><label>Volume Points</label><input type="number" step="0.001" name="volume_points" value="0" required></div>
                <div class="de-actions"><span>The latest verified UMS record is linked when available.</span><button type="submit">Save Renewal →</button></div>
              </form>
            <?php endif; ?>

            <?php if ($tab === 'income'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="income">
                <div class="de-field"><label>Income Date</label><input type="date" name="income_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>Income Type</label><select name="income_type" required><option value="retail">Retail</option><option value="check">Check</option><option value="club">Club</option><option value="other">Other</option></select></div>
                <div class="de-field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
                <div class="de-field full"><label>Notes</label><textarea name="notes" placeholder="Optional note"></textarea></div>
                <div class="de-actions"><span>Income remains organization/club-level unless a future source explicitly provides a verified member relation.</span><button type="submit">Save Income →</button></div>
              </form>
            <?php endif; ?>

            <?php if ($tab === 'royalty'): ?>
              <form method="post" class="de-form">
                <input type="hidden" name="csrf" value="<?= de_h($csrf) ?>"><input type="hidden" name="module" value="royalty">
                <div class="de-field"><label>Royalty Date</label><input type="date" name="royalty_date" value="<?= de_h($today) ?>" required></div>
                <div class="de-field"><label>Period Label</label><input name="period_label" placeholder="Week / period label"></div>
                <div class="de-field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
                <div class="de-field"><label>Volume Points</label><input type="number" step="0.001" name="volume_points" value="0" required></div>
                <div class="de-field wide"><label>Notes</label><textarea name="notes" placeholder="Optional note"></textarea></div>
                <div class="de-actions"><span>Royalty is stored as its own normalized fact with raw source trace.</span><button type="submit">Save Royalty →</button></div>
              </form>
            <?php endif; ?>
          </article>

          <article class="os-card de-recent">
            <div class="os-title-row"><div><h2>Recent Manual Source Trace</h2><p>Latest entries captured through Business OS.</p></div><a class="os-btn" href="operations_center.php">Open Operations</a></div>
            <div class="de-table-wrap"><table class="de-table"><thead><tr><th>Captured</th><th>Dataset</th><th>Mapped Entity</th><th>Entity ID</th><th>Trace</th></tr></thead><tbody>
            <?php if (!$recentManual): ?><tr><td colspan="5" class="de-empty">No manual entries yet.</td></tr><?php else: ?>
              <?php foreach ($recentManual as $row): ?><tr><td><?= de_h((string)$row['captured_at']) ?></td><td><?= de_h((string)$row['source_dataset']) ?></td><td class="de-entity"><?= de_h((string)($row['mapped_entity_type'] ?: '—')) ?></td><td><?= $row['mapped_entity_id'] !== null ? '#' . number_format((int)$row['mapped_entity_id']) : '—' ?></td><td><span class="de-source">RAW #<?= number_format((int)$row['id']) ?></span></td></tr><?php endforeach; ?>
            <?php endif; ?></tbody></table></div>
          </article>

          <article class="os-card de-recent">
            <div class="os-title-row"><div><h2>Data Entry Safety Policy</h2><p>New daily data follows the same architecture as imported data.</p></div></div>
            <div class="de-policy-grid">
              <div class="de-policy"><b>1. Raw first</b><span>The exact submitted payload is stored under the MANUAL data source before normalization.</span></div>
              <div class="de-policy"><b>2. Explicit identity</b><span>Existing-member forms require a selected Member ID. Names/mobile are never used as silent identity keys.</span></div>
              <div class="de-policy"><b>3. Transaction + Audit</b><span>Raw, normalized fact and audit log either all succeed together or all roll back.</span></div>
            </div>
          </article>
        </div>
      </section>
    <?php endif; ?>

    <div class="os-footer-note"><strong>Source-of-truth rule:</strong> Excel remains historical imported evidence; all new daily entries can now be captured through Business OS and immediately become part of the same normalized reporting/operations layer.</div>
  </main>
</div>
</body>
</html>
