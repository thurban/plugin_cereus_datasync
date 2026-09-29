<?php
// SPDX-License-Identifier: GPL-2.0-or-later
chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/license_check.php');
require_once(__DIR__ . '/lib/functions.php');
require_once(__DIR__ . '/setup.php');
cereus_datasync_setup_tables();
cereus_datasync_check_upgrade();

if (!api_user_realm_auth('cereus_datasync.php')) {
    header('Location: ../../index.php');
    exit;
}

$action = get_nfilter_request_var('action', '');

switch ($action) {
    case 'actions':
        cereus_datasync_actions();
        header('Location: cereus_datasync.php');
        exit;
    case 'copy':
        $srcId = get_filter_request_var('id');
        if ($srcId > 0) {
            $newId = cereus_datasync_copy_profile($srcId);
            if ($newId) {
                header('Location: cereus_datasync_edit.php?action=edit&id=' . $newId);
                exit;
            }
        }
        header('Location: cereus_datasync.php');
        exit;
    default:
        top_header();
        cereus_datasync_list();
        bottom_footer();
}

// ─── List view ────────────────────────────────────────────────────────────────

function cereus_datasync_list(): void {
    global $config;

    if (!cereus_datasync_license_ok()) {
        cereus_datasync_license_wall();
        return;
    }

    $filter  = get_request_var('filter', '');
    $page    = get_request_var('page', 1);
    $rows    = 20;

    $sql_where  = '';
    $sql_params = [];
    if (!empty($filter)) {
        $safe = str_replace(['%', '_'], ['\\%', '\\_'], $filter);
        $sql_where  = 'WHERE name LIKE ?';
        $sql_params = ['%' . $safe . '%'];
    }

    $total = (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_cds_profiles $sql_where", $sql_params);
    $items = db_fetch_assoc_prepared(
        "SELECT * FROM plugin_cds_profiles $sql_where ORDER BY name LIMIT " . (($page - 1) * $rows) . ", $rows",
        $sql_params
    );

    $max       = cereus_datasync_max_profiles();
    $count     = cereus_datasync_profile_count();
    $canAdd    = ($count < $max);
    $addUrl    = $canAdd ? 'cereus_datasync_edit.php?action=edit' : '';

    html_start_box(__('Sync Profiles', 'cereus_datasync'), '100%', '', '3', 'center', $addUrl);
    ?>
    <tr class="even">
        <td style="padding:5px 12px;font-size:12px;color:#475569;border-bottom:1px solid #e2e8f0;">
            &#128196; <?php print __('Need to prepare an Excel file?', 'cereus_datasync'); ?>
            <a href="cereus_datasync_sample.php" class="cds-link" style="margin-left:6px;">&#11015; <?php print __('Download sample file', 'cereus_datasync'); ?></a>
            <span style="color:#94a3b8;margin-left:8px;"><?php print __('(30 sample devices, shows required columns and format)', 'cereus_datasync'); ?></span>
            <span style="float:right;"><?php print cereus_datasync_help_link('2-quick-start'); ?></span>
        </td>
    </tr>
    <?php
    ?>
    <tr class="even">
        <td>
            <form id="form_cds" action="cereus_datasync.php">
                <table class="filterTable">
                    <tr>
                        <td><?php print __('Search', 'cereus_datasync'); ?></td>
                        <td><input type="text" class="ui-state-default ui-corner-all" id="filter" value="<?php print html_escape($filter); ?>"></td>
                        <td><input type="button" class="ui-button" id="cds_refresh" value="<?php print __('Go', 'cereus_datasync'); ?>"></td>
                        <td><input type="button" class="ui-button" id="cds_clear" value="<?php print __('Clear', 'cereus_datasync'); ?>"></td>
                    </tr>
                </table>
            </form>
            <script>
            $(function() {
                $('#cds_refresh').click(function() {
                    loadPageNoHeader('cereus_datasync.php?header=false&filter=' + encodeURIComponent($('#filter').val()));
                });
                $('#cds_clear').click(function() {
                    loadPageNoHeader('cereus_datasync.php?header=false');
                });
                $('#filter').keypress(function(e) { if (e.which === 13) $('#cds_refresh').click(); });
            });
            </script>
        </td>
    </tr>
    <?php

    if (!$canAdd && $max > 0) {
        print '<tr><td style="padding:6px 10px;background:#fef9c3;color:#92400e;font-size:12px;">';
        print __('Profile limit reached (%d/%d). Upgrade to Enterprise for unlimited profiles.', $count, $max, 'cereus_datasync');
        print '</td></tr>';
    }
    html_end_box();

    $nav = html_nav_bar('cereus_datasync.php?filter=' . urlencode($filter), MAX_DISPLAY_PAGES, $page, $rows, $total, 8, __('Profiles', 'cereus_datasync'));
    print $nav;

    $form_action = 'cereus_datasync.php';
    print '<form id="cds_form_actions" action="' . $form_action . '" method="post">';

    html_start_box('', '100%', '', '3', 'center', '');

    $header = [
        'nosort0'  => ['display' => __('', 'cereus_datasync')],
        'name'     => ['display' => __('Profile Name', 'cereus_datasync'),   'sort' => 'ASC'],
        'nosort1'  => ['display' => __('Schedule', 'cereus_datasync')],
        'nosort2'  => ['display' => __('Last Run', 'cereus_datasync')],
        'nosort3'  => ['display' => __('Status', 'cereus_datasync')],
        'nosort4'  => ['display' => __('Actions', 'cereus_datasync')],
    ];
    html_header_sort_checkbox($header, 'name', 'ASC');

    if (cacti_sizeof($items)) {
        foreach ($items as $row) {
            $enabled  = ($row['enabled'] === 'on');
            $statusBadge = !empty($row['last_run_status']) ? cereus_datasync_status_badge($row['last_run_status']) : '<em style="color:#999;">' . __('Never', 'cereus_datasync') . '</em>';
            $lastRun  = !empty($row['last_run_at']) ? $row['last_run_at'] : '—';
            $stats    = !empty($row['last_run_stats']) ? json_decode($row['last_run_stats'], true) : null;
            $statsStr = $stats ? sprintf('+%d ~%d ✗%d', $stats['added'] ?? 0, $stats['updated'] ?? 0, $stats['failed'] ?? 0) : '';

            form_alternate_row('cds_line' . $row['id'], true);
            // Enabled indicator
            print '<td style="width:20px;">';
            print '<span class="cds-dot" style="background:' . ($enabled ? '#15803d' : '#dc2626') . ';"></span>';
            print '</td>';
            // Name
            print '<td>';
            print '<a class="linkEditMain" href="cereus_datasync_edit.php?action=edit&id=' . $row['id'] . '">' . html_escape($row['name']) . '</a>';
            if (!empty($row['description'])) {
                print '<br><small style="color:#666;">' . html_escape($row['description']) . '</small>';
            }
            print '</td>';
            // Schedule
            form_selectable_cell(cereus_datasync_schedule_label($row['schedule_type']), $row['id']);
            // Last run
            print '<td>' . html_escape($lastRun);
            if ($statsStr) print '<br><small style="color:#888;font-family:monospace;">' . html_escape($statsStr) . '</small>';
            print '</td>';
            // Status
            form_selectable_cell($statusBadge, $row['id']);
            // Actions
            print '<td style="white-space:nowrap;">';
            print '<a class="cds-btn-run" href="#" data-id="' . $row['id'] . '" data-dry="0" title="' . __('Run Now', 'cereus_datasync') . '">&#9654; Run</a> ';
            print '<a class="cds-btn-run" href="#" data-id="' . $row['id'] . '" data-dry="1" style="color:#7c3aed;" title="' . __('Dry Run', 'cereus_datasync') . '">&#9654; Dry</a> ';
            print '<a href="cereus_datasync_rules.php?profile_id=' . $row['id'] . '" title="' . __('Tree Rules', 'cereus_datasync') . '" class="cds-link">&#127795; Rules</a> ';
            print '<a href="cereus_datasync_log.php?profile_id=' . $row['id'] . '" title="' . __('History', 'cereus_datasync') . '" class="cds-link">&#128203; Log</a> ';
            print '<a href="cereus_datasync.php?action=copy&id=' . $row['id'] . '" title="' . __('Copy Profile', 'cereus_datasync') . '" class="cds-link">&#128260; Copy</a>';
            print '</td>';
            form_checkbox_cell($row['name'], $row['id']);
            form_end_row();
        }
    } else {
        print '<tr><td colspan="7"><em>' . __('No sync profiles found. Create one to get started.', 'cereus_datasync') . '</em></td></tr>';
    }

    html_end_box(false);
    print $nav;

    $actions = [
        1 => __('Delete', 'cereus_datasync'),
        2 => __('Enable', 'cereus_datasync'),
        3 => __('Disable', 'cereus_datasync'),
        4 => __('Copy', 'cereus_datasync'),
    ];
    draw_actions_dropdown($actions);
    print '</form>';

    // Run status modal
    ?>
    <div id="cds-run-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:24px 32px;min-width:340px;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,.2);">
            <h3 style="margin:0 0 12px;font-size:15px;" id="cds-modal-title">Running sync…</h3>
            <div id="cds-modal-body" style="font-family:monospace;font-size:13px;line-height:1.6;white-space:pre-wrap;max-height:300px;overflow-y:auto;background:#f8f9fa;padding:10px;border-radius:4px;margin-bottom:16px;"></div>
            <button type="button" class="ui-button" id="cds-modal-close" style="display:none;">Close</button>
            <span id="cds-modal-spinner" style="color:#666;">&#9696; Working…</span>
        </div>
    </div>

    <style>
    .cds-dot{display:inline-block;width:10px;height:10px;border-radius:50%;}
    .cds-btn-run{cursor:pointer;text-decoration:none;font-size:12px;font-weight:600;}
    </style>
    <script>
    $(function() {
        var cdsPoller      = null;
        var cdsReloadTimer = null;

        function cdsStatsText(s, status) {
            var raw   = s.excel_raw_total    || 0;
            var valid = s.excel_total        || 0;
            var drop  = s.excel_skipped_load || 0;
            var excelLine = raw > 0
                ? 'Excel rows     : ' + raw + ' raw → ' + valid + ' valid (' + drop + ' dropped)'
                : 'Excel devices  : ' + valid;
            var checkLine = (s.checking || 0) > 0
                ? '\nChecking L2    : ' + s.checking + ' devices pending SNMP WAN check…'
                : '';
            return 'Status         : ' + status + '\n\n'
                + excelLine + '\n'
                + 'Cacti devices  : ' + (s.cacti_total    || 0) + '\n'
                + 'Added          : ' + (s.added          || 0) + '\n'
                + 'Updated        : ' + (s.updated        || 0) + '\n'
                + 'Marked deleted : ' + (s.marked_deleted || 0) + '\n'
                + 'Skipped        : ' + (s.skipped        || 0) + '\n'
                + 'Failed         : ' + (s.failed         || 0) + '\n'
                + 'Graphs found   : ' + (s.graphs_found   || 0) + '\n'
                + 'Graphs created : ' + (s.graphs_created || 0)
                + checkLine;
        }

        function cdsPollRun(runId) {
            $.get('cereus_datasync_ajax.php', { action: 'poll_run', run_id: runId }, function(data) {
                if (data.error) {
                    clearInterval(cdsPoller);
                    $('#cds-modal-spinner').hide();
                    $('#cds-modal-body').text('Error: ' + data.error);
                    $('#cds-modal-close').show();
                    return;
                }
                var done = (data.status === 'completed' || data.status === 'failed');
                $('#cds-modal-body').text(cdsStatsText(data.stats || {}, data.status));
                if (done) {
                    clearInterval(cdsPoller);
                    cdsPoller = null;
                    $('#cds-modal-spinner').hide();
                    if (data.error) {
                        $('#cds-modal-body').append('\n\nError: ' + data.error);
                    }
                    $('#cds-modal-close').text('Close & Refresh');
                    cdsReloadTimer = setTimeout(function() { loadPageNoHeader('cereus_datasync.php?header=false'); }, 2000);
                }
            }, 'json').fail(function() {
                // transient poll failure — keep polling
            });
        }

        $('.cds-btn-run').on('click', function(e) {
            e.preventDefault();
            var pid = $(this).data('id');
            var dry = $(this).data('dry');
            $('#cds-modal-title').text(dry ? 'Dry Run — Profile #' + pid : 'Running sync — Profile #' + pid);
            $('#cds-modal-body').text('Queuing run…');
            $('#cds-modal-close').hide();
            $('#cds-modal-spinner').show();
            $('#cds-run-modal').css('display', 'flex');

            clearInterval(cdsPoller);

            $.post('cereus_datasync_ajax.php', {
                action:     'trigger_run',
                profile_id: pid,
                dry_run:    dry,
                __csrf_magic: csrfMagicToken
            }, function(data) {
                if (data.error) {
                    $('#cds-modal-spinner').hide();
                    $('#cds-modal-body').text('Error: ' + data.error);
                    $('#cds-modal-close').show();
                    return;
                }
                $('#cds-modal-body').text('Status         : queued\n\nSync is running in the background.\nThis page remains usable while it runs.');
                $('#cds-modal-close').text('Dismiss').show();
                cdsPoller = setInterval(function() { cdsPollRun(data.run_id); }, 3000);
            }, 'json').fail(function(jqXHR) {
                $('#cds-modal-spinner').hide();
                var raw = jqXHR.responseText || ('HTTP ' + jqXHR.status + ' ' + jqXHR.statusText);
                $('#cds-modal-body').text('Request failed:\n\n' + raw.substring(0, 800));
                $('#cds-modal-close').show();
            });
        });

        $('#cds-modal-close').on('click', function() {
            clearInterval(cdsPoller);
            cdsPoller = null;
            clearTimeout(cdsReloadTimer);
            cdsReloadTimer = null;
            var reload = $(this).text() === 'Close & Refresh';
            $('#cds-run-modal').hide();
            if (reload) {
                loadPageNoHeader('cereus_datasync.php?header=false');
            }
        });
    });
    </script>
    <?php
}

// ─── Bulk actions ─────────────────────────────────────────────────────────────

function cereus_datasync_actions(): void {
    $drp_action = get_nfilter_request_var('drp_action', '');
    $ids = [];
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'chk_') === 0) {
            $ids[] = (int)substr($k, 4);
        }
    }
    if (empty($ids)) return;

    foreach ($ids as $id) {
        switch ($drp_action) {
            case '1': cereus_datasync_delete_profile($id);           break;
            case '2': cereus_datasync_toggle_profile($id, 'enable');  break;
            case '3': cereus_datasync_toggle_profile($id, 'disable'); break;
            case '4': cereus_datasync_copy_profile($id);              break;
        }
    }
}
