<?php
// SPDX-License-Identifier: GPL-2.0-or-later

// Catch fatal errors (out-of-memory, class-not-found, etc.) and surface them
// as JSON so the modal shows a meaningful message instead of a silent 500.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'PHP fatal: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line']]);
    }
});

ob_start();

chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/license_check.php');
require_once(__DIR__ . '/lib/functions.php');

// Release the PHP session lock so other requests in the same browser session
// are not blocked. Must happen after auth.php has written its session data.
session_write_close();

// Discard anything the includes may have printed, then lock in JSON content type
ob_clean();

if (!api_user_realm_auth('cereus_datasync.php')) {
    http_response_code(403);
    header('Content-Type: application/json');
    print json_encode(['error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!cereus_datasync_license_ok()) {
    http_response_code(403);
    print json_encode(['error' => 'License required']);
    exit;
}

// Use PHP's immutable raw input to bypass any $_CACTI_REQUEST manipulation by hooks.
// get_nfilter_request_var() reads $_CACTI_REQUEST first, which set_request_var() can clear.
$action = sanitize_search_string(
    filter_input(INPUT_POST, 'action', FILTER_DEFAULT)
    ?? filter_input(INPUT_GET,  'action', FILTER_DEFAULT)
    ?? ''
);

if ($action === '') {
    // Diagnostic: log what arrived so we can identify why action is missing.
    global $_CACTI_REQUEST;
    cacti_log('cereus_datasync AJAX: empty action.'
        . ' method:[' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ']'
        . ' post_keys:[' . implode(',', array_keys($_POST)) . ']'
        . ' cacti_req_action:[' . (isset($_CACTI_REQUEST['action']) ? $_CACTI_REQUEST['action'] : 'NOT_SET') . ']'
        . ' filter_post:[' . (filter_input(INPUT_POST, 'action') ?? 'NULL') . ']',
        false, 'PLUGIN');
}

// ─── Dispatch ─────────────────────────────────────────────────────────────────

switch ($action) {

    // POST — trigger a sync run (or dry run) as a background process
    case 'trigger_run': {
        $profileId = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $dryRun    = (int)get_nfilter_request_var('dry_run', '0') === 1;

        if (!$profileId) {
            print json_encode(['error' => 'Missing profile_id']);
            exit;
        }

        $profile = cereus_datasync_get_profile($profileId);
        if (!$profile) {
            print json_encode(['error' => 'Profile not found']);
            exit;
        }

        // Pre-create the run record so the UI can poll it immediately
        db_execute_prepared(
            "INSERT INTO plugin_cds_runs (profile_id, profile_name, started_at, status, dry_run, excel_file)
             VALUES (?, ?, NOW(), 'queued', ?, '')",
            [$profileId, $profile['name'], $dryRun ? 1 : 0]
        );
        $runId = (int)db_fetch_insert_id();

        // Use Cacti's configured PHP binary path — avoids PHP_BINARY which in FPM
        // context returns /usr/sbin/php-fpm rather than the CLI interpreter.
        $phpBin = read_config_option('path_php_binary');
        $script  = __DIR__ . '/cereus_datasync_run.php';
        $cmd     = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script)
                 . ' --profile=' . $profileId
                 . ' --run='     . $runId
                 . ($dryRun ? ' --dry' : '');

        // Redirect stdout+stderr to null; '&' detaches the process
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd . ' > NUL 2>&1', 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }

        print json_encode(['run_id' => $runId, 'status' => 'queued']);
        break;
    }

    // GET — poll an in-progress or completed run for status updates
    case 'poll_run': {
        $runId = (int)get_filter_request_var('run_id', FILTER_VALIDATE_INT);
        if (!$runId) {
            print json_encode(['error' => 'Missing run_id']);
            exit;
        }
        $run = db_fetch_row_prepared('SELECT * FROM plugin_cds_runs WHERE id = ?', [$runId]);
        if (!$run) {
            print json_encode(['error' => 'Run not found']);
            exit;
        }
        print json_encode([
            'status' => $run['status'],
            'stats'  => [
                'excel_total'        => (int)$run['excel_total'],
                'excel_raw_total'    => (int)$run['excel_raw_total'],
                'excel_skipped_load' => (int)$run['excel_skipped_load'],
                'cacti_total'        => (int)$run['cacti_total'],
                'added'              => (int)$run['added'],
                'updated'            => (int)$run['updated'],
                'marked_deleted'     => (int)$run['marked_deleted'],
                'skipped'            => (int)$run['skipped'],
                'failed'             => (int)$run['failed'],
                'tree_placed'        => (int)$run['tree_placed'],
                'graphs_found'       => (int)$run['graphs_found'],
                'graphs_created'     => (int)$run['graphs_created'],
                'checking'           => (int)($run['checking'] ?? 0),
            ],
            'error'  => $run['error_message'] ?: null,
        ]);
        break;
    }

    // POST — add a device function map row
    case 'add_function_map': {
        $profileId = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $func      = sanitize_search_string(get_nfilter_request_var('device_function', ''));
        $tplId     = (int)get_filter_request_var('host_template_id', FILTER_VALIDATE_INT);

        if (!$profileId) {
            print json_encode(['error' => 'Missing profile_id']);
            exit;
        }

        $id = cereus_datasync_save_function_map($profileId, $func, $tplId);
        print json_encode(['id' => $id]);
        break;
    }

    // POST — update a device function map row
    case 'update_function_map': {
        $mapId     = (int)get_filter_request_var('map_id', FILTER_VALIDATE_INT);
        $pid       = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $func      = sanitize_search_string(get_nfilter_request_var('device_function', ''));
        $tplId     = (int)get_filter_request_var('host_template_id', FILTER_VALIDATE_INT);
        $snmpCheck = (int)get_nfilter_request_var('snmp_check', '0');

        if (!$mapId || !$pid) {
            print json_encode(['error' => 'Missing params']);
            exit;
        }

        db_execute_prepared(
            'UPDATE plugin_cds_function_maps SET device_function = ?, host_template_id = ?, snmp_check = ? WHERE id = ? AND profile_id = ?',
            [$func, $tplId, $snmpCheck ? 1 : 0, $mapId, $pid]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete a device function map row
    case 'delete_function_map': {
        $mapId = (int)get_filter_request_var('map_id', FILTER_VALIDATE_INT);
        $pid   = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        cereus_datasync_delete_function_map($mapId, $pid);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — add a rule template
    case 'add_tree_rule': {
        $pid = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$pid) { print json_encode(['error' => 'Missing profile_id']); exit; }

        $maxOrder = (int)db_fetch_cell_prepared('SELECT COALESCE(MAX(rule_order), 0) FROM plugin_cds_tree_rules WHERE profile_id = ?', [$pid]);
        db_execute_prepared(
            "INSERT INTO plugin_cds_tree_rules (profile_id, rule_order, enabled, name, tree_id, leaf_type, host_grouping)
             VALUES (?, ?, 'on', 'New Template', 0, 2, 1)",
            [$pid, $maxOrder + 10]
        );
        print json_encode(['id' => (int)db_fetch_insert_id()]);
        break;
    }

    // POST — update a rule template header
    case 'update_rule_template': {
        $ruleId   = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid      = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$ruleId || !$pid) { print json_encode(['error' => 'Missing params']); exit; }

        db_execute_prepared(
            'UPDATE plugin_cds_tree_rules
             SET name = ?, tree_id = ?, leaf_type = ?, host_grouping = ?, enabled = ?
             WHERE id = ? AND profile_id = ?',
            [
                substr(trim(get_nfilter_request_var('name', 'Template')), 0, 128),
                (int)get_filter_request_var('tree_id', FILTER_VALIDATE_INT),
                (int)get_filter_request_var('leaf_type', FILTER_VALIDATE_INT) ?: 2,
                (int)get_filter_request_var('host_grouping', FILTER_VALIDATE_INT) ?: 1,
                (get_nfilter_request_var('enabled', '') === 'on') ? 'on' : '',
                $ruleId, $pid,
            ]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete a rule template (+ its conditions)
    case 'delete_tree_rule': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        cereus_datasync_delete_tree_rule($ruleId, $pid);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — add a condition to a template
    case 'add_rule_condition': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $seq    = (int)get_filter_request_var('sequence', FILTER_VALIDATE_INT) ?: 1;
        if (!$ruleId || !$pid) { print json_encode(['error' => 'Missing params']); exit; }

        // Verify template belongs to this profile
        $owns = db_fetch_cell_prepared('SELECT id FROM plugin_cds_tree_rules WHERE id = ? AND profile_id = ?', [$ruleId, $pid]);
        if (!$owns) { print json_encode(['error' => 'Access denied']); exit; }

        $op = ($seq > 1) ? 1 : 0; // legacy operation column: AND for subsequent conditions
        db_execute_prepared(
            'INSERT INTO plugin_cds_rule_conditions (rule_id, sequence, operation, connector, open_paren, close_paren, field, operator, pattern)
             VALUES (?, ?, ?, ?, 0, 0, ?, 1, ?)',
            [$ruleId, $seq, $op, 'AND', 'h.location', '{site}']
        );
        print json_encode(['id' => (int)db_fetch_insert_id()]);
        break;
    }

    // POST — update a condition
    case 'update_rule_condition': {
        $condId = (int)get_filter_request_var('condition_id', FILTER_VALIDATE_INT);
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$condId || !$ruleId) { print json_encode(['error' => 'Missing params']); exit; }

        $seq       = (int)get_filter_request_var('sequence', FILTER_VALIDATE_INT) ?: 1;
        $connector = strtoupper(get_nfilter_request_var('connector', 'AND')) === 'OR' ? 'OR' : 'AND';
        $openParen  = max(0, min(5, (int)get_filter_request_var('open_paren',  FILTER_VALIDATE_INT)));
        $closeParen = max(0, min(5, (int)get_filter_request_var('close_paren', FILTER_VALIDATE_INT)));
        // Keep the legacy operation column loosely in sync (0 for the first condition, else 1/2).
        $legacyOp  = ($seq <= 1) ? 0 : ($connector === 'OR' ? 2 : 1);

        db_execute_prepared(
            'UPDATE plugin_cds_rule_conditions
             SET sequence = ?, operation = ?, connector = ?, open_paren = ?, close_paren = ?, field = ?, operator = ?, pattern = ?
             WHERE id = ? AND rule_id = ?',
            [
                $seq,
                $legacyOp,
                $connector,
                $openParen,
                $closeParen,
                sanitize_search_string(get_nfilter_request_var('field', 'h.location')),
                (int)get_filter_request_var('operator', FILTER_VALIDATE_INT) ?: 1,
                get_nfilter_request_var('pattern', '{site}'),
                $condId, $ruleId,
            ]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete a condition
    case 'delete_rule_condition': {
        $condId = (int)get_filter_request_var('condition_id', FILTER_VALIDATE_INT);
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        if (!$condId || !$ruleId) { print json_encode(['error' => 'Missing params']); exit; }
        db_execute_prepared('DELETE FROM plugin_cds_rule_conditions WHERE id = ? AND rule_id = ?', [$condId, $ruleId]);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — reorder tree rules
    case 'reorder_rules': {
        $pid   = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $order = get_nfilter_request_var('order', '');
        if (!$pid || empty($order)) {
            print json_encode(['error' => 'Missing params']);
            exit;
        }
        $ids = array_map('intval', explode(',', $order));
        $ids = array_filter($ids);
        cereus_datasync_reorder_tree_rules($pid, $ids);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — add an aggregate rule
    case 'add_agg_rule': {
        $pid = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$pid) { print json_encode(['error' => 'Missing profile_id']); exit; }

        $maxOrder = (int)db_fetch_cell_prepared(
            'SELECT COALESCE(MAX(rule_order), 0) FROM plugin_cds_aggregate_rules WHERE profile_id = ?', [$pid]
        );
        db_execute_prepared(
            "INSERT INTO plugin_cds_aggregate_rules
                (profile_id, rule_order, enabled, name, graph_template_id, aggregate_template_id,
                 tree_id, tree_item_id, device_match_field, device_match_pattern, result_graph_id)
             VALUES (?, ?, 'on', 'New Aggregate', 0, 0, 0, 0, '', '', 0)",
            [$pid, $maxOrder + 10]
        );
        print json_encode(['id' => (int)db_fetch_insert_id()]);
        break;
    }

    // POST — update an aggregate rule
    case 'update_agg_rule': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$ruleId || !$pid) { print json_encode(['error' => 'Missing params']); exit; }

        $pmode = ((int)get_filter_request_var('placement_mode', FILTER_VALIDATE_INT) === 1) ? 1 : 0;

        // Device match conditions now live in plugin_cds_agg_conditions (add/update/delete_agg_condition),
        // so this handler only persists the rule-level fields.
        db_execute_prepared(
            'UPDATE plugin_cds_aggregate_rules
             SET name = ?, graph_template_id = ?, aggregate_template_id = ?,
                 tree_id = ?, tree_item_id = ?, placement_mode = ?, site_name = ?,
                 graph_title_pattern = ?, enabled = ?
             WHERE id = ? AND profile_id = ?',
            [
                substr(trim(get_nfilter_request_var('name', 'New Aggregate')), 0, 128),
                (int)get_filter_request_var('graph_template_id', FILTER_VALIDATE_INT),
                (int)get_filter_request_var('aggregate_template_id', FILTER_VALIDATE_INT),
                (int)get_filter_request_var('tree_id', FILTER_VALIDATE_INT),
                (int)get_filter_request_var('tree_item_id', FILTER_VALIDATE_INT),
                $pmode,
                substr(trim(get_nfilter_request_var('site_name', '')), 0, 128),
                substr(trim(get_nfilter_request_var('graph_title_pattern', '')), 0, 256),
                (get_nfilter_request_var('enabled', '') === 'on') ? 'on' : '',
                $ruleId, $pid,
            ]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — add a device-match condition to an aggregate rule
    case 'add_agg_condition': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        $seq    = (int)get_filter_request_var('sequence', FILTER_VALIDATE_INT) ?: 1;
        if (!$ruleId || !$pid) { print json_encode(['error' => 'Missing params']); exit; }

        $owns = db_fetch_cell_prepared('SELECT id FROM plugin_cds_aggregate_rules WHERE id = ? AND profile_id = ?', [$ruleId, $pid]);
        if (!$owns) { print json_encode(['error' => 'Access denied']); exit; }

        db_execute_prepared(
            'INSERT INTO plugin_cds_agg_conditions (agg_rule_id, sequence, connector, open_paren, close_paren, field, operator, pattern)
             VALUES (?, ?, ?, 0, 0, ?, 1, ?)',
            [$ruleId, $seq, 'AND', 'description', '']
        );
        print json_encode(['id' => (int)db_fetch_insert_id()]);
        break;
    }

    // POST — update an aggregate-rule condition
    case 'update_agg_condition': {
        $condId = (int)get_filter_request_var('condition_id', FILTER_VALIDATE_INT);
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        if (!$condId || !$ruleId) { print json_encode(['error' => 'Missing params']); exit; }

        $allowedFields = ['description', 'hostname', 'location'];
        $field = get_nfilter_request_var('field', 'description');
        if (!in_array($field, $allowedFields, true)) $field = 'description';

        $allowedOps = [1, 2, 3, 5, 7];
        $operator = (int)get_filter_request_var('operator', FILTER_VALIDATE_INT);
        if (!in_array($operator, $allowedOps, true)) $operator = 1;

        $connector  = strtoupper(get_nfilter_request_var('connector', 'AND')) === 'OR' ? 'OR' : 'AND';
        $openParen  = max(0, min(5, (int)get_filter_request_var('open_paren',  FILTER_VALIDATE_INT)));
        $closeParen = max(0, min(5, (int)get_filter_request_var('close_paren', FILTER_VALIDATE_INT)));
        $seq        = (int)get_filter_request_var('sequence', FILTER_VALIDATE_INT) ?: 1;

        db_execute_prepared(
            'UPDATE plugin_cds_agg_conditions
             SET sequence = ?, connector = ?, open_paren = ?, close_paren = ?, field = ?, operator = ?, pattern = ?
             WHERE id = ? AND agg_rule_id = ?',
            [
                $seq, $connector, $openParen, $closeParen, $field, $operator,
                substr(trim(get_nfilter_request_var('pattern', '')), 0, 256),
                $condId, $ruleId,
            ]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete an aggregate-rule condition
    case 'delete_agg_condition': {
        $condId = (int)get_filter_request_var('condition_id', FILTER_VALIDATE_INT);
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        if (!$condId || !$ruleId) { print json_encode(['error' => 'Missing params']); exit; }
        db_execute_prepared('DELETE FROM plugin_cds_agg_conditions WHERE id = ? AND agg_rule_id = ?', [$condId, $ruleId]);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete an aggregate rule
    case 'delete_agg_rule': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        cereus_datasync_delete_agg_rule($ruleId, $pid);
        print json_encode(['ok' => true]);
        break;
    }

    // POST — add a new OID graph rule
    case 'add_oid_rule': {
        $pid = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$pid) { print json_encode(['error' => 'Missing profile_id']); exit; }

        $maxOrder = (int)db_fetch_cell_prepared(
            'SELECT COALESCE(MAX(rule_order), 0) FROM plugin_cds_oid_rules WHERE profile_id = ?', [$pid]
        );
        db_execute_prepared(
            "INSERT INTO plugin_cds_oid_rules
                (profile_id, rule_order, enabled, name, oid, device_match_field, device_match_pattern, tree_id, tree_item_id)
             VALUES (?, ?, 'on', 'New OID Graph', '', '', '', 0, 0)",
            [$pid, $maxOrder + 10]
        );
        print json_encode(['id' => (int)db_fetch_insert_id()]);
        break;
    }

    // POST — update an OID graph rule
    case 'update_oid_rule': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        if (!$ruleId || !$pid) { print json_encode(['error' => 'Missing params']); exit; }

        $allowedMatchFields = ['', 'description', 'hostname', 'location'];
        $matchField = get_nfilter_request_var('device_match_field', '');
        if (!in_array($matchField, $allowedMatchFields, true)) $matchField = '';

        db_execute_prepared(
            'UPDATE plugin_cds_oid_rules
             SET name = ?, oid = ?, device_match_field = ?, device_match_pattern = ?,
                 tree_id = ?, tree_item_id = ?, enabled = ?
             WHERE id = ? AND profile_id = ?',
            [
                substr(trim(get_nfilter_request_var('name', 'New OID Graph')), 0, 128),
                substr(trim(get_nfilter_request_var('oid', '')), 0, 256),
                $matchField,
                substr(trim(get_nfilter_request_var('device_match_pattern', '')), 0, 256),
                (int)get_filter_request_var('tree_id', FILTER_VALIDATE_INT),
                (int)get_filter_request_var('tree_item_id', FILTER_VALIDATE_INT),
                (get_nfilter_request_var('enabled', '') === 'on') ? 'on' : '',
                $ruleId, $pid,
            ]
        );
        print json_encode(['ok' => true]);
        break;
    }

    // POST — delete an OID graph rule
    case 'delete_oid_rule': {
        $ruleId = (int)get_filter_request_var('rule_id', FILTER_VALIDATE_INT);
        $pid    = (int)get_filter_request_var('profile_id', FILTER_VALIDATE_INT);
        cereus_datasync_delete_oid_rule($ruleId, $pid);
        print json_encode(['ok' => true]);
        break;
    }

    // GET — fetch tree nodes for a given tree
    case 'get_tree_nodes': {
        $treeId = (int)get_filter_request_var('tree_id', FILTER_VALIDATE_INT);
        $nodes  = cereus_datasync_get_tree_nodes_array($treeId);
        print json_encode(['nodes' => $nodes]);
        break;
    }

    default:
        http_response_code(400);
        print json_encode(['error' => 'Unknown action: ' . html_escape($action)]);
}
