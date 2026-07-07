<?php
// SPDX-License-Identifier: GPL-2.0-or-later

// ─── Profile helpers ─────────────────────────────────────────────────────────

function cereus_datasync_get_profile(int $id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_cds_profiles WHERE id = ?', [$id]);
}

function cereus_datasync_profile_count(): int {
    return (int)db_fetch_cell('SELECT COUNT(*) FROM plugin_cds_profiles');
}

function cereus_datasync_delete_profile(int $id): void {
    // Delete in FK order
    $runIds = db_fetch_assoc_prepared('SELECT id FROM plugin_cds_runs WHERE profile_id = ?', [$id]);
    if (cacti_sizeof($runIds)) {
        foreach ($runIds as $r) {
            db_execute_prepared('DELETE FROM plugin_cds_run_details WHERE run_id = ?', [(int)$r['id']]);
        }
    }
    db_execute_prepared('DELETE FROM plugin_cds_runs WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_tree_rules WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_aggregate_rules WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_oid_rules WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_function_maps WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_column_maps WHERE profile_id = ?', [$id]);
    db_execute_prepared('DELETE FROM plugin_cds_profiles WHERE id = ?', [$id]);
}

function cereus_datasync_toggle_profile(int $id, string $state): void {
    $val = ($state === 'enable') ? 'on' : '';
    db_execute_prepared("UPDATE plugin_cds_profiles SET enabled = ? WHERE id = ?", [$val, $id]);
}

function cereus_datasync_copy_profile(int $id): int {
    $src = db_fetch_row_prepared('SELECT * FROM plugin_cds_profiles WHERE id = ?', [$id]);
    if (!$src) return 0;

    $newName = __('Copy of %s', $src['name'], 'cereus_datasync');

    db_execute_prepared(
        "INSERT INTO plugin_cds_profiles
            (name, description, enabled, excel_path, excel_file_mode, sheet_name,
             data_start_row, schedule_type, schedule_hour, schedule_wday,
             snmp_check_l2, snmp_wan_pattern, snmp_timeout_ms, snmp_enable_parallel,
             snmp_parallel_workers, default_snmp_version, default_snmp_community,
             default_snmp_port, default_snmp_timeout, default_availability,
             default_ping_method, default_poller_id, deletion_tag, deletion_skip_localhost,
             auto_graph_rules, auto_create_graphs, graph_query_type_id)
         VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $newName,
            $src['description'],
            $src['excel_path'],
            $src['excel_file_mode'],
            $src['sheet_name'],
            (int)$src['data_start_row'],
            $src['schedule_type'],
            (int)$src['schedule_hour'],
            (int)$src['schedule_wday'],
            (int)$src['snmp_check_l2'],
            $src['snmp_wan_pattern'],
            (int)$src['snmp_timeout_ms'],
            (int)$src['snmp_enable_parallel'],
            (int)$src['snmp_parallel_workers'],
            (int)$src['default_snmp_version'],
            $src['default_snmp_community'],
            (int)$src['default_snmp_port'],
            (int)$src['default_snmp_timeout'],
            (int)$src['default_availability'],
            (int)$src['default_ping_method'],
            (int)$src['default_poller_id'],
            $src['deletion_tag'],
            (int)$src['deletion_skip_localhost'],
            (int)$src['auto_graph_rules'],
            (int)$src['auto_create_graphs'],
            (int)$src['graph_query_type_id'],
        ]
    );
    $newId = (int)db_fetch_insert_id();
    if (!$newId) return 0;

    // Copy column maps
    $cols = db_fetch_assoc_prepared('SELECT field_name, col_letter FROM plugin_cds_column_maps WHERE profile_id = ?', [$id]);
    if (cacti_sizeof($cols)) {
        foreach ($cols as $c) {
            db_execute_prepared(
                'INSERT INTO plugin_cds_column_maps (profile_id, field_name, col_letter) VALUES (?, ?, ?)',
                [$newId, $c['field_name'], $c['col_letter']]
            );
        }
    }

    // Copy function maps
    $fmaps = db_fetch_assoc_prepared('SELECT * FROM plugin_cds_function_maps WHERE profile_id = ?', [$id]);
    if (cacti_sizeof($fmaps)) {
        foreach ($fmaps as $f) {
            db_execute_prepared(
                'INSERT INTO plugin_cds_function_maps (profile_id, device_function, host_template_id, snmp_check, enabled) VALUES (?, ?, ?, ?, ?)',
                [$newId, $f['device_function'], (int)$f['host_template_id'], (int)$f['snmp_check'], $f['enabled']]
            );
        }
    }

    // Copy tree rules and their conditions
    $rules = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_tree_rules WHERE profile_id = ? ORDER BY rule_order',
        [$id]
    );
    if (cacti_sizeof($rules)) {
        foreach ($rules as $r) {
            db_execute_prepared(
                'INSERT INTO plugin_cds_tree_rules (profile_id, rule_order, enabled, name, tree_id, leaf_type, host_grouping) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$newId, (int)$r['rule_order'], $r['enabled'], $r['name'], (int)$r['tree_id'], (int)$r['leaf_type'], (int)$r['host_grouping']]
            );
            $newRuleId = (int)db_fetch_insert_id();

            $conds = db_fetch_assoc_prepared(
                'SELECT * FROM plugin_cds_rule_conditions WHERE rule_id = ? ORDER BY sequence',
                [(int)$r['id']]
            );
            if (cacti_sizeof($conds)) {
                foreach ($conds as $c) {
                    db_execute_prepared(
                        'INSERT INTO plugin_cds_rule_conditions (rule_id, sequence, operation, connector, open_paren, close_paren, field, operator, pattern) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$newRuleId, (int)$c['sequence'], (int)$c['operation'],
                         (strtoupper($c['connector'] ?? 'AND') === 'OR' ? 'OR' : 'AND'),
                         (int)($c['open_paren'] ?? 0), (int)($c['close_paren'] ?? 0),
                         $c['field'], (int)$c['operator'], $c['pattern']]
                    );
                }
            }
        }
    }

    // Copy aggregate rules (reset result_graph_id — new profile hasn't run yet)
    $aggRules = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_aggregate_rules WHERE profile_id = ? ORDER BY rule_order',
        [$id]
    );
    if (cacti_sizeof($aggRules)) {
        foreach ($aggRules as $ar) {
            db_execute_prepared(
                'INSERT INTO plugin_cds_aggregate_rules
                    (profile_id, rule_order, enabled, name, graph_template_id, aggregate_template_id,
                     tree_id, tree_item_id, device_match_field, device_match_pattern,
                     graph_title_pattern, result_graph_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
                [
                    $newId,
                    (int)$ar['rule_order'],
                    $ar['enabled'],
                    $ar['name'],
                    (int)$ar['graph_template_id'],
                    (int)$ar['aggregate_template_id'],
                    (int)$ar['tree_id'],
                    (int)$ar['tree_item_id'],
                    $ar['device_match_field'],
                    $ar['device_match_pattern'],
                    $ar['graph_title_pattern'] ?? '',
                ]
            );
        }
    }

    return $newId;
}

// ─── Column map helpers ───────────────────────────────────────────────────────

function cereus_datasync_get_column_maps(int $profileId): array {
    $rows = db_fetch_assoc_prepared('SELECT field_name, col_letter FROM plugin_cds_column_maps WHERE profile_id = ?', [$profileId]);
    $map  = [];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $map[$r['field_name']] = $r['col_letter'];
        }
    }
    return $map;
}

function cereus_datasync_save_column_maps(int $profileId, array $maps): void {
    db_execute_prepared('DELETE FROM plugin_cds_column_maps WHERE profile_id = ?', [$profileId]);
    foreach ($maps as $field => $col) {
        $col = strtoupper(trim($col));
        if ($col === '') continue;
        db_execute_prepared(
            'INSERT INTO plugin_cds_column_maps (profile_id, field_name, col_letter) VALUES (?, ?, ?)',
            [$profileId, $field, $col]
        );
    }
}

// ─── Function map helpers ─────────────────────────────────────────────────────

function cereus_datasync_get_function_maps(int $profileId): array {
    $rows = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_function_maps WHERE profile_id = ? ORDER BY device_function',
        [$profileId]
    );
    return cacti_sizeof($rows) ? $rows : [];
}

function cereus_datasync_save_function_map(int $profileId, string $func, int $templateId): int {
    db_execute_prepared(
        'INSERT INTO plugin_cds_function_maps (profile_id, device_function, host_template_id) VALUES (?, ?, ?)',
        [$profileId, $func, $templateId]
    );
    return (int)db_fetch_insert_id();
}

function cereus_datasync_delete_function_map(int $mapId, int $profileId): void {
    db_execute_prepared('DELETE FROM plugin_cds_function_maps WHERE id = ? AND profile_id = ?', [$mapId, $profileId]);
}

// ─── Tree rule helpers ────────────────────────────────────────────────────────

// ─── Rule template helpers ────────────────────────────────────────────────────

function cereus_datasync_get_rule_templates(int $profileId): array {
    $rows = db_fetch_assoc_prepared(
        'SELECT r.*, gt.name AS tree_name
         FROM plugin_cds_tree_rules r
         LEFT JOIN graph_tree gt ON gt.id = r.tree_id
         WHERE r.profile_id = ?
         ORDER BY r.rule_order ASC',
        [$profileId]
    );
    return cacti_sizeof($rows) ? $rows : [];
}

function cereus_datasync_get_rule_conditions(int $ruleId): array {
    $rows = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_rule_conditions WHERE rule_id = ? ORDER BY sequence',
        [$ruleId]
    );
    return cacti_sizeof($rows) ? $rows : [];
}

function cereus_datasync_delete_tree_rule(int $ruleId, int $profileId): void {
    db_execute_prepared('DELETE FROM plugin_cds_rule_conditions WHERE rule_id = ?', [$ruleId]);
    db_execute_prepared('DELETE FROM plugin_cds_tree_rules WHERE id = ? AND profile_id = ?', [$ruleId, $profileId]);
}

function cereus_datasync_reorder_tree_rules(int $profileId, array $orderedIds): void {
    $order = 10;
    foreach ($orderedIds as $id) {
        db_execute_prepared(
            'UPDATE plugin_cds_tree_rules SET rule_order = ? WHERE id = ? AND profile_id = ?',
            [$order, (int)$id, $profileId]
        );
        $order += 10;
    }
}

// ─── Aggregate rule helpers ───────────────────────────────────────────────────

function cereus_datasync_get_agg_rules(int $profileId): array {
    $rows = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_aggregate_rules WHERE profile_id = ? ORDER BY rule_order ASC, id ASC',
        [$profileId]
    );
    return cacti_sizeof($rows) ? $rows : [];
}

function cereus_datasync_delete_agg_rule(int $ruleId, int $profileId): void {
    db_execute_prepared(
        'DELETE FROM plugin_cds_aggregate_rules WHERE id = ? AND profile_id = ?',
        [$ruleId, $profileId]
    );
}

function cereus_datasync_get_oid_rules(int $profileId): array {
    $rows = db_fetch_assoc_prepared(
        'SELECT * FROM plugin_cds_oid_rules WHERE profile_id = ? ORDER BY rule_order ASC, id ASC',
        [$profileId]
    );
    return cacti_sizeof($rows) ? $rows : [];
}

function cereus_datasync_delete_oid_rule(int $ruleId, int $profileId): void {
    db_execute_prepared(
        'DELETE FROM plugin_cds_oid_rules WHERE id = ? AND profile_id = ?',
        [$ruleId, $profileId]
    );
}

function cereus_datasync_get_graph_templates_array(): array {
    $rows   = db_fetch_assoc('SELECT id, name FROM graph_templates ORDER BY name');
    $result = [0 => __('-- Select Graph Template --', 'cereus_datasync')];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $result[(int)$r['id']] = html_escape($r['name']);
        }
    }
    return $result;
}

function cereus_datasync_get_aggregate_templates_array(): array {
    $rows   = db_fetch_assoc('SELECT id, name FROM aggregate_graph_templates ORDER BY name');
    $result = [0 => __('— None (no aggregate template) —', 'cereus_datasync')];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $result[(int)$r['id']] = html_escape($r['name']);
        }
    }
    return $result;
}

// ─── UI helpers ──────────────────────────────────────────────────────────────

function cereus_datasync_status_badge(string $status): string {
    $colors = [
        'completed' => '#15803d',
        'failed'    => '#dc2626',
        'running'   => '#2563eb',
        'dry_run'   => '#7c3aed',
    ];
    $color = $colors[$status] ?? '#64748b';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $color . ';color:#fff;font-size:11px;font-weight:600;">' . html_escape(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

function cereus_datasync_schedule_label(string $type): string {
    $labels = [
        'manual'       => __('Manual', 'cereus_datasync'),
        'every_poller' => __('Every Poll Cycle', 'cereus_datasync'),
        'hourly'       => __('Hourly', 'cereus_datasync'),
        'daily'        => __('Daily', 'cereus_datasync'),
        'weekly'       => __('Weekly', 'cereus_datasync'),
    ];
    return $labels[$type] ?? $type;
}

function cereus_datasync_get_trees_array(): array {
    $rows   = db_fetch_assoc('SELECT id, name FROM graph_tree ORDER BY name');
    $result = [0 => __('-- Select Tree --', 'cereus_datasync')];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $result[(int)$r['id']] = html_escape($r['name']);
        }
    }
    return $result;
}

function cereus_datasync_get_tree_nodes_array(int $treeId): array {
    $result = [0 => __('(Tree Root)', 'cereus_datasync')];
    if (!$treeId) return $result;

    $rows = db_fetch_assoc_prepared(
        "SELECT id, title, parent, COALESCE(position, 0) AS position
         FROM graph_tree_items
         WHERE graph_tree_id = ? AND local_graph_id = 0 AND host_id = 0 AND title != ''
         ORDER BY parent, COALESCE(position, 0), title",
        [$treeId]
    );
    if (!cacti_sizeof($rows)) return $result;

    // Build parent → ordered-children map
    $children = [];
    foreach ($rows as $r) {
        $children[(int)$r['parent']][] = $r;
    }

    // Depth-first traversal — produces tree-ordered list with NBSP indentation.
    // U+00A0 (non-breaking space, UTF-8: \xc2\xa0) renders correctly in both
    // direct PHP <option> output and JSON → JS innerHTML paths.
    $traverse = function(int $parentId, int $depth) use (&$traverse, &$result, &$children): void {
        if (!isset($children[$parentId])) return;
        $indent = str_repeat("\xc2\xa0\xc2\xa0\xc2\xa0", $depth);
        foreach ($children[$parentId] as $node) {
            $result[(int)$node['id']] = $indent . html_escape($node['title']);
            $traverse((int)$node['id'], $depth + 1);
        }
    };

    $traverse(0, 0);
    return $result;
}

function cereus_datasync_get_templates_array(): array {
    $rows   = db_fetch_assoc('SELECT id, name FROM host_template ORDER BY name');
    $result = [0 => __('-- Select Template --', 'cereus_datasync')];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $r) {
            $result[(int)$r['id']] = html_escape($r['name']);
        }
    }
    return $result;
}

function cereus_datasync_license_wall(): void {
    html_start_box(__('Cereus Data Sync', 'cereus_datasync'), '100%', '', '3', 'center', '');
    print '<tr><td style="padding:24px;text-align:center;">';
    print '<p style="font-size:16px;font-weight:600;margin-bottom:8px;">' . __('Professional License Required', 'cereus_datasync') . '</p>';
    print '<p style="color:#666;">' . __('Cereus Data Sync requires a valid Professional or Enterprise license. Please install and activate your Cereus license.', 'cereus_datasync') . '</p>';
    print '</td></tr>';
    html_end_box();
}
