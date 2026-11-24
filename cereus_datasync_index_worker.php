<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Data query indexing worker — launched by SyncEngine via proc_open().
// Runs each device's data queries once; run_data_query() then applies Cacti's
// Graph Rules (and the tree rules for the graphs they create). Reads a JSON job
// list from stdin, writes JSON results to stdout. Called as:
//   php cereus_datasync_index_worker.php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

chdir(dirname(__DIR__, 2)); // Cacti root

// A fresh proc_open() process with its own DB connection, as Cacti's own
// cli/poller_reindex_hosts.php would have.
$no_http_headers = true;
define('CACTI_CLI_ONLY', true);
require('./include/global.php');
require_once($config['base_path'] . '/lib/api_automation_tools.php');
require_once($config['base_path'] . '/lib/api_automation.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_tree.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/sort.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/utility.php');

$input  = json_decode(file_get_contents('php://stdin'), true);
$jobs   = $input['jobs']   ?? [];
$prefix = (string)($input['prefix'] ?? '');

$results = [];

// Anything Cacti prints while indexing would corrupt the JSON on stdout.
ob_start();

foreach ($jobs as $job) {
    $hostId = (int)($job['host_id'] ?? 0);
    if (!$hostId) continue;

    $result = ['graphs' => [], 'errors' => []];
    $before = (int)db_fetch_cell_prepared('SELECT COALESCE(MAX(id), 0) FROM graph_local WHERE host_id = ?', [$hostId]);

    foreach ($job['queries'] ?? [] as $queryId) {
        $queryId = (int)$queryId;

        try {
            run_data_query($hostId, $queryId);
        } catch (\Throwable $e) {
            $result['errors'][] = 'data query ' . $queryId . ': ' . $e->getMessage();
        }

        // Once per device and data query, whatever the walk found — so a device
        // without e.g. IP SLA entries is not walked for them on every run.
        db_execute_prepared(
            'INSERT INTO plugin_cds_dq_indexed (host_id, snmp_query_id, indexed_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE indexed_at = NOW()',
            [$hostId, $queryId]
        );
    }

    $graphIds = array_map('intval', array_column(db_fetch_assoc_prepared(
        'SELECT id FROM graph_local WHERE host_id = ? AND id > ? ORDER BY id',
        [$hostId, $before]
    ), 'id'));

    if ($prefix !== '') {
        foreach ($graphIds as $graphId) {
            db_execute_prepared(
                'UPDATE graph_templates_graph SET title = CONCAT(?, title) WHERE local_graph_id = ?',
                [$prefix, $graphId]
            );
            update_graph_title_cache($graphId);
        }
    }

    $result['graphs'] = $graphIds;
    $results[$hostId] = $result;
}

ob_end_clean();

echo json_encode($results);
