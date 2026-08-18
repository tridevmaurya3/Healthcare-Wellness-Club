<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

const FES_EXPECTED = [
    'New UMS' => 78,
    'Volume Points' => 282,
    'First & Second Set' => 94,
    'Active UMS Month_Wise' => 25,
    'Renewal UMS' => 141,
    'Monthely_Income' => 26,
    'Royalty_Tracking' => 97,
    'Extra Order for Customer' => 14,
];
const FES_OPERATIONAL_TOTAL = 757;
const FES_REMAINING_RAW_TOTAL = 278;

function fes_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fes_trim(mixed $value): string
{
    return trim((string)$value);
}

function fes_name_key(mixed $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(fes_trim($value), 'UTF-8'));
    return $normalized === null ? '' : $normalized;
}

function fes_decode_values(string $json): array
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['values'] ?? null)) {
        throw new RuntimeException('A raw Excel row does not contain the expected values payload.');
    }
    return (array)$payload['values'];
}

function fes_year(mixed $value): array
{
    $raw = fes_trim($value);
    if ($raw === '' || !is_numeric($raw)) {
        return ['value' => null, 'valid' => false];
    }
    $year = (int)$raw;
    return ['value' => $year, 'valid' => $year >= 2000 && $year <= 2100];
}

function fes_month(mixed $value): array
{
    $raw = fes_trim($value);
    if ($raw === '') {
        return ['name' => null, 'number' => null, 'valid' => false];
    }

    $lookup = [
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    $key = mb_strtolower($raw, 'UTF-8');
    $number = $lookup[$key] ?? null;
    if ($number === null && is_numeric($raw)) {
        $candidate = (int)$raw;
        if ($candidate >= 1 && $candidate <= 12) {
            $number = $candidate;
        }
    }
    if ($number === null) {
        return ['name' => null, 'number' => null, 'valid' => false];
    }

    $date = DateTimeImmutable::createFromFormat('!m', (string)$number);
    return [
        'name' => $date ? $date->format('F') : $raw,
        'number' => $number,
        'valid' => true,
    ];
}

function fes_excel_date(mixed $value): array
{
    $raw = fes_trim($value);
    if ($raw === '') {
        return ['iso' => null, 'valid' => false];
    }

    if (is_numeric($raw)) {
        $serial = (int)floor((float)$raw);
        if ($serial > 20000 && $serial < 90000) {
            try {
                return [
                    'iso' => (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d'),
                    'valid' => true,
                ];
            } catch (Throwable) {
                return ['iso' => null, 'valid' => false];
            }
        }
    }

    try {
        return ['iso' => (new DateTimeImmutable($raw))->format('Y-m-d'), 'valid' => true];
    } catch (Throwable) {
        return ['iso' => null, 'valid' => false];
    }
}

function fes_decimal(mixed $value, bool $blankAllowed = false): array
{
    $raw = fes_trim($value);
    if ($raw === '') {
        return ['value' => $blankAllowed ? 0.0 : null, 'valid' => $blankAllowed, 'blank' => true];
    }

    $clean = str_replace([',', '₹', 'Rs.', 'Rs', 'INR', ' '], '', $raw);
    if (preg_match('/^\((.+)\)$/', $clean, $m)) {
        $clean = '-' . $m[1];
    }
    if (!is_numeric($clean)) {
        return ['value' => null, 'valid' => false, 'blank' => false];
    }
    return ['value' => (float)$clean, 'valid' => true, 'blank' => false];
}

function fes_equal(float $a, float $b, float $tolerance = 0.01): bool
{
    return abs($a - $b) <= $tolerance;
}

function fes_json(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Seeding metadata could not be encoded.');
    }
    return $json;
}

function fes_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function fes_column_nullable(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return strtoupper((string)$stmt->fetchColumn()) === 'YES';
}

function fes_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function fes_prepare_renewal_schema(PDO $pdo): array
{
    $changes = [];

    if (!fes_column_nullable($pdo, 'renewals', 'member_id')) {
        $pdo->exec('ALTER TABLE renewals MODIFY member_id BIGINT UNSIGNED NULL');
        $changes[] = 'renewals.member_id made nullable';
    }

    if (!fes_column_exists($pdo, 'renewals', 'member_name_snapshot')) {
        $pdo->exec('ALTER TABLE renewals ADD COLUMN member_name_snapshot VARCHAR(180) NULL AFTER member_id');
        $changes[] = 'renewals.member_name_snapshot added';
    }

    if (!fes_index_exists($pdo, 'renewals', 'idx_renewals_name_snapshot')) {
        $pdo->exec('CREATE INDEX idx_renewals_name_snapshot ON renewals (organization_id, member_name_snapshot)');
        $changes[] = 'renewal source-name index added';
    }

    $meta = $pdo->prepare(
        "INSERT INTO schema_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
    );
    $meta->execute(['remaining_excel_seeding_compat', '1.0']);
    $meta->execute(['renewal_identity_policy', 'nullable-member-id-plus-source-name-snapshot']);

    return $changes;
}

function fes_member_index(PDO $pdo, int $organizationId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name FROM members WHERE organization_id=? ORDER BY id');
    $stmt->execute([$organizationId]);
    $index = [];
    foreach ($stmt->fetchAll() as $member) {
        $key = fes_name_key($member['full_name']);
        if ($key !== '') {
            $index[$key][] = $member;
        }
    }
    return $index;
}

function fes_member_id(array $index, string $name): ?int
{
    $key = fes_name_key($name);
    if ($key !== '' && isset($index[$key]) && count($index[$key]) === 1) {
        return (int)$index[$key][0]['id'];
    }
    return null;
}

function fes_fetch_raw(PDO $pdo, int $organizationId, int $sourceId, int $batchId, string $dataset): array
{
    $stmt = $pdo->prepare(
        "SELECT id, source_row, external_record_id, mapping_status, mapped_entity_type, mapped_entity_id, raw_json
         FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND source_dataset=?
         ORDER BY source_row"
    );
    $stmt->execute([$organizationId, $sourceId, $batchId, $dataset]);
    return $stmt->fetchAll();
}

function fes_state(array $rows): array
{
    $state = ['total' => count($rows), 'pending' => 0, 'mapped' => 0, 'other' => 0];
    foreach ($rows as $row) {
        if ((string)$row['mapping_status'] === 'pending') {
            $state['pending']++;
        } elseif ((string)$row['mapping_status'] === 'mapped') {
            $state['mapped']++;
        } else {
            $state['other']++;
        }
    }
    return $state;
}

function fes_count_source(PDO $pdo, string $table, int $organizationId, string $sheet): int
{
    $allowed = ['members', 'ums_records', 'volume_point_entries', 'ums_activity_snapshots', 'orders', 'renewals', 'income_entries', 'royalty_entries'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported reconciliation table.');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE organization_id=? AND source_sheet=?");
    $stmt->execute([$organizationId, $sheet]);
    return (int)$stmt->fetchColumn();
}

function fes_sum_source(PDO $pdo, string $table, string $column, int $organizationId, string $sheet, ?string $type = null): float
{
    $allowed = [
        'income_entries' => ['amount'],
        'royalty_entries' => ['amount'],
        'orders' => ['net_amount', 'profit_amount', 'volume_points'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new InvalidArgumentException('Unsupported reconciliation sum.');
    }

    if ($table === 'income_entries' && $type !== null) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$column}`),0) FROM `{$table}` WHERE organization_id=? AND source_sheet=? AND income_type=?");
        $stmt->execute([$organizationId, $sheet, $type]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$column}`),0) FROM `{$table}` WHERE organization_id=? AND source_sheet=?");
        $stmt->execute([$organizationId, $sheet]);
    }
    return (float)$stmt->fetchColumn();
}

$error = null;
$success = null;
$schemaChanges = [];
$blockers = [];
$warnings = [];
$context = null;
$raw = [];
$states = [];
$prepared = [
    'Renewal UMS' => [],
    'Monthely_Income' => [],
    'Royalty_Tracking' => [],
    'Extra Order for Customer' => [],
];
$stats = [
    'renewal_linked' => 0,
    'renewal_link_later' => 0,
    'extra_linked' => 0,
    'extra_link_later' => 0,
    'income_source_total_missing' => 0,
    'income_total_mismatch' => 0,
];
$sourceTotals = [
    'income_retail' => 0.0,
    'income_check' => 0.0,
    'income_club' => 0.0,
    'royalty' => 0.0,
    'extra_order' => 0.0,
    'extra_profit' => 0.0,
    'extra_vp' => 0.0,
];
$reconciliation = [];
$finalPass = false;

try {
    $pdo = business_db();

    foreach (['raw_source_records', 'members', 'ums_records', 'orders', 'renewals', 'income_entries', 'royalty_entries', 'volume_point_entries', 'ums_activity_snapshots', 'audit_logs'] as $requiredTable) {
        if (!business_table_exists($pdo, $requiredTable)) {
            throw new RuntimeException("Required table {$requiredTable} is missing.");
        }
    }

    $org = $pdo->query("SELECT id, organization_name FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization was not found.');
    }
    $organizationId = (int)$org['id'];

    $clubStmt = $pdo->prepare("SELECT id, club_name FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $clubStmt->execute([$organizationId]);
    $club = $clubStmt->fetch();
    if (!$club) {
        throw new RuntimeException('Ghazipur club was not found.');
    }
    $clubId = (int)$club['id'];

    $sourceStmt = $pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
    $sourceStmt->execute([$organizationId]);
    $sourceId = (int)$sourceStmt->fetchColumn();
    if ($sourceId <= 0) {
        throw new RuntimeException('LEGACY-XLSX data source was not found.');
    }

    $batchStmt = $pdo->prepare(
        "SELECT id, original_file_name, status, imported_rows, failed_rows, completed_at
         FROM import_batches
         WHERE organization_id=? AND data_source_id=? AND import_type='excel_raw_capture' AND status='completed'
         ORDER BY id DESC LIMIT 1"
    );
    $batchStmt->execute([$organizationId, $sourceId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('No completed raw Excel capture batch was found.');
    }
    $batchId = (int)$batch['id'];

    $context = [
        'organization_id' => $organizationId,
        'club_id' => $clubId,
        'source_id' => $sourceId,
        'batch_id' => $batchId,
        'workbook' => (string)$batch['original_file_name'],
    ];

    foreach (array_keys(FES_EXPECTED) as $dataset) {
        $raw[$dataset] = fes_fetch_raw($pdo, $organizationId, $sourceId, $batchId, $dataset);
        $states[$dataset] = fes_state($raw[$dataset]);
        if ($states[$dataset]['total'] !== FES_EXPECTED[$dataset]) {
            $blockers[] = $dataset . ' raw row count is ' . $states[$dataset]['total'] . '; expected ' . FES_EXPECTED[$dataset] . '.';
        }
        if ($states[$dataset]['other'] > 0) {
            $blockers[] = $dataset . ' contains rows in an unexpected mapping state.';
        }
    }

    // Earlier source sheets must already be fully normalized before the final run.
    foreach (['New UMS', 'Volume Points', 'First & Second Set', 'Active UMS Month_Wise'] as $doneDataset) {
        if ($states[$doneDataset]['mapped'] !== FES_EXPECTED[$doneDataset] || $states[$doneDataset]['pending'] !== 0) {
            $blockers[] = $doneDataset . ' is not fully mapped yet.';
        }
    }

    // Remaining sheets may be fully pending (ready) or fully mapped (idempotent rerun), never partial.
    foreach (['Renewal UMS', 'Monthely_Income', 'Royalty_Tracking', 'Extra Order for Customer'] as $remainingDataset) {
        $s = $states[$remainingDataset];
        $fullyPending = $s['pending'] === FES_EXPECTED[$remainingDataset] && $s['mapped'] === 0;
        $fullyMapped = $s['mapped'] === FES_EXPECTED[$remainingDataset] && $s['pending'] === 0;
        if (!$fullyPending && !$fullyMapped) {
            $blockers[] = $remainingDataset . ' is in a partial mapping state.';
        }
    }

    // Verify earlier normalized fact counts, including Active UMS Month_Wise reconciliation that was pending as a separate step.
    $earlierCountChecks = [
        ['members', 'New UMS', 78],
        ['ums_records', 'New UMS', 78],
        ['volume_point_entries', 'Volume Points', 282],
        ['orders', 'First & Second Set', 94],
        ['ums_activity_snapshots', 'Active UMS Month_Wise', 25],
    ];
    foreach ($earlierCountChecks as [$table, $sheet, $expected]) {
        $actual = fes_count_source($pdo, $table, $organizationId, $sheet);
        if ($actual !== $expected) {
            $blockers[] = "{$sheet} reconciliation failed in {$table}: {$actual}/{$expected}.";
        }
    }

    $memberIndex = fes_member_index($pdo, $organizationId);

    // Renewal UMS — preserve source identity, never guess member or amount/VP meaning.
    foreach ($raw['Renewal UMS'] as $row) {
        $v = fes_decode_values((string)$row['raw_json']);
        $name = fes_trim($v['B'] ?? null);
        $date = fes_excel_date($v['F'] ?? null);
        if ($name === '') {
            $blockers[] = 'Renewal UMS source row ' . (int)$row['source_row'] . ' has no customer name.';
        }
        if (!$date['valid']) {
            $blockers[] = 'Renewal UMS source row ' . (int)$row['source_row'] . ' has an invalid renewal date.';
        }
        $externalId = fes_trim($row['external_record_id'] ?? null);
        if ($externalId === '') {
            $blockers[] = 'Renewal UMS source row ' . (int)$row['source_row'] . ' has no external source key.';
        }
        $memberId = $name !== '' ? fes_member_id($memberIndex, $name) : null;
        if ($memberId !== null) {
            $stats['renewal_linked']++;
        } else {
            $stats['renewal_link_later']++;
        }
        $prepared['Renewal UMS'][] = [
            'raw_id' => (int)$row['id'],
            'source_row' => (int)$row['source_row'],
            'external_id' => $externalId,
            'member_id' => $memberId,
            'name' => $name,
            'date' => $date['iso'],
            'year' => fes_trim($v['C'] ?? null),
            'month' => fes_trim($v['D'] ?? null),
            'ums_type' => fes_trim($v['E'] ?? null),
            'supervisor' => fes_trim($v['G'] ?? null),
            'team' => fes_trim($v['H'] ?? null),
        ];
    }

    // Monthely_Income — one raw month becomes exactly 3 income facts; source Total Income is reconciliation only.
    foreach ($raw['Monthely_Income'] as $row) {
        $v = fes_decode_values((string)$row['raw_json']);
        $year = fes_year($v['B'] ?? null);
        $month = fes_month($v['C'] ?? null);
        $retail = fes_decimal($v['D'] ?? null, true);
        $check = fes_decimal($v['E'] ?? null, true);
        $clubIncome = fes_decimal($v['F'] ?? null, true);
        $sourceTotal = fes_decimal($v['G'] ?? null, true);

        if (!$year['valid'] || !$month['valid']) {
            $blockers[] = 'Monthely_Income source row ' . (int)$row['source_row'] . ' has an invalid Year/Month.';
        }
        foreach (['Retail Income' => $retail, 'Check Income' => $check, 'Club Income' => $clubIncome, 'Total Income' => $sourceTotal] as $label => $parsed) {
            if (!$parsed['valid']) {
                $blockers[] = 'Monthely_Income source row ' . (int)$row['source_row'] . " has a non-numeric {$label}.";
            }
        }

        $componentTotal = (float)($retail['value'] ?? 0) + (float)($check['value'] ?? 0) + (float)($clubIncome['value'] ?? 0);
        if ($sourceTotal['blank']) {
            $stats['income_source_total_missing']++;
        } elseif ($sourceTotal['valid'] && !fes_equal($componentTotal, (float)$sourceTotal['value'])) {
            $stats['income_total_mismatch']++;
            $blockers[] = 'Monthely_Income source row ' . (int)$row['source_row'] . ' Total Income does not equal Retail + Check + Club.';
        }

        $externalId = fes_trim($row['external_record_id'] ?? null);
        if ($externalId === '') {
            $blockers[] = 'Monthely_Income source row ' . (int)$row['source_row'] . ' has no external source key.';
        }

        $periodDate = ($year['valid'] && $month['valid']) ? sprintf('%04d-%02d-01', (int)$year['value'], (int)$month['number']) : null;
        $periodKey = ($year['valid'] && $month['valid']) ? sprintf('%04d-%02d', (int)$year['value'], (int)$month['number']) : null;

        $sourceTotals['income_retail'] += (float)($retail['value'] ?? 0);
        $sourceTotals['income_check'] += (float)($check['value'] ?? 0);
        $sourceTotals['income_club'] += (float)($clubIncome['value'] ?? 0);

        $prepared['Monthely_Income'][] = [
            'raw_id' => (int)$row['id'],
            'source_row' => (int)$row['source_row'],
            'external_id' => $externalId,
            'period_date' => $periodDate,
            'period_key' => $periodKey,
            'year' => $year['value'],
            'month' => $month['name'],
            'retail' => (float)($retail['value'] ?? 0),
            'check' => (float)($check['value'] ?? 0),
            'club' => (float)($clubIncome['value'] ?? 0),
            'source_total' => $sourceTotal['blank'] ? null : (float)$sourceTotal['value'],
        ];
    }

    // Royalty Tracking — Year/Month become a documented period anchor; Week remains the period label.
    foreach ($raw['Royalty_Tracking'] as $row) {
        $v = fes_decode_values((string)$row['raw_json']);
        $year = fes_year($v['B'] ?? null);
        $month = fes_month($v['C'] ?? null);
        $amount = fes_decimal($v['E'] ?? null, false);
        if (!$year['valid'] || !$month['valid']) {
            $blockers[] = 'Royalty_Tracking source row ' . (int)$row['source_row'] . ' has an invalid Year/Month.';
        }
        if (!$amount['valid']) {
            $blockers[] = 'Royalty_Tracking source row ' . (int)$row['source_row'] . ' has a non-numeric Royalty value.';
        }
        $externalId = fes_trim($row['external_record_id'] ?? null);
        if ($externalId === '') {
            $blockers[] = 'Royalty_Tracking source row ' . (int)$row['source_row'] . ' has no external source key.';
        }
        $periodDate = ($year['valid'] && $month['valid']) ? sprintf('%04d-%02d-01', (int)$year['value'], (int)$month['number']) : null;
        $sourceTotals['royalty'] += (float)($amount['value'] ?? 0);
        $prepared['Royalty_Tracking'][] = [
            'raw_id' => (int)$row['id'],
            'source_row' => (int)$row['source_row'],
            'external_id' => $externalId,
            'period_date' => $periodDate,
            'year' => $year['value'],
            'month' => $month['name'],
            'week' => fes_trim($v['D'] ?? null),
            'amount' => (float)($amount['value'] ?? 0),
        ];
    }

    // Extra Order for Customer — order-level facts only; product columns remain source metadata until catalog mapping.
    foreach ($raw['Extra Order for Customer'] as $row) {
        $v = fes_decode_values((string)$row['raw_json']);
        $name = fes_trim($v['E'] ?? null);
        $date = fes_excel_date($v['G'] ?? null);
        $vp = fes_decimal($v['H'] ?? null, false);
        $received = fes_decimal($v['N'] ?? null, true);
        $orderAmount = fes_decimal($v['O'] ?? null, false);
        $profit = fes_decimal($v['P'] ?? null, false);

        if ($name === '') {
            $blockers[] = 'Extra Order source row ' . (int)$row['source_row'] . ' has no customer name.';
        }
        if (!$date['valid']) {
            $blockers[] = 'Extra Order source row ' . (int)$row['source_row'] . ' has an invalid order date.';
        }
        foreach (['Volume Points' => $vp, 'Received Amount' => $received, 'Order Amount' => $orderAmount, 'Profit' => $profit] as $label => $parsed) {
            if (!$parsed['valid']) {
                $blockers[] = 'Extra Order source row ' . (int)$row['source_row'] . " has a non-numeric {$label}.";
            }
        }
        $externalId = fes_trim($row['external_record_id'] ?? null);
        if ($externalId === '') {
            $blockers[] = 'Extra Order source row ' . (int)$row['source_row'] . ' has no external source key.';
        }

        $memberId = $name !== '' ? fes_member_id($memberIndex, $name) : null;
        if ($memberId !== null) {
            $stats['extra_linked']++;
        } else {
            $stats['extra_link_later']++;
        }

        $sourceTotals['extra_order'] += (float)($orderAmount['value'] ?? 0);
        $sourceTotals['extra_profit'] += (float)($profit['value'] ?? 0);
        $sourceTotals['extra_vp'] += (float)($vp['value'] ?? 0);

        $prepared['Extra Order for Customer'][] = [
            'raw_id' => (int)$row['id'],
            'source_row' => (int)$row['source_row'],
            'external_id' => $externalId,
            'member_id' => $memberId,
            'name' => $name,
            'sponsor' => fes_trim($v['F'] ?? null),
            'date' => $date['iso'],
            'year' => fes_trim($v['B'] ?? null),
            'month' => fes_trim($v['C'] ?? null),
            'week' => fes_trim($v['D'] ?? null),
            'vp' => (float)($vp['value'] ?? 0),
            'received' => (float)($received['value'] ?? 0),
            'order_amount' => (float)($orderAmount['value'] ?? 0),
            'profit' => (float)($profit['value'] ?? 0),
            'products' => [
                'formula_1' => fes_trim($v['I'] ?? null),
                'afresh' => fes_trim($v['J'] ?? null),
                'protein_powder' => fes_trim($v['K'] ?? null),
                'dinoshake' => fes_trim($v['L'] ?? null),
                'other_products' => fes_trim($v['M'] ?? null),
            ],
        ];
    }

    // If a sheet is still pending, pre-existing normalized rows are unsafe and block a duplicate write.
    $pendingExistingChecks = [
        ['Renewal UMS', 'renewals', 141],
        ['Monthely_Income', 'income_entries', 78],
        ['Royalty_Tracking', 'royalty_entries', 97],
        ['Extra Order for Customer', 'orders', 14],
    ];
    foreach ($pendingExistingChecks as [$dataset, $table, $expectedNormalized]) {
        $existing = fes_count_source($pdo, $table, $organizationId, $dataset);
        if ($states[$dataset]['pending'] === FES_EXPECTED[$dataset] && $existing !== 0) {
            $blockers[] = "{$dataset} is pending but {$existing} normalized rows already exist in {$table}.";
        }
        if ($states[$dataset]['mapped'] === FES_EXPECTED[$dataset] && $existing !== $expectedNormalized) {
            $blockers[] = "{$dataset} is mapped but {$table} has {$existing}/{$expectedNormalized} expected rows.";
        }
    }

    $schemaReady = fes_column_nullable($pdo, 'renewals', 'member_id') && fes_column_exists($pdo, 'renewals', 'member_name_snapshot');
    if (!$schemaReady) {
        $warnings[] = 'Renewal identity compatibility will be prepared automatically when the final seeding button is pressed.';
    }
    if ($stats['renewal_link_later'] > 0) {
        $warnings[] = $stats['renewal_link_later'] . ' Renewal UMS rows will preserve the source customer name and defer Member linking; no identity will be guessed.';
    }
    if ($stats['extra_link_later'] > 0) {
        $warnings[] = $stats['extra_link_later'] . ' Extra Order rows will keep member_id NULL and preserve the source customer name in metadata until identity reconciliation.';
    }
    if ($stats['income_source_total_missing'] > 0) {
        $warnings[] = $stats['income_source_total_missing'] . ' monthly income rows have no source Total Income value; their three component facts will still be preserved.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['confirm_final_excel_seed'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm the final Excel seeding before continuing.');
        }
        if ($blockers) {
            throw new RuntimeException('Final Excel seeding is blocked by preflight validation.');
        }

        $datasetsToWrite = array_values(array_filter(
            ['Renewal UMS', 'Monthely_Income', 'Royalty_Tracking', 'Extra Order for Customer'],
            static fn(string $dataset): bool => $states[$dataset]['pending'] === FES_EXPECTED[$dataset]
        ));

        if ($datasetsToWrite) {
            // DDL is intentionally completed before the business-data transaction.
            $schemaChanges = fes_prepare_renewal_schema($pdo);

            $rawUpdate = $pdo->prepare(
                "UPDATE raw_source_records
                 SET mapping_status='mapped', mapped_entity_type=?, mapped_entity_id=?, error_message=NULL, updated_at=NOW()
                 WHERE id=? AND mapping_status='pending'"
            );

            $renewalInsert = $pdo->prepare(
                "INSERT INTO renewals
                 (organization_id, club_id, member_id, member_name_snapshot, ums_record_id, renewal_date,
                  period_months, amount, currency_code, volume_points, notes,
                  source_record_id, source_sheet, source_row, source_key)
                 VALUES (?, ?, ?, ?, NULL, ?, NULL, 0, 'INR', 0, ?, ?, 'Renewal UMS', ?, ?)"
            );

            $incomeInsert = $pdo->prepare(
                "INSERT INTO income_entries
                 (organization_id, club_id, income_date, member_id, income_type, amount, currency_code,
                  period_key, notes, source_record_id, source_sheet, source_row, source_key)
                 VALUES (?, ?, ?, NULL, ?, ?, 'INR', ?, ?, ?, 'Monthely_Income', ?, ?)"
            );

            $royaltyInsert = $pdo->prepare(
                "INSERT INTO royalty_entries
                 (organization_id, club_id, royalty_date, period_label, amount, currency_code, volume_points,
                  notes, source_record_id, source_sheet, source_row, source_key)
                 VALUES (?, ?, ?, ?, ?, 'INR', 0, ?, ?, 'Royalty_Tracking', ?, ?)"
            );

            $extraOrderInsert = $pdo->prepare(
                "INSERT INTO orders
                 (organization_id, club_id, member_id, order_date, order_type, description,
                  gross_amount, discount_amount, net_amount, profit_amount, currency_code, volume_points,
                  notes, source_record_id, source_sheet, source_row, source_key)
                 VALUES (?, ?, ?, ?, 'extra_customer_order', ?, ?, 0, ?, ?, 'INR', ?, ?, ?, 'Extra Order for Customer', ?, ?)"
            );

            $auditInsert = $pdo->prepare(
                "INSERT INTO audit_logs
                 (organization_id, club_id, event_type, entity_type, entity_id, details_json, ip_address, user_agent)
                 VALUES (?, ?, ?, 'import_batch', ?, ?, ?, ?)"
            );

            $result = [
                'renewals' => 0,
                'income_entries' => 0,
                'royalties' => 0,
                'extra_orders' => 0,
                'raw_mapped' => 0,
            ];

            $pdo->beginTransaction();
            try {
                if (in_array('Renewal UMS', $datasetsToWrite, true)) {
                    foreach ($prepared['Renewal UMS'] as $row) {
                        $notes = fes_json([
                            'dataset' => 'Renewal UMS',
                            'member_name_snapshot' => $row['name'],
                            'year' => $row['year'],
                            'ums_month' => $row['month'],
                            'ums_type_source' => $row['ums_type'],
                            'supervisor_name_snapshot' => $row['supervisor'],
                            'team_source' => $row['team'],
                            'amount_and_vp_parsing' => 'deferred-no-guessing',
                            'ums_record_link' => 'deferred',
                            'identity_policy' => $row['member_id'] !== null ? 'unique-exact-name-link' : 'source-name-preserved-link-later',
                        ]);
                        $sourceKey = 'legacy-xlsx:renewal-ums:' . $row['external_id'];
                        $renewalInsert->execute([
                            $organizationId, $clubId, $row['member_id'], $row['name'], $row['date'],
                            $notes, $row['raw_id'], $row['source_row'], $sourceKey,
                        ]);
                        $id = (int)$pdo->lastInsertId();
                        $result['renewals']++;
                        $rawUpdate->execute(['renewal', $id, $row['raw_id']]);
                        if ($rawUpdate->rowCount() !== 1) {
                            throw new RuntimeException('Renewal UMS raw mapping changed unexpectedly at source row ' . $row['source_row'] . '.');
                        }
                        $result['raw_mapped']++;
                    }
                    $auditInsert->execute([
                        $organizationId, $clubId, 'renewal_ums_normalization_completed', $batchId,
                        fes_json(['dataset' => 'Renewal UMS', 'created' => $result['renewals'], 'linked_members' => $stats['renewal_linked'], 'link_later' => $stats['renewal_link_later'], 'amount_vp_policy' => 'preserved-not-guessed']),
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                }

                if (in_array('Monthely_Income', $datasetsToWrite, true)) {
                    foreach ($prepared['Monthely_Income'] as $row) {
                        $firstId = null;
                        foreach ([
                            'retail' => $row['retail'],
                            'check' => $row['check'],
                            'club' => $row['club'],
                        ] as $type => $amount) {
                            $notes = fes_json([
                                'dataset' => 'Monthely_Income',
                                'source_year' => $row['year'],
                                'source_month' => $row['month'],
                                'source_total_income' => $row['source_total'],
                                'component' => $type,
                                'total_income_policy' => 'validation-only-not-fourth-fact',
                            ]);
                            $sourceKey = 'legacy-xlsx:monthely-income:' . $row['external_id'] . ':' . $type;
                            $incomeInsert->execute([
                                $organizationId, $clubId, $row['period_date'], $type, $amount,
                                $row['period_key'], $notes, $row['raw_id'], $row['source_row'], $sourceKey,
                            ]);
                            $id = (int)$pdo->lastInsertId();
                            $firstId ??= $id;
                            $result['income_entries']++;
                        }
                        $rawUpdate->execute(['income_period', $firstId, $row['raw_id']]);
                        if ($rawUpdate->rowCount() !== 1) {
                            throw new RuntimeException('Monthely_Income raw mapping changed unexpectedly at source row ' . $row['source_row'] . '.');
                        }
                        $result['raw_mapped']++;
                    }
                    $auditInsert->execute([
                        $organizationId, $clubId, 'monthly_income_normalization_completed', $batchId,
                        fes_json(['dataset' => 'Monthely_Income', 'raw_rows' => 26, 'created_income_entries' => $result['income_entries'], 'facts_per_source_row' => 3, 'source_total_policy' => 'validation-only']),
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                }

                if (in_array('Royalty_Tracking', $datasetsToWrite, true)) {
                    foreach ($prepared['Royalty_Tracking'] as $row) {
                        $notes = fes_json([
                            'dataset' => 'Royalty_Tracking',
                            'source_year' => $row['year'],
                            'source_month' => $row['month'],
                            'source_week' => $row['week'],
                            'royalty_date_semantics' => 'period-anchor-first-day-not-exact-payout-date',
                        ]);
                        $sourceKey = 'legacy-xlsx:royalty-tracking:' . $row['external_id'];
                        $royaltyInsert->execute([
                            $organizationId, $clubId, $row['period_date'], $row['week'] !== '' ? $row['week'] : null,
                            $row['amount'], $notes, $row['raw_id'], $row['source_row'], $sourceKey,
                        ]);
                        $id = (int)$pdo->lastInsertId();
                        $result['royalties']++;
                        $rawUpdate->execute(['royalty_entry', $id, $row['raw_id']]);
                        if ($rawUpdate->rowCount() !== 1) {
                            throw new RuntimeException('Royalty_Tracking raw mapping changed unexpectedly at source row ' . $row['source_row'] . '.');
                        }
                        $result['raw_mapped']++;
                    }
                    $auditInsert->execute([
                        $organizationId, $clubId, 'royalty_tracking_normalization_completed', $batchId,
                        fes_json(['dataset' => 'Royalty_Tracking', 'created' => $result['royalties'], 'date_policy' => 'month-first-day-period-anchor']),
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                }

                if (in_array('Extra Order for Customer', $datasetsToWrite, true)) {
                    foreach ($prepared['Extra Order for Customer'] as $row) {
                        $notes = fes_json([
                            'dataset' => 'Extra Order for Customer',
                            'member_name_snapshot' => $row['name'],
                            'sponsor_name_snapshot' => $row['sponsor'],
                            'source_year' => $row['year'],
                            'source_month' => $row['month'],
                            'source_week' => $row['week'],
                            'received_amount_source' => $row['received'],
                            'products_raw' => $row['products'],
                            'product_item_mapping' => 'deferred-until-product-catalog',
                            'identity_policy' => $row['member_id'] !== null ? 'unique-exact-name-link' : 'source-name-preserved-link-later',
                        ]);
                        $description = mb_substr('Extra Order | Member: ' . $row['name'], 0, 255, 'UTF-8');
                        $sourceKey = 'legacy-xlsx:extra-order:' . $row['external_id'];
                        $extraOrderInsert->execute([
                            $organizationId, $clubId, $row['member_id'], $row['date'], $description,
                            $row['order_amount'], $row['order_amount'], $row['profit'], $row['vp'],
                            $notes, $row['raw_id'], $row['source_row'], $sourceKey,
                        ]);
                        $id = (int)$pdo->lastInsertId();
                        $result['extra_orders']++;
                        $rawUpdate->execute(['order', $id, $row['raw_id']]);
                        if ($rawUpdate->rowCount() !== 1) {
                            throw new RuntimeException('Extra Order raw mapping changed unexpectedly at source row ' . $row['source_row'] . '.');
                        }
                        $result['raw_mapped']++;
                    }
                    $auditInsert->execute([
                        $organizationId, $clubId, 'extra_order_normalization_completed', $batchId,
                        fes_json(['dataset' => 'Extra Order for Customer', 'created_orders' => $result['extra_orders'], 'linked_members' => $stats['extra_linked'], 'link_later' => $stats['extra_link_later'], 'product_item_mapping' => 'deferred']),
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                }

                $auditInsert->execute([
                    $organizationId, $clubId, 'remaining_excel_seeding_completed', $batchId,
                    fes_json([
                        'raw_rows_mapped_this_run' => $result['raw_mapped'],
                        'renewals' => $result['renewals'],
                        'income_entries' => $result['income_entries'],
                        'royalties' => $result['royalties'],
                        'extra_orders' => $result['extra_orders'],
                        'target_operational_raw_rows' => FES_OPERATIONAL_TOTAL,
                    ]),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);

                $pdo->commit();
                $success = 'Remaining Excel source data was seeded successfully in one controlled transaction.';
            } catch (Throwable $transactionError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $transactionError;
            }
        } else {
            $success = 'All remaining Excel source sheets were already normalized. No duplicate facts were created.';
        }

        // Refresh raw states after successful write.
        foreach (array_keys(FES_EXPECTED) as $dataset) {
            $raw[$dataset] = fes_fetch_raw($pdo, $organizationId, $sourceId, $batchId, $dataset);
            $states[$dataset] = fes_state($raw[$dataset]);
        }
    }

    // Final whole-workbook operational reconciliation.
    $overallMappedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM raw_source_records
         WHERE organization_id=? AND data_source_id=? AND import_batch_id=? AND mapping_status='mapped'
           AND source_dataset IN ('New UMS','Volume Points','First & Second Set','Active UMS Month_Wise','Renewal UMS','Monthely_Income','Royalty_Tracking','Extra Order for Customer')"
    );
    $overallMappedStmt->execute([$organizationId, $sourceId, $batchId]);
    $overallMapped = (int)$overallMappedStmt->fetchColumn();

    $normalizedCounts = [
        'Members from New UMS' => fes_count_source($pdo, 'members', $organizationId, 'New UMS'),
        'UMS records from New UMS' => fes_count_source($pdo, 'ums_records', $organizationId, 'New UMS'),
        'Volume Point facts' => fes_count_source($pdo, 'volume_point_entries', $organizationId, 'Volume Points'),
        'First & Second Set orders' => fes_count_source($pdo, 'orders', $organizationId, 'First & Second Set'),
        'Active UMS snapshots' => fes_count_source($pdo, 'ums_activity_snapshots', $organizationId, 'Active UMS Month_Wise'),
        'Renewal facts' => fes_count_source($pdo, 'renewals', $organizationId, 'Renewal UMS'),
        'Monthly income facts' => fes_count_source($pdo, 'income_entries', $organizationId, 'Monthely_Income'),
        'Royalty facts' => fes_count_source($pdo, 'royalty_entries', $organizationId, 'Royalty_Tracking'),
        'Extra customer orders' => fes_count_source($pdo, 'orders', $organizationId, 'Extra Order for Customer'),
    ];

    $traceRenewal = $pdo->prepare(
        "SELECT COUNT(*) FROM raw_source_records r
         LEFT JOIN renewals n ON n.id=r.mapped_entity_id AND n.source_record_id=r.id
         WHERE r.organization_id=? AND r.data_source_id=? AND r.import_batch_id=? AND r.source_dataset='Renewal UMS'
           AND (r.mapping_status<>'mapped' OR r.mapped_entity_type<>'renewal' OR n.id IS NULL)"
    );
    $traceRenewal->execute([$organizationId, $sourceId, $batchId]);
    $renewalTraceFailures = (int)$traceRenewal->fetchColumn();

    $traceIncome = $pdo->prepare(
        "SELECT COUNT(*) FROM (
             SELECT r.id, COUNT(i.id) AS fact_count
             FROM raw_source_records r
             LEFT JOIN income_entries i ON i.source_record_id=r.id AND i.source_sheet='Monthely_Income'
             WHERE r.organization_id=? AND r.data_source_id=? AND r.import_batch_id=? AND r.source_dataset='Monthely_Income'
             GROUP BY r.id
             HAVING fact_count<>3
         ) x"
    );
    $traceIncome->execute([$organizationId, $sourceId, $batchId]);
    $incomeTraceFailures = (int)$traceIncome->fetchColumn();

    $traceRoyalty = $pdo->prepare(
        "SELECT COUNT(*) FROM raw_source_records r
         LEFT JOIN royalty_entries x ON x.id=r.mapped_entity_id AND x.source_record_id=r.id
         WHERE r.organization_id=? AND r.data_source_id=? AND r.import_batch_id=? AND r.source_dataset='Royalty_Tracking'
           AND (r.mapping_status<>'mapped' OR r.mapped_entity_type<>'royalty_entry' OR x.id IS NULL)"
    );
    $traceRoyalty->execute([$organizationId, $sourceId, $batchId]);
    $royaltyTraceFailures = (int)$traceRoyalty->fetchColumn();

    $traceExtra = $pdo->prepare(
        "SELECT COUNT(*) FROM raw_source_records r
         LEFT JOIN orders o ON o.id=r.mapped_entity_id AND o.source_record_id=r.id
         WHERE r.organization_id=? AND r.data_source_id=? AND r.import_batch_id=? AND r.source_dataset='Extra Order for Customer'
           AND (r.mapping_status<>'mapped' OR r.mapped_entity_type<>'order' OR o.id IS NULL)"
    );
    $traceExtra->execute([$organizationId, $sourceId, $batchId]);
    $extraTraceFailures = (int)$traceExtra->fetchColumn();

    $dbTotals = [
        'income_retail' => fes_sum_source($pdo, 'income_entries', 'amount', $organizationId, 'Monthely_Income', 'retail'),
        'income_check' => fes_sum_source($pdo, 'income_entries', 'amount', $organizationId, 'Monthely_Income', 'check'),
        'income_club' => fes_sum_source($pdo, 'income_entries', 'amount', $organizationId, 'Monthely_Income', 'club'),
        'royalty' => fes_sum_source($pdo, 'royalty_entries', 'amount', $organizationId, 'Royalty_Tracking'),
        'extra_order' => fes_sum_source($pdo, 'orders', 'net_amount', $organizationId, 'Extra Order for Customer'),
        'extra_profit' => fes_sum_source($pdo, 'orders', 'profit_amount', $organizationId, 'Extra Order for Customer'),
        'extra_vp' => fes_sum_source($pdo, 'orders', 'volume_points', $organizationId, 'Extra Order for Customer'),
    ];

    $reconciliation = [
        'All 757 operational raw rows mapped' => $overallMapped === FES_OPERATIONAL_TOTAL,
        'New UMS members = 78' => $normalizedCounts['Members from New UMS'] === 78,
        'New UMS records = 78' => $normalizedCounts['UMS records from New UMS'] === 78,
        'Volume Point facts = 282' => $normalizedCounts['Volume Point facts'] === 282,
        'First & Second Set orders = 94' => $normalizedCounts['First & Second Set orders'] === 94,
        'Active UMS snapshots = 25' => $normalizedCounts['Active UMS snapshots'] === 25,
        'Renewal facts = 141' => $normalizedCounts['Renewal facts'] === 141,
        'Monthly income facts = 78 (26 × 3)' => $normalizedCounts['Monthly income facts'] === 78,
        'Royalty facts = 97' => $normalizedCounts['Royalty facts'] === 97,
        'Extra customer orders = 14' => $normalizedCounts['Extra customer orders'] === 14,
        'Renewal raw → fact trace exact' => $renewalTraceFailures === 0,
        'Each monthly income raw row → exactly 3 facts' => $incomeTraceFailures === 0,
        'Royalty raw → fact trace exact' => $royaltyTraceFailures === 0,
        'Extra Order raw → order trace exact' => $extraTraceFailures === 0,
        'Retail Income total reconciles' => fes_equal($sourceTotals['income_retail'], $dbTotals['income_retail']),
        'Check Income total reconciles' => fes_equal($sourceTotals['income_check'], $dbTotals['income_check']),
        'Club Income total reconciles' => fes_equal($sourceTotals['income_club'], $dbTotals['income_club']),
        'Royalty total reconciles' => fes_equal($sourceTotals['royalty'], $dbTotals['royalty']),
        'Extra Order amount total reconciles' => fes_equal($sourceTotals['extra_order'], $dbTotals['extra_order']),
        'Extra Order profit total reconciles' => fes_equal($sourceTotals['extra_profit'], $dbTotals['extra_profit']),
        'Extra Order VP total reconciles' => fes_equal($sourceTotals['extra_vp'], $dbTotals['extra_vp']),
    ];

    $finalPass = !$blockers && $reconciliation && !in_array(false, $reconciliation, true);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$remainingPending = 0;
foreach (['Renewal UMS', 'Monthely_Income', 'Royalty_Tracking', 'Extra Order for Customer'] as $dataset) {
    $remainingPending += (int)($states[$dataset]['pending'] ?? 0);
}
$ready = $error === null && !$blockers && $remainingPending > 0;
$complete = $error === null && $finalPass;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Final Excel Seeding - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .fes-wide{grid-column:span 12}.fes-main{grid-column:span 8}.fes-side{grid-column:span 4}
    .fes-ok{color:#236a43}.fes-bad{color:#9b2c2c}.fes-note{font-size:.76rem;line-height:1.55;color:#5e6b65}
    .fes-table{width:100%;border-collapse:collapse;font-size:.75rem}.fes-table th,.fes-table td{padding:10px;border-bottom:1px solid #e8eeea;text-align:left}.fes-table th{font-size:.67rem;text-transform:uppercase;color:#65736c}
    .fes-confirm{display:flex;gap:10px;align-items:flex-start;margin:15px 0;padding:13px;border:1px solid #d8e5dd;border-radius:13px;background:#fff}.fes-confirm input{margin-top:3px}
    .fes-alert{padding:13px 15px;border-radius:13px;background:#fff8e7;border:1px solid #ecd79d;color:#735414;margin-top:10px}.fes-error{background:#fff1f1;border-color:#efc4c4;color:#8d2929}
    @media(max-width:900px){.fes-main,.fes-side{grid-column:span 12}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Final Excel Seeding</small></span>
    </a>
    <nav class="imp-nav"><a href="index.php">Business OS</a><a href="raw_import.php">Raw Import</a></nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Final Excel source seeding • one controlled run</div>
      <h1><?= $complete ? 'Excel operational seeding is COMPLETE.' : ($ready ? 'All remaining source sheets are READY for one-click seeding.' : 'Final Excel seeding preflight') ?></h1>
      <p>Sheets 1–6 remain derived reports and are never seeded as source facts. This pipeline completes Sheets 11–14 plus Renewal UMS, and also includes the Active UMS reconciliation that was previously pending.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">No branches</span>
      <span class="imp-chip good">Source-first</span>
      <span class="imp-chip <?= $complete ? 'good' : '' ?>"><?= $complete ? '757 / 757 mapped' : ($ready ? 'READY TO SEED' : 'Review required') ?></span>
    </div>
  </section>

  <?php if ($error !== null): ?>
    <div class="fes-alert fes-error"><strong>Seeding could not continue:</strong> <?= fes_h($error) ?></div>
  <?php endif; ?>
  <?php if ($success !== null): ?>
    <div class="fes-alert" style="background:#eef8f2;border-color:#cce5d5;color:#266246"><strong>Success:</strong> <?= fes_h($success) ?></div>
  <?php endif; ?>

  <section class="imp-grid">
    <section class="imp-summary">
      <article class="imp-kpi green"><small>Operational raw rows</small><strong><?= number_format(FES_OPERATIONAL_TOTAL) ?></strong><span>8 source sheets</span></article>
      <article class="imp-kpi blue"><small>Remaining batch</small><strong><?= number_format($remainingPending) ?></strong><span>Expected before final run: 278</span></article>
      <article class="imp-kpi gold"><small>Renewal member links</small><strong><?= number_format($stats['renewal_linked']) ?></strong><span><?= number_format($stats['renewal_link_later']) ?> preserved for link later</span></article>
      <article class="imp-kpi"><small>Final status</small><strong><?= $complete ? 'PASS' : ($ready ? 'READY' : 'CHECK') ?></strong><span><?= $complete ? 'All operational source data reconciled' : 'No guessing / no derived-sheet seeding' ?></span></article>
    </section>

    <article class="imp-card fes-main">
      <h2>Workbook source status</h2>
      <p class="fes-note">The first four operational datasets are already normalized. The remaining four are processed together only when every preflight validation passes.</p>
      <table class="fes-table">
        <thead><tr><th>Source sheet</th><th>Expected</th><th>Pending</th><th>Mapped</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (FES_EXPECTED as $dataset => $expected): $s=$states[$dataset] ?? ['pending'=>0,'mapped'=>0,'total'=>0]; ?>
          <tr>
            <td><strong><?= fes_h($dataset) ?></strong></td>
            <td><?= number_format($expected) ?></td>
            <td><?= number_format((int)$s['pending']) ?></td>
            <td><?= number_format((int)$s['mapped']) ?></td>
            <td class="<?= (int)$s['mapped']===$expected ? 'fes-ok' : ((int)$s['pending']===$expected ? '' : 'fes-bad') ?>"><strong><?= (int)$s['mapped']===$expected ? 'DONE' : ((int)$s['pending']===$expected ? 'READY' : 'CHECK') ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </article>

    <aside class="imp-card fes-side">
      <h2>Safe mapping policies</h2>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>Renewal UMS</b><span>141 facts; unique exact-name link only. UMS Type amount/VP meaning preserved, never guessed.</span></div></div>
        <div class="imp-plan-row"><div><b>Monthely_Income</b><span>26 source rows → 78 facts: Retail + Check + Club. Total Income is reconciliation only.</span></div></div>
        <div class="imp-plan-row"><div><b>Royalty_Tracking</b><span>97 facts. Year/Month stored as period anchor; Week preserved.</span></div></div>
        <div class="imp-plan-row"><div><b>Extra Order for Customer</b><span>14 order facts. Product columns preserved raw until Product Catalog mapping.</span></div></div>
      </div>
    </aside>

    <?php if ($warnings): ?>
      <article class="imp-card fes-wide">
        <h2>Non-blocking preservation notes</h2>
        <?php foreach ($warnings as $warning): ?><div class="fes-alert"><?= fes_h($warning) ?></div><?php endforeach; ?>
      </article>
    <?php endif; ?>

    <?php if ($blockers): ?>
      <article class="imp-card fes-wide">
        <h2>Blocking checks</h2>
        <?php foreach ($blockers as $blocker): ?><div class="fes-alert fes-error"><?= fes_h($blocker) ?></div><?php endforeach; ?>
      </article>
    <?php endif; ?>

    <?php if ($ready): ?>
      <article class="imp-card fes-wide">
        <h2>Run the remaining Excel seeding once</h2>
        <p class="fes-note">The page will first prepare the Renewal UMS compatibility column if required, then write all pending business facts inside one transaction. If any business-data insert fails, that transaction rolls back.</p>
        <form method="post">
          <label class="fes-confirm"><input type="checkbox" name="confirm_final_excel_seed" value="yes" required><span><strong>I confirm the verified remaining Excel source data should now be normalized.</strong><br><small>Derived sheets 1–6 will remain untouched. Uncertain member identities and product mappings will be preserved for later reconciliation rather than guessed.</small></span></label>
          <button class="imp-button" type="submit">Seed All Remaining Excel Data Safely →</button>
        </form>
      </article>
    <?php endif; ?>

    <article class="imp-card fes-wide">
      <h2>Whole-workbook operational reconciliation</h2>
      <?php if (!$reconciliation): ?>
        <p class="fes-note">Reconciliation will appear after database/source checks are available.</p>
      <?php else: ?>
        <div class="imp-plan-list">
          <?php foreach ($reconciliation as $label => $pass): ?>
            <div class="imp-plan-row"><div><b><?= fes_h($label) ?></b><span><?= $pass ? 'Verified against normalized source-linked facts.' : 'Does not reconcile yet.' ?></span></div><em class="<?= $pass ? 'fes-ok' : 'fes-bad' ?>"><?= $pass ? 'PASS' : 'CHECK' ?></em></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="imp-card fes-main">
      <h2>Expected normalized source facts after completion</h2>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>Members + New UMS</b><span>78 Members + 78 UMS records</span></div><em>156</em></div>
        <div class="imp-plan-row"><div><b>Volume Points</b><span>Dedicated VP facts</span></div><em>282</em></div>
        <div class="imp-plan-row"><div><b>Orders</b><span>94 First/Second Set + 14 Extra Orders</span></div><em>108</em></div>
        <div class="imp-plan-row"><div><b>Active UMS snapshots</b><span>Monthly activity facts</span></div><em>25</em></div>
        <div class="imp-plan-row"><div><b>Renewals</b><span>Source-preserved renewal facts</span></div><em>141</em></div>
        <div class="imp-plan-row"><div><b>Income</b><span>26 months × 3 income components</span></div><em>78</em></div>
        <div class="imp-plan-row"><div><b>Royalty</b><span>Weekly/period royalty facts</span></div><em>97</em></div>
      </div>
    </article>

    <aside class="imp-card fes-side">
      <h2>After this page passes</h2>
      <p class="fes-note"><strong>Excel source seeding is finished.</strong> The next stage is not another import. It is the Derived Report Engine for sheets 1–6, followed by Member Identity Reconciliation and the Product & Price catalog mapping.</p>
      <?php if ($schemaChanges): ?>
        <h3>Schema preparation applied</h3>
        <?php foreach ($schemaChanges as $change): ?><div class="fes-note">✓ <?= fes_h($change) ?></div><?php endforeach; ?>
      <?php endif; ?>
    </aside>
  </section>
</main>
</body>
</html>
