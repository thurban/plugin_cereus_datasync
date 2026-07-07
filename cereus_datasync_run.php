<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// CLI runner — launched in background by cereus_datasync_ajax.php trigger_run.
// Never called directly from the web.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

chdir(dirname(__DIR__, 2)); // Cacti root
require('./include/cli_check.php');
require_once('./plugins/cereus_datasync/lib/license_check.php');
require_once('./plugins/cereus_datasync/lib/functions.php');
require_once('./plugins/cereus_datasync/lib/SyncEngine.php');

$profileId     = 0;
$runId         = 0;
$dryRun        = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--profile=(\d+)$/', $arg, $m)) $profileId = (int)$m[1];
    if (preg_match('/^--run=(\d+)$/',     $arg, $m)) $runId     = (int)$m[1];
    if ($arg === '--dry') $dryRun = true;
}

if (!$profileId || !$runId) {
    cacti_log('cereus_datasync runner: missing --profile or --run argument', false, 'SYSTEM');
    exit(1);
}

try {
    $engine = new CereusDatasyncEngine();
    $engine->run($profileId, $dryRun, $runId);
} catch (\Throwable $e) {
    cacti_log('cereus_datasync runner error [' . get_class($e) . ']: ' . $e->getMessage(), false, 'SYSTEM');
    exit(1);
}
