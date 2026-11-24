<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Parallel SNMP naming-check worker — launched by SyncEngine via proc_open().
// Reads the checks and a JSON device batch from stdin. No database access.
// Writes one "CDS {json}" line per device to stdout as soon as it is checked, so
// the run can show progress. Called as:
//   php cereus_datasync_snmp_worker.php <timeout_ms> <port>

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

chdir(dirname(__DIR__, 2)); // Cacti root

// Load Cacti's global environment for snmp lib + read_config_option().
// This is a fresh proc_open() process — no inherited DB socket from the parent.
$no_http_headers = true;
define('CACTI_CLI_ONLY', true);
require('./include/global.php');
require_once('./lib/snmp.php');
require_once(__DIR__ . '/lib/snmp_checks.php');

$timeoutMs = (int)($argv[1] ?? 2000);
$port      = (int)($argv[2] ?? 161);

$input   = json_decode(file_get_contents('php://stdin'), true);
$checks  = $input['checks']  ?? [];
$devices = $input['devices'] ?? null;
if (!is_array($devices)) {
    exit;
}

foreach ($devices as $dev) {
    $ip        = trim($dev['ip']             ?? '');
    $community = trim($dev['snmp_community'] ?? 'public') ?: 'public';
    $key       = $dev['hostname']            ?? $ip;

    $matched = empty($ip) ? null : cereus_datasync_snmp_check_device(cereus_datasync_snmp_checks_for_function($checks, (string)($dev['dev_function'] ?? '')), [
        'hostname'  => $ip,
        'community' => $community,
        'port'      => $port,
    ], $timeoutMs);

    echo 'CDS ' . json_encode(['key' => $key, 'matched' => $matched]) . "\n";
    flush();
}
