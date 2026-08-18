<?php
declare(strict_types=1);

/**
 * Runtime adapter for the six workbook-derived live reports.
 *
 * Legacy reconciliation stays fixed at 757 Excel rows. MANUAL Business OS entries
 * are projected into report-compatible read models at SQL prepare-time only.
 * No legacy/raw row is copied, rewritten or counted as Excel source data.
 */

function business_report_runtime_script(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return basename($script);
}

function business_report_runtime_reports(): array
{
    return [
        'master_tracking.php',
        'sp_house.php',
        'name_wise_tracking.php',
        'master_business_tracking.php',
        'ums_renewal.php',
        'ums_active_duration.php',
    ];
}

function business_report_runtime_enabled(): bool
{
    return in_array(business_report_runtime_script(), business_report_runtime_reports(), true);
}

function business_report_runtime_version(): string
{
    return '1.0-manual-normalized-facts';
}

function business_report_runtime_week_sql(string $dateExpression): string
{
    return "CONCAT('Week-', LEAST(4, CEIL(DAY({$dateExpression}) / 7)))";
}

function business_report_runtime_manual_new_ums_json(string $umsAlias = 'u', string $rawAlias = 'r', string $memberAlias = 'm'): string
{
    $week = business_report_runtime_week_sql("{$umsAlias}.start_date");
    return "JSON_OBJECT('values', JSON_OBJECT(" .
        "'B', YEAR({$umsAlias}.start_date)," .
        "'C', MONTHNAME({$umsAlias}.start_date)," .
        "'D', {$week}," .
        "'E', COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rawAlias}.raw_json, '$.team')), '')," .
        "'F', COALESCE({$memberAlias}.full_name, JSON_UNQUOTE(JSON_EXTRACT({$rawAlias}.raw_json, '$.full_name')), '')," .
        "'G', COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$rawAlias}.raw_json, '$.sponsor_name')), '')," .
        "'H', DATE_FORMAT({$umsAlias}.start_date, '%Y-%m-%d')," .
        "'K', CASE WHEN LOWER(COALESCE({$umsAlias}.status, ''))='active' THEN 'Yes' ELSE 'No' END," .
        "'L', ''," .
        "'M', COALESCE({$umsAlias}.set_type, JSON_UNQUOTE(JSON_EXTRACT({$rawAlias}.raw_json, '$.ums_type')), '')" .
    "))";
}

function business_report_runtime_vp_query(string $script): ?string
{
    $weekVp = business_report_runtime_week_sql('v.entry_date');
    $weekOrder = business_report_runtime_week_sql('o.order_date');
    $weekRenewal = business_report_runtime_week_sql('n.renewal_date');

    if ($script === 'master_tracking.php') {
        return <<<SQL
WITH p AS (SELECT ? AS org_id)
SELECT v.id, v.member_id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label,
       v.volume_points, v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set,
       CASE WHEN v.source_sheet='Manual Entry'
            THEN JSON_OBJECT('values', JSON_OBJECT(
                'D', YEAR(v.entry_date), 'E', MONTHNAME(v.entry_date),
                'F', COALESCE(NULLIF(v.week_label,''), {$weekVp})
            ))
            ELSE r.raw_json END raw_json
FROM volume_point_entries v
LEFT JOIN raw_source_records r ON r.id=v.source_record_id
JOIN p ON p.org_id=v.organization_id
WHERE v.source_sheet IN ('Volume Points','Manual Entry')
UNION ALL
SELECT o.id, o.member_id, m.full_name, o.order_date, NULL, {$weekOrder},
       o.volume_points, o.order_type, 'Order', '', 'Order VP', NULL,
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(o.order_date), 'E', MONTHNAME(o.order_date), 'F', {$weekOrder}))
FROM orders o
LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
JOIN p ON p.org_id=o.organization_id
WHERE o.source_sheet='Manual Entry' AND ABS(o.volume_points) > 0.0005
UNION ALL
SELECT n.id, n.member_id, m.full_name, n.renewal_date, NULL, {$weekRenewal},
       n.volume_points, 'Renewal UMS', 'UMS', '', 'Renewal VP', NULL,
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(n.renewal_date), 'E', MONTHNAME(n.renewal_date), 'F', {$weekRenewal}))
FROM renewals n
LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id
JOIN p ON p.org_id=n.organization_id
WHERE n.source_sheet='Manual Entry' AND ABS(n.volume_points) > 0.0005
ORDER BY entry_date, id
SQL;
    }

    if (in_array($script, ['sp_house.php', 'name_wise_tracking.php'], true)) {
        return <<<SQL
WITH p AS (SELECT ? AS org_id)
SELECT v.id, v.member_name_snapshot, v.entry_date, v.level_label, v.week_label, v.volume_points,
       v.order_type, v.vp_from, v.ordered_by, v.vp_type, v.order_set,
       CASE WHEN v.source_sheet='Manual Entry'
            THEN JSON_OBJECT('values', JSON_OBJECT('D', YEAR(v.entry_date), 'E', MONTHNAME(v.entry_date)))
            ELSE r.raw_json END raw_json
FROM volume_point_entries v
LEFT JOIN raw_source_records r ON r.id=v.source_record_id
JOIN p ON p.org_id=v.organization_id
WHERE v.source_sheet IN ('Volume Points','Manual Entry')
UNION ALL
SELECT o.id, m.full_name, o.order_date, NULL, {$weekOrder}, o.volume_points,
       o.order_type, 'Order', '', 'Order VP', NULL,
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(o.order_date), 'E', MONTHNAME(o.order_date)))
FROM orders o
LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
JOIN p ON p.org_id=o.organization_id
WHERE o.source_sheet='Manual Entry' AND ABS(o.volume_points) > 0.0005
UNION ALL
SELECT n.id, m.full_name, n.renewal_date, NULL, {$weekRenewal}, n.volume_points,
       'Renewal UMS', 'UMS', '', 'Renewal VP', NULL,
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(n.renewal_date), 'E', MONTHNAME(n.renewal_date)))
FROM renewals n
LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id
JOIN p ON p.org_id=n.organization_id
WHERE n.source_sheet='Manual Entry' AND ABS(n.volume_points) > 0.0005
ORDER BY entry_date, id
SQL;
    }

    if ($script === 'master_business_tracking.php') {
        return <<<SQL
WITH p AS (SELECT ? AS org_id)
SELECT v.member_name_snapshot, v.entry_date, v.volume_points, v.order_type,
       CASE WHEN v.source_sheet='Manual Entry'
            THEN JSON_OBJECT('values', JSON_OBJECT('D', YEAR(v.entry_date), 'E', MONTHNAME(v.entry_date)))
            ELSE r.raw_json END raw_json
FROM volume_point_entries v
LEFT JOIN raw_source_records r ON r.id=v.source_record_id
JOIN p ON p.org_id=v.organization_id
WHERE v.source_sheet IN ('Volume Points','Manual Entry')
UNION ALL
SELECT m.full_name, o.order_date, o.volume_points, o.order_type,
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(o.order_date), 'E', MONTHNAME(o.order_date)))
FROM orders o
LEFT JOIN members m ON m.id=o.member_id AND m.organization_id=o.organization_id
JOIN p ON p.org_id=o.organization_id
WHERE o.source_sheet='Manual Entry' AND ABS(o.volume_points) > 0.0005
UNION ALL
SELECT m.full_name, n.renewal_date, n.volume_points, 'Renewal UMS',
       JSON_OBJECT('values', JSON_OBJECT('D', YEAR(n.renewal_date), 'E', MONTHNAME(n.renewal_date)))
FROM renewals n
LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id
JOIN p ON p.org_id=n.organization_id
WHERE n.source_sheet='Manual Entry' AND ABS(n.volume_points) > 0.0005
ORDER BY entry_date
SQL;
    }

    return null;
}

function business_report_rewrite_sql(string $sql): string
{
    if (!business_report_runtime_enabled()) {
        return $sql;
    }

    $script = business_report_runtime_script();
    $normalized = preg_replace('/\s+/u', ' ', trim($sql)) ?? trim($sql);

    // All four VP-driven reports consume one unified read model: explicit VP facts plus
    // manual Order/Renewal VP values. The legacy tables themselves are never changed.
    if (str_contains($normalized, 'FROM volume_point_entries v') && str_contains($normalized, "v.source_sheet='Volume Points'")) {
        $vpSql = business_report_runtime_vp_query($script);
        if ($vpSql !== null) {
            return $vpSql;
        }
    }

    if ($script === 'master_tracking.php') {
        if (str_contains($normalized, 'FROM orders o') && str_contains($normalized, "o.source_sheet='Extra Order for Customer'")) {
            $weekOrder = business_report_runtime_week_sql('o.order_date');
            return <<<SQL
SELECT o.id, o.member_id, o.order_date, o.net_amount, o.profit_amount, o.volume_points, o.notes,
       CASE WHEN o.source_sheet='Manual Entry'
            THEN JSON_OBJECT('values', JSON_OBJECT('B', YEAR(o.order_date), 'C', MONTHNAME(o.order_date), 'D', {$weekOrder}))
            ELSE r.raw_json END raw_json
FROM orders o
LEFT JOIN raw_source_records r ON r.id=o.source_record_id
WHERE o.organization_id=?
  AND (o.source_sheet='Extra Order for Customer'
       OR (o.source_sheet='Manual Entry' AND LOWER(COALESCE(o.order_type,'')) LIKE '%extra%'))
ORDER BY o.order_date, o.id
SQL;
        }

        if (str_contains($normalized, 'FROM raw_source_records') && str_contains($normalized, "source_dataset='New UMS'")) {
            $manualJson = business_report_runtime_manual_new_ums_json('u', 'r', 'm');
            return <<<SQL
SELECT CASE WHEN u.source_sheet='Manual Entry' THEN {$manualJson} ELSE r.raw_json END raw_json
FROM ums_records u
LEFT JOIN raw_source_records r ON r.id=u.source_record_id
LEFT JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
WHERE u.organization_id=? AND u.source_sheet IN ('New UMS','Manual Entry')
ORDER BY u.start_date, u.id
SQL;
        }
    }

    if ($script === 'master_business_tracking.php') {
        if (str_contains($normalized, 'SELECT source_row, raw_json') && str_contains($normalized, "source_dataset='New UMS'")) {
            $manualJson = business_report_runtime_manual_new_ums_json('u', 'r', 'm');
            return <<<SQL
SELECT COALESCE(u.source_row, r.source_row) source_row,
       CASE WHEN u.source_sheet='Manual Entry' THEN {$manualJson} ELSE r.raw_json END raw_json
FROM ums_records u
LEFT JOIN raw_source_records r ON r.id=u.source_record_id
LEFT JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
WHERE u.organization_id=? AND u.source_sheet IN ('New UMS','Manual Entry')
ORDER BY COALESCE(u.source_row, r.source_row), u.start_date, u.id
SQL;
        }

        if (str_contains($normalized, 'FROM ums_activity_snapshots') && str_contains($normalized, 'snapshot_year=?') && str_contains($normalized, 'snapshot_month_number=?')) {
            return <<<'SQL'
WITH p AS (SELECT ? AS org_id, ? AS yr, ? AS mon)
SELECT x.member_name_snapshot, x.snapshot_year, x.snapshot_month_number
FROM (
    SELECT s.member_name_snapshot, s.snapshot_year, s.snapshot_month_number
    FROM ums_activity_snapshots s
    JOIN p ON p.org_id=s.organization_id
    WHERE s.source_sheet IN ('Active UMS Month_Wise','Manual Entry')
      AND s.snapshot_year=p.yr AND s.snapshot_month_number=p.mon AND s.is_active=1

    UNION ALL

    SELECT m.full_name, p.yr, p.mon
    FROM ums_records u
    JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
    JOIN p ON p.org_id=u.organization_id
    WHERE u.source_sheet='Manual Entry'
      AND LOWER(COALESCE(u.status,''))='active'
      AND u.start_date IS NOT NULL
      AND u.start_date <= LAST_DAY(STR_TO_DATE(CONCAT(p.yr,'-',LPAD(p.mon,2,'0'),'-01'), '%Y-%m-%d'))
      AND NOT EXISTS (
          SELECT 1 FROM ums_activity_snapshots s2
          WHERE s2.organization_id=p.org_id AND s2.member_id=u.member_id
            AND s2.snapshot_year=p.yr AND s2.snapshot_month_number=p.mon AND s2.is_active=1
      )
) x
ORDER BY x.member_name_snapshot
SQL;
        }
    }

    if ($script === 'ums_renewal.php') {
        // Keep the legacy integrity guard exactly 141/141; manual renewals are additive runtime facts.
        if (str_contains($normalized, 'SELECT COUNT(*) FROM renewals') && str_contains($normalized, "source_sheet='Renewal UMS'")) {
            return $sql;
        }

        if (str_contains($normalized, 'FROM renewals n') && str_contains($normalized, "n.source_sheet='Renewal UMS'")) {
            return <<<'SQL'
SELECT n.id, n.member_id, n.member_name_snapshot, n.renewal_date, n.notes, n.source_row,
       CASE WHEN n.source_sheet='Manual Entry' THEN
            JSON_OBJECT('values', JSON_OBJECT(
                'B', COALESCE(n.member_name_snapshot, m.full_name, ''),
                'C', YEAR(n.renewal_date),
                'D', MONTHNAME(n.renewal_date),
                'E', COALESCE(m.member_type, ''),
                'G', COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(mr.raw_json, '$.values.L')),
                    JSON_UNQUOTE(JSON_EXTRACT(mr.raw_json, '$.active_supervisor')),
                    ''
                ),
                'H', COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(mr.raw_json, '$.values.E')),
                    JSON_UNQUOTE(JSON_EXTRACT(mr.raw_json, '$.team')),
                    ''
                )
            ))
            ELSE r.raw_json END raw_json
FROM renewals n
LEFT JOIN raw_source_records r ON r.id=n.source_record_id
LEFT JOIN members m ON m.id=n.member_id AND m.organization_id=n.organization_id
LEFT JOIN raw_source_records mr ON mr.id=m.source_record_id
WHERE n.organization_id=? AND n.source_sheet IN ('Renewal UMS','Manual Entry')
ORDER BY n.renewal_date, n.id
SQL;
        }

        if (str_contains($normalized, 'r.id raw_id') && str_contains($normalized, "r.source_dataset='New UMS'")) {
            $manualJson = business_report_runtime_manual_new_ums_json('u', 'r', 'm');
            return <<<SQL
SELECT r.id raw_id, COALESCE(u.source_row, r.source_row) source_row, u.id ums_id,
       CASE WHEN u.source_sheet='Manual Entry' THEN {$manualJson} ELSE r.raw_json END raw_json,
       u.member_id, m.full_name
FROM ums_records u
LEFT JOIN raw_source_records r ON r.id=u.source_record_id
LEFT JOIN members m ON m.id=u.member_id AND m.organization_id=u.organization_id
WHERE u.organization_id=? AND u.source_sheet IN ('New UMS','Manual Entry')
ORDER BY COALESCE(u.source_row, r.source_row), u.start_date, u.id
SQL;
        }
    }

    if ($script === 'ums_active_duration.php') {
        // Keep the 78/78 legacy New UMS integrity check unchanged.
        if (str_contains($normalized, 'SELECT COUNT(*) FROM ums_records') && str_contains($normalized, "source_sheet='New UMS'")) {
            return $sql;
        }

        if (str_contains($normalized, 'FROM ums_records u') && str_contains($normalized, "u.source_sheet='New UMS'")) {
            $manualJson = business_report_runtime_manual_new_ums_json('u', 'r', 'm');
            return <<<SQL
SELECT u.id ums_id, u.member_id, u.start_date, u.status normalized_status, u.notes ums_notes,
       m.full_name member_full_name, r.source_row,
       CASE WHEN u.source_sheet='Manual Entry' THEN {$manualJson} ELSE r.raw_json END raw_json
FROM ums_records u
INNER JOIN raw_source_records r ON r.id=u.source_record_id
LEFT JOIN members m ON m.id=u.member_id
WHERE u.organization_id=? AND u.source_sheet IN ('New UMS','Manual Entry')
ORDER BY u.start_date, u.id
SQL;
        }
    }

    // Organization-level manual facts participate in the same monthly derived reports.
    $sql = str_replace("source_sheet='Monthely_Income'", "source_sheet IN ('Monthely_Income','Manual Entry')", $sql);
    $sql = str_replace("source_sheet='Royalty_Tracking'", "source_sheet IN ('Royalty_Tracking','Manual Entry')", $sql);

    return $sql;
}

final class BusinessReportPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(business_report_rewrite_sql($query), $options);
    }
}
