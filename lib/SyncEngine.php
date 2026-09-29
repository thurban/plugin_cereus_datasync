<?php
// SPDX-License-Identifier: GPL-2.0-or-later

// Branch-path helpers are shared with the UI; poller_bottom loads this file on
// its own, so pull them in here rather than relying on the caller.
require_once __DIR__ . '/functions.php';

class CereusDatasyncEngine {

    private array  $profile;
    private array  $colMaps   = [];
    private array  $funcMaps  = [];   // device_function => host_template_id
    private array  $treeRules = [];
    private array  $treeRuleConds  = [];   // rule id => condition rows
    private array  $treeRuleWarned = [];   // rule ids already logged as not evaluable
    private array  $siteIdCache    = [];   // "region/country/site" => sites.id
    private array  $reqGroups      = [];   // co-requisite group => member template ids
    private array  $gatedRules     = [];   // group => location => template id => generated rule
    private array  $materialised   = [];   // template id|rule signature => true, per rule pass
    private array  $usedRuleNames  = [];   // generated rule name => rule signature, per rule pass
    private array  $aggRules  = [];
    private array  $oidRules  = [];
    private int    $runId     = 0;
    private bool   $dryRun    = false;
    private array  $stats     = [
        'excel_total'        => 0, 'excel_raw_total'    => 0,
        'excel_skipped_load' => 0, 'cacti_total'        => 0,
        'added'              => 0, 'updated'             => 0,
        'marked_deleted'     => 0, 'skipped'             => 0,
        'failed'             => 0, 'tree_placed'         => 0,
        'graphs_found'       => 0, 'graphs_created'      => 0,
        'empty_sites_marked' => 0, 'empty_trees_marked'  => 0,
        'sites_unmarked'     => 0, 'trees_unmarked'      => 0,
        'checking'           => 0, // devices pending SNMP interface name check
    ];

    // ─── Public API ──────────────────────────────────────────────────────────

    public function run(int $profileId, bool $dryRun = false, int $existingRunId = 0): array {
        $this->dryRun = $dryRun;
        if ($existingRunId) {
            $this->preCreatedRunId = $existingRunId;
        }

        // Extend MySQL's net_read_timeout for this session so a slow SNMP walk
        // or large Cacti query doesn't trigger "MySQL server has gone away".
        db_execute("SET SESSION net_read_timeout = 300");
        db_execute("SET SESSION net_write_timeout = 300");

        if (!$this->loadProfile($profileId)) {
            throw new \RuntimeException("Profile $profileId not found");
        }

        $this->colMaps   = $this->loadColumnMaps();
        $this->funcMaps  = $this->loadFunctionMaps();
        $this->treeRules = $this->loadTreeRules();
        $this->aggRules  = $this->loadAggRules();
        $this->oidRules  = $this->loadOidRules();

        // Load CLI sync classes
        $this->requireCliClasses();

        $syncConfig = $this->buildSyncConfig();
        $logger     = new \CactiSync\Logger($syncConfig);
        // Permissive validator — overrides the CLI Validator's strict rules that
        // silently drop rows before SyncEngine sees them.
        //
        // validateDeviceFunction: CLI only allows functions in allowed_device_functions list;
        //   we resolve templates in addDevice() so every function value is acceptable.
        //
        // validateExcelDevice: CLI requires regex-clean hostname AND a valid routable IP.
        //   Many real-world Excel files have hostnames with spaces/slashes, missing IPs,
        //   or IPs formatted in non-standard ways. We relax to: must have at least one
        //   of (non-empty hostname) or (valid IP); everything else passes.
        $validator  = new class($syncConfig) extends \CactiSync\Validator {
            public array $rejections = [];   // rows dropped by validateExcelDevice

            public function validateDeviceFunction($deviceFunction): bool { return true; }

            public function validateExcelDevice($device): array {
                $hostname = trim($device['hostname'] ?? '');
                $ip       = trim($device['ip']       ?? '');
                $errors   = [];

                if ($hostname === '' && $ip === '') {
                    $errors[] = 'Both hostname and IP are empty';
                }
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $errors[] = 'Invalid IP: ' . $ip;
                }

                if (!empty($errors)) {
                    $this->rejections[] = [
                        'hostname' => $hostname ?: '(empty)',
                        'ip'       => $ip       ?: '(empty)',
                        'reason'   => implode('; ', $errors),
                    ];
                }

                return ['valid' => empty($errors), 'errors' => $errors];
            }
        };
        $matcher    = new \CactiSync\DeviceMatcher($logger);
        $transaction = new \CactiSync\DatabaseTransaction($logger);
        $excelLoader = new \CactiSync\ExcelLoader($syncConfig, $validator, $logger);
        $devMgr      = new \CactiSync\DeviceManager($syncConfig, $logger, $transaction, $validator);
        $snmpChecker = new \CactiSync\ParallelSNMPChecker($syncConfig, $logger);

        // Find Excel file
        $excelFile = $this->findExcelFile();
        if (!$excelFile) {
            throw new \RuntimeException('No Excel file found at path: ' . $this->profile['excel_path']);
        }

        // Create run record
        $this->runId = $this->startRun($excelFile);

        try {
            // Load source data
            $excelDevices = $excelLoader->loadDevices($excelFile);
            $cactiDevices = $devMgr->loadCactiDevices();

            // Log each row the ExcelLoader silently dropped so the user can see why
            $dropped = $validator->rejections;
            $this->stats['excel_skipped_load'] = count($dropped);
            $this->stats['excel_raw_total']    = count($excelDevices) + count($dropped);
            $this->stats['excel_total']        = count($excelDevices);
            $this->stats['cacti_total']        = count($cactiDevices);

            foreach ($dropped as $r) {
                $this->logDetail('load_skip', $r['hostname'], $r['ip'], null, $r['reason']);
            }

            $this->flushStats();

            // ── Pre-create all Cacti sites from Excel location data ───────────
            // Sites must exist before autoGraphRulesForAllLocations() so that the
            // {site_id} placeholder resolves to a real integer for every location.
            // addDevice() also calls getOrCreateSiteId() but only as devices are
            // added one-by-one — too late for the automation rules built below.
            if (!$this->dryRun) {
                $seenLocations = [];
                foreach ($excelDevices as $dev) {
                    $key = ($dev['region'] ?? '') . '|' . ($dev['country'] ?? '') . '|' . ($dev['site'] ?? '');
                    if (!isset($seenLocations[$key])) {
                        $seenLocations[$key] = true;
                        $this->getOrCreateSite($devMgr,
                            $dev['region']  ?? '',
                            $dev['country'] ?? '',
                            $dev['site']    ?? ''
                        );
                    }
                }
            }

            // ── Auto graph rules — build tree branches and Cacti automation rules
            //    BEFORE devices are added so that when createWanGraphs() calls
            //    create_complete_graph_from_template(), automation_hook_graph_create_tree()
            //    fires against rules that already exist and places graphs immediately.
            if (!$this->dryRun) {
                $this->autoGraphRulesForAllLocations($excelDevices);
            }

            // Build O(n) lookup maps
            $matcher->buildLookupMaps($cactiDevices);

            // ── Mark devices not in Excel ─────────────────────────────────
            $notInExcel = $matcher->findCactiDevicesNotInExcel($excelDevices);
            foreach ($notInExcel as $device) {
                $tag = $this->profile['deletion_tag'];
                if (strpos((string)$device['description'], $tag) !== false) continue;
                if ($this->profile['deletion_skip_localhost'] && $device['description'] === 'localhost') continue;

                if ($this->dryRun) {
                    $this->stats['marked_deleted']++;
                    $this->logDetail('marked_deleted_dry', $device['description'], $device['hostname'], null, 'dry-run');
                } else {
                    $ok = $devMgr->markAsDeleted($device['id'], 'Not found in Excel import ' . date('Y-m-d'));
                    if ($ok) {
                        // The tag went onto host.description directly, so every graph
                        // still carries its pre-tag cached title until this runs.
                        $this->refreshTitleCaches((int)$device['id']);
                        $this->stats['marked_deleted']++;
                        $this->logDetail('marked_deleted', $device['description'], $device['hostname'], (int)$device['id'], '');
                    } else {
                        $this->stats['failed']++;
                        $this->logDetail('failed', $device['description'], $device['hostname'], (int)$device['id'], 'mark_deleted failed');
                    }
                }
                $this->flushStats();
            }

            // ── Categorise Excel devices ──────────────────────────────────
            $toAdd      = [];
            $toUpdate   = [];
            $toRecheck  = []; // existing devices with no metadata changes — still need graph recheck
            $l2check    = [];

            foreach ($excelDevices as $exDev) {
                $cactiDev = $matcher->findMatch($exDev);

                if ($cactiDev) {
                    // Check description / location drift
                    $exLocation = substr($exDev['site'] ?? '', 0, 40);
                    $exSiteId   = !$this->dryRun ? $this->getOrCreateSite($devMgr, $exDev['region'], $exDev['country'], $exDev['site']) : 0;

                    $descChanged = ($cactiDev['description'] !== $exDev['hostname']);
                    $locChanged  = ($cactiDev['location'] !== $exLocation || (!$this->dryRun && $cactiDev['site_id'] != $exSiteId));

                    if ($descChanged || $locChanged) {
                        $toUpdate[] = compact('exDev', 'cactiDev', 'exLocation', 'exSiteId', 'descChanged', 'locChanged');
                    } else {
                        // No metadata change — queue for graph recheck only
                        $toRecheck[] = ['deviceId' => (int)$cactiDev['id'], 'exDev' => $exDev];
                    }
                } elseif (!isset($this->funcMaps[$exDev['dev_function']])) {
                    $this->stats['skipped']++;
                    $this->logDetail('skipped', $exDev['hostname'], $exDev['ip'], null,
                        'No function mapping for "' . $exDev['dev_function'] . '"');
                } elseif ($this->profile['snmp_check_l2']
                       && isset($this->snmpCheckFunctions[$exDev['dev_function']])) {
                    // Interface naming check enabled for this device function — SNMP walk first
                    $l2check[] = $exDev;
                } else {
                    $toAdd[] = ['device' => $exDev, 'has_wan' => true];
                }
            }

            // ── SNMP interface name checks ────────────────────────────────
            if (!empty($l2check) && !$this->dryRun) {
                $this->stats['checking'] = count($l2check);
                $this->flushStats();

                // Use proc_open() workers for parallelism — each is a clean PHP
                // process with no inherited DB connection, so the parent's MySQL
                // socket survives. pcntl_fork() is intentionally avoided because
                // forked children destroy the shared PDO socket on exit.
                $useParallel = !empty($this->profile['snmp_enable_parallel']);
                if ($useParallel) {
                    $snmpResults = $this->checkL2Parallel($l2check);
                    foreach ($l2check as $dev) {
                        $key    = $dev['hostname'] ?? $dev['ip'] ?? '';
                        $hasWan = $snmpResults[$key]['has_wan'] ?? false;
                        $toAdd[] = ['device' => $dev, 'has_wan' => $hasWan];
                        $this->stats['checking']--;
                    }
                    $this->flushStats();
                } else {
                    $callback = $devMgr->createSNMPCheckCallback();
                    foreach ($l2check as $dev) {
                        $result  = $callback($dev);
                        $toAdd[] = ['device' => $dev, 'has_wan' => $result['has_wan'] ?? false];
                        $this->stats['checking']--;
                        $this->flushStats();
                    }
                }
            } elseif (!empty($l2check)) {
                // Dry run — no SNMP, so the interface check cannot run: count the
                // device as an add, but say it still depends on that check.
                foreach ($l2check as $dev) {
                    $toAdd[] = ['device' => $dev, 'has_wan' => true, 'unchecked' => true];
                }
            }

            // ── Update existing ───────────────────────────────────────────
            foreach ($toUpdate as $upd) {
                if ($this->dryRun) {
                    $this->stats['updated']++;
                    $this->logDetail('updated_dry', $upd['exDev']['hostname'], $upd['exDev']['ip'], (int)$upd['cactiDev']['id'], 'dry-run');
                    continue;
                }
                $ok = true;
                if ($upd['descChanged']) {
                    $ok = $ok && $devMgr->updateDescription((int)$upd['cactiDev']['id'], $upd['exDev']['hostname'], $upd['cactiDev']['description']);
                }
                if ($upd['locChanged']) {
                    $ok = $ok && $devMgr->updateLocationAndSite((int)$upd['cactiDev']['id'], $upd['exLocation'], $upd['exSiteId'], $upd['exDev']['hostname']);
                }
                if ($ok) {
                    // Graph titles embed |host_description| and |host_location|, both
                    // of which this branch may just have changed underneath them.
                    $this->refreshTitleCaches((int)$upd['cactiDev']['id']);
                    $this->stats['updated']++;
                    $this->logDetail('updated', $upd['exDev']['hostname'], $upd['exDev']['ip'], (int)$upd['cactiDev']['id'], '');
                } else {
                    $this->stats['failed']++;
                    $this->logDetail('failed', $upd['exDev']['hostname'], $upd['exDev']['ip'], (int)$upd['cactiDev']['id'], 'update failed');
                }
                $this->flushStats(); // persist before risky graph creation

                // Office moved (or a name change affecting tree rules) — re-place
                // the device in the tree so its branch follows the new location,
                // and remove the now-stale placement under the old branch.
                $this->stats['tree_placed'] += $this->reconcileTreePlacement((int)$upd['cactiDev']['id'], $upd['exDev']);

                // Recheck WAN graphs for updated devices (only if no graphs exist yet)
                $this->maybeCreateWanGraphs((int)$upd['cactiDev']['id'], $upd['exDev']);
            }

            // ── Recheck graphs for unchanged existing devices ─────────────
            // Only runs SNMP walk for devices that currently have no graphs at all,
            // avoiding expensive walks on devices that were already fully set up.
            if (!$this->dryRun) {
                foreach ($toRecheck as $item) {
                    $this->maybeCreateWanGraphs($item['deviceId'], $item['exDev']);
                    $this->flushStats();
                }
            }

            // ── Add new devices ───────────────────────────────────────────
            foreach ($toAdd as $item) {
                $device  = $item['device'];
                $hasWan  = $item['has_wan'];

                if ($this->profile['snmp_check_l2']
                    && isset($this->snmpCheckFunctions[$device['dev_function']])
                    && !$hasWan) {
                    $this->stats['skipped']++;
                    $this->logDetail('skipped', $device['hostname'], $device['ip'], null,
                        'No interface matching pattern "' . ($this->profile['snmp_wan_pattern'] ?? '') . '"');
                    continue;
                }

                if ($this->dryRun) {
                    $this->stats['added']++;
                    $this->logDetail('added_dry', $device['hostname'], $device['ip'], null,
                        !empty($item['unchecked'])
                            ? 'dry-run — interface check not run; a real run adds it only if an interface matches "'
                              . ($this->profile['snmp_wan_pattern'] ?? '') . '"'
                            : 'dry-run');
                    continue;
                }

                $deviceId = $this->addDevice($device, $devMgr);
                if ($deviceId) {
                    $this->stats['added']++;
                    $this->logDetail('added', $device['hostname'], $device['ip'], $deviceId, '');
                    $this->flushStats(); // persist BEFORE risky operations — bare exit() in
                                        // template.php can kill the process mid-device
                    // Apply pattern-based tree placement rules
                    $placed = $this->applyTreeRules($deviceId, $device);
                    $this->stats['tree_placed'] += $placed;
                    // Create WAN interface graphs (triggers Cacti automation → tree placement)
                    $this->createWanGraphs($deviceId, $device);
                } else {
                    $this->stats['failed']++;
                    $this->logDetail('failed', $device['hostname'], $device['ip'], null, 'api_device_save failed');
                    $this->flushStats();
                }

                usleep(100000); // 0.1s between adds
            }

            // ── Apply aggregate graph rules (post-sync, live runs only) ────────
            if (!$this->dryRun) {
                $this->applyAggregateRules();
                $this->applyOidGraphRules();

                // Every graph of this run exists now, so co-requisite groups can
                // finally be judged per location.
                $this->applyCoRequisiteGroups();
            }

            // ── Flag containers left empty by this sync ────────────────────────
            // Runs last, after every add/update/delete and graph/tree placement,
            // so the tree and site membership reflect the final post-sync state.
            $this->markEmptyContainers();
            $this->flushStats();

            $this->finishRun('completed');

        } catch (\Throwable $e) {
            $this->finishRun('failed', $e->getMessage());
            throw $e;
        }

        return $this->stats;
    }

    // ─── Profile loading ─────────────────────────────────────────────────────

    private function loadProfile(int $id): bool {
        $row = db_fetch_row_prepared('SELECT * FROM plugin_cds_profiles WHERE id = ?', [$id]);
        if (!$row) return false;
        $this->profile = $row;
        return true;
    }

    private function loadColumnMaps(): array {
        $rows = db_fetch_assoc_prepared('SELECT field_name, col_letter FROM plugin_cds_column_maps WHERE profile_id = ?', [(int)$this->profile['id']]);
        $map  = [];
        if (cacti_sizeof($rows)) {
            foreach ($rows as $row) {
                $map[$row['field_name']] = strtoupper($row['col_letter']);
            }
        }
        // Defaults if not configured
        $defaults = ['hostname' => 'A', 'ip' => 'B', 'snmp_community' => 'E', 'country' => 'K', 'device_function' => 'M', 'region' => 'O', 'site' => 'P'];
        foreach ($defaults as $field => $col) {
            if (!isset($map[$field])) $map[$field] = $col;
        }
        return $map;
    }

    private array $snmpCheckFunctions = []; // device functions that have interface check enabled

    private function loadFunctionMaps(): array {
        $rows = db_fetch_assoc_prepared(
            "SELECT device_function, host_template_id, snmp_check FROM plugin_cds_function_maps WHERE profile_id = ? AND enabled = 'on'",
            [(int)$this->profile['id']]
        );
        $map = [];
        $this->snmpCheckFunctions = [];
        if (cacti_sizeof($rows)) {
            foreach ($rows as $row) {
                $map[$row['device_function']] = (int)$row['host_template_id'];
                if ($row['snmp_check']) {
                    $this->snmpCheckFunctions[$row['device_function']] = true;
                }
            }
        }
        return $map;
    }

    private function loadTreeRules(): array {
        $rows = db_fetch_assoc_prepared(
            "SELECT * FROM plugin_cds_tree_rules WHERE profile_id = ? AND enabled = 'on' ORDER BY rule_order ASC",
            [(int)$this->profile['id']]
        );
        return cacti_sizeof($rows) ? $rows : [];
    }

    private function loadAggRules(): array {
        $rows = db_fetch_assoc_prepared(
            "SELECT * FROM plugin_cds_aggregate_rules WHERE profile_id = ? AND enabled = 'on' ORDER BY rule_order ASC, id ASC",
            [(int)$this->profile['id']]
        );
        return cacti_sizeof($rows) ? $rows : [];
    }

    private function loadOidRules(): array {
        $rows = db_fetch_assoc_prepared(
            "SELECT * FROM plugin_cds_oid_rules WHERE profile_id = ? AND enabled = 'on' ORDER BY rule_order ASC, id ASC",
            [(int)$this->profile['id']]
        );
        return cacti_sizeof($rows) ? $rows : [];
    }

    // ─── Aggregate graph rule application ────────────────────────────────────

    private function applyAggregateRules(): void {
        if (empty($this->aggRules)) return;

        global $config;

        if (!function_exists('aggregate_create_update')) {
            require_once $config['base_path'] . '/lib/api_aggregate.php';
        }
        if (!function_exists('api_tree_item_save')) {
            require_once $config['base_path'] . '/lib/api_tree.php';
        }

        // aggregate_create_update() stores user_id — ensure session key exists in CLI context
        if (empty($_SESSION['sess_user_id'])) {
            $_SESSION['sess_user_id'] = 1;
        }

        $fieldMap = [
            'description' => 'h.description',
            'hostname'    => 'h.hostname',
            'location'    => 'h.location',
        ];

        foreach ($this->aggRules as $rule) {
            $graphTemplateId = (int)$rule['graph_template_id'];
            $treeId          = (int)$rule['tree_id'];
            $treeItemId      = 0;   // resolved from the rule's branch path below
            $aggTemplateId   = (int)$rule['aggregate_template_id'];
            $titlePattern    = trim($rule['graph_title_pattern'] ?? '');

            if (!$graphTemplateId || !$treeId) {
                $this->logDetail('agg_skip', '', '', null,
                    'Rule "' . $rule['name'] . '": missing graph_template_id or tree_id');
                continue;
            }

            // Build the device-match WHERE clause from the rule's condition list (AND/OR +
            // parentheses). No conditions → match every graph of the template. Always JOIN
            // graph_templates_graph so the title filter can apply without a second query; the
            // JOIN is cheap (indexed on local_graph_id).
            $useTitleFilter = $titlePattern !== '';

            $conds = db_fetch_assoc_prepared(
                'SELECT * FROM plugin_cds_agg_conditions WHERE agg_rule_id = ? ORDER BY sequence, id',
                [(int)$rule['id']]
            );

            $whereParams = [];
            try {
                $whereClause = $this->buildAggConditionWhere(
                    cacti_sizeof($conds) ? $conds : [], $fieldMap, $whereParams
                );
            } catch (\Throwable $e) {
                $this->logDetail('agg_skip', '', '', null,
                    'Rule "' . $rule['name'] . '": ' . $e->getMessage());
                continue;
            }

            if ($whereClause !== null) {
                $sql    = "SELECT gl.id FROM graph_local gl
                           INNER JOIN host h ON h.id = gl.host_id
                           INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                           WHERE gl.graph_template_id = ?
                             AND ($whereClause)";
                $params = array_merge([$graphTemplateId], $whereParams);
            } else {
                $sql    = 'SELECT gl.id FROM graph_local gl
                           INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                           WHERE gl.graph_template_id = ?';
                $params = [$graphTemplateId];
            }

            if ($useTitleFilter) {
                $sql    .= ' AND gtg.title_cache LIKE ?';
                $params[] = '%' . $titlePattern . '%';
            }

            $sql .= ' ORDER BY gl.id';
            $rows = db_fetch_assoc_prepared($sql, $params);

            if (!cacti_sizeof($rows)) {
                $this->logDetail('agg_skip', '', '', null,
                    'Rule "' . $rule['name'] . '": no graphs found for graph_template_id=' . $graphTemplateId);
                continue;
            }

            $memberGraphIds = array_column($rows, 'id');
            $resultGraphId  = (int)$rule['result_graph_id'];

            // Purge stale member entries so the rebuild is clean
            if ($resultGraphId > 0) {
                $aggId = (int)db_fetch_cell_prepared(
                    'SELECT id FROM aggregate_graphs WHERE local_graph_id = ?', [$resultGraphId]
                );
                if ($aggId) {
                    db_execute_prepared(
                        'DELETE FROM aggregate_graphs_items WHERE aggregate_graph_id = ?', [$aggId]
                    );
                }
            }

            $attribs = [
                'graph_title'           => $rule['name'],
                'aggregate_template_id' => $aggTemplateId,
                'graph_template_id'     => $graphTemplateId,
                'aggregate_graph_id'    => $resultGraphId,
                'template_propogation'  => ($aggTemplateId > 0) ? 'on' : '',
                'gprint_prefix'         => '',
                'gprint_format'         => '',
                'graph_type'            => 0,  // keep original item types
                'total'                 => 0,  // no totals line
                'total_type'            => 0,
                'total_prefix'          => '',
                'reorder'               => 0,
                'item_no'               => 0,
                'color_templates'       => [],
                'graph_item_types'      => [],
                'cdefs'                 => [],
                'skipped_items'         => [],
                'total_items'           => [],
            ];

            $wasNew = ($resultGraphId <= 0);

            try {
                aggregate_create_update($resultGraphId, $memberGraphIds, $attribs);
            } catch (\Throwable $e) {
                $this->logDetail('agg_error', '', '', null,
                    'Rule "' . $rule['name'] . '": ' . $e->getMessage());
                continue;
            }

            if ($wasNew && $resultGraphId > 0) {
                $this->stats['graphs_created']++;
                $this->logDetail('graph_created', '', '', null,
                    'graph_id=' . $resultGraphId . ' aggregate rule "' . $rule['name'] . '"');
            }

            // Persist updated result_graph_id (set by aggregate_create_update via reference)
            db_execute_prepared(
                'UPDATE plugin_cds_aggregate_rules SET result_graph_id = ? WHERE id = ?',
                [$resultGraphId, (int)$rule['id']]
            );

            // Resolve the placement node. Fixed-node mode follows the rule's branch
            // path; site mode targets a single header named after the site, which is
            // the same thing expressed as a one-segment path — "/" inside the site
            // name is escaped so it stays one header rather than splitting.
            // Either way a header that is not there yet gets created.
            $placementMode = (int)($rule['placement_mode'] ?? 0);
            $siteName      = trim($rule['site_name'] ?? '');

            if ($placementMode === 1) {
                $treeItemId = ($siteName !== '')
                    ? $this->resolveRuleBranch($treeId, str_replace('/', '\\/', $siteName))
                    : 0;
            } else {
                $treeItemId = $this->resolveRuleBranch($treeId, (string)($rule['branch_path'] ?? ''));
            }

            // Place aggregate in tree (idempotent — skip if already there)
            if ($resultGraphId > 0 && !api_tree_graph_exists($treeId, $treeItemId, $resultGraphId)) {
                api_tree_item_save(
                    0,                     // new item
                    $treeId,
                    TREE_ITEM_TYPE_GRAPH,
                    $treeItemId,           // 0 = root of tree
                    '',                    // title unused for graph items
                    $resultGraphId,
                    0, 0, 0, 1, false
                );
                $this->countTreePlacement($resultGraphId, '', '', null);
            }

            // Place each member graph at the same tree node
            foreach ($memberGraphIds as $memberId) {
                if ($memberId > 0 && !api_tree_graph_exists($treeId, $treeItemId, $memberId)) {
                    api_tree_item_save(
                        0,
                        $treeId,
                        TREE_ITEM_TYPE_GRAPH,
                        $treeItemId,
                        '',
                        $memberId,
                        0, 0, 0, 1, false
                    );
                    $this->countTreePlacement($memberId, '', '', null);
                }
            }

            $this->logDetail('agg_done', '', '', null,
                'Rule "' . $rule['name'] . '" graph_id=' . $resultGraphId
                . ' members=' . count($memberGraphIds));
        }
    }

    /**
     * Assemble a parameterised SQL WHERE fragment from an aggregate rule's device-match
     * conditions. Each condition contributes "<field> <op> ?" wrapped in its opening/closing
     * parentheses and joined to the previous one with its AND/OR connector, e.g.
     *   h.description LIKE ? AND ( h.location LIKE ? OR h.location LIKE ? )
     * Field names come from the $fieldMap whitelist and operators from a fixed table, so the
     * only user-supplied SQL is the bound "?" pattern — no injection surface. Returns null when
     * there are no usable conditions (caller then matches all graphs of the template). Throws on
     * unbalanced parentheses so the caller can skip the rule rather than emit malformed SQL.
     */
    private function buildAggConditionWhere(array $conds, array $fieldMap, array &$params): ?string {
        // operator code => [sql operator, pattern prefix, pattern suffix]
        $opMap = [
            1 => ['LIKE',     '%', '%'],   // contains
            2 => ['NOT LIKE', '%', '%'],   // does not contain
            3 => ['LIKE',     '',  '%'],   // begins with
            5 => ['LIKE',     '%', ''],    // ends with
            7 => ['=',        '',  ''],    // equals (exact)
        ];

        $sql     = '';
        $first   = true;
        $balance = 0;

        foreach ($conds as $c) {
            $field = $fieldMap[$c['field']] ?? null;
            if ($field === null) continue; // unknown field (shouldn't happen — validated on save)

            $op    = $opMap[(int)$c['operator']] ?? $opMap[1];
            $open  = max(0, (int)($c['open_paren']  ?? 0));
            $close = max(0, (int)($c['close_paren'] ?? 0));
            $conn  = (strtoupper($c['connector'] ?? 'AND') === 'OR') ? 'OR' : 'AND';

            if (!$first) {
                $sql .= " $conn ";
            }
            $sql     .= str_repeat('(', $open) . " $field {$op[0]} ? " . str_repeat(')', $close);
            $params[] = $op[1] . trim($c['pattern']) . $op[2];
            $balance += $open - $close;
            $first    = false;
        }

        if ($sql === '') {
            $params = [];
            return null;
        }
        if ($balance !== 0) {
            $params = [];
            throw new \RuntimeException('unbalanced parentheses in device match conditions');
        }

        return trim($sql);
    }

    // ─── OID graph rule application ───────────────────────────────────────────

    private function applyOidGraphRules(): void {
        if (empty($this->oidRules)) return;

        global $config;

        if (!function_exists('create_complete_graph_from_template')) {
            require_once $config['base_path'] . '/lib/template.php';
        }
        if (!function_exists('api_tree_item_save')) {
            require_once $config['base_path'] . '/lib/api_tree.php';
        }
        if (!function_exists('push_out_host')) {
            require_once $config['base_path'] . '/lib/utility.php';
        }

        // Resolve SNMP Generic OID Template IDs by name (portable across installations)
        $dataTemplateId = (int)db_fetch_cell(
            "SELECT id FROM data_template WHERE name = 'SNMP - Generic OID Template' LIMIT 1"
        );
        $graphTemplateId = (int)db_fetch_cell(
            "SELECT id FROM graph_templates WHERE name = 'SNMP - Generic OID Template' LIMIT 1"
        );
        $oidFieldId = $dataTemplateId ? (int)db_fetch_cell_prepared(
            "SELECT dif.id FROM data_input_fields dif
             JOIN data_template_data dtd ON dtd.data_input_id = dif.data_input_id AND dtd.local_data_id = 0
             WHERE dtd.data_template_id = ? AND dif.type_code = 'snmp_oid' LIMIT 1",
            [$dataTemplateId]
        ) : 0;

        if (!$dataTemplateId || !$graphTemplateId || !$oidFieldId) {
            $this->logDetail('oid_skip', '', '', null,
                'SNMP Generic OID Template not found (dt=' . $dataTemplateId . ' gt=' . $graphTemplateId . ' field=' . $oidFieldId . ')');
            return;
        }

        foreach ($this->oidRules as $rule) {
            $oid        = trim($rule['oid']);
            $treeId     = (int)$rule['tree_id'];
            $treeItemId = (int)$rule['tree_item_id'];
            $matchField = trim($rule['device_match_field']);
            $matchPat   = trim($rule['device_match_pattern']);

            if (!$oid) {
                $this->logDetail('oid_skip', '', '', null, 'Rule "' . $rule['name'] . '": OID is empty');
                continue;
            }

            // Collect matching enabled hosts
            $validFields = ['description', 'hostname', 'location'];
            if ($matchField && $matchPat && in_array($matchField, $validFields, true)) {
                $hosts = db_fetch_assoc_prepared(
                    "SELECT id, description, hostname FROM host WHERE disabled = '' AND $matchField LIKE ? ORDER BY id",
                    ['%' . $matchPat . '%']
                );
            } else {
                $hosts = db_fetch_assoc("SELECT id, description, hostname FROM host WHERE disabled = '' ORDER BY id");
            }

            if (!cacti_sizeof($hosts)) {
                $this->logDetail('oid_skip', '', '', null, 'Rule "' . $rule['name'] . '": no matching devices');
                continue;
            }

            $created = 0;
            $skipped = 0;

            foreach ($hosts as $host) {
                $hostId = (int)$host['id'];

                // Idempotency: check if a data source with this template and OID already exists for this host
                $existingLocalDataId = (int)db_fetch_cell_prepared(
                    "SELECT dl.id FROM data_local dl
                     JOIN data_template_data dtd ON dtd.local_data_id = dl.id
                     JOIN data_input_data did ON did.data_template_data_id = dtd.id
                     WHERE dl.host_id = ? AND dl.data_template_id = ? AND did.data_input_field_id = ? AND did.value = ?
                     LIMIT 1",
                    [$hostId, $dataTemplateId, $oidFieldId, $oid]
                );

                if ($existingLocalDataId) {
                    // Already exists — just ensure tree placement if configured
                    if ($treeId) {
                        $existingGraphId = (int)db_fetch_cell_prepared(
                            "SELECT gl.id FROM graph_local gl
                             JOIN graph_templates_item gti ON gti.local_graph_id = gl.id
                             JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id AND dtr.local_data_id > 0
                             WHERE gl.host_id = ? AND dtr.local_data_id = ?
                             LIMIT 1",
                            [$hostId, $existingLocalDataId]
                        );
                        if ($existingGraphId && !api_tree_graph_exists($treeId, $treeItemId, $existingGraphId)) {
                            api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_GRAPH, $treeItemId, '', $existingGraphId, 0, 0, 0, 1, false);
                            $this->countTreePlacement($existingGraphId, $host['description'], $host['hostname'], $hostId);
                        }
                    }
                    $skipped++;
                    continue;
                }

                // Inject OID and title via suggested_vals so create_complete_graph_from_template()
                // sets both atomically — title_cache is resolved, OID lands in data_input_data.
                $suggestedVals = [
                    $graphTemplateId => [
                        'graph_template' => ['title' => $rule['name']],
                        'custom_data'    => [
                            $dataTemplateId => [$oidFieldId => $oid],
                        ],
                    ],
                ];

                $cacheArray = create_complete_graph_from_template($graphTemplateId, $hostId, [], $suggestedVals);

                if (empty($cacheArray['local_graph_id'])) {
                    $this->logDetail('oid_error', $host['description'], $host['hostname'], $hostId,
                        'Rule "' . $rule['name'] . '": create_complete_graph_from_template failed');
                    continue;
                }

                $localGraphId = (int)$cacheArray['local_graph_id'];
                $localDataId  = (int)($cacheArray['local_data_id'][$dataTemplateId] ?? 0);

                // Rebuild poller_item for this host so the OID is picked up immediately
                if ($localDataId) {
                    push_out_host($hostId, $localDataId);
                }

                $this->stats['graphs_created']++;
                $this->logDetail('graph_created', $host['description'], $host['hostname'], $hostId,
                    'graph_id=' . $localGraphId . ' OID rule "' . $rule['name'] . '"');

                // Place in tree if configured
                if ($treeId && !api_tree_graph_exists($treeId, $treeItemId, $localGraphId)) {
                    api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_GRAPH, $treeItemId, '', $localGraphId, 0, 0, 0, 1, false);
                }
                $this->countTreePlacement($localGraphId, $host['description'], $host['hostname'], $hostId);

                $created++;
            }

            $this->logDetail('oid_done', '', '', null,
                'Rule "' . $rule['name'] . '" oid=' . $oid . ' created=' . $created . ' skipped=' . $skipped);
        }
    }

    // ─── Device addition ─────────────────────────────────────────────────────

    private function addDevice(array $device, \CactiSync\DeviceManager $devMgr) {
        global $config;

        if (!function_exists('api_device_save')) {
            require_once $config['base_path'] . '/lib/api_device.php';
        }

        // Resolve host template from our DB maps
        $templateId = $this->funcMaps[$device['dev_function']] ?? 0;
        if (!$templateId) {
            // Fallback: any generic template
            $templateId = (int)db_fetch_cell("SELECT id FROM host_template WHERE name LIKE '%Generic%' OR name LIKE '%SNMP%' LIMIT 1");
        }

        // Get or create site
        $siteId = $this->getOrCreateSite($devMgr, $device['region'], $device['country'], $device['site']);

        $location = substr($device['site'] ?? '', 0, 40);

        // Clear stale validation state — api_device_save/is_error_message() checks
        // $_SESSION['sess_error_fields']; a prior failure poisons every subsequent call.
        // Use direct unset rather than clear_messages() — that function calls
        // cacti_session_start() which fails (and logs a WARNING) in CLI context.
        unset($_SESSION['sess_error_fields'], $_SESSION['sess_messages']);

        // Pass template_id=0 to skip api_device_update_host_template() which does
        // run_data_query() (live SNMP walks) for every query in the template —
        // 3 queries × 258 devices × timeout = hours of blocking.
        // We wire the template associations directly below without any network access.
        $deviceId = api_device_save(
            '0',             // string — integer 0 == '' in PHP loose comparison, failing form_input_validate
            '0',             // template_id=0 avoids SNMP walks on add
            $device['hostname'],
            $device['ip'],
            $device['snmp_community'] ?: $this->profile['default_snmp_community'],
            (int)$this->profile['default_snmp_version'],
            '', '', // snmp_username, snmp_password
            (int)$this->profile['default_snmp_port'],
            (int)$this->profile['default_snmp_timeout'],
            '',  // disabled
            (int)$this->profile['default_availability'],
            (int)$this->profile['default_ping_method'],
            0,   // ping_port
            500, // ping_timeout
            2,   // ping_retries
            sprintf('Region: %s | Country: %s | Site: %s | Synced: %s', $device['region'], $device['country'], $device['site'], date('Y-m-d H:i:s')),
            '', '', '', '',  // snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context
            '',              // snmp_engine_id
            10,              // max_oids
            1,               // device_threads
            (int)$this->profile['default_poller_id'],
            $siteId,
            '',              // external_id
            $location
        );

        if ($deviceId && $deviceId > 0 && $templateId) {
            // Set template and associate its data queries / graph templates directly —
            // no SNMP walks. The poller will populate host_snmp_cache on its next cycle.
            db_execute_prepared('UPDATE host SET host_template_id = ? WHERE id = ?', [$templateId, $deviceId]);

            db_execute_prepared(
                'INSERT IGNORE INTO host_graph (host_id, graph_template_id)
                 SELECT ?, graph_template_id FROM host_template_graph WHERE host_template_id = ?',
                [$deviceId, $templateId]
            );
            db_execute_prepared(
                'INSERT IGNORE INTO host_snmp_query (host_id, snmp_query_id, reindex_method)
                 SELECT ?, snmp_query_id, 0 FROM host_template_snmp_query WHERE host_template_id = ?',
                [$deviceId, $templateId]
            );
        }

        if (!$deviceId || $deviceId <= 0) {
            $badFields = isset($_SESSION['sess_error_fields'])
                ? implode(', ', array_keys($_SESSION['sess_error_fields']))
                : 'none';
            cacti_log(sprintf(
                'cereus_datasync: api_device_save failed — host=%s ip=%s tmpl=%d snmp_v=%d snmp_port=%d avail=%d ping_method=%d site_id=%d — rejected fields: [%s]',
                $device['hostname'], $device['ip'], $templateId,
                (int)$this->profile['default_snmp_version'],
                (int)$this->profile['default_snmp_port'],
                (int)$this->profile['default_availability'],
                (int)$this->profile['default_ping_method'],
                $siteId,
                $badFields
            ), false, 'SYSTEM');
            return false;
        }

        return (int)$deviceId;
    }

    // ─── WAN interface graph creation ────────────────────────────────────────

    // Only creates graphs when the device has none yet — avoids SNMP-walking
    // every existing device on every sync run.
    private function maybeCreateWanGraphs(int $deviceId, array $device): void {
        if (empty($this->profile['auto_create_graphs'])) return;
        $hasGraphs = (int)db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM graph_local WHERE host_id = ?', [$deviceId]
        );
        if (!$hasGraphs) {
            $this->createWanGraphs($deviceId, $device);
        }
    }

    private function createWanGraphs(int $deviceId, array $device): void {
        if (empty($this->profile['auto_create_graphs'])) return;

        $queryTypeId = (int)$this->profile['graph_query_type_id'];
        if (!$queryTypeId) return;

        global $config;

        $base = $config['base_path'] . '/';
        // Each file is required independently so a missing one doesn't block others
        if (!function_exists('api_device_dq_add'))                 require_once $base . 'lib/api_device.php';
        if (!function_exists('run_data_query'))                     require_once $base . 'lib/data_query.php';
        if (!function_exists('push_out_host'))                      require_once $base . 'lib/utility.php';
        if (!function_exists('create_complete_graph_from_template')) require_once $base . 'lib/template.php';
        if (!function_exists('get_graph_template_graph'))           require_once $base . 'lib/api_graph.php';
        if (!function_exists('get_data_template_item'))             require_once $base . 'lib/api_data_source.php';
        if (!function_exists('api_tree_graph_exists'))              require_once $base . 'lib/api_tree.php';

        // Look up the snmp_query_graph record — contains snmp_query_id + graph_template_id
        $qtype = db_fetch_row_prepared(
            'SELECT * FROM snmp_query_graph WHERE id = ?', [$queryTypeId]
        );
        if (!$qtype) return;

        $snmpQueryId = (int)$qtype['snmp_query_id'];
        $templateId  = (int)$qtype['graph_template_id'];
        $wanPattern  = trim($this->profile['snmp_wan_pattern'] ?? '-WAN-') ?: '-WAN-';

        // Associate the data query with the device if not already
        $assoc = db_fetch_cell_prepared(
            'SELECT host_id FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?',
            [$deviceId, $snmpQueryId]
        );
        if (!$assoc) {
            api_device_dq_add($deviceId, $snmpQueryId, DATA_QUERY_AUTOINDEX_NONE);
        }

        // SNMP walk — populates host_snmp_cache with interface data
        // Wrapped in try/catch so an SNMP failure on one device doesn't abort the run.
        try {
            run_data_query($deviceId, $snmpQueryId);
        } catch (\Throwable $e) {
            $this->logDetail('graph_skip', $device['hostname'], $device['ip'], $deviceId,
                'run_data_query failed: ' . $e->getMessage());
            return;
        }

        // Find interfaces whose ifAlias matches the WAN pattern
        $interfaces = db_fetch_assoc_prepared(
            "SELECT DISTINCT snmp_index FROM host_snmp_cache
             WHERE host_id = ? AND snmp_query_id = ?
               AND field_name = 'ifAlias' AND field_value LIKE ?",
            [$deviceId, $snmpQueryId, '%' . $wanPattern . '%']
        );

        if (!cacti_sizeof($interfaces)) {
            return; // no matching interfaces — normal for unreachable or non-WAN devices
        }

        $this->stats['graphs_found'] += count($interfaces);

        $snmpIndexOn = get_best_data_query_index_type($deviceId, $snmpQueryId);

        foreach ($interfaces as $iface) {
            $snmpIndex = $iface['snmp_index'];

            // Skip if a graph already exists for this device + template + query + index
            $exists = db_fetch_cell_prepared(
                'SELECT id FROM graph_local
                 WHERE host_id = ? AND graph_template_id = ?
                   AND snmp_query_id = ? AND snmp_index = ?',
                [$deviceId, $templateId, $snmpQueryId, $snmpIndex]
            );
            if ($exists) continue;

            $snmpQueryArray = [
                'snmp_query_id'       => $snmpQueryId,
                'snmp_index_on'       => $snmpIndexOn,
                'snmp_query_graph_id' => $queryTypeId,
                'snmp_index'          => $snmpIndex,
            ];

            // Isolated try/catch per graph — template.php has a bare exit() that
            // would otherwise kill the entire background process for one bad device.
            try {
                $suggestedVals = [];
                $result = create_complete_graph_from_template($templateId, $deviceId, $snmpQueryArray, $suggestedVals);
            } catch (\Throwable $e) {
                $this->logDetail('graph_error', $device['hostname'], $device['ip'], $deviceId,
                    'create_graph failed index=' . $snmpIndex . ': ' . $e->getMessage());
                continue;
            }

            if ($result !== false) {
                $this->stats['graphs_created']++;
                if (!empty($result['local_data_id'])) {
                    foreach ($result['local_data_id'] as $dataId) {
                        push_out_host($deviceId, $dataId);
                    }
                }
                $this->logDetail('graph_created', $device['hostname'], $device['ip'], $deviceId,
                    'graph_id=' . $result['local_graph_id'] . ' snmp_index=' . $snmpIndex);

                // Cacti's tree automation places the graph while it is created;
                // count it here, since nothing else sees that placement.
                $this->countTreePlacement((int)$result['local_graph_id'], $device['hostname'], $device['ip'], $deviceId);
            }
        }
    }

    // ─── Template-driven Cacti automation rule generation ────────────────────

    private function autoGraphRulesForAllLocations(array $excelDevices): void {
        if (empty($this->profile['auto_graph_rules'])) return;

        // Load enabled rule templates for this profile
        $templates = db_fetch_assoc_prepared(
            "SELECT * FROM plugin_cds_tree_rules
             WHERE profile_id = ? AND enabled = 'on'
             ORDER BY rule_order",
            [(int)$this->profile['id']]
        );
        if (!cacti_sizeof($templates)) return;

        // Load all conditions for these templates in one query
        $tplIds     = array_column($templates, 'id');
        $placeholders = implode(',', array_fill(0, count($tplIds), '?'));
        $condRows   = db_fetch_assoc_prepared(
            "SELECT * FROM plugin_cds_rule_conditions
             WHERE rule_id IN ($placeholders)
             ORDER BY rule_id, sequence",
            $tplIds
        );
        $conditions = [];
        if (cacti_sizeof($condRows)) {
            foreach ($condRows as $c) {
                $conditions[(int)$c['rule_id']][] = $c;
            }
        }

        // A template whose parentheses do not balance can only produce a broken
        // WHERE clause — build_rule_item_filter() concatenates the tokens verbatim
        // and never checks — so drop it here. Writing it out would replace a
        // working automation rule with one that errors for every device, and the
        // breakage would show up as "no graphs placed" rather than as an error.
        foreach ($templates as $tpl) {
            $tplId = (int)$tpl['id'];
            if (isset($conditions[$tplId]) && !$this->conditionsBalanced($conditions[$tplId])) {
                $this->logDetail('auto_rule_skip', '', '', null,
                    'Template "' . $tpl['name'] . '": unbalanced parentheses in its conditions — '
                    . 'no automation rule generated. Balance every "(" with a matching ")".');
                unset($conditions[$tplId]);
            }
        }

        // Graph templates sharing a co-requisite group are placed as a unit, so
        // remember every member — one that is misconfigured or skipped at a
        // location must still hold the rest of its group back there.
        $this->reqGroups  = [];
        $this->gatedRules = [];

        // These live for the whole pass, not per location: a branch path that drops
        // the site level (or is entirely literal) collapses many locations onto one
        // branch, and without them the same rule would be rebuilt — and its
        // conditions overwritten — once per location.
        $this->materialised  = [];
        $this->usedRuleNames = [];
        foreach ($templates as $tpl) {
            if ($this->requireGroup($tpl) !== '') {
                $this->reqGroups[$this->requireGroup($tpl)][] = (int)$tpl['id'];
            }
        }

        // Collect unique locations from the full Excel device set
        $seen = [];
        foreach ($excelDevices as $device) {
            $parts = array_values(array_filter([
                trim($device['region']  ?? ''),
                trim($device['country'] ?? ''),
                trim($device['site']    ?? ''),
            ]));
            if (empty($parts)) continue;
            $key = implode('|', $parts);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            foreach ($templates as $tpl) {
                $tplConds = $conditions[(int)$tpl['id']] ?? [];
                if (empty($tplConds)) continue;
                $this->applyRuleTemplate($tpl, $tplConds, $parts, $device);
            }
        }
    }

    // Parentheses must balance across the whole condition list, and a ")" may never
    // precede its "(". build_rule_item_filter() does no validation of its own, so an
    // unmatched bracket reaches MySQL as a syntax error.
    private function conditionsBalanced(array $conditions): bool {
        $depth = 0;
        foreach ($conditions as $cond) {
            $depth += max(0, (int)($cond['open_paren']  ?? 0));
            $depth -= max(0, (int)($cond['close_paren'] ?? 0));
            if ($depth < 0) return false;
        }

        return $depth === 0;
    }

    private function applyRuleTemplate(array $tpl, array $conditions, array $locationParts, array $device): void {
        $treeId = (int)$tpl['tree_id'];
        if (!$treeId) return;

        // Resolve site_id from the sites table — used by the {site_id} placeholder.
        // The key format matches what getOrCreateSiteId() stores: "region/country/site".
        $locationKey = implode('/', array_filter([
            trim($device['region']  ?? ''),
            trim($device['country'] ?? ''),
            trim($device['site']    ?? ''),
        ]));
        // A site an earlier sync flagged as empty still carries the deletion tag,
        // and siteIdFor() finds it in that form too — otherwise {site_id} resolves
        // to 0 for a returning location, and with a "contains" operator 0 matches
        // every site id with a zero in it.
        $resolvedSiteId = $this->deviceSiteId($device);

        // Substitution map — {site_id} enables exact integer matching on h.site_id,
        // which is more reliable than text matching on the truncated h.location field.
        $subs = [
            '{site}'     => substr(trim($device['site']    ?? ''), 0, 40),
            '{region}'   => trim($device['region']  ?? ''),
            '{country}'  => trim($device['country'] ?? ''),
            '{location}' => substr(trim($device['site']    ?? ''), 0, 40),
            '{site_id}'  => (string)$resolvedSiteId,
        ];

        // Never write a rule whose {site_id} could not be resolved: the placeholder
        // would be substituted with 0 and the generated filter would match a wide
        // swathe of unrelated sites instead of none.
        if (!$resolvedSiteId) {
            foreach ($conditions as $cond) {
                if (strpos((string)$cond['pattern'], '{site_id}') !== false) {
                    $this->logDetail('auto_rule_skip', '', '', null,
                        'Template "' . $tpl['name'] . '" at "' . $locationKey . '": site not found, '
                        . 'so {site_id} cannot be resolved — rule not generated');

                    return;
                }
            }
        }

        $leafType    = (int)$tpl['leaf_type'] ?: 2;
        $hostGrouping = (int)$tpl['host_grouping'] ?: 1;
        $items       = $this->buildRuleItems($conditions, $subs);
        $group       = $this->requireGroup($tpl);

        $branch = $this->templateBranchTitles($tpl, $device, $locationParts);
        if (empty($branch)) {
            $this->logDetail('auto_rule_skip', '', '', null,
                'Template "' . $tpl['name'] . '" at "' . $locationKey . '": branch path "'
                . trim((string)($tpl['branch_path'] ?? '')) . '" resolved to nothing — rule not generated');

            return;
        }

        // The branch and the resolved conditions together are the rule's identity:
        // locations that land on the same branch with the same conditions share one
        // rule instead of each writing — and overwriting — their own.
        $branchKey = implode(' / ', $branch);
        $signature = md5($treeId . '|' . $branchKey . '|' . json_encode($items));
        $dedupKey  = (int)$tpl['id'] . '|' . $signature;
        if (isset($this->materialised[$dedupKey])) return;
        $this->materialised[$dedupKey] = true;

        // Name the rule after the branch it fills. On the default path that is the
        // location, so existing rules keep their names and are reused.
        $ruleName = substr('Auto[' . $tpl['name'] . ']: ' . $branchKey, 0, 255);

        // Same branch, different conditions — a literal path combined with a
        // location-specific condition such as {site_id}. Both rules are wanted, so
        // disambiguate rather than let the second overwrite the first.
        if (isset($this->usedRuleNames[$ruleName]) && $this->usedRuleNames[$ruleName] !== $signature) {
            $ruleName = substr($ruleName . ' #' . $locationKey, 0, 255);
        }
        $this->usedRuleNames[$ruleName] = $signature;

        // A co-requisite rule is not written yet: whether its location qualifies
        // is only known once this run's graphs exist. applyCoRequisiteGroups()
        // writes it — branch and all — only when the whole group matches.
        if ($group !== '') {
            $this->gatedRules[$group][implode('|', $locationParts)][(int)$tpl['id']] = [
                'name'     => $ruleName,
                'tpl'      => $tpl,
                'branch'   => $branch,
                'items'    => $items,
                'tree_id'  => $treeId,
                'leaf'     => $leafType,
                'grouping' => $hostGrouping,
            ];

            return;
        }

        $leafItemId = $this->templateBranch($treeId, $branch);
        if (!$leafItemId) return;

        $ruleId = $this->saveTreeRule($ruleName, $treeId, $leafItemId, $leafType, $hostGrouping, $items);
        if (!$ruleId) return;

        $this->logDetail('auto_rule', '', '', null,
            'Materialised "' . $ruleName . '" (rule_id=' . $ruleId . ', ' . count($items) . ' tokens)');
    }

    // Reuse the rule if it already exists (dedup by generated name), otherwise create it.
    // Reuse lets edited conditions — including newly added OR/parenthesis grouping — take
    // effect on the next sync instead of being permanently frozen at first creation.
    // Returns the automation_tree_rules id, or 0 on failure.
    private function saveTreeRule(string $ruleName, int $treeId, int $leafItemId, int $leafType, int $hostGrouping, array $items): int {
        $ruleId = (int)db_fetch_cell_prepared(
            'SELECT id FROM automation_tree_rules WHERE name = ?',
            [$ruleName]
        );

        if ($ruleId) {
            db_execute_prepared(
                "UPDATE automation_tree_rules
                    SET tree_id = ?, tree_item_id = ?, leaf_type = ?, host_grouping_type = ?
                  WHERE id = ?",
                [$treeId, $leafItemId, $leafType, $hostGrouping, $ruleId]
            );
            db_execute_prepared(
                'DELETE FROM automation_match_rule_items WHERE rule_id = ? AND rule_type = ?',
                [$ruleId, AUTOMATION_RULE_TYPE_TREE_MATCH]
            );
        } else {
            db_execute_prepared(
                "INSERT INTO automation_tree_rules
                    (name, tree_id, tree_item_id, leaf_type, host_grouping_type, enabled)
                 VALUES (?, ?, ?, ?, ?, 'on')",
                [$ruleName, $treeId, $leafItemId, $leafType, $hostGrouping]
            );
            $ruleId = (int)db_fetch_insert_id();
        }
        if (!$ruleId) return 0;

        $seq = 1;
        foreach ($items as $item) {
            db_execute_prepared(
                'INSERT INTO automation_match_rule_items
                    (rule_id, rule_type, sequence, operation, field, operator, pattern)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$ruleId, AUTOMATION_RULE_TYPE_TREE_MATCH, $seq++,
                 $item['operation'], $item['field'], $item['operator'], $item['pattern']]
            );
        }

        return $ruleId;
    }

    // Materialise the conditions into a Cacti automation_match_rule_items token stream.
    // Each plugin condition can carry a connector (AND/OR joining it to the previous
    // condition) plus opening/closing parentheses, so one condition may expand into
    // several tokens: [connector] [ '(' … ] <field op pattern> [ … ')' ]. Cacti's
    // build_rule_item_filter() walks these tokens to assemble the WHERE clause, so the
    // grouping "A AND ( B OR C OR D )" is reproduced faithfully.
    private function buildRuleItems(array $conditions, array $subs): array {
        $items = [];
        $token = function(int $operation, string $field, int $operator, string $patt) use (&$items) {
            $items[] = ['operation' => $operation, 'field' => $field, 'operator' => $operator, 'pattern' => $patt];
        };

        $first = true;
        foreach ($conditions as $cond) {
            $pattern = str_replace(array_keys($subs), array_values($subs), $cond['pattern']);
            $open    = max(0, (int)($cond['open_paren']  ?? 0));
            $close   = max(0, (int)($cond['close_paren'] ?? 0));
            $connOp  = (strtoupper($cond['connector'] ?? 'AND') === 'OR')
                       ? AUTOMATION_OPER_OR : AUTOMATION_OPER_AND;

            if ($first) {
                // First condition: no leading connector.
                for ($i = 0; $i < $open; $i++) {
                    $token(AUTOMATION_OPER_LEFT_BRACKET, '', 0, '');
                }
                $token(AUTOMATION_OPER_NULL, $cond['field'], (int)$cond['operator'], $pattern);
            } elseif ($open == 0) {
                // Common case — fold the connector directly onto the field token: "AND field …".
                $token($connOp, $cond['field'], (int)$cond['operator'], $pattern);
            } else {
                // Connector must precede the opening bracket(s): "AND ( field …".
                $token($connOp, '', 0, '');
                for ($i = 0; $i < $open; $i++) {
                    $token(AUTOMATION_OPER_LEFT_BRACKET, '', 0, '');
                }
                $token(AUTOMATION_OPER_NULL, $cond['field'], (int)$cond['operator'], $pattern);
            }

            for ($i = 0; $i < $close; $i++) {
                $token(AUTOMATION_OPER_RIGHT_BRACKET, '', 0, '');
            }

            $first = false;
        }

        return $items;
    }

    // Graphs a token stream would match, evaluated the way Cacti's
    // get_matching_graphs() does but without a stored rule behind it.
    private function matchingGraphIds(array $items): array {
        if (!cacti_sizeof($items)) return [];

        $rows = db_fetch_assoc('SELECT DISTINCT gl.id
            FROM graph_local AS gl
            INNER JOIN graph_templates_graph AS gtg
            LEFT JOIN graph_templates AS gt
            ON (gl.graph_template_id=gt.id)
            LEFT JOIN host AS h
            ON (gl.host_id=h.id)
            LEFT JOIN host_template AS ht
            ON (h.host_template_id=ht.id)
            WHERE gl.id=gtg.local_graph_id AND ' . build_rule_item_filter($items), false);

        return cacti_sizeof($rows) ? array_map('intval', array_column($rows, 'id')) : [];
    }

    // Count a graph that now sits in a graph tree towards Tree Placed and log
    // where it went. A graph not in any tree (yet) is not counted — a
    // co-requisite graph, for one, is counted when its group places it.
    private function countTreePlacement(int $graphId, string $hostname, string $ip, ?int $deviceId): void {
        if ($graphId <= 0 || $this->dryRun) return;

        $where = db_fetch_row_prepared(
            'SELECT gt.name AS tree, COALESCE(p.title, \'\') AS branch
             FROM graph_tree_items AS gti
             INNER JOIN graph_tree AS gt ON gt.id = gti.graph_tree_id
             LEFT JOIN graph_tree_items AS p ON p.id = gti.parent
             WHERE gti.local_graph_id = ?
             ORDER BY gti.id DESC
             LIMIT 1',
            [$graphId]
        );
        if (!cacti_sizeof($where)) return;

        $this->stats['tree_placed']++;
        $this->logDetail('tree_placed', $hostname, $ip, $deviceId,
            'graph_id=' . $graphId . ' in "' . $where['tree'] . '"'
            . ($where['branch'] !== '' ? ' under "' . $where['branch'] . '"' : ' at the tree root'));
    }

    // The co-requisite group of a template. Only graph templates take part: a
    // host template places the device itself, so there is no graph to require.
    private function requireGroup(array $tpl): string {
        if ((int)($tpl['leaf_type'] ?? 2) !== TREE_ITEM_TYPE_GRAPH) {
            return '';
        }

        return trim((string)($tpl['require_group'] ?? ''));
    }

    // The header titles of a template's branch for one location. An explicit path
    // wins; without one the branch is the device's region / country / site, as it
    // always was. Placeholders take the untruncated values — the 40-char clamp on
    // {site} exists only to match h.location and would rename long site branches —
    // and a level whose placeholder is empty is dropped.
    private function templateBranchTitles(array $tpl, array $device, array $locationParts): array {
        $path = trim((string)($tpl['branch_path'] ?? ''));

        if ($path === '') {
            return array_map('cereus_datasync_truncate_title', $locationParts);
        }

        // A "/" inside a value is part of that one header, not a new level.
        $subs = [];
        foreach ($this->branchSubs($device) as $placeholder => $value) {
            $subs[$placeholder] = str_replace('/', '\\/', $value);
        }

        return cereus_datasync_split_branch_path(str_replace(array_keys($subs), array_values($subs), $path));
    }

    // Find or create a branch in the target tree from its header titles.
    // Returns the deepest header's tree item id, or 0 on failure.
    private function templateBranch(int $treeId, array $titles): int {
        $leafItemId = 0;
        foreach ($titles as $title) {
            $leafItemId = $this->findOrCreateBranch($treeId, $leafItemId, $title);
            if (!$leafItemId) {
                $this->logDetail('tree_error', '', '', null,
                    'could not create branch "' . implode(' / ', $titles) . '" in tree #' . $treeId);

                return 0;
            }
        }

        return $leafItemId;
    }

    /**
     * Write and apply the rules of co-requisite templates. At each location a
     * group is written only when every member template matches at least one
     * graph there: then its branch and Cacti tree rules are created and the
     * matching graphs placed, and graphs created later are placed by Cacti as
     * usual. Otherwise no branch and no rule are created, and a rule an earlier
     * run wrote is deleted together with the placements it made.
     */
    private function applyCoRequisiteGroups(): void {
        global $config;

        if (empty($this->gatedRules)) return;

        include_once $config['base_path'] . '/lib/api_automation.php';
        include_once $config['base_path'] . '/lib/api_tree.php';

        foreach ($this->gatedRules as $group => $locations) {
            foreach ($locations as $location => $members) {
                $matches = [];
                $missing = [];

                foreach ($this->reqGroups[$group] as $tplId) {
                    if (!isset($members[$tplId])) {
                        $missing[] = $tplId;
                        continue;
                    }

                    $matches[$tplId] = $this->matchingGraphIds($members[$tplId]['items']);

                    if (!cacti_sizeof($matches[$tplId])) {
                        $missing[] = $tplId;
                    }
                }

                $satisfied = empty($missing);
                $placed    = 0;
                $removed   = 0;

                foreach ($members as $tplId => $m) {
                    if ($satisfied) {
                        $leafItemId = $this->templateBranch($m['tree_id'], $m['branch']);
                        if (!$leafItemId) continue;

                        $ruleId = $this->saveTreeRule($m['name'], $m['tree_id'], $leafItemId, $m['leaf'], $m['grouping'], $m['items']);
                        if (!$ruleId) continue;

                        $rule = db_fetch_row_prepared('SELECT * FROM automation_tree_rules WHERE id = ?', [$ruleId]);

                        foreach ($matches[$tplId] as $graphId) {
                            $parent = create_all_header_nodes($graphId, $rule);
                            if (!api_tree_graph_exists($m['tree_id'], $parent, $graphId)) {
                                create_graph_node($graphId, $parent, $rule);
                                $this->countTreePlacement($graphId, '', '', null);
                                $placed++;
                            }
                        }
                    } else {
                        $removed += $this->deleteTreeRule($m['name'], $m['items']);
                    }
                }

                if ($satisfied) {
                    $this->logDetail('auto_rule', '', '', null,
                        'Co-requisite group "' . $group . '" at "' . str_replace('|', '/', $location) . '": all '
                        . count($this->reqGroups[$group]) . ' templates match — rules written, ' . $placed . ' graph(s) placed');
                } else {
                    $names = [];
                    foreach ($missing as $tplId) {
                        $names[] = isset($members[$tplId]) ? $members[$tplId]['tpl']['name'] : ('#' . $tplId);
                    }

                    $this->logDetail('auto_rule_skip', '', '', null,
                        'Co-requisite group "' . $group . '" at "' . str_replace('|', '/', $location) . '": no graph for '
                        . implode(', ', $names) . ' — no rule or branch created'
                        . ($removed ? ', ' . $removed . ' earlier placement(s) removed' : ''));
                }
            }
        }
    }

    // Delete a generated tree rule, and take the graphs it placed out of its
    // branch. Returns the number of tree items removed.
    private function deleteTreeRule(string $ruleName, array $items): int {
        $rule = db_fetch_row_prepared('SELECT id, tree_id, tree_item_id FROM automation_tree_rules WHERE name = ?', [$ruleName]);
        if (!cacti_sizeof($rule)) return 0;

        $removed = 0;
        if ((int)$rule['tree_item_id'] > 0) {
            $graphIds = $this->matchingGraphIds($items);
            if (cacti_sizeof($graphIds)) {
                $removed = $this->removeGraphsUnder((int)$rule['tree_id'], (int)$rule['tree_item_id'], $graphIds);
            }
        }

        db_execute_prepared('DELETE FROM automation_match_rule_items WHERE rule_id = ?', [(int)$rule['id']]);
        db_execute_prepared('DELETE FROM automation_tree_rule_items WHERE rule_id = ?', [(int)$rule['id']]);
        db_execute_prepared('DELETE FROM automation_tree_rules WHERE id = ?', [(int)$rule['id']]);

        return $removed;
    }

    // Delete the tree leaves of the given graphs anywhere below a branch.
    // Returns the number of items removed.
    private function removeGraphsUnder(int $treeId, int $branchId, array $graphIds): int {
        $items = db_fetch_assoc_prepared(
            'SELECT id, parent, local_graph_id FROM graph_tree_items WHERE graph_tree_id = ?',
            [$treeId]
        );

        $children = [];
        foreach ($items as $it) {
            $children[(int)$it['parent']][] = $it;
        }

        $wanted  = array_flip(array_map('intval', $graphIds));
        $removed = 0;
        $queue   = [$branchId];

        while (!empty($queue)) {
            $parent = array_shift($queue);

            foreach ($children[$parent] ?? [] as $it) {
                if (isset($wanted[(int)$it['local_graph_id']])) {
                    db_execute_prepared('DELETE FROM graph_tree_items WHERE id = ?', [(int)$it['id']]);
                    $removed++;
                } else {
                    $queue[] = (int)$it['id'];
                }
            }
        }

        return $removed;
    }

    // Find an existing header branch or create a new one in the target tree.
    // Returns the branch's graph_tree_items.id, or 0 on failure.
    private function findOrCreateBranch(int $treeId, int $parentId, string $title): int {
        return cereus_datasync_find_or_create_branch(
            $treeId, $parentId, $title, (string)($this->profile['deletion_tag'] ?? '')
        );
    }

    /**
     * Resolve a rule's configured branch path to a tree item id, creating every
     * header along the path that does not exist yet. $subs carries the
     * {site}/{region}/{country} substitutions for per-device rules; a rule with a
     * static path passes none. An empty path means the tree root, which is what 0
     * denotes to Cacti.
     */
    private function resolveRuleBranch(int $treeId, string $path, array $subs = []): int {
        $path = trim($path);
        if ($path === '' || !$treeId) return 0;

        if (!empty($subs)) {
            $path = str_replace(array_keys($subs), array_values($subs), $path);
        }

        $itemId = cereus_datasync_resolve_branch_path(
            $treeId, $path, (string)($this->profile['deletion_tag'] ?? '')
        );

        if (!$itemId) {
            $this->logDetail('tree_error', '', '', null,
                'could not create branch path "' . $path . '" in tree #' . $treeId . ' — placing at tree root');
        }

        return $itemId;
    }

    /**
     * Look a rule's branch path up without creating anything. Returns the tree
     * item id, 0 for the tree root, or -1 when the branch is not there — used by
     * the reconciliation pass, which must not materialise headers for rules that
     * do not match the device it is re-placing.
     */
    private function lookupRuleBranch(int $treeId, string $path, array $subs = []): int {
        $path = trim($path);
        if ($path === '' || !$treeId) return 0;

        if (!empty($subs)) {
            $path = str_replace(array_keys($subs), array_values($subs), $path);
        }

        return cereus_datasync_lookup_branch_path($treeId, $path);
    }

    // Placeholder map for a branch path, mirroring the substitutions available to
    // a rule template's condition patterns so a path can follow the device.
    private function branchSubs(array $device): array {
        return [
            '{region}'   => trim($device['region']  ?? ''),
            '{country}'  => trim($device['country'] ?? ''),
            '{site}'     => trim($device['site']    ?? ''),
            '{location}' => trim($device['site']    ?? ''),
        ];
    }

    /**
     * Cacti substitutes |host_description| / |host_location| once and stores the
     * result in graph_templates_graph.title_cache and data_template_data.name_cache.
     * Nothing recomputes those columns when host.description changes — the only
     * refresh path in core is api_device_save(), which this sync calls only when
     * adding a device. So a device that is tagged for deletion or renamed in the
     * source inventory keeps its old title on every graph until this runs.
     *
     * update_graph_title_cache() declines to overwrite a non-empty cache when the
     * substituted title still contains an unresolved |host_ or |query_ variable,
     * so a graph whose data query index has disappeared stays stale.
     */
    private function refreshTitleCaches(int $deviceId): void {
        global $config;

        if (!$deviceId || $this->dryRun) return;

        if (!function_exists('update_graph_title_cache_from_host')) {
            require_once $config['base_path'] . '/lib/variables.php';
        }

        update_data_source_title_cache_from_host($deviceId);
        update_graph_title_cache_from_host($deviceId);
    }

    // ─── Tree rule application ───────────────────────────────────────────────

    private function applyTreeRules(int $deviceId, array $device): int {
        global $config;

        if (empty($this->treeRules)) return 0;

        if (!function_exists('api_tree_item_save')) {
            require_once $config['base_path'] . '/lib/api_tree.php';
        }

        $placed = 0;
        foreach ($this->treeRules as $rule) {
            $treeId = (int)$rule['tree_id'];
            if (!$treeId) continue;

            // leaf_type 2 ("place matching graphs") is the automation rule's job —
            // Cacti places those as each graph is created. Only a Host rule asks
            // the plugin to put the device itself in the tree.
            if ((int)$rule['leaf_type'] !== TREE_ITEM_TYPE_HOST) continue;

            if (!$this->matchTreeRule($rule, $device)) continue;

            $parentItem = $this->ruleBranchFor($rule, $device, true);
            $grouping   = (int)$rule['host_grouping'];

            // Skip if already in this tree under this parent
            if (api_tree_host_exists($treeId, $parentItem, $deviceId)) continue;

            $result = api_tree_item_save(
                0,                   // item id (new)
                $treeId,             // tree_id
                TREE_ITEM_TYPE_HOST, // type = 3
                $parentItem,         // parent_tree_item_id
                '',                  // title
                0,                   // local_graph_id
                $deviceId,           // host_id
                0,                   // site_id
                $grouping,           // host_grouping_type (1=template, 2=data query)
                1,                   // sort_children_type
                false                // propagate_changes
            );

            if ($result) {
                $placed++;
                $this->logDetail('tree_placed', $device['hostname'], $device['ip'], $deviceId,
                    "tree_id=$treeId parent=$parentItem");
            }
        }

        return $placed;
    }

    /**
     * Re-place an existing device in the tree after its location (or a name field
     * used by a tree rule) changed. Removes the device from any of this profile's
     * managed rule-parents that no longer match, then adds it under every rule
     * that matches now. Only touches parents referenced by this profile's own tree
     * rules — manual placements elsewhere are left alone. Returns items added.
     */
    private function reconcileTreePlacement(int $deviceId, array $device): int {
        global $config;

        if (empty($this->treeRules)) return 0;

        if (!function_exists('api_tree_item_save')) {
            require_once $config['base_path'] . '/lib/api_tree.php';
        }

        // Parents a currently-matching rule wants the device to stay under. Only a
        // matching rule may create its branch — resolving the path of a rule that
        // does not apply here would materialise headers as a side-effect.
        $keepParents = [];
        foreach ($this->treeRules as $rule) {
            $treeId = (int)$rule['tree_id'];
            if (!$treeId || (int)$rule['leaf_type'] !== TREE_ITEM_TYPE_HOST) continue;
            if ($this->matchTreeRule($rule, $device)) {
                $keepParents[$treeId . ':' . $this->ruleBranchFor($rule, $device, true)] = true;
            }
        }

        // Remove the device from managed parents it should no longer be under.
        foreach ($this->treeRules as $rule) {
            $treeId = (int)$rule['tree_id'];
            if (!$treeId || (int)$rule['leaf_type'] !== TREE_ITEM_TYPE_HOST) continue;

            $parentItem = $this->ruleBranchFor($rule, $device, false);
            if ($parentItem < 0) continue;   // branch does not exist — nothing to remove
            if (isset($keepParents[$treeId . ':' . $parentItem])) continue;

            $existing = api_tree_host_exists($treeId, $parentItem, $deviceId);
            if ($existing) {
                db_execute_prepared(
                    'DELETE FROM graph_tree_items WHERE id = ? AND host_id = ?',
                    [(int)$existing, $deviceId]
                );
                $this->logDetail('tree_moved', $device['hostname'], $device['ip'] ?? '', $deviceId,
                    "removed stale placement tree_id=$treeId parent=$parentItem");
            }
        }

        // Add placements under every rule that matches now (idempotent).
        return $this->applyTreeRules($deviceId, $device);
    }

    /**
     * The branch a rule wants this device under: its configured path when it has
     * one, otherwise the Region / Country / Site path that applyRuleTemplate()
     * builds, so the plugin and the generated automation rule agree on where the
     * device belongs. With $create the missing headers are made; without it the
     * branch is only looked up and -1 comes back when it is not there.
     */
    private function ruleBranchFor(array $rule, array $device, bool $create): int {
        $treeId = (int)$rule['tree_id'];
        if (!$treeId) return $create ? 0 : -1;

        $path = trim((string)($rule['branch_path'] ?? ''));

        if ($path === '') {
            $parts = [];
            foreach ([$device['region'] ?? '', $device['country'] ?? '', $device['site'] ?? ''] as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $parts[] = str_replace('/', '\\/', $part);
                }
            }
            $path = implode('/', $parts);
        }

        return $create
            ? $this->resolveRuleBranch($treeId, $path, $this->branchSubs($device))
            : $this->lookupRuleBranch($treeId, $path, $this->branchSubs($device));
    }

    private function treeRuleConditions(int $ruleId): array {
        if (!isset($this->treeRuleConds[$ruleId])) {
            $rows = db_fetch_assoc_prepared(
                'SELECT * FROM plugin_cds_rule_conditions WHERE rule_id = ? ORDER BY sequence, id',
                [$ruleId]
            );
            $this->treeRuleConds[$ruleId] = cacti_sizeof($rows) ? $rows : [];
        }

        return $this->treeRuleConds[$ruleId];
    }

    /**
     * Evaluate a tree rule's conditions against one inventory row.
     *
     * The conditions are the same rows autoGraphRulesForAllLocations() materialises
     * into a Cacti automation rule, so they are written in SQL terms (h.location,
     * h.site_id, …) and joined with AND/OR connectors and parentheses. Evaluating
     * them here follows SQL precedence — AND binds tighter than OR — so both
     * engines read the same grouping the same way.
     *
     * Returns false for a rule with no conditions, with unbalanced parentheses, or
     * naming a field that does not exist until the device is in the database:
     * guessing either way would place devices the operator never asked for.
     */
    private function matchTreeRule(array $rule, array $device): bool {
        $conditions = $this->treeRuleConditions((int)$rule['id']);
        if (empty($conditions)) return false;

        $precedence = ['OR' => 1, 'AND' => 2];
        $values     = [];
        $ops        = [];

        // Fold the top operator over the two most recent operands.
        $apply = function(string $op) use (&$values): bool {
            if (count($values) < 2) return false;
            $b = array_pop($values);
            $a = array_pop($values);
            $values[] = ($op === 'OR') ? ($a || $b) : ($a && $b);

            return true;
        };

        $first = true;
        foreach ($conditions as $cond) {
            $result = $this->matchTreeCondition($cond, $device);

            if ($result === null) {
                if (!isset($this->treeRuleWarned[(int)$rule['id']])) {
                    $this->treeRuleWarned[(int)$rule['id']] = true;
                    $this->logDetail('tree_skip', '', '', null,
                        'Rule "' . $rule['name'] . '": condition on "' . $cond['field']
                        . '" cannot be evaluated before the device exists — leaving placement to the automation rule');
                }

                return false;
            }

            if (!$first) {
                $op = (strtoupper((string)($cond['connector'] ?? 'AND')) === 'OR') ? 'OR' : 'AND';
                while (!empty($ops) && end($ops) !== '(' && $precedence[end($ops)] >= $precedence[$op]) {
                    if (!$apply((string)array_pop($ops))) return false;
                }
                $ops[] = $op;
            }

            for ($i = 0, $n = max(0, (int)($cond['open_paren'] ?? 0)); $i < $n; $i++) {
                $ops[] = '(';
            }

            $values[] = $result;

            for ($i = 0, $n = max(0, (int)($cond['close_paren'] ?? 0)); $i < $n; $i++) {
                while (!empty($ops) && end($ops) !== '(') {
                    if (!$apply((string)array_pop($ops))) return false;
                }
                if (empty($ops)) return false;   // unbalanced ")"
                array_pop($ops);                 // discard the matching "("
            }

            $first = false;
        }

        while (!empty($ops)) {
            $op = (string)array_pop($ops);
            if ($op === '(') return false;       // unbalanced "("
            if (!$apply($op)) return false;
        }

        return (count($values) === 1) ? (bool)$values[0] : false;
    }

    /**
     * Evaluate one condition. Returns null when the condition cannot be decided
     * from the inventory row, which the caller treats as "do not place".
     */
    private function matchTreeCondition(array $cond, array $device): ?bool {
        $haystack = $this->treeRuleFieldValue((string)$cond['field'], $device);
        if ($haystack === null) return null;

        $subs    = $this->branchSubs($device) + ['{site_id}' => (string)$this->deviceSiteId($device)];
        $pattern = str_replace(array_keys($subs), array_values($subs), (string)$cond['pattern']);

        // Operator ids match the Cacti automation operators the UI offers.
        switch ((int)$cond['operator']) {
            case 1:  return stripos($haystack, $pattern) !== false;            // contains
            case 2:  return stripos($haystack, $pattern) === false;            // does not contain
            case 3:  return stripos($haystack, $pattern) === 0;                // begins with
            case 5:  return $pattern === ''                                    // ends with
                         || strcasecmp(substr($haystack, -strlen($pattern)), $pattern) === 0;
            case 7:  return strcasecmp($haystack, $pattern) === 0;             // equals
            default: return null;
        }
    }

    /**
     * Map a condition's field name onto the inventory row. Returns null for a
     * field that only exists once the device (or its graphs) are in the database
     * — h.notes, ht.name, gt.name and gtg.title_cache are all resolved by the
     * generated automation rule instead, which runs against real tables.
     */
    private function treeRuleFieldValue(string $field, array $device): ?string {
        switch ($field) {
            // Fields the UI offers, in Cacti's SQL vocabulary.
            case 'h.description': return trim((string)($device['hostname'] ?? ''));
            case 'h.hostname':    return trim((string)($device['ip'] ?? ''));
            case 'h.location':    return substr(trim((string)($device['site'] ?? '')), 0, 40);
            case 'h.site_id':     return (string)$this->deviceSiteId($device);

            // Legacy field names from the single-condition rule format.
            case 'description':   return trim((string)($device['hostname'] ?? ''));
            case 'hostname':      return trim((string)($device['ip'] ?? ''));
            case 'dev_function':  return trim((string)($device['dev_function'] ?? ''));
            case 'site':          return trim((string)($device['site'] ?? ''));
            case 'region':        return trim((string)($device['region'] ?? ''));
            case 'country':       return trim((string)($device['country'] ?? ''));

            default:              return null;
        }
    }

    // Site id for an inventory row, without creating anything. 0 when the
    // location has no site yet.
    private function deviceSiteId(array $device): int {
        return $this->siteIdFor($device['region'] ?? '', $device['country'] ?? '', $device['site'] ?? '');
    }

    /**
     * Site id for a location, 0 when none exists. The name format matches what
     * DeviceManager::getOrCreateSiteId() stores: "region/country/site".
     *
     * A site an earlier sync flagged as empty carries the deletion tag in front
     * of its name until reconciliation strips it again, so it is looked up in
     * that form too — otherwise every run would create a fresh site for the
     * location, only for the end-of-run pass to flag that one as well. The
     * lookup is done here rather than left to DeviceManager, whose copy lives
     * in Cacti's cli/ tree and is not updated when the plugin is deployed.
     */
    private function siteIdFor($region, $country, $site): int {
        $parts = [];
        foreach ([$region, $country, $site] as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (empty($parts)) return 0;

        $name = substr(implode('/', $parts), 0, 100);
        if (!empty($this->siteIdCache[$name])) {
            return $this->siteIdCache[$name];
        }

        $id = (int)db_fetch_cell_prepared('SELECT id FROM sites WHERE name = ? ORDER BY id LIMIT 1', [$name]);

        if (!$id) {
            // The profile's tag, plus the default one in case the profile's tag
            // was changed after sites had already been flagged.
            $tags = array_unique(array_filter([
                trim((string)($this->profile['deletion_tag'] ?? '')),
                '[TO BE DELETED]',
            ]));

            foreach ($tags as $tag) {
                // Flagged names are cut to the 100-char column, so compare against
                // the tagged form after the same cut.
                $want = strtolower(trim($this->stripTag(substr($tag . ' ' . $name, 0, 100), $tag)));

                $rows = db_fetch_assoc_prepared(
                    'SELECT id, name FROM sites WHERE name LIKE ? ORDER BY id',
                    [$this->likeEscape($tag) . '%']
                );

                foreach ($rows as $row) {
                    if (strtolower(trim($this->stripTag((string)$row['name'], $tag))) === $want) {
                        $id = (int)$row['id'];
                        break 2;
                    }
                }
            }
        }

        if ($id) {
            $this->siteIdCache[$name] = $id;
        }

        return $id;
    }

    // Site id for a location, creating the site only when none exists — neither
    // under its plain name nor flagged with the deletion tag.
    private function getOrCreateSite($devMgr, $region, $country, $site): int {
        $id = $this->siteIdFor($region, $country, $site);
        if ($id) return $id;

        return (int)$devMgr->getOrCreateSiteId($region, $country, $site);
    }

    // ─── Empty-container marking ────────────────────────────────────────────────
    //
    // When devices leave the inventory they are tagged for deletion (description
    // prefixed with the profile's deletion_tag) but remain in Cacti until an
    // operator removes them. Once tagged, the sites and tree branches that held
    // them are effectively empty. These methods flag those containers with the
    // same tag so the operator can find and clean them up in the same pass.

    /**
     * Reconcile the deletion tag on sites and tree branches against their current
     * contents after this sync: flag containers left with no live devices, and
     * strip the tag from any previously-flagged container that is populated again
     * (deletion is manual, so a flagged container can still exist and be reused).
     * Marking/un-marking is non-destructive — it only edits the site/branch name
     * (and the site note). Honours dry-run: counts, no writes.
     */
    private function markEmptyContainers(): void {
        $markSites = !isset($this->profile['mark_empty_sites'])      || (int)$this->profile['mark_empty_sites'];
        $markTrees = !isset($this->profile['mark_empty_tree_items']) || (int)$this->profile['mark_empty_tree_items'];
        if (!$markSites && !$markTrees) {
            return;
        }

        $tag = trim((string)($this->profile['deletion_tag'] ?? '')) ?: '[TO BE DELETED]';

        if ($markSites) {
            $this->unmarkNonEmptySites($tag);
            $this->markEmptySites($tag);
        }
        if ($markTrees) {
            // Per-tree pass handles both marking and un-marking in one traversal.
            $this->markEmptyTreeItems($tag);
        }
    }

    /**
     * Remove the deletion tag from any site that was flagged empty but now holds
     * at least one live (untagged) device again.
     */
    private function unmarkNonEmptySites(string $tag): void {
        $like = '%' . $this->likeEscape($tag) . '%';

        $sites = db_fetch_assoc_prepared(
            "SELECT s.id, s.name, s.notes
             FROM sites AS s
             WHERE s.name LIKE ?
               AND EXISTS (SELECT 1 FROM host h WHERE h.site_id = s.id AND h.description NOT LIKE ?)",
            [$like, $like]
        );

        if (!cacti_sizeof($sites)) {
            return;
        }

        foreach ($sites as $site) {
            $cleanName = $this->stripTag((string)$site['name'], $tag);
            if ($cleanName === (string)$site['name']) {
                continue; // tag not a name prefix — leave it alone
            }

            $this->stats['sites_unmarked']++;

            if ($this->dryRun) {
                $this->logDetail('site_unmarked_dry', $site['name'], '', null, 'dry-run — site #' . $site['id']);
                continue;
            }

            $notes = $this->stripSiteMarker((string)$site['notes'], $tag);
            db_execute_prepared(
                'UPDATE sites SET name = ?, notes = ? WHERE id = ?',
                [substr($cleanName, 0, 100), $notes, (int)$site['id']]
            );
            $this->logDetail('site_unmarked', $cleanName, '', null, 'un-flagged repopulated site #' . $site['id']);
        }

        $this->flushStats();
    }

    /**
     * A site is a candidate when it holds no live devices — either it has no host
     * rows at all, or every host referencing it is already tagged for deletion.
     * Both cases get flagged so an operator can find and remove them manually.
     */
    private function markEmptySites(string $tag): void {
        $like = '%' . $this->likeEscape($tag) . '%';

        $sites = db_fetch_assoc_prepared(
            "SELECT s.id, s.name, s.notes
             FROM sites AS s
             WHERE s.name NOT LIKE ?
               AND NOT EXISTS (SELECT 1 FROM host h WHERE h.site_id = s.id AND h.description NOT LIKE ?)",
            [$like, $like]
        );

        if (!cacti_sizeof($sites)) {
            return;
        }

        foreach ($sites as $site) {
            $this->stats['empty_sites_marked']++;

            if ($this->dryRun) {
                $this->logDetail('empty_site_dry', $site['name'], '', null, 'dry-run — site #' . $site['id']);
                continue;
            }

            $newName = substr($tag . ' ' . $site['name'], 0, 100);
            $marker  = $tag . ' empty (no live devices) ' . date('Y-m-d H:i');
            $notes   = substr($marker . "\n" . rtrim((string)$site['notes']), 0, 1024);

            db_execute_prepared(
                'UPDATE sites SET name = ?, notes = ? WHERE id = ?',
                [$newName, $notes, (int)$site['id']]
            );

            $this->logDetail('empty_site', $site['name'], '', null, 'flagged empty site #' . $site['id']);
        }

        $this->flushStats();
    }

    /**
     * Walk each managed tree bottom-up and flag the top-most header of every
     * branch that contains no live content. A branch is empty when all of its
     * descendants are either empty headers or leaf items pointing at devices that
     * are tagged for deletion (or no longer exist). Only the outermost empty
     * header of a subtree is flagged, so removing it takes the whole branch.
     */
    private function markEmptyTreeItems(string $tag): void {
        // Scope strictly to the trees this profile places devices into (tree,
        // aggregate and OID rules). We never touch unmanaged trees — e.g. the
        // default Local/Machine tree — so an empty header there is left alone.
        $treeIds = [];
        foreach ([$this->treeRules, $this->aggRules, $this->oidRules] as $ruleSet) {
            foreach ($ruleSet as $rule) {
                $tid = (int)($rule['tree_id'] ?? 0);
                if ($tid) {
                    $treeIds[$tid] = true;
                }
            }
        }
        if (empty($treeIds)) {
            return;
        }

        foreach (array_keys($treeIds) as $treeId) {
            $this->markEmptyTreeItemsForTree($treeId, $tag);
        }
    }

    private function markEmptyTreeItemsForTree(int $treeId, string $tag): void {
        $items = db_fetch_assoc_prepared(
            'SELECT id, parent, title, host_id, local_graph_id
             FROM graph_tree_items WHERE graph_tree_id = ?',
            [$treeId]
        );
        if (!cacti_sizeof($items)) {
            return;
        }

        // Index items and build a parent → children adjacency map.
        $byId     = [];
        $children = [];
        foreach ($items as $it) {
            $id            = (int)$it['id'];
            $it['id']      = $id;
            $it['parent']  = (int)($it['parent'] ?? 0);
            $byId[$id]     = $it;
            $children[$it['parent']][] = $id;
        }

        // Resolve which referenced devices are still "live". A leaf pointing at a
        // tagged-for-deletion or missing host counts as removable content.
        $hostIds = [];
        foreach ($byId as $it) {
            if ((int)$it['host_id'] > 0) {
                $hostIds[(int)$it['host_id']] = true;
            }
        }
        // Graph leaves resolve to their owning host via graph_local.
        $graphIds = [];
        foreach ($byId as $it) {
            if ((int)$it['local_graph_id'] > 0) {
                $graphIds[(int)$it['local_graph_id']] = true;
            }
        }
        $graphHost = [];
        if (!empty($graphIds)) {
            $ph   = implode(',', array_fill(0, count($graphIds), '?'));
            $rows = db_fetch_assoc_prepared(
                "SELECT id, host_id FROM graph_local WHERE id IN ($ph)",
                array_keys($graphIds)
            );
            if (cacti_sizeof($rows)) {
                foreach ($rows as $row) {
                    $graphHost[(int)$row['id']] = (int)$row['host_id'];
                    if ((int)$row['host_id'] > 0) {
                        $hostIds[(int)$row['host_id']] = true;
                    }
                }
            }
        }

        // A host is "live" only if it exists and is NOT tagged for deletion.
        $liveHost = [];
        if (!empty($hostIds)) {
            $ph   = implode(',', array_fill(0, count($hostIds), '?'));
            $rows = db_fetch_assoc_prepared(
                "SELECT id, description FROM host WHERE id IN ($ph)",
                array_keys($hostIds)
            );
            if (cacti_sizeof($rows)) {
                foreach ($rows as $row) {
                    $liveHost[(int)$row['id']] = (strpos((string)$row['description'], $tag) === false);
                }
            }
        }

        // Bottom-up removability, memoised. A leaf is removable when its device is
        // gone/tagged; a header is removable when all its children are removable.
        $removable = [];
        $resolve = function (int $id) use (&$resolve, &$removable, $byId, $children, $liveHost, $graphHost): bool {
            if (isset($removable[$id])) {
                return $removable[$id];
            }
            $removable[$id] = false; // cycle guard (defensive; trees are acyclic)
            $it = $byId[$id];

            if ((int)$it['host_id'] > 0) {
                // Live host → keep; tagged/missing host → removable.
                return $removable[$id] = empty($liveHost[(int)$it['host_id']]);
            }
            if ((int)$it['local_graph_id'] > 0) {
                $hid = $graphHost[(int)$it['local_graph_id']] ?? 0;
                // Removable only when we can prove the owning host is gone/tagged.
                return $removable[$id] = ($hid > 0 && empty($liveHost[$hid]));
            }

            // Header: removable when it has no children, or all children are.
            $kids = $children[$id] ?? [];
            foreach ($kids as $kid) {
                if (!$resolve($kid)) {
                    return $removable[$id] = false;
                }
            }
            return $removable[$id] = true;
        };
        foreach (array_keys($byId) as $id) {
            $resolve($id);
        }

        // Reconcile the tag on every header:
        //  • non-empty + tagged  → un-flag (a returning device repopulated it),
        //  • empty     + untagged → flag the top-most removable header of the
        //                           subtree (a removable header whose parent is
        //                           not itself removable).
        foreach ($byId as $id => $it) {
            $isHeader = ((int)$it['host_id'] === 0 && (int)$it['local_graph_id'] === 0);
            if (!$isHeader) {
                continue;
            }
            $title  = (string)$it['title'];
            $tagged = (strpos($title, $tag) !== false);

            if (empty($removable[$id])) {
                // Header holds live content — remove any stale flag.
                if (!$tagged) {
                    continue;
                }
                $clean = $this->stripTag($title, $tag);
                if ($clean === $title) {
                    continue; // tag not a title prefix — leave alone
                }

                $this->stats['trees_unmarked']++;

                if ($this->dryRun) {
                    $this->logDetail('tree_unmarked_dry', $title, '', null, 'dry-run — tree #' . $treeId . ' item #' . $id);
                    continue;
                }
                db_execute_prepared(
                    'UPDATE graph_tree_items SET title = ? WHERE id = ?',
                    [substr($clean, 0, 255), $id]
                );
                $this->logDetail('tree_unmarked', $clean, '', null, 'un-flagged repopulated branch — tree #' . $treeId . ' item #' . $id);
                continue;
            }

            // Header is empty.
            if ($tagged) {
                continue; // already flagged on a previous run
            }
            $parent = (int)$it['parent'];
            if ($parent !== 0 && !empty($removable[$parent]) && isset($byId[$parent])) {
                continue; // an ancestor header will be flagged instead
            }

            $this->stats['empty_trees_marked']++;

            if ($this->dryRun) {
                $this->logDetail('empty_tree_dry', $title, '', null, 'dry-run — tree #' . $treeId . ' item #' . $id);
                continue;
            }

            $newTitle = substr($tag . ' ' . $title, 0, 255);
            db_execute_prepared(
                'UPDATE graph_tree_items SET title = ? WHERE id = ?',
                [$newTitle, $id]
            );
            $this->logDetail('empty_tree', $title, '', null, 'flagged empty branch — tree #' . $treeId . ' item #' . $id);
        }

        $this->flushStats();
    }

    /** Escape LIKE wildcards so a deletion_tag containing % or _ still matches literally. */
    private function likeEscape(string $s): string {
        return addcslashes($s, '\\%_');
    }

    /** Strip a leading deletion tag (and the following whitespace) from a string. */
    private function stripTag(string $s, string $tag): string {
        if ($tag !== '' && strncmp($s, $tag, strlen($tag)) === 0) {
            return ltrim(substr($s, strlen($tag)));
        }
        return $s;
    }

    /** Drop the "<tag> empty (…)" marker line the empty-site flag appended to notes. */
    private function stripSiteMarker(string $notes, string $tag): string {
        if ($notes === '') {
            return $notes;
        }
        $kept = [];
        foreach (preg_split('/\r\n|\r|\n/', $notes) as $line) {
            if (strncmp($line, $tag . ' empty', strlen($tag) + 6) === 0) {
                continue;
            }
            $kept[] = $line;
        }
        return trim(implode("\n", $kept));
    }

    // ─── Run tracking ─────────────────────────────────────────────────────────

    private int $preCreatedRunId = 0;

    public function setPreCreatedRunId(int $id): void {
        $this->preCreatedRunId = $id;
    }

    // Ping the database and reconnect if the connection was dropped (e.g. due to
    // net_read_timeout during a long SNMP walk).  Called before every flushStats()
    // so a "MySQL server has gone away" error self-heals on the next flush.
    // ─── Parallel SNMP WAN checks via proc_open workers ──────────────────────
    // Each worker is a fresh PHP process — no inherited DB connection, no
    // shared socket, no COM_QUIT on child exit. Devices are split across
    // workers (up to 20 concurrent) and each worker handles its batch
    // sequentially for good SNMP connection reuse.

    private function checkL2Parallel(array $devices): array {
        global $config;

        $phpBin     = read_config_option('path_php_binary') ?: '/usr/bin/php';
        $worker     = dirname(__DIR__) . '/cereus_datasync_snmp_worker.php';
        $wanPattern = trim($this->profile['snmp_wan_pattern'] ?? '-WAN-') ?: '-WAN-';
        $timeoutMs  = (int)$this->profile['snmp_timeout_ms'] ?: 2000;

        if (!file_exists($worker)) {
            cacti_log('cereus_datasync: SNMP worker script not found, falling back to sequential', false, 'SYSTEM');
            return [];
        }

        $maxWorkers = min(
            max(1, (int)($this->profile['snmp_parallel_workers'] ?? 20)),
            count($devices)
        );
        $chunks = array_chunk($devices, (int)ceil(count($devices) / $maxWorkers));

        $cmd = escapeshellcmd($phpBin) . ' '
             . escapeshellarg($worker) . ' '
             . escapeshellarg($wanPattern) . ' '
             . (int)$timeoutMs;

        // Launch all workers concurrently
        $procs = [];
        foreach ($chunks as $chunk) {
            $desc = [
                0 => ['pipe', 'r'],   // stdin  — we write the device batch
                1 => ['pipe', 'w'],   // stdout — worker writes JSON results
                2 => ['file', '/dev/null', 'a'],
            ];
            $proc = proc_open($cmd, $desc, $pipes);
            if (!is_resource($proc)) continue;

            fwrite($pipes[0], json_encode($chunk));
            fclose($pipes[0]);

            $procs[] = ['proc' => $proc, 'out' => $pipes[1]];
        }

        // Collect results — stream_get_contents blocks per-worker until it exits
        $results = [];
        foreach ($procs as $p) {
            $raw = stream_get_contents($p['out']);
            fclose($p['out']);
            proc_close($p['proc']);
            $batch = json_decode($raw, true);
            if (is_array($batch)) {
                $results = array_merge($results, $batch);
            }
        }

        return $results;
    }

    private function ensureDbConnection(): void {
        global $cnn_id, $config;

        // Try a lightweight query; if it fails, reconnect.
        if ($cnn_id instanceof \PDO) {
            $result = @$cnn_id->query('SELECT 1');
            if ($result !== false) return;
        }

        // Reconnect using the same credentials Cacti loaded at startup
        $newConn = db_connect_real(
            $config['database_hostname'] ?? 'localhost',
            $config['database_username'] ?? '',
            $config['database_password'] ?? '',
            $config['database_default']  ?? 'cacti',
            'mysql',
            (string)($config['database_port'] ?? '3306'),
            5   // fast retry limit
        );

        if ($newConn !== false) {
            $cnn_id = $newConn;
        }
    }

    // Write current stats to the DB without closing the run — allows the poll
    // endpoint to show live progress while the background process is still running.
    private function flushStats(): void {
        if (!$this->runId) return;
        $this->ensureDbConnection();
        db_execute_prepared(
            'UPDATE plugin_cds_runs
             SET excel_total = ?, excel_raw_total = ?, excel_skipped_load = ?,
                 cacti_total = ?, added = ?, updated = ?,
                 marked_deleted = ?, skipped = ?, failed = ?,
                 tree_placed = ?, graphs_found = ?, graphs_created = ?,
                 empty_sites_marked = ?, empty_trees_marked = ?,
                 sites_unmarked = ?, trees_unmarked = ?
             WHERE id = ?',
            [
                $this->stats['excel_total'],        $this->stats['excel_raw_total'],
                $this->stats['excel_skipped_load'],
                $this->stats['cacti_total'],        $this->stats['added'],
                $this->stats['updated'],            $this->stats['marked_deleted'],
                $this->stats['skipped'],            $this->stats['failed'],
                $this->stats['tree_placed'],        $this->stats['graphs_found'],
                $this->stats['graphs_created'],
                $this->stats['empty_sites_marked'], $this->stats['empty_trees_marked'],
                $this->stats['sites_unmarked'],     $this->stats['trees_unmarked'],
                $this->runId,
            ]
        );
    }

    private function startRun(string $excelFile): int {
        if ($this->preCreatedRunId) {
            db_execute_prepared(
                'UPDATE plugin_cds_runs SET status = ?, started_at = NOW(), excel_file = ? WHERE id = ?',
                ['running', basename($excelFile), $this->preCreatedRunId]
            );
        } else {
            db_execute_prepared(
                'INSERT INTO plugin_cds_runs (profile_id, profile_name, started_at, status, dry_run, excel_file)
                 VALUES (?, ?, NOW(), ?, ?, ?)',
                [(int)$this->profile['id'], $this->profile['name'], 'running', $this->dryRun ? 1 : 0, basename($excelFile)]
            );
            $this->preCreatedRunId = (int)db_fetch_insert_id();
        }

        // Reflect "running" in the profile list badge immediately. A dry run
        // leaves it alone: last_run_at also drives the scheduler, and the
        // profile's last run should be one that changed something.
        if (!$this->dryRun) {
            db_execute_prepared(
                'UPDATE plugin_cds_profiles SET last_run_at = NOW(), last_run_status = ? WHERE id = ?',
                ['running', (int)$this->profile['id']]
            );
        }

        return $this->preCreatedRunId;
    }

    private function finishRun(string $status, string $error = ''): void {
        $this->ensureDbConnection();
        db_execute_prepared(
            'UPDATE plugin_cds_runs
             SET status = ?, completed_at = NOW(), error_message = ?,
                 excel_total = ?, excel_raw_total = ?, excel_skipped_load = ?,
                 cacti_total = ?, added = ?, updated = ?,
                 marked_deleted = ?, skipped = ?, failed = ?,
                 tree_placed = ?, graphs_found = ?, graphs_created = ?,
                 empty_sites_marked = ?, empty_trees_marked = ?,
                 sites_unmarked = ?, trees_unmarked = ?
             WHERE id = ?',
            [
                $status, $error,
                $this->stats['excel_total'],        $this->stats['excel_raw_total'],
                $this->stats['excel_skipped_load'],
                $this->stats['cacti_total'],        $this->stats['added'],
                $this->stats['updated'],            $this->stats['marked_deleted'],
                $this->stats['skipped'],            $this->stats['failed'],
                $this->stats['tree_placed'],        $this->stats['graphs_found'],
                $this->stats['graphs_created'],
                $this->stats['empty_sites_marked'], $this->stats['empty_trees_marked'],
                $this->stats['sites_unmarked'],     $this->stats['trees_unmarked'],
                $this->runId,
            ]
        );

        // The profile list's "Last Run" is the last run that changed something;
        // a dry run is reviewed in the log instead of replacing it.
        if ($this->dryRun) return;

        db_execute_prepared(
            'UPDATE plugin_cds_profiles SET last_run_at = NOW(), last_run_status = ?, last_run_stats = ? WHERE id = ?',
            [
                $status,
                json_encode($this->stats),
                (int)$this->profile['id'],
            ]
        );
    }

    private function logDetail(string $action, string $hostname, string $ip, ?int $deviceId, string $details): void {
        if (!$this->runId) return;
        db_execute_prepared(
            'INSERT INTO plugin_cds_run_details (run_id, action, device_hostname, device_ip, device_id, details) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->runId, $action, $hostname, $ip, $deviceId, $details]
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requireCliClasses(): void {
        global $config;

        // PhpSpreadsheet ships with the plugin (composer.json at the plugin root).
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        if (!file_exists($autoload)) {
            throw new \RuntimeException('PhpSpreadsheet vendor autoload not found. Run "composer install" in '
                . dirname(__DIR__) . '/');
        }

        require_once $autoload;

        // The sync classes ship with the plugin so they are deployed with it.
        $syncDir = __DIR__ . '/sync';
        require_once $syncDir . '/Constants.php';
        require_once $syncDir . '/Exceptions.php';
        require_once $syncDir . '/Logger.php';
        require_once $syncDir . '/Validator.php';
        require_once $syncDir . '/DeviceMatcher.php';
        require_once $syncDir . '/DatabaseTransaction.php';
        require_once $syncDir . '/ExcelLoader.php';
        require_once $syncDir . '/DeviceManager.php';
        require_once $syncDir . '/ParallelSNMPChecker.php';

        if (!function_exists('api_device_save')) {
            require_once $config['base_path'] . '/lib/api_device.php';
        }
    }

    private function buildSyncConfig(): array {
        global $config;

        return [
            'cacti' => [
                'base_path' => $config['base_path'],
                'tree_id'   => 1,
            ],
            'excel' => [
                'sheet_name'               => $this->profile['sheet_name'] ?: 'Sheet1',
                'start_row'                => (int)$this->profile['data_start_row'],
                'columns'                  => $this->colMaps,
                // The permissive Validator subclass bypasses this list entirely,
                // so all rows are loaded regardless of their dev_function value.
                'allowed_device_functions' => [],
            ],
            'device_options' => [
                'availability_method' => (int)$this->profile['default_availability'],
                'ping_method'         => (int)$this->profile['default_ping_method'],
                'disabled'            => '',
                'snmp_version'        => (int)$this->profile['default_snmp_version'],
                'snmp_port'           => (int)$this->profile['default_snmp_port'],
                'snmp_timeout'        => (int)$this->profile['default_snmp_timeout'],
                'ping_timeout'        => 500,
                'ping_retries'        => 2,
                'max_oids'            => 10,
                'device_threads'      => 1,
                'poller_id'           => (int)$this->profile['default_poller_id'],
                'snmp_community'      => $this->profile['default_snmp_community'],
            ],
            'snmp' => [
                'check_wan_for_l2_switches' => (bool)$this->profile['snmp_check_l2'],
                'wan_check_timeout'         => (int)$this->profile['snmp_timeout_ms'],
                'fast_fail_timeout'         => 500,
                'default_community'         => $this->profile['default_snmp_community'],
                'max_retries'               => 1,
                'enable_parallel'           => (bool)$this->profile['snmp_enable_parallel'],
            ],
            'performance' => [
                'delay_between_devices'  => 100000,
                'delay_between_rules'    => 50000,
                'batch_size'             => 100,
                'parallel_snmp_processes' => 20,
                'snmp_batch_size'        => 50,
            ],
            'logging' => [
                'enabled'        => true,
                'level'          => 'INFO',
                'log_file'       => $config['base_path'] . '/log/cereus_datasync.log',
                'console_output' => false,
            ],
            'deletion' => [
                'mark_as_deleted_tag'       => $this->profile['deletion_tag'],
                'permanent_delete_after_days' => 30,
                'skip_localhost'            => (bool)$this->profile['deletion_skip_localhost'],
            ],
            'automation' => [
                'enabled'     => true,
                'match_field' => 'h.description',
                'wan_pattern' => $this->profile['snmp_wan_pattern'],
            ],
            'validation' => [
                'hostname_pattern'  => '/^[a-zA-Z0-9\.\-_]+$/',
                'ip_validation'     => true,
                'max_hostname_length' => 255,
                'max_site_length'   => 255,
            ],
        ];
    }

    private function findExcelFile(): ?string {
        $path = $this->profile['excel_path'];
        $mode = $this->profile['excel_file_mode'];

        if (empty($path)) return null;

        if ($mode === 'specific_file') {
            return file_exists($path) ? $path : null;
        }

        // latest_in_dir: find newest .xlsx in directory
        if (!is_dir($path)) return null;

        $files = glob(rtrim($path, '/') . '/*.xlsx');
        if (empty($files)) {
            $files = glob(rtrim($path, '/') . '/*.xls');
        }
        if (empty($files)) return null;

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        return $files[0];
    }
}
