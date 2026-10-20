<?php
// SPDX-License-Identifier: GPL-2.0-or-later
chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/license_check.php');
require_once(__DIR__ . '/lib/functions.php');

if (!api_user_realm_auth('cereus_datasync.php')) {
    header('Location: ../../index.php');
    exit;
}

if (!cereus_datasync_license_ok()) {
    top_header();
    cereus_datasync_license_wall();
    bottom_footer();
    exit;
}

$action     = get_nfilter_request_var('action', '');
$profile_id = get_filter_request_var('profile_id', FILTER_VALIDATE_INT) ?: 0;
$run_id     = get_filter_request_var('run_id', FILTER_VALIDATE_INT) ?: 0;

cereus_datasync_fail_stale_runs();

switch ($action) {
    case 'view':
        top_header();
        cereus_datasync_log_detail($run_id);
        bottom_footer();
        break;
    case 'purge':
        cereus_datasync_log_purge($profile_id);
        header('Location: cereus_datasync_log.php?profile_id=' . $profile_id);
        exit;
    default:
        top_header();
        cereus_datasync_log_list($profile_id);
        bottom_footer();
}

// ─── Run list ─────────────────────────────────────────────────────────────────

function cereus_datasync_log_list(int $profileId): void {
    $profile = $profileId ? cereus_datasync_get_profile($profileId) : null;
    $page    = get_request_var('page', 1);
    $rows    = 25;

    $sql_where  = $profileId ? 'WHERE profile_id = ?' : '';
    $sql_params = $profileId ? [$profileId] : [];

    $total = (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_cds_runs $sql_where", $sql_params);
    $items = db_fetch_assoc_prepared(
        "SELECT * FROM plugin_cds_runs $sql_where ORDER BY started_at DESC LIMIT " . (($page - 1) * $rows) . ", $rows",
        $sql_params
    );

    $title = $profile
        ? __('Sync Log — %s', html_escape($profile['name']), 'cereus_datasync')
        : __('Sync Log — All Profiles', 'cereus_datasync');

    html_start_box($title, '100%', '', '3', 'center', '');
    print '<tr class="even"><td style="padding:6px 15px;">';
    print '<span style="float:right;">' . cereus_datasync_help_link('5-running-a-sync') . '</span>';
    print '<a href="cereus_datasync.php" class="cds-link">&laquo; ' . __('Back to Profiles', 'cereus_datasync') . '</a>';
    if ($profileId) {
        print ' &nbsp; <a href="cereus_datasync_log.php?action=purge&profile_id=' . $profileId . '" class="cds-link-danger" onclick="return confirm(\'' . __('Delete all run history for this profile?', 'cereus_datasync') . '\')">' . __('Purge Log', 'cereus_datasync') . '</a>';
    }
    print '</td></tr>';
    html_end_box();

    $nav = html_nav_bar('cereus_datasync_log.php?profile_id=' . $profileId, MAX_DISPLAY_PAGES, $page, $rows, $total, 10, __('Runs', 'cereus_datasync'));
    print $nav;

    html_start_box('', '100%', '', '3', 'center', '');

    $header = [
        'started_at'  => ['display' => __('Started', 'cereus_datasync'),  'sort' => 'DESC'],
        'nosort0'     => ['display' => __('Profile', 'cereus_datasync')],
        'nosort1'     => ['display' => __('Status', 'cereus_datasync')],
        'nosort2'     => ['display' => __('Excel / Cacti', 'cereus_datasync')],
        'nosort3'     => ['display' => __('Added', 'cereus_datasync')],
        'nosort4'     => ['display' => __('Updated', 'cereus_datasync')],
        'nosort5'     => ['display' => __('Marked Del.', 'cereus_datasync')],
        'nosort6'     => ['display' => __('Skipped', 'cereus_datasync')],
        'nosort7'     => ['display' => __('Failed', 'cereus_datasync')],
        'nosort8'     => ['display' => __('Tree', 'cereus_datasync')],
        'nosort11'    => ['display' => __('Rules', 'cereus_datasync')],
        'nosort9'     => ['display' => __('Duration', 'cereus_datasync')],
        'nosort10'    => ['display' => ''],
    ];
    html_header_sort_checkbox($header, 'started_at', 'DESC');

    if (cacti_sizeof($items)) {
        foreach ($items as $row) {
            $duration = '';
            if ($row['completed_at'] && $row['started_at']) {
                $secs = strtotime($row['completed_at']) - strtotime($row['started_at']);
                $duration = $secs < 60 ? $secs . 's' : floor($secs / 60) . 'm ' . ($secs % 60) . 's';
            }

            form_alternate_row('cds_run' . $row['id'], true);
            form_selectable_cell(html_escape($row['started_at']), $row['id']);
            form_selectable_cell(html_escape($row['profile_name']) . ($row['dry_run'] ? ' <em style="color:#7c3aed;">(dry)</em>' : ''), $row['id']);
            form_selectable_cell(cereus_datasync_status_badge($row['status']), $row['id']);
            $rawTotal = (int)($row['excel_raw_total'] ?? 0);
            $excelStr = $rawTotal > 0
                ? $rawTotal . ' raw / ' . $row['excel_total'] . ' valid'
                : $row['excel_total'];
            if (!empty($row['excel_skipped_load'])) {
                $excelStr .= ' <span style="color:#dc2626;font-size:11px;">(' . $row['excel_skipped_load'] . ' dropped)</span>';
            }
            $excelStr .= ' / ' . $row['cacti_total'] . ' Cacti';
            form_selectable_cell($excelStr, $row['id']);
            form_selectable_cell('<span style="color:' . ($row['added'] > 0 ? '#15803d' : '#888') . ';font-weight:600;">' . $row['added'] . '</span>', $row['id']);
            form_selectable_cell($row['updated'], $row['id']);
            form_selectable_cell($row['marked_deleted'], $row['id']);
            form_selectable_cell($row['skipped'], $row['id']);
            form_selectable_cell('<span style="color:' . ($row['failed'] > 0 ? '#dc2626' : '#888') . ';font-weight:600;">' . $row['failed'] . '</span>', $row['id']);
            form_selectable_cell($row['tree_placed'], $row['id']);
            form_selectable_cell(cereus_datasync_log_rule_summary(cereus_datasync_run_action_counts((int)$row['id'])), $row['id']);
            form_selectable_cell($duration, $row['id']);
            print '<td style="text-align:right;white-space:nowrap;">';
            print '<a href="cereus_datasync_log.php?action=view&run_id=' . $row['id'] . '" class="cds-link">' . __('Details', 'cereus_datasync') . '</a>';
            print '</td>';
            form_end_row();
        }
    } else {
        print '<tr><td colspan="14"><em>' . __('No sync runs yet.', 'cereus_datasync') . '</em></td></tr>';
    }

    html_end_box(false);
    print $nav;
}

// ─── Run detail view ──────────────────────────────────────────────────────────

function cereus_datasync_log_detail(int $runId): void {
    $run = db_fetch_row_prepared('SELECT * FROM plugin_cds_runs WHERE id = ?', [$runId]);
    if (!$run) {
        print '<p>' . __('Run not found.', 'cereus_datasync') . '</p>';
        return;
    }

    $actionFilter = get_nfilter_request_var('af', '');
    $page         = get_request_var('page', 1);
    $rows         = 50;

    $sql_where  = 'WHERE run_id = ?';
    $sql_params = [$runId];
    if (!empty($actionFilter)) {
        // A dry run logs "added_dry" etc.; the card for "added" lists those too.
        $sql_where  .= ' AND action IN (?, ?)';
        $sql_params[] = $actionFilter;
        $sql_params[] = $actionFilter . '_dry';
    }

    $total = (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_cds_run_details $sql_where", $sql_params);
    $items = db_fetch_assoc_prepared(
        "SELECT * FROM plugin_cds_run_details $sql_where ORDER BY id LIMIT " . (($page - 1) * $rows) . ", $rows",
        $sql_params
    );

    // ── Dashboard header ─────────────────────────────────────────────────────
    $duration = '';
    if ($run['completed_at'] && $run['started_at']) {
        $secs = strtotime($run['completed_at']) - strtotime($run['started_at']);
        $duration = $secs < 60 ? $secs . 's' : floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    }

    // [action, run column, label, colour]. Without a column the count is the
    // number of log entries for the action; without an action the row is not a filter.
    $sections = [
        'input' => [__('Inventory File', 'cereus_datasync'), [
            [null,             'excel_raw_total',    __('Rows in file', 'cereus_datasync'),        '#0f172a'],
            [null,             'excel_total',        __('Valid rows', 'cereus_datasync'),          '#15803d'],
            ['load_skip',      'excel_skipped_load', __('Dropped rows', 'cereus_datasync'),        '#ea580c'],
            [null,             'cacti_total',        __('Devices in Cacti', 'cereus_datasync'),    '#0f172a'],
        ]],
        'devices' => [__('Devices', 'cereus_datasync'), [
            ['added',          'added',              __('Added', 'cereus_datasync'),               '#15803d'],
            ['updated',        'updated',            __('Updated', 'cereus_datasync'),             '#2563eb'],
            ['marked_deleted', 'marked_deleted',     __('Marked for deletion', 'cereus_datasync'), '#d97706'],
            ['skipped',        'skipped',            __('Skipped', 'cereus_datasync'),             '#475569'],
            ['failed',         'failed',             __('Failed', 'cereus_datasync'),              '#dc2626'],
        ]],
        'graphs' => [__('Graphs', 'cereus_datasync'), [
            [null,             'graphs_found',       __('WAN interfaces found', 'cereus_datasync'), '#0369a1'],
            ['graph_created',  'graphs_created',     __('Graphs created', 'cereus_datasync'),      '#0891b2'],
            ['tree_placed',    'tree_placed',        __('Placed on a tree', 'cereus_datasync'),    '#7c3aed'],
            ['graph_skip',     null,                 __('Graphs skipped', 'cereus_datasync'),      '#475569'],
            ['graph_error',    null,                 __('Graph errors', 'cereus_datasync'),        '#dc2626'],
        ]],
        'rules' => [__('Tree Rules & Branches', 'cereus_datasync'), [
            ['auto_rule',         null, __('Rules created', 'cereus_datasync'),       '#7c3aed'],
            ['auto_rule_updated', null, __('Rules updated', 'cereus_datasync'),       '#2563eb'],
            ['auto_rule_exists',  null, __('Rules already exist', 'cereus_datasync'), '#475569'],
            ['auto_rule_skip',    null, __('Rules skipped', 'cereus_datasync'),       '#d97706'],
            ['coreq_ok',          null, __('Co-req groups met', 'cereus_datasync'),   '#0d9488'],
            ['branch_created',    null, __('Branches created', 'cereus_datasync'),    '#15803d'],
            ['tree_moved',        null, __('Placements moved', 'cereus_datasync'),    '#9333ea'],
            ['tree_skip',         null, __('Host rules deferred', 'cereus_datasync'), '#475569'],
            ['tree_error',        null, __('Tree errors', 'cereus_datasync'),         '#dc2626'],
        ]],
        'aggoid' => [__('Aggregate & OID Rules', 'cereus_datasync'), [
            ['agg_done',  null, __('Aggregates built', 'cereus_datasync'),   '#0891b2'],
            ['agg_skip',  null, __('Aggregates skipped', 'cereus_datasync'), '#d97706'],
            ['agg_error', null, __('Aggregate errors', 'cereus_datasync'),   '#dc2626'],
            ['oid_done',  null, __('OID rules run', 'cereus_datasync'),      '#0891b2'],
            ['oid_skip',  null, __('OID rules skipped', 'cereus_datasync'),  '#d97706'],
            ['oid_error', null, __('OID errors', 'cereus_datasync'),         '#dc2626'],
        ]],
        'cleanup' => [__('Cleanup', 'cereus_datasync'), [
            ['empty_site',    'empty_sites_marked', __('Empty sites', 'cereus_datasync'),      '#b45309'],
            ['empty_tree',    'empty_trees_marked', __('Empty branches', 'cereus_datasync'),   '#7c3aed'],
            ['site_unmarked', 'sites_unmarked',     __('Sites revived', 'cereus_datasync'),    '#0d9488'],
            ['tree_unmarked', 'trees_unmarked',     __('Branches revived', 'cereus_datasync'), '#0d9488'],
        ]],
    ];

    // The spark charts compare this run with the profile's earlier runs of the
    // same kind (sync, dry run or Apply Rules), oldest first.
    $history = cereus_datasync_log_history($run);

    // Anything logged that no row covers still gets one.
    $known = [];
    foreach ($sections as [, $rowsDef]) {
        foreach ($rowsDef as $r) {
            $known[] = $r[0];
        }
    }
    $other = [];
    foreach (array_diff(array_keys($history['details'][$runId] ?? []), $known) as $extra) {
        $other[] = [$extra, null, $extra, '#475569'];
    }
    if (!empty($other)) {
        $sections['other'] = [__('Other Events', 'cereus_datasync'), $other];
    }

    html_start_box('', '100%', '', '3', 'center', '');

    // Back link + run header bar
    print '<tr class="even"><td style="padding:10px 16px 4px;">';
    print '<a href="cereus_datasync_log.php?profile_id=' . $run['profile_id'] . '" class="cds-link">&laquo; Back to Log</a>';
    print '</td></tr>';

    print '<tr class="even"><td style="padding:6px 16px 16px;">';

    // Run meta bar
    $statusColors = ['completed' => '#15803d', 'failed' => '#dc2626', 'running' => '#2563eb', 'queued' => '#64748b'];
    $sc = $statusColors[$run['status']] ?? '#64748b';
    print '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:10px;font-size:13px;">';
    print '<strong style="font-size:14px;">' . html_escape($run['profile_name']) . '</strong>';
    print '<span style="color:#64748b;">' . html_escape($run['started_at']) . '</span>';
    print '<span style="padding:2px 10px;border-radius:12px;background:' . $sc . ';color:#fff;font-size:12px;font-weight:600;">' . strtoupper($run['status']) . ($run['dry_run'] ? ' · DRY' : '') . '</span>';
    if ($duration) print '<span style="color:#64748b;">&#9200; ' . $duration . '</span>';
    if ($run['error_message']) {
        print '<span style="color:#dc2626;font-size:12px;">&#9888; ' . html_escape(substr($run['error_message'], 0, 120)) . '</span>';
    }
    print '</div>';

    if ($run['dry_run']) {
        $unchecked = (int)db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_cds_run_details
             WHERE run_id = ? AND action = 'added_dry' AND details LIKE 'dry-run — interface check not run%'",
            [$runId]
        );
        print '<div style="padding:8px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:12px;color:#7c2d12;margin-bottom:10px;">';
        print __('Dry run: nothing was changed. The counts show what a real run would do.', 'cereus_datasync');
        if ($unchecked > 0) {
            print ' ' . __('%d of the devices counted as added still need the SNMP interface check, which dry runs skip — a real run adds them only if an interface matches.', $unchecked, 'cereus_datasync');
        }
        print '</div>';
    }

    cereus_datasync_log_stats($runId, $run, $sections, $history, $actionFilter);

    // Active filter pill
    if ($actionFilter !== '') {
        print '<div style="margin-top:12px;padding:6px 12px;background:#f1f5f9;border-radius:6px;font-size:12px;color:#475569;display:inline-block;">';
        print '&#128269; Filtered by: <strong>' . html_escape($actionFilter) . '</strong> ';
        print '<a href="cereus_datasync_log.php?action=view&run_id=' . $runId . '" style="color:#dc2626;font-weight:600;">&times; Clear</a>';
        print '</div>';
    }

    print '</td></tr>';
    html_end_box();

    // Detail table
    $nav = html_nav_bar('cereus_datasync_log.php?action=view&run_id=' . $runId . '&af=' . urlencode($actionFilter), MAX_DISPLAY_PAGES, $page, $rows, $total, 5, __('Events', 'cereus_datasync'));
    print $nav;

    html_start_box('', '100%', '', '3', 'center', '');

    $actionColors = [
        'added'              => '#15803d',
        'added_dry'          => '#16a34a80',
        'updated'            => '#2563eb',
        'updated_dry'        => '#2563eb80',
        'marked_deleted'     => '#d97706',
        'marked_deleted_dry' => '#d9780680',
        'skipped'            => '#64748b',
        'failed'             => '#dc2626',
        'load_skip'          => '#f97316',
        'graph_skip'         => '#94a3b8',
        'graph_error'        => '#dc2626',
        'graph_created'      => '#0891b2',
        'auto_rule'          => '#7c3aed',
        'auto_rule_updated'  => '#2563eb',
        'auto_rule_exists'   => '#64748b',
        'auto_rule_skip'     => '#d97706',
        'coreq_ok'           => '#0d9488',
        'branch_created'     => '#15803d',
        'tree_skip'          => '#94a3b8',
        'tree_error'         => '#dc2626',
        'agg_done'           => '#0891b2',
        'agg_skip'           => '#d97706',
        'agg_error'          => '#dc2626',
        'oid_done'           => '#0891b2',
        'oid_skip'           => '#d97706',
        'oid_error'          => '#dc2626',
        'tree_placed'        => '#7c3aed',
        'tree_moved'         => '#9333ea',
        'empty_site'         => '#b45309',
        'empty_site_dry'     => '#b4530980',
        'empty_tree'         => '#7c3aed',
        'empty_tree_dry'     => '#7c3aed80',
        'site_unmarked'      => '#0d9488',
        'site_unmarked_dry'  => '#0d948880',
        'tree_unmarked'      => '#0d9488',
        'tree_unmarked_dry'  => '#0d948880',
    ];

    $header = [
        'device_hostname' => ['display' => __('Hostname', 'cereus_datasync'), 'sort' => 'ASC'],
        'device_ip'       => ['display' => __('IP', 'cereus_datasync'),       'sort' => 'ASC'],
        'nosort0'         => ['display' => __('Action', 'cereus_datasync')],
        'nosort1'         => ['display' => __('Device ID', 'cereus_datasync')],
        'nosort2'         => ['display' => __('Details', 'cereus_datasync')],
    ];
    html_header_sort_checkbox($header, 'device_hostname', 'ASC');

    if (cacti_sizeof($items)) {
        foreach ($items as $item) {
            $color = $actionColors[$item['action']] ?? '#888';
            form_alternate_row('cds_det' . $item['id'], false);
            form_selectable_cell(html_escape($item['device_hostname']), $item['id']);
            form_selectable_cell(html_escape($item['device_ip']), $item['id']);
            print '<td><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $color . ';color:#fff;font-size:11px;">' . html_escape($item['action']) . '</span></td>';
            form_selectable_cell($item['device_id'] ? '<a href="../../host.php?action=edit&id=' . $item['device_id'] . '" class="linkEditMain">' . $item['device_id'] . '</a>' : '—', $item['id']);
            form_selectable_cell(html_escape($item['details']), $item['id'], '', 'cds-rpt-details');
            form_end_row();
        }
    } else {
        print '<tr><td colspan="5"><em>' . __('No detail records for this filter.', 'cereus_datasync') . '</em></td></tr>';
    }

    html_end_box(false);
    print $nav;
}

// "2 new · 5 exist · 1 skipped" for the run list; empty when no rule ran.
function cereus_datasync_log_rule_summary(array $counts): string {
    $parts = [];
    foreach ([
        'auto_rule'         => ['new', '#7c3aed'],
        'auto_rule_updated' => ['updated', '#2563eb'],
        'auto_rule_exists'  => ['exist', '#64748b'],
        'auto_rule_skip'    => ['skipped', '#d97706'],
        'branch_created'    => ['branches', '#15803d'],
    ] as $key => [$label, $color]) {
        if (!empty($counts[$key])) {
            $parts[] = '<span style="color:' . $color . ';white-space:nowrap;">' . $counts[$key] . ' ' . $label . '</span>';
        }
    }

    return implode(' &middot; ', $parts);
}

// The profile's last runs of the same kind as $run, up to and including it:
// ['runs' => run rows oldest first, 'details' => run id => action => log entries].
function cereus_datasync_log_history(array $run, int $limit = 20): array {
    $rulesOnly = ($run['excel_file'] === '(rules only)');

    $runs = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_runs
          WHERE profile_id = ? AND id <= ? AND dry_run = ? AND status = \'completed\'
            AND ' . ($rulesOnly ? "excel_file = '(rules only)'" : "excel_file <> '(rules only)'") . '
          ORDER BY id DESC LIMIT ' . $limit,
        [(int)$run['profile_id'], (int)$run['id'], (int)$run['dry_run']]
    );
    $runs = array_reverse(cacti_sizeof($runs) ? $runs : []);

    // The run itself, even when it failed or is still going.
    if (!cacti_sizeof($runs) || (int)end($runs)['id'] !== (int)$run['id']) {
        $runs[] = $run;
    }

    $details = [];
    $ids     = array_map(function($r) { return (int)$r['id']; }, $runs);
    $rows    = db_fetch_assoc(
        'SELECT run_id, action, COUNT(*) AS cnt FROM plugin_cds_run_details
          WHERE run_id IN (' . implode(',', $ids) . ') GROUP BY run_id, action'
    );
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $key = preg_replace('/_dry$/', '', (string)$r['action']);
            $details[(int)$r['run_id']][$key] = ($details[(int)$r['run_id']][$key] ?? 0) + (int)$r['cnt'];
        }
    }

    return ['runs' => $runs, 'details' => $details];
}

// The collapsible statistics block: one compact row per counter, with a spark
// chart of the same counter over the earlier runs. Rows that are 0 now and were
// 0 in every earlier run are folded away behind "show all".
function cereus_datasync_log_stats(int $runId, array $run, array $sections, array $history, string $actionFilter): void {
    $value = function(array $r, ?string $key, ?string $column) use ($history): int {
        if ($column !== null) return (int)($r[$column] ?? 0);

        return (int)($history['details'][(int)$r['id']][$key] ?? 0);
    };

    $highlights = [];
    $hidden     = 0;
    $html       = '';

    foreach ($sections as $id => [$title, $rowsDef]) {
        $html .= '<div class="cds-st-sec"><div class="cds-st-title">' . html_escape($title) . '</div>';
        if ($id === 'rules' && $run['dry_run']) {
            $html .= '<div class="cds-st-note">' . __('Dry runs do not evaluate rule templates.', 'cereus_datasync') . '</div>';
        }

        foreach ($rowsDef as [$key, $column, $label, $color]) {
            $series = [];
            foreach ($history['runs'] as $r) {
                $series[] = $value($r, $key, $column);
            }
            $now     = (int)end($series);
            $entries = $key === null ? 0 : (int)($history['details'][$runId][$key] ?? 0);
            $empty   = !$now && !$entries && !array_sum($series);
            $active  = ($key !== null && $actionFilter === $key);

            if ($empty) $hidden++;
            if ($now && $id !== 'input') {
                // "Rules created" reads "rules created"; "OID errors" stays as it is.
                $lower        = preg_match('/^[A-Z]{2}/', $label) ? $label : lcfirst($label);
                $highlights[] = '<span style="color:' . $color . ';">' . $now . '</span> ' . html_escape($lower);
            }

            $class = 'cds-st-row' . ($now ? '' : ' cds-st-zero') . ($empty ? ' cds-st-empty' : '') . ($active ? ' cds-st-active' : '');
            $cells = '<span class="cds-st-dot" style="background:' . $color . ';"></span>'
                . '<span class="cds-st-lbl">' . html_escape($label) . '</span>'
                . '<span class="cds-st-num">' . $now . '</span>'
                . cereus_datasync_log_spark($series, $color, $history['runs']);

            if ($key !== null && ($entries || $active)) {
                $href  = 'cereus_datasync_log.php?action=view&run_id=' . $runId . '&af=' . ($active ? '' : urlencode($key));
                $html .= '<a class="' . $class . '" href="' . $href . '" title="' . html_escape($active ? __('Show all events', 'cereus_datasync') : __('Show these %d events', $entries, 'cereus_datasync')) . '">' . $cells . '</a>';
            } else {
                $html .= '<div class="' . $class . '">' . $cells . '</div>';
            }
        }

        $html .= '</div>';
    }

    $runCount = cacti_sizeof($history['runs']);

    cereus_datasync_log_report_css();

    print '<details class="cds-st" id="cds-stats" open>';
    print '<summary><span class="cds-st-caret">&#9656;</span><strong>' . __('Statistics', 'cereus_datasync') . '</strong>';
    print '<span class="cds-st-sum">' . (cacti_sizeof($highlights) ? implode(' &middot; ', array_slice($highlights, 0, 6)) : __('nothing changed', 'cereus_datasync')) . '</span>';
    print '<span class="cds-st-hint">' . __('trend: last %d runs of this kind', $runCount, 'cereus_datasync') . '</span>';
    print '</summary>';
    print '<div class="cds-st-body">' . $html . '</div>';
    if ($hidden) {
        print '<div class="cds-st-foot"><a href="#" id="cds-st-toggle" data-more="' . html_escape(__('Show %d empty counters', $hidden, 'cereus_datasync')) . '" data-less="' . html_escape(__('Hide empty counters', 'cereus_datasync')) . '">' . __('Show %d empty counters', $hidden, 'cereus_datasync') . '</a></div>';
    }
    print '</details>';
    ?>
    <script>
    (function() {
        var box = document.getElementById('cds-stats');
        var key = 'cds_stats_state';
        var st  = {};
        try { st = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) {}

        if (st.closed) box.removeAttribute('open');
        if (st.all) box.classList.add('cds-st-all');

        function save() {
            try { localStorage.setItem(key, JSON.stringify(st)); } catch (e) {}
        }

        box.addEventListener('toggle', function() {
            st.closed = !box.open;
            save();
        });

        var t = document.getElementById('cds-st-toggle');
        function label() {
            if (t) t.textContent = box.classList.contains('cds-st-all') ? t.getAttribute('data-less') : t.getAttribute('data-more');
        }
        label();
        if (t) t.addEventListener('click', function(e) {
            e.preventDefault();
            box.classList.toggle('cds-st-all');
            st.all = box.classList.contains('cds-st-all');
            save();
            label();
        });
    })();
    </script>
    <?php
}

// An inline SVG spark chart of $series, with this run (the last point) marked.
function cereus_datasync_log_spark(array $series, string $color, array $runs): string {
    $w = 64;
    $h = 16;
    $n = count($series);
    $max = max(1, max($series));

    $title = [];
    foreach ($runs as $i => $r) {
        $title[] = substr((string)$r['started_at'], 0, 16) . ': ' . $series[$i];
    }
    $title = html_escape(implode("\n", array_slice($title, -8)));

    $pts = [];
    foreach ($series as $i => $v) {
        $x = $n > 1 ? round($i * ($w - 4) / ($n - 1) + 2, 1) : $w - 2;
        $y = round($h - 2 - ($v / $max) * ($h - 4), 1);
        $pts[] = $x . ',' . $y;
    }
    [$lx, $ly] = explode(',', end($pts));
    $stroke = array_sum($series) ? $color : '#cbd5e1';

    return '<svg class="cds-st-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img"><title>' . $title . '</title>'
        . ($n > 1 ? '<polyline fill="none" stroke="' . $stroke . '" stroke-width="1.3" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>' : '')
        . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2" fill="' . $stroke . '"/></svg>';
}

function cereus_datasync_log_report_css(): void {
    ?>
    <style>
    .cds-st{background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-top:4px;}
    .cds-st summary{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:8px 12px;cursor:pointer;list-style:none;font-size:12px;color:#0f172a;user-select:none;}
    .cds-st summary::-webkit-details-marker{display:none;}
    .cds-st-caret{display:inline-block;width:10px;color:#64748b;font-size:12px;line-height:1;text-align:center;transition:transform .15s;}
    .cds-st[open] .cds-st-caret{transform:rotate(90deg);}
    .cds-st-sum{color:#475569;font-weight:600;}
    .cds-st-hint{margin-left:auto;color:#94a3b8;font-size:11px;}
    .cds-st-body{columns:230px;column-gap:20px;padding:4px 12px 8px;border-top:1px solid #f1f5f9;}
    .cds-st-sec{break-inside:avoid;padding-top:6px;margin-bottom:6px;}
    .cds-st-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:2px;}
    .cds-st-note{font-size:10px;color:#94a3b8;margin-bottom:2px;}
    .cds-st-row{display:flex;align-items:center;gap:6px;height:19px;padding:0 4px;border-radius:4px;font-size:12px;color:#0f172a;text-decoration:none;}
    a.cds-st-row:hover{background:#f1f5f9;}
    .cds-st-dot{width:6px;height:6px;border-radius:50%;flex:0 0 6px;}
    .cds-st-lbl{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .cds-st-num{font-weight:700;min-width:28px;text-align:right;font-variant-numeric:tabular-nums;}
    .cds-st-spark{flex:0 0 64px;}
    .cds-st-zero{color:#94a3b8;}
    .cds-st-zero .cds-st-dot{background:#e2e8f0 !important;}
    .cds-st-empty{display:none;}
    .cds-st-all .cds-st-empty{display:flex;}
    .cds-st-active{background:#0f172a;color:#fff;}
    a.cds-st-active:hover{background:#1e293b;}
    .cds-st-foot{padding:0 12px 8px;font-size:11px;}
    .cds-st-foot a{color:#64748b;}
    .cds-rpt-details{white-space:normal !important;word-break:break-word;}
    </style>
    <?php
}

// ─── Purge ────────────────────────────────────────────────────────────────────

function cereus_datasync_log_purge(int $profileId): void {
    if (!$profileId) return;
    $runIds = db_fetch_assoc_prepared('SELECT id FROM plugin_cds_runs WHERE profile_id = ?', [$profileId]);
    if (cacti_sizeof($runIds)) {
        foreach ($runIds as $r) {
            db_execute_prepared('DELETE FROM plugin_cds_run_details WHERE run_id = ?', [(int)$r['id']]);
        }
    }
    db_execute_prepared('DELETE FROM plugin_cds_runs WHERE profile_id = ?', [$profileId]);
    db_execute_prepared("UPDATE plugin_cds_profiles SET last_run_at = NULL, last_run_status = '', last_run_stats = NULL WHERE id = ?", [$profileId]);
}
