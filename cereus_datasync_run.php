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

// The web UI shows the run as "queued" until the engine starts it. Anything
// that stops the runner before or during the sync must end the run as failed
// with the reason, or the UI waits on it forever.
function cereus_datasync_runner_fail(int $runId, string $message): void {
    cacti_log('cereus_datasync runner error: ' . $message, false, 'SYSTEM');

    db_execute_prepared(
        "UPDATE plugin_cds_runs
            SET status = 'failed', completed_at = NOW(), error_message = ?
          WHERE id = ? AND status IN ('queued', 'running')",
        [substr($message, 0, 1000), $runId]
    );
}

// Fatal errors — e.g. a missing vendor/ or lib/sync/ file — bypass the catch below.
register_shutdown_function(function () use ($runId) {
    $err = error_get_last();

    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        cereus_datasync_runner_fail($runId, $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

require_once('./plugins/cereus_datasync/lib/license_check.php');
require_once('./plugins/cereus_datasync/lib/functions.php');
require_once('./plugins/cereus_datasync/lib/SyncEngine.php');

try {
    $engine = new CereusDatasyncEngine();
    $engine->run($profileId, $dryRun, $runId);
} catch (\Throwable $e) {
    cereus_datasync_runner_fail($runId, '[' . get_class($e) . '] ' . $e->getMessage());
    exit(1);
}
