<?php
// SPDX-License-Identifier: GPL-2.0-or-later

class CereusDatasyncEngine {

    private array  $profile;
    private array  $colMaps   = [];
    private array  $funcMaps  = [];   // device_function => host_template_id
    private array  $treeRules = [];
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
                        $devMgr->getOrCreateSiteId(
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
                    $exSiteId   = !$this->dryRun ? $devMgr->getOrCreateSiteId($exDev['region'], $exDev['country'], $exDev['site']) : 0;

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
                // Dry run — assume interfaces match (no SNMP in dry run)
                foreach ($l2check as $dev) {
                    $toAdd[] = ['device' => $dev, 'has_wan' => true];
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
                    $this->stats['updated']++;
                    $this->logDetail('updated', $upd['exDev']['hostname'], $upd['exDev']['ip'], (int)$upd['cactiDev']['id'], '');
                } else {
                    $this->stats['failed']++;
                    $this->logDetail('failed', $upd['exDev']['hostname'], $upd['exDev']['ip'], (int)$upd['cactiDev']['id'], 'update failed');
                }
                $this->flushStats(); // persist before risky graph creation

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
                    $this->logDetail('added_dry', $device['hostname'], $device['ip'], null, 'dry-run');
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
            }

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
            $treeItemId      = (int)$rule['tree_item_id'];
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

            try {
                aggregate_create_update($resultGraphId, $memberGraphIds, $attribs);
            } catch (\Throwable $e) {
                $this->logDetail('agg_error', '', '', null,
                    'Rule "' . $rule['name'] . '": ' . $e->getMessage());
                continue;
            }

            // Persist updated result_graph_id (set by aggregate_create_update via reference)
            db_execute_prepared(
                'UPDATE plugin_cds_aggregate_rules SET result_graph_id = ? WHERE id = ?',
                [$resultGraphId, (int)$rule['id']]
            );

            // Resolve placement node — site mode finds/creates a header node by name
            $placementMode = (int)($rule['placement_mode'] ?? 0);
            $siteName      = trim($rule['site_name'] ?? '');
            if ($placementMode === 1 && $siteName !== '' && $treeId) {
                $siteNodeId = (int)db_fetch_cell_prepared(
                    "SELECT id FROM graph_tree_items
                     WHERE graph_tree_id = ? AND title = ? AND local_graph_id = 0 AND host_id = 0 AND leaf_type = 1
                     LIMIT 1",
                    [$treeId, $siteName]
                );
                if (!$siteNodeId) {
                    $siteNodeId = (int)api_tree_item_save(
                        0, $treeId, TREE_ITEM_TYPE_HEADER, 0, $siteName, 0, 0, 0, 0, 1, false
                    );
                }
                $treeItemId = $siteNodeId ?: 0;
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

                // Place in tree if configured
                if ($treeId && !api_tree_graph_exists($treeId, $treeItemId, $localGraphId)) {
                    api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_GRAPH, $treeItemId, '', $localGraphId, 0, 0, 0, 1, false);
                }

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
        $siteId = $devMgr->getOrCreateSiteId($device['region'], $device['country'], $device['site']);

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
        $resolvedSiteId = (int)db_fetch_cell_prepared(
            'SELECT id FROM sites WHERE name = ?', [$locationKey]
        );

        // Substitution map — {site_id} enables exact integer matching on h.site_id,
        // which is more reliable than text matching on the truncated h.location field.
        $subs = [
            '{site}'     => substr(trim($device['site']    ?? ''), 0, 40),
            '{region}'   => trim($device['region']  ?? ''),
            '{country}'  => trim($device['country'] ?? ''),
            '{location}' => substr(trim($device['site']    ?? ''), 0, 40),
            '{site_id}'  => (string)$resolvedSiteId,
        ];

        // Find or create the branch path in the target tree
        $leafItemId = 0;
        foreach ($locationParts as $title) {
            $leafItemId = $this->findOrCreateBranch($treeId, $leafItemId, $title);
            if (!$leafItemId) return;
        }

        $leafType    = (int)$tpl['leaf_type'] ?: 2;
        $hostGrouping = (int)$tpl['host_grouping'] ?: 1;

        // Reuse the rule if it already exists (dedup by generated name), otherwise create it.
        // Reuse lets edited conditions — including newly added OR/parenthesis grouping — take
        // effect on the next sync instead of being permanently frozen at first creation.
        $ruleName = 'Auto[' . $tpl['name'] . ']: ' . implode(' / ', $locationParts);
        $ruleId   = (int)db_fetch_cell_prepared(
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
        if (!$ruleId) return;

        // Materialise the conditions into a Cacti automation_match_rule_items token stream.
        // Each plugin condition can carry a connector (AND/OR joining it to the previous
        // condition) plus opening/closing parentheses, so one condition may expand into
        // several tokens: [connector] [ '(' … ] <field op pattern> [ … ')' ]. Cacti's
        // build_rule_item_filter() walks these tokens to assemble the WHERE clause, so the
        // grouping "A AND ( B OR C OR D )" is reproduced faithfully.
        $seq   = 1;
        $first = true;
        foreach ($conditions as $cond) {
            $pattern = str_replace(array_keys($subs), array_values($subs), $cond['pattern']);
            $open    = max(0, (int)($cond['open_paren']  ?? 0));
            $close   = max(0, (int)($cond['close_paren'] ?? 0));
            $connOp  = (strtoupper($cond['connector'] ?? 'AND') === 'OR')
                       ? AUTOMATION_OPER_OR : AUTOMATION_OPER_AND;

            $insertItem = function(int $operation, string $field, int $operator, string $patt) use (&$seq, $ruleId) {
                db_execute_prepared(
                    'INSERT INTO automation_match_rule_items
                        (rule_id, rule_type, sequence, operation, field, operator, pattern)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$ruleId, AUTOMATION_RULE_TYPE_TREE_MATCH, $seq++, $operation, $field, $operator, $patt]
                );
            };

            if ($first) {
                // First condition: no leading connector. Fold any opening bracket onto the
                // field token if there is exactly one and no connector to sit before it.
                for ($i = 0; $i < $open; $i++) {
                    $insertItem(AUTOMATION_OPER_LEFT_BRACKET, '', 0, '');
                }
                $insertItem(AUTOMATION_OPER_NULL, $cond['field'], (int)$cond['operator'], $pattern);
            } elseif ($open == 0) {
                // Common case — fold the connector directly onto the field token: "AND field …".
                $insertItem($connOp, $cond['field'], (int)$cond['operator'], $pattern);
            } else {
                // Connector must precede the opening bracket(s): "AND ( field …".
                $insertItem($connOp, '', 0, '');
                for ($i = 0; $i < $open; $i++) {
                    $insertItem(AUTOMATION_OPER_LEFT_BRACKET, '', 0, '');
                }
                $insertItem(AUTOMATION_OPER_NULL, $cond['field'], (int)$cond['operator'], $pattern);
            }

            for ($i = 0; $i < $close; $i++) {
                $insertItem(AUTOMATION_OPER_RIGHT_BRACKET, '', 0, '');
            }

            $first = false;
        }

        $this->logDetail('auto_rule', '', '', null,
            'Materialised "' . $ruleName . '" (rule_id=' . $ruleId . ', ' . ($seq - 1) . ' tokens)');
    }

    // Find an existing header branch or create a new one in the target tree.
    // Returns the branch's graph_tree_items.id, or 0 on failure.
    private function findOrCreateBranch(int $treeId, int $parentId, string $title): int {
        if ($title === '') return $parentId;

        $id = (int)db_fetch_cell_prepared(
            'SELECT id FROM graph_tree_items
             WHERE graph_tree_id = ? AND parent = ? AND title = ?
               AND local_graph_id = 0 AND host_id = 0',
            [$treeId, $parentId, $title]
        );

        if ($id) return $id;

        // Direct insert — avoids form_input_validate side-effects in CLI/poller context
        db_execute_prepared(
            'INSERT INTO graph_tree_items
                (graph_tree_id, title, parent, position,
                 local_graph_id, host_id, site_id,
                 host_grouping_type, sort_children_type)
             VALUES (?, ?, ?, 0, 0, 0, 0, 0, 1)',
            [$treeId, $title, $parentId]
        );

        return (int)db_fetch_insert_id();
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
            if (!$this->matchTreeRule($rule, $device)) continue;

            $treeId     = (int)$rule['tree_id'];
            $parentItem = (int)$rule['tree_item_id'];
            $grouping   = (int)$rule['host_grouping'];

            if (!$treeId) continue;

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

    private function matchTreeRule(array $rule, array $device): bool {
        switch ($rule['match_field']) {
            case 'description':  $haystack = $device['hostname'];          break;
            case 'hostname':     $haystack = $device['ip'];                break;
            case 'dev_function': $haystack = $device['dev_function'];      break;
            case 'site':         $haystack = $device['site'] ?? '';        break;
            case 'region':       $haystack = $device['region'] ?? '';      break;
            case 'country':      $haystack = $device['country'] ?? '';     break;
            default:             $haystack = $device['hostname'];          break;
        }

        $pattern = $rule['pattern'];

        switch ($rule['operator']) {
            case 'contains':     return stripos($haystack, $pattern) !== false;
            case 'not_contains': return stripos($haystack, $pattern) === false;
            case 'equals':       return strcasecmp($haystack, $pattern) === 0;
            case 'not_equals':   return strcasecmp($haystack, $pattern) !== 0;
            case 'starts_with':  return stripos($haystack, $pattern) === 0;
            case 'ends_with':    return stripos($haystack, $pattern) === strlen($haystack) - strlen($pattern);
            case 'regex':        return (bool)@preg_match($pattern, $haystack);
            default:             return false;
        }
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
                 tree_placed = ?, graphs_found = ?, graphs_created = ?
             WHERE id = ?',
            [
                $this->stats['excel_total'],        $this->stats['excel_raw_total'],
                $this->stats['excel_skipped_load'],
                $this->stats['cacti_total'],        $this->stats['added'],
                $this->stats['updated'],            $this->stats['marked_deleted'],
                $this->stats['skipped'],            $this->stats['failed'],
                $this->stats['tree_placed'],        $this->stats['graphs_found'],
                $this->stats['graphs_created'],
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

        // Reflect "running" in the profile list badge immediately
        db_execute_prepared(
            'UPDATE plugin_cds_profiles SET last_run_at = NOW(), last_run_status = ? WHERE id = ?',
            ['running', (int)$this->profile['id']]
        );

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
                 tree_placed = ?, graphs_found = ?, graphs_created = ?
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
                $this->runId,
            ]
        );

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

        $cliDir = $config['base_path'] . '/cli';

        if (!file_exists($cliDir . '/vendor/autoload.php')) {
            throw new \RuntimeException('PhpSpreadsheet vendor autoload not found. Install it in ' . $cliDir . '/vendor/');
        }

        require_once $cliDir . '/vendor/autoload.php';
        require_once $cliDir . '/lib/sync/Constants.php';
        require_once $cliDir . '/lib/sync/Exceptions.php';
        require_once $cliDir . '/lib/sync/Logger.php';
        require_once $cliDir . '/lib/sync/Validator.php';
        require_once $cliDir . '/lib/sync/DeviceMatcher.php';
        require_once $cliDir . '/lib/sync/DatabaseTransaction.php';
        require_once $cliDir . '/lib/sync/ExcelLoader.php';
        require_once $cliDir . '/lib/sync/DeviceManager.php';
        require_once $cliDir . '/lib/sync/ParallelSNMPChecker.php';

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
