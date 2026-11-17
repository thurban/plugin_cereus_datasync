<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SNMP naming checks: per-profile entries that say which OID to walk and what text
// to look for. Also loaded by the SNMP worker, so nothing here may run on include.

function cereus_datasync_snmp_check_operators(): array {
    return [
        1 => __('contains', 'cereus_datasync'),
        3 => __('begins with', 'cereus_datasync'),
        5 => __('ends with', 'cereus_datasync'),
        7 => __('equals', 'cereus_datasync'),
    ];
}

function cereus_datasync_snmp_check_presets(): array {
    return [
        '.1.3.6.1.2.1.31.1.1.1.18'       => 'ifAlias',
        '.1.3.6.1.2.1.2.2.1.2'           => 'ifDescr',
        '.1.3.6.1.2.1.31.1.1.1.1'        => 'ifName',
        '.1.3.6.1.4.1.9.9.42.1.2.1.1.3'  => 'Cisco IP SLA tag',
    ];
}

function cereus_datasync_snmp_check_oid_valid(string $oid): bool {
    return (bool)preg_match('/^\.?\d+(\.\d+)+$/', $oid);
}

/**
 * Case-insensitive, as the single interface pattern this replaces was.
 */
function cereus_datasync_snmp_check_value_matches(string $value, int $operator, string $text): bool {
    $value = strtolower(trim($value));
    $text  = strtolower(trim($text));

    if ($value === '' || $text === '') return false;

    switch ($operator) {
        case 3:  return strpos($value, $text) === 0;
        case 5:  return substr($value, -strlen($text)) === $text;
        case 7:  return $value === $text;
        default: return strpos($value, $text) !== false;
    }
}

/**
 * Walk the check's OID and return the index of every row whose value matches. The
 * index is what follows the OID, e.g. 7 for .1.3.6.1.4.1.9.9.42.1.2.1.1.3.7.
 *
 * $snmp holds hostname, community and port; version, the v3 fields and max_oids
 * are optional.
 */
function cereus_datasync_snmp_check_walk(array $check, array $snmp, int $timeoutMs): array {
    $rows = @cacti_snmp_walk(
        $snmp['hostname'],
        $snmp['community'],
        $check['oid'],
        $snmp['version'] ?? 2,
        $snmp['username'] ?? '',
        $snmp['password'] ?? '',
        $snmp['auth_protocol'] ?? '',
        $snmp['priv_passphrase'] ?? '',
        $snmp['priv_protocol'] ?? '',
        $snmp['context'] ?? '',
        $snmp['port'],
        $timeoutMs,
        1,
        $snmp['max_oids'] ?? 10,
        'SNMP',
        $snmp['engine_id'] ?? ''
    );

    if (!is_array($rows)) return [];

    $base    = trim($check['oid'], '.') . '.';
    $indexes = [];

    foreach ($rows as $row) {
        if (!cereus_datasync_snmp_check_value_matches((string)($row['value'] ?? ''), (int)$check['operator'], $check['pattern'])) {
            continue;
        }

        $oid = trim((string)($row['oid'] ?? ''), '.');
        $indexes[] = strpos($oid, $base) === 0 ? substr($oid, strlen($base)) : '';
    }

    return $indexes;
}

/**
 * The name of the first check the device passes, or null if it passes none.
 */
function cereus_datasync_snmp_check_device(array $checks, array $snmp, int $timeoutMs): ?string {
    foreach ($checks as $check) {
        if (cacti_sizeof(cereus_datasync_snmp_check_walk($check, $snmp, $timeoutMs))) {
            return $check['name'];
        }
    }

    return null;
}

/**
 * The checks that apply to a device function. $check['functions'] holds the names
 * of the functions an entry is limited to.
 */
function cereus_datasync_snmp_checks_for_function(array $checks, string $function): array {
    return array_values(array_filter($checks, function ($check) use ($function) {
        return !empty($check['all_functions']) || in_array($function, $check['functions'] ?? [], true);
    }));
}

function cereus_datasync_snmp_check_set_functions(int $checkId, int $profileId, array $mapIds): void {
    db_execute_prepared('DELETE FROM plugin_cds_snmp_check_functions WHERE check_id = ?', [$checkId]);

    foreach ($mapIds as $mapId) {
        db_execute_prepared(
            'INSERT IGNORE INTO plugin_cds_snmp_check_functions (check_id, function_map_id)
             SELECT ?, id FROM plugin_cds_function_maps WHERE id = ? AND profile_id = ?',
            [$checkId, (int)$mapId, $profileId]
        );
    }
}

function cereus_datasync_snmp_check_add(int $profileId, string $name, string $oid, string $pattern, int $queryTypeId = 0): int {
    db_execute_prepared(
        "INSERT INTO plugin_cds_snmp_checks (profile_id, enabled, name, oid, operator, pattern, graph_query_type_id)
         VALUES (?, 'on', ?, ?, 1, ?, ?)",
        [$profileId, $name, $oid, $pattern, $queryTypeId]
    );

    return (int)db_fetch_insert_id();
}

function cereus_datasync_query_type_options(): array {
    $rows = db_fetch_assoc(
        'SELECT sqg.id, CONCAT(sq.name, " — ", sqg.name) AS label
         FROM snmp_query_graph sqg
         JOIN snmp_query sq ON sq.id = sqg.snmp_query_id
         ORDER BY sq.name, sqg.name'
    );

    $options = [];
    if (cacti_sizeof($rows)) {
        foreach ($rows as $row) {
            $options[(int)$row['id']] = $row['label'];
        }
    }

    return $options;
}

function cereus_datasync_render_snmp_check_row(array $check, array $queryTypes, array $funcMaps): string {
    $presetOid = isset(cereus_datasync_snmp_check_presets()[$check['oid']]) ? $check['oid'] : '';

    $preset = '<option value="">' . html_escape(__('Custom OID', 'cereus_datasync')) . '</option>';
    foreach (cereus_datasync_snmp_check_presets() as $oid => $label) {
        $preset .= '<option value="' . html_escape($oid) . '"' . ($oid === $presetOid ? ' selected' : '') . '>' . html_escape($label) . '</option>';
    }

    $operator = '';
    foreach (cereus_datasync_snmp_check_operators() as $code => $label) {
        $operator .= '<option value="' . $code . '"' . ($code === (int)$check['operator'] ? ' selected' : '') . '>' . html_escape($label) . '</option>';
    }

    $graph = '<option value="0">' . html_escape(__('None', 'cereus_datasync')) . '</option>';
    foreach ($queryTypes as $id => $label) {
        $graph .= '<option value="' . $id . '"' . ($id === (int)$check['graph_query_type_id'] ? ' selected' : '') . '>' . html_escape($label) . '</option>';
    }

    $linked = array_column(db_fetch_assoc_prepared(
        'SELECT function_map_id FROM plugin_cds_snmp_check_functions WHERE check_id = ?',
        [(int)$check['id']]
    ), 'function_map_id');

    $functions = '';
    foreach ($funcMaps as $map) {
        $functions .= '<label class="cds-check-func" data-map="' . (int)$map['id'] . '"' . ($map['device_function'] === '' ? ' style="display:none;"' : '') . '>'
            . '<input type="checkbox" value="' . (int)$map['id'] . '"' . (in_array($map['id'], $linked) ? ' checked' : '') . '> '
            . '<span>' . html_escape($map['device_function']) . '</span></label>';
    }

    $all = !empty($check['all_functions']);

    $field = function (string $label, string $control, string $class = ''): string {
        return '<label class="cds-check-field ' . $class . '"><span>' . html_escape($label) . '</span>' . $control . '</label>';
    };

    return '<div class="cds-check-row" data-id="' . (int)$check['id'] . '">'
        . $field(__('Name', 'cereus_datasync'), '<input type="text" class="cds-check-name ui-state-default ui-corner-all" maxlength="128" value="' . html_escape($check['name']) . '">')
        . $field(__('SNMP Data', 'cereus_datasync'), '<select class="cds-check-preset">' . $preset . '</select>')
        . $field(__('OID', 'cereus_datasync'), '<input type="text" class="cds-check-oid ui-state-default ui-corner-all" maxlength="256" value="' . html_escape($check['oid']) . '">')
        . $field(__('Match', 'cereus_datasync'), '<select class="cds-check-operator">' . $operator . '</select>')
        . $field(__('Text', 'cereus_datasync'), '<input type="text" class="cds-check-pattern ui-state-default ui-corner-all" maxlength="256" value="' . html_escape($check['pattern']) . '">')
        . $field(__('Create Graphs', 'cereus_datasync'), '<select class="cds-check-graph">' . $graph . '</select>', 'cds-check-wide')
        . '<label class="cds-check-enabled"><input type="checkbox" class="cds-check-on"' . ($check['enabled'] === 'on' ? ' checked' : '') . '> ' . html_escape(__('Enabled', 'cereus_datasync')) . '</label>'
        . '<button type="button" class="ui-button cds-check-del" title="' . html_escape(__('Delete', 'cereus_datasync')) . '">&#128465;</button>'
        . '<div class="cds-check-scope">'
        . '<span class="cds-check-scope-title">' . html_escape(__('Device Functions', 'cereus_datasync')) . '</span>'
        . '<label class="cds-check-func"><input type="checkbox" class="cds-check-all"' . ($all ? ' checked' : '') . '> ' . html_escape(__('All device functions', 'cereus_datasync')) . '</label>'
        . '<span class="cds-check-func-list"' . ($all ? ' style="display:none;"' : '') . '>' . $functions . '</span>'
        . '<span class="cds-check-none"' . ($all || cacti_sizeof($linked) ? ' style="display:none;"' : '') . '>' . html_escape(__('No device function chosen, so this entry is not used.', 'cereus_datasync')) . '</span>'
        . '</div>'
        . '<span class="cds-check-status" role="status"></span>'
        . '</div>';
}
