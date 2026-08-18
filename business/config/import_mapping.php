<?php
declare(strict_types=1);

/**
 * Reviewed mapping for Master_Personal_Tracking.xlsx.
 *
 * Rules:
 * - Sheets 1-6 are DERIVED reports and are never imported as source facts.
 * - Sheets 7-14 are operational source datasets.
 * - Column letters are authoritative because the workbook contains duplicate/blank headings.
 * - Every row is captured to raw_source_records before any normalized mapping.
 * - Fields marked raw_only are preserved but are not written to a normalized fact yet.
 * - Fields marked support_table require the source-mapping support migration before live import.
 */
return [
    'version' => '1.0-reviewed-2026-08',

    'derived_sheets' => [
        'Master_Tracking',
        'SP_House',
        'Name_Wise_Tracking',
        'Master_Business_Tracking',
        'UMS_Renewal',
        'UMS_Active_Duration',
    ],

    'source_sheets' => [
        'New UMS' => [
            'target' => 'members + ums_records',
            'normalization_status' => 'ready',
            'identity' => ['name' => 'F', 'mobile' => 'I'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'C' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'D' => ['header' => 'Week', 'field' => 'week', 'role' => 'context'],
                'E' => ['header' => 'Team of', 'field' => 'team_of', 'role' => 'metadata'],
                'F' => ['header' => 'Name', 'field' => 'full_name', 'role' => 'members.full_name'],
                'G' => ['header' => 'Sponsor', 'field' => 'sponsor_name', 'role' => 'member_relation'],
                'H' => ['header' => 'UMS Date', 'field' => 'ums_date', 'role' => 'ums_records.start_date', 'transform' => 'excel_date'],
                'I' => ['header' => 'Mobile Number', 'field' => 'mobile', 'role' => 'members.mobile', 'transform' => 'phone_text'],
                'J' => ['header' => 'Duration', 'field' => 'duration_label', 'role' => 'metadata'],
                'K' => ['header' => 'UMS Active/Inactive', 'field' => 'ums_active_flag', 'role' => 'metadata'],
                'L' => ['header' => 'Active Supervisor', 'field' => 'active_supervisor_name', 'role' => 'member_relation'],
                'M' => ['header' => 'UMS Type', 'field' => 'ums_status_label', 'role' => 'ums_records.status'],
            ],
        ],

        'Volume Points' => [
            'target' => 'volume_point_entries',
            'normalization_status' => 'support_table',
            'identity' => ['name' => 'B', 'date' => 'G', 'volume_points' => 'H'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Name', 'field' => 'member_name', 'role' => 'member_relation'],
                'C' => ['header' => '@Level', 'field' => 'level_label', 'role' => 'volume_point_entries.level_label'],
                'D' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'E' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'F' => ['header' => 'Week', 'field' => 'week', 'role' => 'volume_point_entries.week_label'],
                'G' => ['header' => 'Date', 'field' => 'entry_date', 'role' => 'volume_point_entries.entry_date', 'transform' => 'excel_date'],
                'H' => ['header' => 'Volume Point', 'field' => 'volume_points', 'role' => 'volume_point_entries.volume_points', 'transform' => 'decimal'],
                'I' => ['header' => 'Order Type', 'field' => 'order_type', 'role' => 'volume_point_entries.order_type'],
                'J' => ['header' => 'Note', 'field' => 'note', 'role' => 'volume_point_entries.notes'],
                'K' => ['header' => 'VP From', 'field' => 'vp_from', 'role' => 'volume_point_entries.vp_from'],
                'L' => ['header' => 'Ordered By', 'field' => 'ordered_by', 'role' => 'volume_point_entries.ordered_by'],
                'M' => ['header' => 'VP Type', 'field' => 'vp_type', 'role' => 'volume_point_entries.vp_type'],
                'N' => ['header' => 'Order Set', 'field' => 'order_set', 'role' => 'volume_point_entries.order_set'],
            ],
        ],

        'First & Second Set' => [
            'target' => 'orders + future order_items',
            'normalization_status' => 'partial',
            'identity' => ['name' => 'I', 'date' => 'G', 'order_set' => 'H'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Order Level', 'field' => 'order_level', 'role' => 'metadata'],
                'C' => ['header' => 'Ordered By', 'field' => 'ordered_by', 'role' => 'metadata'],
                'D' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'E' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'F' => ['header' => 'Week', 'field' => 'week', 'role' => 'metadata'],
                'G' => ['header' => 'Date', 'field' => 'order_date', 'role' => 'orders.order_date', 'transform' => 'excel_date'],
                'H' => ['header' => 'Order Set', 'field' => 'order_set', 'role' => 'orders.order_type'],
                'I' => ['header' => 'Name', 'field' => 'member_name', 'role' => 'member_relation'],
                'J' => ['header' => 'Sponser', 'field' => 'sponsor_name', 'role' => 'member_relation'],
                'K' => ['header' => 'UMS Amount', 'field' => 'ums_amount_label', 'role' => 'metadata'],
                'L' => ['header' => 'UMS Type', 'field' => 'ums_type', 'role' => 'metadata'],
                'M' => ['header' => 'Formula-1', 'field' => 'first_formula1', 'role' => 'future_order_item'],
                'N' => ['header' => 'Afresh', 'field' => 'first_afresh', 'role' => 'future_order_item'],
                'O' => ['header' => 'Shaker Cup', 'field' => 'shaker_cup', 'role' => 'future_order_item'],
                'P' => ['header' => 'Formula-1', 'field' => 'second_formula1', 'role' => 'future_order_item'],
                'Q' => ['header' => 'Afresh', 'field' => 'second_afresh', 'role' => 'future_order_item'],
                'R' => ['header' => 'Order Amount', 'field' => 'order_amount_primary', 'role' => 'financial_review', 'transform' => 'decimal'],
                'S' => ['header' => 'Profit', 'field' => 'profit_primary', 'role' => 'orders.profit_amount', 'transform' => 'decimal'],
                'U' => ['header' => 'Order Amount', 'field' => 'order_amount_mirror', 'role' => 'comparison_only', 'transform' => 'decimal'],
                'V' => ['header' => 'Profit', 'field' => 'profit_mirror', 'role' => 'comparison_only', 'transform' => 'decimal'],
                'W' => ['header' => '', 'field' => 'legacy_ad_hoc_formula', 'role' => 'raw_only'],
            ],
            'notes' => 'R/S and U/V are duplicated financial headings. U/V are treated as comparison mirrors; W contains isolated ad-hoc formula values and must never drive imports.',
        ],

        'Active UMS Month_Wise' => [
            'target' => 'ums_activity_snapshots',
            'normalization_status' => 'support_table',
            'identity' => ['name' => 'D', 'year' => 'B', 'month' => 'C'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Year', 'field' => 'snapshot_year', 'role' => 'ums_activity_snapshots.snapshot_year', 'transform' => 'integer'],
                'C' => ['header' => 'Month', 'field' => 'snapshot_month', 'role' => 'ums_activity_snapshots.snapshot_month'],
                'D' => ['header' => 'Customer Name', 'field' => 'member_name', 'role' => 'member_relation'],
            ],
        ],

        'Renewal UMS' => [
            'target' => 'renewals',
            'normalization_status' => 'ready',
            'identity' => ['name' => 'B', 'date' => 'F', 'ums_type' => 'E'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Customer Name', 'field' => 'member_name', 'role' => 'member_relation'],
                'C' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'D' => ['header' => 'UMS Month', 'field' => 'month', 'role' => 'context'],
                'E' => ['header' => 'UMS Type', 'field' => 'ums_type_label', 'role' => 'metadata'],
                'F' => ['header' => 'Date', 'field' => 'renewal_date', 'role' => 'renewals.renewal_date', 'transform' => 'excel_date'],
                'G' => ['header' => 'Supervisor', 'field' => 'supervisor_name', 'role' => 'member_relation'],
                'H' => ['header' => 'Team', 'field' => 'team_label', 'role' => 'metadata'],
                'I' => ['header' => '', 'field' => 'legacy_helper', 'role' => 'ignore'],
            ],
            'notes' => 'UMS Type contains both monetary and VP-based labels; amount parsing will be a separate validated rule instead of guessing during raw import.',
        ],

        'Monthely_Income' => [
            'target' => 'income_entries',
            'normalization_status' => 'ready',
            'identity' => ['year' => 'B', 'month' => 'C'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'C' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'D' => ['header' => 'Retail Income', 'field' => 'retail_income', 'role' => 'income_entries:retail', 'transform' => 'decimal'],
                'E' => ['header' => 'Check Income', 'field' => 'check_income', 'role' => 'income_entries:check', 'transform' => 'decimal'],
                'F' => ['header' => 'Club Income', 'field' => 'club_income', 'role' => 'income_entries:club', 'transform' => 'decimal'],
                'G' => ['header' => 'Total Income', 'field' => 'source_total_income', 'role' => 'validation_only', 'transform' => 'decimal'],
            ],
            'notes' => 'Total Income is preserved from the source for reconciliation but is not imported as a fourth income fact, preventing double counting.',
        ],

        'Royalty_Tracking' => [
            'target' => 'royalty_entries',
            'normalization_status' => 'ready',
            'identity' => ['year' => 'B', 'month' => 'C', 'week' => 'D'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'C' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'D' => ['header' => 'Week', 'field' => 'week', 'role' => 'royalty_entries.period_label'],
                'E' => ['header' => 'Royalty', 'field' => 'royalty_amount', 'role' => 'royalty_entries.amount', 'transform' => 'decimal'],
                'F' => ['header' => '', 'field' => 'legacy_blank_column', 'role' => 'ignore'],
            ],
        ],

        'Extra Order for Customer' => [
            'target' => 'orders + future order_items',
            'normalization_status' => 'partial',
            'identity' => ['name' => 'E', 'date' => 'G', 'volume_points' => 'H'],
            'columns' => [
                'A' => ['header' => 'Sr.No.', 'field' => 'source_serial', 'role' => 'raw_only'],
                'B' => ['header' => 'Year', 'field' => 'year', 'role' => 'context'],
                'C' => ['header' => 'Month', 'field' => 'month', 'role' => 'context'],
                'D' => ['header' => 'Week', 'field' => 'week', 'role' => 'metadata'],
                'E' => ['header' => 'Name', 'field' => 'member_name', 'role' => 'member_relation'],
                'F' => ['header' => 'Sponsor', 'field' => 'sponsor_name', 'role' => 'member_relation'],
                'G' => ['header' => 'Date', 'field' => 'order_date', 'role' => 'orders.order_date', 'transform' => 'excel_date'],
                'H' => ['header' => 'Volume Points', 'field' => 'volume_points', 'role' => 'orders.volume_points', 'transform' => 'decimal'],
                'I' => ['header' => 'Formula-1', 'field' => 'formula1_products', 'role' => 'future_order_item'],
                'J' => ['header' => 'Afresh', 'field' => 'afresh_products', 'role' => 'future_order_item'],
                'K' => ['header' => 'Protein Powder', 'field' => 'protein_products', 'role' => 'future_order_item'],
                'L' => ['header' => 'Dinoshake', 'field' => 'dinoshake_products', 'role' => 'future_order_item'],
                'M' => ['header' => 'Other Products', 'field' => 'other_products', 'role' => 'future_order_item'],
                'N' => ['header' => 'Received Amount', 'field' => 'received_amount', 'role' => 'financial_review', 'transform' => 'decimal'],
                'O' => ['header' => 'Order Amount', 'field' => 'order_amount', 'role' => 'financial_review', 'transform' => 'decimal'],
                'P' => ['header' => 'Profit', 'field' => 'profit_amount', 'role' => 'orders.profit_amount', 'transform' => 'decimal'],
            ],
            'notes' => 'Product columns are preserved now and will later resolve to the Product & Price module. Received Amount and Order Amount remain explicitly named until final order-finance semantics are approved.',
        ],
    ],

    'schema_support_required' => [
        'volume_point_entries' => 'Dedicated VP fact table; prevents VP-only rows from being forced into generic orders.',
        'ums_activity_snapshots' => 'Month-wise active UMS source facts; preserves the Google Form dataset without inventing UMS dates.',
    ],

    'global_safety' => [
        'raw_first' => true,
        'database_write_enabled' => false,
        'member_match_order' => ['mobile_exact', 'normalized_name_exact', 'manual_review'],
        'legacy_row_key' => 'workbook_sha256 + sheet_name + excel_row_number',
        'duplicate_strategy' => 'idempotent raw capture; normalized mapping references source_record_id',
    ],
];
