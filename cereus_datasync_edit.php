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

$action = get_nfilter_request_var('action', '');
$id     = get_filter_request_var('id', FILTER_VALIDATE_INT) ?: 0;

switch ($action) {
    case 'save':
        cereus_datasync_save();
        break;
    case 'edit':
    default:
        top_header();
        cereus_datasync_edit_form($id);
        bottom_footer();
}

// ─── Save ─────────────────────────────────────────────────────────────────────

function cereus_datasync_save(): void {
    $id = get_filter_request_var('id', FILTER_VALIDATE_INT) ?: 0;

    // License limit check on create
    if (!$id) {
        $max = cereus_datasync_max_profiles();
        if (cereus_datasync_profile_count() >= $max) {
            raise_message('cds_limit', __('Profile limit reached.', 'cereus_datasync'), MESSAGE_LEVEL_ERROR);
            header('Location: cereus_datasync.php');
            exit;
        }
    }

    $name = get_nfilter_request_var('name', '');
    if (empty(trim($name))) {
        raise_message('cds_name', __('Profile name is required.', 'cereus_datasync'), MESSAGE_LEVEL_ERROR);
        header('Location: cereus_datasync_edit.php?action=edit&id=' . $id);
        exit;
    }

    $data = [
        'name'                  => substr(trim($name), 0, 100),
        'description'           => substr(trim(get_nfilter_request_var('description', '')), 0, 512),
        'enabled'               => isset($_POST['enabled']) ? 'on' : '',
        'excel_path'            => trim(get_nfilter_request_var('excel_path', '')),
        'excel_file_mode'       => get_nfilter_request_var('excel_file_mode', 'latest_in_dir'),
        'sheet_name'            => trim(get_nfilter_request_var('sheet_name', 'Sheet1')) ?: 'Sheet1',
        'data_start_row'        => max(1, (int)get_nfilter_request_var('data_start_row', 4)),
        'schedule_type'         => get_nfilter_request_var('schedule_type', 'manual'),
        'schedule_hour'         => (int)get_nfilter_request_var('schedule_hour', 2),
        'schedule_wday'         => (int)get_nfilter_request_var('schedule_wday', 1),
        'snmp_check_l2'         => isset($_POST['snmp_check_l2']) ? 1 : 0,
        'snmp_wan_pattern'      => trim(get_nfilter_request_var('snmp_wan_pattern', '-WAN-')) ?: '-WAN-',
        'snmp_timeout_ms'       => max(500, (int)get_nfilter_request_var('snmp_timeout_ms', 2000)),
        'snmp_enable_parallel'  => isset($_POST['snmp_enable_parallel']) ? 1 : 0,
        'snmp_parallel_workers' => max(1, min(100, (int)get_nfilter_request_var('snmp_parallel_workers', 20))),
        'default_snmp_version'  => (int)get_nfilter_request_var('default_snmp_version', 2),
        'default_snmp_community'=> trim(get_nfilter_request_var('default_snmp_community', 'public')) ?: 'public',
        'default_snmp_port'     => (int)get_nfilter_request_var('default_snmp_port', 161),
        'default_snmp_timeout'  => (int)get_nfilter_request_var('default_snmp_timeout', 500),
        'default_availability'  => (int)get_nfilter_request_var('default_availability', 2),
        'default_ping_method'   => (int)get_nfilter_request_var('default_ping_method', 2),
        'default_poller_id'     => (int)get_nfilter_request_var('default_poller_id', 1),
        'deletion_tag'          => trim(get_nfilter_request_var('deletion_tag', '[TO BE DELETED]')) ?: '[TO BE DELETED]',
        'deletion_skip_localhost'=> isset($_POST['deletion_skip_localhost']) ? 1 : 0,
        'mark_empty_sites'      => isset($_POST['mark_empty_sites']) ? 1 : 0,
        'mark_empty_tree_items' => isset($_POST['mark_empty_tree_items']) ? 1 : 0,
        'auto_create_graphs'    => isset($_POST['auto_create_graphs']) ? 1 : 0,
        'graph_query_type_id'   => (int)get_nfilter_request_var('graph_query_type_id', 0),
        'auto_graph_rules'      => isset($_POST['auto_graph_rules']) ? 1 : 0,
    ];

    // Scheduling requires Enterprise
    if (!cereus_datasync_has_scheduling() && $data['schedule_type'] !== 'manual') {
        $data['schedule_type'] = 'manual';
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $vals = array_values($data);
        $vals[] = $id;
        db_execute_prepared("UPDATE plugin_cds_profiles SET $sets WHERE id = ?", $vals);
    } else {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        db_execute_prepared("INSERT INTO plugin_cds_profiles ($cols) VALUES ($phs)", array_values($data));
        $id = (int)db_fetch_insert_id();
    }

    // Save column maps
    $colFields = ['hostname', 'ip', 'snmp_community', 'country', 'device_function', 'region', 'site'];
    $colMaps = [];
    foreach ($colFields as $f) {
        $col = strtoupper(trim(get_nfilter_request_var('col_' . $f, '')));
        if ($col !== '') $colMaps[$f] = $col;
    }
    cereus_datasync_save_column_maps($id, $colMaps);

    raise_message(1);
    header('Location: cereus_datasync_edit.php?action=edit&id=' . $id);
    exit;
}

// ─── Edit form ───────────────────────────────────────────────────────────────

function cereus_datasync_edit_form(int $id): void {
    $row     = $id ? cereus_datasync_get_profile($id) : [];
    $colMaps = $id ? cereus_datasync_get_column_maps($id) : [];
    $funcMaps = $id ? cereus_datasync_get_function_maps($id) : [];
    $isNew   = !$id;
    $title   = $isNew ? __('New Sync Profile', 'cereus_datasync') : __('Edit: %s', html_escape($row['name']), 'cereus_datasync');

    $hasScheduling = cereus_datasync_has_scheduling();

    $defaults = [
        'name' => '', 'description' => '', 'enabled' => 'on',
        'excel_path' => '', 'excel_file_mode' => 'latest_in_dir',
        'sheet_name' => 'Sheet1', 'data_start_row' => 4,
        'schedule_type' => 'manual', 'schedule_hour' => 2, 'schedule_wday' => 1,
        'snmp_check_l2' => 1, 'snmp_wan_pattern' => '-WAN-', 'snmp_timeout_ms' => 2000,
        'snmp_enable_parallel' => 0, 'snmp_parallel_workers' => 20,
        'default_snmp_version' => 2, 'default_snmp_community' => 'public',
        'default_snmp_port' => 161, 'default_snmp_timeout' => 500,
        'default_availability' => 2, 'default_ping_method' => 2,
        'default_poller_id' => 1,
        'deletion_tag' => '[TO BE DELETED]', 'deletion_skip_localhost' => 1,
        'mark_empty_sites' => 1, 'mark_empty_tree_items' => 1,
        'auto_create_graphs' => 0, 'graph_query_type_id' => 0, 'auto_graph_rules' => 0,
    ];
    $r = array_merge($defaults, $row ?: []);

    $colDefaults = ['hostname' => 'A', 'ip' => 'B', 'snmp_community' => 'E', 'country' => 'K', 'device_function' => 'M', 'region' => 'O', 'site' => 'P'];
    $cols = array_merge($colDefaults, $colMaps);

    $templates = cereus_datasync_get_templates_array();
    $pollers   = db_fetch_assoc('SELECT id, name FROM poller ORDER BY id');
    $pollerArr = [0 => ''];
    if (cacti_sizeof($pollers)) {
        foreach ($pollers as $p) $pollerArr[(int)$p['id']] = $p['name'];
    }

    print '<form method="post" action="cereus_datasync_edit.php">';
    print '<input type="hidden" name="action" value="save">';
    print '<input type="hidden" name="id" value="' . $id . '">';

    // ── General ──────────────────────────────────────────────────────────
    html_start_box($title, '100%', '', '3', 'center', '');
    print '<tr class="even"><td colspan="2" style="padding:6px 15px;text-align:right;">'
        . cereus_datasync_help_link('4-sync-profiles') . '</td></tr>';
    draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
        'name' => [
            'friendly_name' => __('Profile Name', 'cereus_datasync'),
            'description'   => __('Unique name for this sync profile.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 100, 'size' => 50,
            'value'         => $r['name'],
        ],
        'description' => [
            'friendly_name' => __('Description', 'cereus_datasync'),
            'description'   => __('Optional notes about this profile.', 'cereus_datasync'),
            'method'        => 'textarea', 'textarea_rows' => 2, 'textarea_cols' => 60,
            'value'         => $r['description'],
        ],
        'enabled' => [
            'friendly_name' => __('Enabled', 'cereus_datasync'),
            'description'   => __('Enable or disable this profile.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['enabled'],
        ],
        'sp_file' => ['method' => 'spacer', 'friendly_name' => __('Excel File Source', 'cereus_datasync')],
        'excel_file_mode' => [
            'friendly_name' => __('File Mode', 'cereus_datasync'),
            'description'   => __('How to find the Excel file.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => ['latest_in_dir' => __('Latest file in directory', 'cereus_datasync'), 'specific_file' => __('Specific file path', 'cereus_datasync')],
            'value'         => $r['excel_file_mode'],
        ],
        'excel_path' => [
            'friendly_name' => __('Path', 'cereus_datasync'),
            'description'   => __('Directory (for latest-in-dir) or full path to the .xlsx file.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 512, 'size' => 60,
            'value'         => $r['excel_path'],
        ],
        'sheet_name' => [
            'friendly_name' => __('Sheet Name', 'cereus_datasync'),
            'description'   => __('Name of the worksheet tab to read.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 64, 'size' => 30,
            'value'         => $r['sheet_name'],
        ],
        'data_start_row' => [
            'friendly_name' => __('Data Start Row', 'cereus_datasync'),
            'description'   => __('First row containing device data (e.g. 4 if rows 1-3 are headers).', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 5, 'size' => 6,
            'value'         => $r['data_start_row'],
        ],
    ]]);
    html_end_box();

    // ── Column Mapping ────────────────────────────────────────────────────
    html_start_box(__('Column Mapping', 'cereus_datasync'), '100%', '', '3', 'center', '');
    $colFields = [
        'hostname'       => ['label' => __('Device Description', 'cereus_datasync'),        'desc' => __('Device description (display name) in Cacti', 'cereus_datasync')],
        'ip'             => ['label' => __('Hostname / IP Address', 'cereus_datasync'),    'desc' => __('Hostname or IP address Cacti polls', 'cereus_datasync')],
        'snmp_community' => ['label' => __('SNMP Community', 'cereus_datasync'),           'desc' => __('SNMP v1/v2c community string', 'cereus_datasync')],
        'country'        => ['label' => __('Country', 'cereus_datasync'),                  'desc' => __('Used for site grouping', 'cereus_datasync')],
        'device_function'=> ['label' => __('Device Function', 'cereus_datasync'),           'desc' => __('Device type/role (maps to host template)', 'cereus_datasync')],
        'region'         => ['label' => __('Region', 'cereus_datasync'),                   'desc' => __('Used for site grouping', 'cereus_datasync')],
        'site'           => ['label' => __('Site', 'cereus_datasync'),                     'desc' => __('Site name — also used as Cacti location', 'cereus_datasync')],
    ];
    print '<tr class="even"><td style="padding:10px 15px;">';
    print '<table class="cactiTable" style="width:100%;">';
    print '<tr style="background:#f1f5f9;"><th style="width:35%;padding:8px 10px;">' . __('Field', 'cereus_datasync') . '</th>';
    print '<th style="width:12%;padding:8px 10px;">' . __('Column Letter', 'cereus_datasync') . '</th>';
    print '<th style="padding:8px 10px;">' . __('Notes', 'cereus_datasync') . '</th></tr>';
    foreach ($colFields as $fname => $meta) {
        print '<tr><td style="padding:6px 10px;font-weight:500;">' . html_escape($meta['label']) . '</td>';
        print '<td style="padding:6px 10px;"><input type="text" class="ui-state-default ui-corner-all" name="col_' . $fname . '" value="' . html_escape($cols[$fname] ?? '') . '" size="4" style="width:50px;text-align:center;text-transform:uppercase;font-family:monospace;"></td>';
        print '<td style="padding:6px 10px;color:#666;font-size:12px;">' . html_escape($meta['desc']) . '</td></tr>';
    }
    print '</table>';
    print '</td></tr>';
    html_end_box();

    // ── Device Functions ──────────────────────────────────────────────────
    html_start_box(__('Device Function → Host Template Mapping', 'cereus_datasync'), '100%', '', '3', 'center', '');
    print '<tr class="even"><td style="padding:10px 15px;">';
    print '<p style="margin:0 0 10px;font-size:12px;color:#666;">' . __('Map each device function value from your Excel to a Cacti host template. Devices with unmapped functions use the first Generic/SNMP template found.', 'cereus_datasync') . '</p>';
    print '<table class="cactiTable" id="cds-func-table" style="width:100%;">';
    print '<tr style="background:#f1f5f9;">';
    print '<th style="padding:8px 10px;width:38%;">' . __('Device Function', 'cereus_datasync') . '</th>';
    print '<th style="padding:8px 10px;">' . __('Host Template', 'cereus_datasync') . '</th>';
    print '<th style="padding:8px 10px;width:110px;text-align:center;" title="' . __('Run SNMP interface name check before adding. Devices with no matching interface are skipped.', 'cereus_datasync') . '">' . __('Interface Check', 'cereus_datasync') . '</th>';
    print '<th style="width:50px;padding:8px 10px;"></th></tr>';

    if (cacti_sizeof($funcMaps)) {
        foreach ($funcMaps as $fm) {
            cereus_datasync_render_funcmap_row($fm['id'], $fm['device_function'], (int)$fm['host_template_id'], (int)$fm['snmp_check'], $templates);
        }
    }
    print '</table>';
    if ($id) {
        print '<div style="margin-top:8px;">';
        print '<button type="button" class="ui-button" id="cds-add-func">+ ' . __('Add Mapping', 'cereus_datasync') . '</button>';
        print '</div>';
    } else {
        print '<p style="color:#666;font-size:12px;margin-top:8px;font-style:italic;">' . __('Save profile first to add device function mappings.', 'cereus_datasync') . '</p>';
    }
    print '</td></tr>';
    html_end_box();

    // ── SNMP & Performance ────────────────────────────────────────────────
    html_start_box(__('SNMP & Device Defaults', 'cereus_datasync'), '100%', '', '3', 'center', '');
    draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
        'snmp_check_l2' => [
            'friendly_name' => __('Enable Interface Naming Checks', 'cereus_datasync'),
            'description'   => __('Before adding a device, SNMP-walk its ifAlias OIDs and check whether any interface name matches the pattern below. Devices with no matching interface are skipped.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['snmp_check_l2'] ? 'on' : '',
        ],
        'snmp_wan_pattern' => [
            'friendly_name' => __('Interface Pattern', 'cereus_datasync'),
            'description'   => __('Substring matched (case-insensitive) against each interface\'s ifAlias. Devices with no matching interface are skipped.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 64, 'size' => 20,
            'value'         => $r['snmp_wan_pattern'],
        ],
        'snmp_timeout_ms' => [
            'friendly_name' => __('SNMP Timeout (ms)', 'cereus_datasync'),
            'description'   => __('SNMP timeout in milliseconds for WAN interface checks.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 6, 'size' => 8,
            'value'         => $r['snmp_timeout_ms'],
        ],
        'snmp_enable_parallel' => [
            'friendly_name' => __('Parallel SNMP Checks', 'cereus_datasync'),
            'description'   => __('Run interface checks concurrently using multiple worker processes. Significantly faster for large device lists.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['snmp_enable_parallel'] ? 'on' : '',
        ],
        'snmp_parallel_workers' => [
            'friendly_name' => __('Parallel Workers', 'cereus_datasync'),
            'description'   => __('Number of concurrent SNMP worker processes to spawn. Each worker handles a batch of devices. Higher values finish faster but consume more CPU and memory. Recommended: 10–20.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 3, 'size' => 5,
            'value'         => (int)($r['snmp_parallel_workers'] ?? 20),
        ],
        'sp_snmp_defaults' => ['method' => 'spacer', 'friendly_name' => __('New Device Defaults', 'cereus_datasync')],
        'default_snmp_version' => [
            'friendly_name' => __('SNMP Version', 'cereus_datasync'),
            'description'   => __('Default SNMP version for new devices.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => [1 => 'v1', 2 => 'v2c', 3 => 'v3'],
            'value'         => $r['default_snmp_version'],
        ],
        'default_snmp_community' => [
            'friendly_name' => __('Default SNMP Community', 'cereus_datasync'),
            'description'   => __('Used when the Excel row has no community string.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 100, 'size' => 25,
            'value'         => $r['default_snmp_community'],
        ],
        'default_snmp_port' => [
            'friendly_name' => __('SNMP Port', 'cereus_datasync'),
            'description'   => __('Default SNMP port.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 5, 'size' => 8,
            'value'         => $r['default_snmp_port'],
        ],
        'default_snmp_timeout' => [
            'friendly_name' => __('Device SNMP Timeout (ms)', 'cereus_datasync'),
            'description'   => __('SNMP timeout stored on the Cacti device record.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 5, 'size' => 8,
            'value'         => $r['default_snmp_timeout'],
        ],
        'default_availability' => [
            'friendly_name' => __('Availability Method', 'cereus_datasync'),
            'description'   => __('How Cacti checks device availability.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => [0 => __('None', 'cereus_datasync'), 2 => __('SNMP', 'cereus_datasync'), 3 => __('ICMP', 'cereus_datasync'), 4 => __('SNMP+ICMP', 'cereus_datasync')],
            'value'         => $r['default_availability'],
        ],
        'default_ping_method' => [
            'friendly_name' => __('Ping Method', 'cereus_datasync'),
            'description'   => __('Ping protocol for availability checks.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => [0 => __('None', 'cereus_datasync'), 2 => __('ICMP', 'cereus_datasync'), 3 => __('TCP', 'cereus_datasync'), 4 => __('UDP', 'cereus_datasync')],
            'value'         => $r['default_ping_method'],
        ],
        'default_poller_id' => [
            'friendly_name' => __('Poller', 'cereus_datasync'),
            'description'   => __('Which poller to assign new devices to.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => $pollerArr ?: [1 => 'Main Poller'],
            'value'         => $r['default_poller_id'],
        ],
    ]]);
    html_end_box();

    // ── Schedule ──────────────────────────────────────────────────────────
    $schedTypes = ['manual' => __('Manual only', 'cereus_datasync'), 'every_poller' => __('Every poll cycle', 'cereus_datasync'), 'hourly' => __('Hourly', 'cereus_datasync'), 'daily' => __('Daily', 'cereus_datasync'), 'weekly' => __('Weekly', 'cereus_datasync')];
    $hours = [];
    for ($h = 0; $h < 24; $h++) $hours[$h] = sprintf('%02d:00', $h);
    $wdays = [0 => __('Sunday', 'cereus_datasync'), 1 => __('Monday', 'cereus_datasync'), 2 => __('Tuesday', 'cereus_datasync'), 3 => __('Wednesday', 'cereus_datasync'), 4 => __('Thursday', 'cereus_datasync'), 5 => __('Friday', 'cereus_datasync'), 6 => __('Saturday', 'cereus_datasync')];

    html_start_box(__('Schedule', 'cereus_datasync'), '100%', '', '3', 'center', '');
    if (!$hasScheduling) {
        print '<tr><td style="padding:8px 15px;background:#fef9c3;color:#92400e;">';
        print __('Scheduled sync requires an Enterprise license. Only Manual mode is available.', 'cereus_datasync');
        print '</td></tr>';
    }
    draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
        'schedule_type' => [
            'friendly_name' => __('Run Schedule', 'cereus_datasync'),
            'description'   => __('When to automatically run this profile. Requires Enterprise for non-manual options.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => $hasScheduling ? $schedTypes : ['manual' => __('Manual only', 'cereus_datasync')],
            'value'         => $r['schedule_type'],
        ],
        'schedule_hour' => [
            'friendly_name' => __('Hour of Day', 'cereus_datasync'),
            'description'   => __('For daily/weekly schedules, the hour to run at.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => $hours,
            'value'         => $r['schedule_hour'],
        ],
        'schedule_wday' => [
            'friendly_name' => __('Day of Week', 'cereus_datasync'),
            'description'   => __('For weekly schedules, the day to run on.', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => $wdays,
            'value'         => $r['schedule_wday'],
        ],
    ]]);
    html_end_box();

    // ── Deletion Policy ───────────────────────────────────────────────────
    html_start_box(__('Deletion Policy', 'cereus_datasync'), '100%', '', '3', 'center', '');
    draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
        'deletion_tag' => [
            'friendly_name' => __('Mark-as-Deleted Tag', 'cereus_datasync'),
            'description'   => __('String prepended to a device description when it is no longer in the Excel file.', 'cereus_datasync'),
            'method'        => 'textbox', 'max_length' => 64, 'size' => 30,
            'value'         => $r['deletion_tag'],
        ],
        'deletion_skip_localhost' => [
            'friendly_name' => __('Skip Localhost', 'cereus_datasync'),
            'description'   => __('Never mark the "localhost" device for deletion.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['deletion_skip_localhost'] ? 'on' : '',
        ],
        'mark_empty_sites' => [
            'friendly_name' => __('Flag Empty Sites', 'cereus_datasync'),
            'description'   => __('After a sync, prefix the tag onto any site whose devices are all tagged for deletion, so the now-empty site is easy to find and remove manually.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['mark_empty_sites'] ? 'on' : '',
        ],
        'mark_empty_tree_items' => [
            'friendly_name' => __('Flag Empty Tree Branches', 'cereus_datasync'),
            'description'   => __('After a sync, prefix the tag onto the top-most tree header of any branch left with no live devices, so empty branches are easy to find and remove manually.', 'cereus_datasync'),
            'method'        => 'checkbox', 'value' => $r['mark_empty_tree_items'] ? 'on' : '',
        ],
    ]]);
    html_end_box();

    // ── Graph Creation & Automation Rules ────────────────────────────────
    // Build query type dropdown: "SNMP - Interface Statistics — In/Out Bits"
    $queryTypeRows = db_fetch_assoc(
        'SELECT sqg.id, CONCAT(sq.name, " — ", sqg.name) AS label
         FROM snmp_query_graph sqg
         JOIN snmp_query sq ON sq.id = sqg.snmp_query_id
         ORDER BY sq.name, sqg.name'
    );
    $queryTypeArr = [0 => __('-- Select Data Query / Graph Type --', 'cereus_datasync')];
    if (cacti_sizeof($queryTypeRows)) {
        foreach ($queryTypeRows as $qt) {
            $queryTypeArr[(int)$qt['id']] = html_escape($qt['label']);
        }
    }

    html_start_box(__('Graph Creation & Tree Automation', 'cereus_datasync'), '100%', '', '3', 'center', '');
    print '<tr><td colspan="2" style="padding:6px 15px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:12px;color:#0369a1;">';
    print __('When a new device is added, the sync can immediately create graphs for its WAN interfaces. It walks the device via SNMP, finds interfaces whose <strong>ifAlias</strong> matches the Interface pattern above, then creates a graph per interface using the selected template. Cacti\'s automation rules (configured on the Rules page) then fire automatically to place those graphs in the correct tree leaf.', 'cereus_datasync');
    print '</td></tr>';
    draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
        'auto_create_graphs' => [
            'friendly_name' => __('Auto-create WAN Graphs', 'cereus_datasync'),
            'description'   => __('After a device is added, SNMP-walk it, find WAN interfaces (by ifAlias pattern), and create a graph for each using the selected Data Query and Graph Template below.', 'cereus_datasync'),
            'method'        => 'checkbox',
            'value'         => !empty($r['auto_create_graphs']) ? 'on' : '',
        ],
        'graph_query_type_id' => [
            'friendly_name' => __('Data Query / Graph Template', 'cereus_datasync'),
            'description'   => __('The Data Query and Graph Template combination to use when creating WAN interface graphs. e.g. "SNMP - Interface Statistics — In/Out Bits".', 'cereus_datasync'),
            'method'        => 'drop_array',
            'array'         => $queryTypeArr,
            'value'         => (int)($r['graph_query_type_id'] ?? 0),
        ],
        'sp_autorules' => ['method' => 'spacer', 'friendly_name' => __('Tree Automation Rules', 'cereus_datasync')],
        'auto_graph_rules' => [
            'friendly_name' => __('Enable Auto Tree Rules', 'cereus_datasync'),
            'description'   => __('When a new device is added, its graphs are automatically placed in the correct tree branch. Rule Templates (&#127795; Rules page) are built at the start of each sync run — one Cacti automation rule per unique location in the Excel file — so tree placement fires immediately when each graph is created.', 'cereus_datasync'),
            'method'        => 'checkbox',
            'value'         => !empty($r['auto_graph_rules']) ? 'on' : '',
        ],
    ]]);
    html_end_box();

    // ── Aggregate Graph Rules ─────────────────────────────────────────────
    if ($id) {
        html_start_box(__('Aggregate Graph Rules', 'cereus_datasync'), '100%', '', '3', 'center', '');
        print '<tr><td style="padding:10px 15px;">';
        print '<p style="margin:0 0 8px;font-size:12px;color:#555;">'
            . __('Define rules to build aggregate graphs from sets of same-template graphs and place them into a tree node. Aggregates are created (or rebuilt with current members) on every sync run.', 'cereus_datasync')
            . '</p>';
        $aggCount = (int)db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_cds_aggregate_rules WHERE profile_id = ?', [$id]
        );
        print '<a href="cereus_datasync_rules.php?profile_id=' . $id . '&tab=aggregate" class="ui-button ui-corner-all">'
            . '&#931; ' . __('Manage Aggregate Rules', 'cereus_datasync');
        if ($aggCount > 0) {
            print ' <span style="background:#2563eb;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">' . $aggCount . '</span>';
        }
        print '</a>';
        print '</td></tr>';
        html_end_box();
    }

    form_save_button('cereus_datasync.php', 'save');
    print '</form>';

    // ── AJAX for device function rows ─────────────────────────────────────
    if ($id) {
        ?>
        <script>
        var cdsProfileId = <?php print (int)$id; ?>;
        var cdsTemplates = <?php print json_encode(cereus_datasync_get_templates_array()); ?>;

        $(function() {
            $('#cds-add-func').on('click', function() {
                $.post('cereus_datasync_ajax.php', {
                    action: 'add_function_map',
                    profile_id: cdsProfileId,
                    device_function: '',
                    host_template_id: 0,
                    __csrf_magic: csrfMagicToken
                }, function(data) {
                    if (data.id) {
                        var html = buildFuncRow(data.id, '', 0, 0);
                        $('#cds-func-table').append(html);
                    }
                }, 'json');
            });

            $(document).on('click', '.cds-del-func', function() {
                var btn  = $(this);
                var mid  = btn.data('id');
                $.post('cereus_datasync_ajax.php', {
                    action: 'delete_function_map',
                    map_id: mid,
                    profile_id: cdsProfileId,
                    __csrf_magic: csrfMagicToken
                }, function() {
                    btn.closest('tr').remove();
                }, 'json');
            });

            $(document).on('blur change', '.cds-func-name, .cds-func-tpl, .cds-func-snmp', function() {
                var row = $(this).closest('tr');
                var mid = row.data('id');
                $.post('cereus_datasync_ajax.php', {
                    action: 'update_function_map',
                    map_id: mid,
                    profile_id: cdsProfileId,
                    device_function: row.find('.cds-func-name').val(),
                    host_template_id: row.find('.cds-func-tpl').val(),
                    snmp_check: row.find('.cds-func-snmp').is(':checked') ? 1 : 0,
                    __csrf_magic: csrfMagicToken
                }, null, 'json');
            });
        });

        function buildFuncRow(id, fn, tplId, snmpCheck) {
            var rowCount = $('#cds-func-table tr[data-id]').length;
            var rowClass = (rowCount % 2 === 0) ? 'odd' : 'even';
            var opts = Object.entries(cdsTemplates).map(function(e) {
                return '<option value="' + e[0] + '"' + (e[0] == tplId ? ' selected' : '') + '>' + e[1] + '</option>';
            }).join('');
            return '<tr data-id="' + id + '" class="' + rowClass + '">'
                + '<td style="padding:5px 10px;"><input type="text" class="cds-func-name ui-state-default ui-corner-all" value="' + $('<div>').text(fn).html() + '" style="width:100%;" placeholder="e.g. Router"></td>'
                + '<td style="padding:5px 10px;"><select class="cds-func-tpl ui-state-default ui-corner-all" style="width:100%;">' + opts + '</select></td>'
                + '<td style="padding:5px 10px;text-align:center;"><input type="checkbox" class="cds-func-snmp"' + (snmpCheck ? ' checked' : '') + ' title="Run interface name check for this device type"></td>'
                + '<td style="padding:5px 10px;text-align:center;"><button type="button" class="ui-button cds-del-func" data-id="' + id + '" style="min-width:0;padding:2px 8px;">&#128465;</button></td>'
                + '</tr>';
        }
        </script>
        <?php
    }
}

// ─── Helper: render a function map table row ─────────────────────────────────

function cereus_datasync_render_funcmap_row(int $id, string $func, int $tplId, int $snmpCheck, array $templates): void {
    static $rowIndex = 0;
    $rowClass = ($rowIndex % 2 === 0) ? 'odd' : 'even';
    $rowIndex++;

    print '<tr data-id="' . $id . '" class="' . $rowClass . '">';
    print '<td style="padding:5px 10px;"><input type="text" class="cds-func-name ui-state-default ui-corner-all" value="' . html_escape($func) . '" style="width:100%;" placeholder="e.g. Router"></td>';
    print '<td style="padding:5px 10px;"><select class="cds-func-tpl ui-state-default ui-corner-all" style="width:100%;">';
    foreach ($templates as $tid => $tname) {
        print '<option value="' . $tid . '"' . ($tid == $tplId ? ' selected' : '') . '>' . html_escape($tname) . '</option>';
    }
    print '</select></td>';
    print '<td style="padding:5px 10px;text-align:center;" title="' . __('Run SNMP interface name check for this device type before adding', 'cereus_datasync') . '">';
    print '<input type="checkbox" class="cds-func-snmp"' . ($snmpCheck ? ' checked' : '') . '>';
    print '</td>';
    print '<td style="padding:5px 10px;text-align:center;"><button type="button" class="ui-button cds-del-func" data-id="' . $id . '" style="min-width:0;padding:2px 8px;">&#128465;</button></td>';
    print '</tr>';
}
