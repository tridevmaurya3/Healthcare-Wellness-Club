<?php
declare(strict_types=1);

/**
 * Reviewed formula-to-engine mapping for Master_Personal_Tracking.xlsx sheets 1-6.
 *
 * Important architecture rule:
 * - Sheets 7-14 are operational source facts and have already been normalized.
 * - Sheets 1-6 are NOT imported as facts. They are rebuilt from normalized data.
 * - Google Sheets functions (FILTER/QUERY/SEQUENCE) are translated into SQL/PHP.
 * - Legacy hard-coded owner names, exchange-rate calls and VP adjustments are exposed
 *   as versioned/configurable business rules instead of hidden spreadsheet magic.
 */
return [
    'version' => '1.0-reviewed-2026-08-18',
    'workbook' => 'Master_Personal_Tracking.xlsx',
    'formula_cell_count' => 280,
    'reports' => [
        'master_tracking' => [
            'sheet' => 'Master_Tracking',
            'formula_cells' => 130,
            'status' => 'reviewed_with_external_rules',
            'inputs' => ['member', 'year', 'month', 'week'],
            'sources' => ['volume_point_entries', 'orders', 'royalty_entries'],
            'logic' => [
                'Weekly personal VP by order type: Extra for Myself / Family, New UMS and Renewal UMS.',
                'Weekly extra-order VP from Extra Order for Customer.',
                'Weekly organization/team VP by discount level @15%, @25%, @35%, @42% and @50%, excluding the selected owner where the workbook does so.',
                'Monthly totals are sums of the four weekly buckets.',
                'Approximate check-income scenarios combine organization VP value, royalty percentages and non-supervisor VP values.',
            ],
            'legacy_rules' => [
                'Workbook hard-codes the owner name Kusum Maurya in several exclusion filters; engine must use a selected/canonical owner instead.',
                'Workbook calls GOOGLEFINANCE("CURRENCY:USDINR"); engine must use an explicit configurable exchange-rate source/rule.',
                'Legacy scratch/helper cells outside the core report are not business facts and must not drive calculations.',
            ],
        ],
        'sp_house' => [
            'sheet' => 'SP_House',
            'formula_cells' => 33,
            'status' => 'reviewed_with_legacy_vp_adjustments',
            'inputs' => ['member', 'year', 'month'],
            'sources' => ['volume_point_entries'],
            'logic' => [
                'Personal/family consumption, New UMS VP and Renewal UMS VP for the selected member and period.',
                'Unique first-line PC member list and each member\'s monthly VP.',
                'First-line Associate VP and team VP using VP From / Ordered By / VP Type source dimensions.',
                'Total monthly VP and supporting first-line summaries.',
            ],
            'legacy_rules' => [
                'Workbook contains legacy numeric VP adjustment formulas using specific values such as 62.8, 63.13, 70.6, 70.93, 33.25, 29.55, 33.58, 41.05 and 41.38.',
                'Those constants must become named, versioned calculation rules before the live engine is allowed to reproduce adjusted Renewal/Club VP.',
            ],
        ],
        'name_wise_tracking' => [
            'sheet' => 'Name_Wise_Tracking',
            'formula_cells' => 26,
            'status' => 'reviewed_with_legacy_vp_adjustments',
            'inputs' => ['member', 'year', 'month'],
            'sources' => ['volume_point_entries'],
            'logic' => [
                'Name-wise first-line PC and Associate lists with monthly VP per person.',
                'Personal consumption, Renewal UMS, First Line New UMS, First Line PC, First Line Associate and Club VP summary.',
                'Total VP for the selected month and owner.',
            ],
            'legacy_rules' => [
                'Uses the same legacy numeric VP-adjustment constants as SP_House and must share one versioned rule set.',
                'Workbook hard-codes Kusum Maurya in exclusions; live report must use the selected owner/member.',
            ],
        ],
        'master_business_tracking' => [
            'sheet' => 'Master_Business_Tracking',
            'formula_cells' => 37,
            'status' => 'reviewed_ready_for_engine',
            'inputs' => ['owner', 'year', 'month', 'team_filter'],
            'sources' => ['volume_point_entries', 'members', 'ums_records', 'ums_activity_snapshots', 'royalty_entries'],
            'logic' => [
                'PPV = selected owner monthly VP; DVP = organization monthly VP excluding selected owner; Total VP = PPV + DVP.',
                'Royalty = maximum royalty source value for the selected year/month, matching workbook behavior.',
                'Active UMS member list is derived from active New UMS/member records excluding Self where the workbook does so.',
                'Counts active UMS by first-line/Myself, Non-Supervisor and Supervisor team categories.',
                'Average per customer VP = (Total VP - Personal Consumption VP) / active monthly customer count.',
                'New UMS list supports year/month/team filtering.',
            ],
            'legacy_rules' => [
                'Workbook uses Kusum Maurya as the owner in PPV/DVP and personal-consumption filters; live engine must parameterize this.',
                'Team labels such as Myself, Supervisor and Non-Supervisor must be matched from preserved source metadata, not inferred from names.',
            ],
        ],
        'ums_renewal' => [
            'sheet' => 'UMS_Renewal',
            'formula_cells' => 14,
            'status' => 'reviewed_ready_for_engine',
            'inputs' => ['renewal_year', 'renewal_month', 'team_filter', 'pending_month', 'supervisor_filter'],
            'sources' => ['renewals', 'members', 'ums_records'],
            'logic' => [
                'Renewed list = Renewal UMS rows filtered by year, month and optional team.',
                'Pending renewal list = active UMS/member names not present in the renewed list for the selected context.',
                'Pending list excludes Self where the workbook does so and supports optional supervisor filtering.',
                'Sponsor/source-name snapshots are retained for unresolved identity links.',
            ],
            'legacy_rules' => [
                'Spreadsheet MATCH/FILTER logic is translated to anti-join/NOT EXISTS semantics.',
                'Identity remains preservation-first: no automatic member merge by name.',
            ],
        ],
        'ums_active_duration' => [
            'sheet' => 'UMS_Active_Duration',
            'formula_cells' => 37,
            'status' => 'reviewed_ready_for_engine',
            'inputs' => ['as_of_date'],
            'sources' => ['members', 'ums_records'],
            'logic' => [
                'Shows active UMS rows with Team of, Name, Sponsor, UMS Date and UMS status/type.',
                'Duration is calculated dynamically from UMS start date to the current/as-of date.',
                'Spreadsheet QUERY/SEQUENCE arrays become an ordinary ordered SQL result set.',
            ],
            'legacy_rules' => [
                'Duration must be calculated at view time; it is never stored as a static imported fact.',
                'The workbook approximates months with 30.44 days after whole years; engine should preserve this legacy display mode initially and may later offer calendar-exact duration as an additional view.',
            ],
        ],
    ],
    'cross_cutting_rules' => [
        'No derived sheet is copied into a database fact table.',
        'All reports are organization/club scoped.',
        'All owner/member references are parameters or verified links; hard-coded personal names are prohibited in the final engine.',
        'Legacy numeric adjustment constants must live in calculation_rules with version/effective dates.',
        'Currency conversion must use an explicit rate provider/configuration and record the applied rate/source.',
        'Unmatched member names remain traceable through source-name snapshots rather than guessed identity links.',
    ],
];
