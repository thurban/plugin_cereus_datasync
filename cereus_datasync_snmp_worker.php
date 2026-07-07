<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Parallel SNMP WAN-check worker — launched by SyncEngine via proc_open().
// Reads a JSON device batch from stdin. No database access.
// Outputs JSON results to stdout. Called as:
//   php cereus_datasync_snmp_worker.php <wan_pattern> <timeout_ms>

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

chdir(dirname(__DIR__, 2)); // Cacti root

// Load Cacti's global environment for snmp lib + read_config_option().
// This is a fresh proc_open() process — no inherited DB socket from the parent.
$no_http_headers = true;
define('CACTI_CLI_ONLY', true);
require('./include/global.php');
require_once('./lib/snmp.php');

$wanPattern  = $argv[1] ?? '-WAN-';
$timeoutMs   = (int)($argv[2] ?? 2000);

$devices = json_decode(file_get_contents('php://stdin'), true);
if (!is_array($devices)) {
    echo json_encode([]);
    exit;
}

$results = [];

foreach ($devices as $dev) {
    $ip        = trim($dev['ip']             ?? '');
    $community = trim($dev['snmp_community'] ?? 'public') ?: 'public';
    $key       = $dev['hostname']            ?? $ip;

    if (empty($ip)) {
        $results[$key] = ['has_wan' => false];
        continue;
    }

    $hasWan = false;

    $ifAliases = @cacti_snmp_walk(
        $ip, $community,
        '.1.3.6.1.2.1.31.1.1.1.18',  // ifAlias OID
        2,                             // SNMPv2c
        '', '', '', '', '', '',        // v3 params (unused)
        161,                           // port
        $timeoutMs,
        1,                             // retries
        10                             // max OIDs
    );

    if (is_array($ifAliases)) {
        foreach ($ifAliases as $entry) {
            if (stripos($entry['value'] ?? '', $wanPattern) !== false) {
                $hasWan = true;
                break;
            }
        }
    }

    $results[$key] = ['has_wan' => $hasWan];
}

echo json_encode($results);
