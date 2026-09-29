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
            form_selectable_cell($duration, $row['id']);
            print '<td style="text-align:right;white-space:nowrap;">';
            print '<a href="cereus_datasync_log.php?action=view&run_id=' . $row['id'] . '" class="cds-link">' . __('Details', 'cereus_datasync') . '</a>';
            print '</td>';
            form_end_row();
        }
    } else {
        print '<tr><td colspan="13"><em>' . __('No sync runs yet.', 'cereus_datasync') . '</em></td></tr>';
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
    $rawTotal  = (int)($run['excel_raw_total']    ?? 0);
    $dropCount = (int)($run['excel_skipped_load'] ?? 0);
    $valid     = (int)$run['excel_total'];
    $dropPct   = $rawTotal > 0 ? round($dropCount / $rawTotal * 100) : 0;
    $validPct  = 100 - $dropPct;

    $devCards = [
        ['added',          $run['added'],                        'Added',       '#15803d', '#f0fdf4'],
        ['updated',        $run['updated'],                      'Updated',     '#2563eb', '#eff6ff'],
        ['marked_deleted', $run['marked_deleted'],               'Marked Del.', '#d97706', '#fffbeb'],
        ['skipped',        $run['skipped'],                      'Skipped',     '#64748b', '#f8fafc'],
        ['failed',         $run['failed'],                       'Failed',      '#dc2626', '#fef2f2'],
        ['load_skip',      $run['excel_skipped_load'] ?? 0,      'Dropped',     '#f97316', '#fff7ed'],
        ['empty_site',     $run['empty_sites_marked'] ?? 0,      'Empty Sites',    '#b45309', '#fffbeb'],
        ['empty_tree',     $run['empty_trees_marked'] ?? 0,      'Empty Branches', '#7c3aed', '#f5f3ff'],
        ['site_unmarked',  $run['sites_unmarked'] ?? 0,          'Sites Revived',    '#0d9488', '#f0fdfa'],
        ['tree_unmarked',  $run['trees_unmarked'] ?? 0,          'Branches Revived', '#0d9488', '#f0fdfa'],
    ];
    $graphCards = [
        ['graph_created',  $run['graphs_created'] ?? 0, 'Graphs Created', '#0891b2', '#ecfeff'],
        ['tree_placed',    $run['tree_placed'],          'Tree Placed',    '#7c3aed', '#f5f3ff'],
    ];

    html_start_box('', '100%', '', '3', 'center', '');

    // Back link + run header bar
    print '<tr class="even"><td style="padding:10px 16px 4px;">';
    print '<a href="cereus_datasync_log.php?profile_id=' . $run['profile_id'] . '" class="cds-link">&laquo; Back to Log</a>';
    print '</td></tr>';

    print '<tr class="even"><td style="padding:6px 16px 16px;">';

    // Run meta bar
    $statusColors = ['completed' => '#15803d', 'failed' => '#dc2626', 'running' => '#2563eb', 'queued' => '#64748b'];
    $sc = $statusColors[$run['status']] ?? '#64748b';
    print '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:16px;font-size:13px;">';
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
        print '<div style="padding:8px 14px;margin:-6px 0 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:12px;color:#7c2d12;">';
        print __('Dry run: nothing was changed. The counts show what a real run would do.', 'cereus_datasync');
        if ($unchecked > 0) {
            print ' ' . __('%d of the devices counted as added still need the SNMP interface check, which dry runs skip — a real run adds them only if an interface matches.', $unchecked, 'cereus_datasync');
        }
        print '</div>';
    }

    // Three-column dashboard grid
    print '<div style="display:grid;grid-template-columns:220px 1fr 180px;gap:16px;align-items:start;">';

    // ── Column 1: Excel input ─────────────────────────────────────────────
    print '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">';
    print '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px;">&#128196; Excel Input</div>';
    if ($rawTotal > 0) {
        print '<div style="font-size:32px;font-weight:800;color:#0f172a;line-height:1;">' . $rawTotal . '</div>';
        print '<div style="font-size:12px;color:#64748b;margin-bottom:8px;">raw rows in file</div>';
        // Progress bar: valid (green) vs dropped (orange)
        print '<div style="height:8px;border-radius:4px;background:#f1f5f9;overflow:hidden;margin-bottom:6px;">';
        print '<div style="width:' . $validPct . '%;height:100%;background:#15803d;border-radius:4px;display:inline-block;"></div>';
        print '<div style="width:' . $dropPct . '%;height:100%;background:#f97316;border-radius:4px;display:inline-block;"></div>';
        print '</div>';
        print '<div style="display:flex;gap:8px;font-size:11px;">';
        print '<span><span style="color:#15803d;font-weight:700;">' . $valid . '</span> valid</span>';
        if ($dropCount > 0) {
            print '<span><a href="cereus_datasync_log.php?action=view&run_id=' . $runId . '&af=' . ($actionFilter === 'load_skip' ? '' : 'load_skip') . '" style="color:#f97316;font-weight:700;text-decoration:none;">' . $dropCount . ' dropped &#8250;</a></span>';
        }
        print '</div>';
    } else {
        print '<div style="font-size:32px;font-weight:800;color:#0f172a;line-height:1;">' . $valid . '</div>';
        print '<div style="font-size:12px;color:#64748b;">devices loaded</div>';
    }
    print '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;">';
    print '<span style="font-weight:600;color:#0f172a;">' . $run['cacti_total'] . '</span> devices in Cacti';
    print '</div>';
    print '</div>';

    // ── Column 2: Device results (clickable cards) ────────────────────────
    print '<div>';
    print '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px;">&#128290; Device Results</div>';
    print '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
    foreach ($devCards as [$key, $val, $label, $color, $bg]) {
        $active = ($actionFilter === $key);
        $detailCount = (int)db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_cds_run_details WHERE run_id = ? AND action IN (?, ?)",
            [$runId, $key, $key . '_dry']
        );
        $href = 'cereus_datasync_log.php?action=view&run_id=' . $runId . '&af=' . ($active ? '' : $key);
        $cardBg    = $active ? $color : $bg;
        $cardColor = $active ? '#fff' : '#0f172a';
        $labelClr  = $active ? 'rgba(255,255,255,.8)' : '#64748b';
        print '<a href="' . $href . '" style="text-decoration:none;flex:0 0 auto;">';
        print '<div style="min-width:90px;padding:10px 14px;border-radius:8px;background:' . $cardBg . ';border:2px solid ' . $color . ';text-align:center;transition:box-shadow .15s;">';
        print '<div style="font-size:28px;font-weight:800;color:' . $cardColor . ';line-height:1;">' . $val . '</div>';
        print '<div style="font-size:11px;color:' . $labelClr . ';margin-top:2px;white-space:nowrap;">' . $label . '</div>';
        if ($detailCount > 0) {
            print '<div style="font-size:10px;color:' . $labelClr . ';margin-top:1px;opacity:.7;">' . $detailCount . ' entries</div>';
        }
        print '</div></a>';
    }
    print '</div>';
    print '</div>';

    // ── Column 3: Graph results ───────────────────────────────────────────
    print '<div>';
    print '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px;">&#128200; Graph Results</div>';
    print '<div style="display:flex;flex-direction:column;gap:8px;">';
    // Interfaces found (not clickable — no log entries for this)
    $gFound = (int)($run['graphs_found'] ?? 0);
    print '<div style="padding:10px 14px;border-radius:8px;background:#f0f9ff;border:1px solid #bae6fd;">';
    print '<div style="font-size:28px;font-weight:800;color:#0369a1;line-height:1;">' . $gFound . '</div>';
    print '<div style="font-size:11px;color:#0369a1;margin-top:2px;">WAN interfaces found</div>';
    print '</div>';
    foreach ($graphCards as [$key, $val, $label, $color, $bg]) {
        $active = ($actionFilter === $key);
        $detailCount = (int)db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_cds_run_details WHERE run_id = ? AND action IN (?, ?)",
            [$runId, $key, $key . '_dry']
        );
        $href = 'cereus_datasync_log.php?action=view&run_id=' . $runId . '&af=' . ($active ? '' : $key);
        $cardBg    = $active ? $color : $bg;
        $cardColor = $active ? '#fff' : '#0f172a';
        $labelClr  = $active ? 'rgba(255,255,255,.8)' : '#64748b';
        print '<a href="' . $href . '" style="text-decoration:none;">';
        print '<div style="padding:10px 14px;border-radius:8px;background:' . $cardBg . ';border:2px solid ' . $color . '">';
        print '<div style="font-size:28px;font-weight:800;color:' . $cardColor . ';line-height:1;">' . $val . '</div>';
        print '<div style="font-size:11px;color:' . $labelClr . ';margin-top:2px;">' . $label . '</div>';
        if ($detailCount > 0) {
            print '<div style="font-size:10px;color:' . $labelClr . ';opacity:.7;">' . $detailCount . ' entries</div>';
        }
        print '</div></a>';
    }
    print '</div>';
    print '</div>';

    print '</div>'; // end grid

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
            form_selectable_cell(html_escape($item['details']), $item['id']);
            form_end_row();
        }
    } else {
        print '<tr><td colspan="5"><em>' . __('No detail records for this filter.', 'cereus_datasync') . '</em></td></tr>';
    }

    html_end_box(false);
    print $nav;
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
