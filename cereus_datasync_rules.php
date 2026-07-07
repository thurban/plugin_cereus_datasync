<?php
// SPDX-License-Identifier: GPL-2.0-or-later
chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/license_check.php');
require_once(__DIR__ . '/lib/functions.php');

if (!api_user_realm_auth('cereus_datasync.php')) {
    header('Location: ../../index.php');
    exit;
}

if (!cereus_datasync_license_ok()) {
    top_header();
    cereus_datasync_license_wall();
    bottom_footer();
    exit;
}

$profile_id = get_filter_request_var('profile_id', FILTER_VALIDATE_INT) ?: 0;
$profile    = $profile_id ? cereus_datasync_get_profile($profile_id) : null;

if (!$profile) {
    header('Location: cereus_datasync.php');
    exit;
}

$tab = get_nfilter_request_var('tab', 'tree');
if (!in_array($tab, ['tree', 'aggregate', 'oid'], true)) $tab = 'tree';

top_header();
if ($tab === 'aggregate') {
    cereus_datasync_agrules_tab($profile_id, $profile);
} elseif ($tab === 'oid') {
    cereus_datasync_oid_rules_tab($profile_id, $profile);
} else {
    cereus_datasync_rule_templates_page($profile_id, $profile);
}
bottom_footer();

// ─── Shared tab bar ───────────────────────────────────────────────────────────

function cereus_datasync_tab_bar(int $profileId, string $active): void {
    $base = 'cereus_datasync_rules.php?profile_id=' . $profileId . '&tab=';
    $tabs = [
        'tree'      => __('Tree Placement Rules', 'cereus_datasync'),
        'aggregate' => __('Aggregate Graph Rules', 'cereus_datasync'),
        'oid'       => __('OID Graph Rules', 'cereus_datasync'),
    ];
    print '<div style="display:flex;gap:2px;margin-bottom:10px;border-bottom:2px solid #e2e8f0;">';
    foreach ($tabs as $key => $label) {
        if ($key === $active) {
            $s = 'display:inline-block;padding:7px 18px;font-size:13px;font-weight:600;'
               . 'color:#fff;background:#2563eb;border-radius:4px 4px 0 0;text-decoration:none;'
               . 'margin-bottom:-2px;border:2px solid #2563eb;border-bottom-color:#fff;';
        } else {
            $s = 'display:inline-block;padding:7px 18px;font-size:13px;font-weight:500;'
               . 'color:#475569;background:#f8fafc;border-radius:4px 4px 0 0;text-decoration:none;'
               . 'margin-bottom:-2px;border:2px solid #e2e8f0;';
        }
        print '<a href="' . $base . $key . '" style="' . $s . '">' . html_escape($label) . '</a>';
    }
    print '</div>';
}

// ─── Tab: Tree Placement Rules ────────────────────────────────────────────────

function cereus_datasync_rule_templates_page(int $profileId, array $profile): void {
    $templates = cereus_datasync_get_rule_templates($profileId);
    $trees     = cereus_datasync_get_trees_array();

    $fields = [
        'h.site_id'       => 'h.site_id — Site Association (recommended)',
        'h.location'      => 'h.location — Location text (truncated)',
        'h.description'   => 'h.description — Device name',
        'h.hostname'      => 'h.hostname — Device IP',
        'h.notes'         => 'h.notes — Notes',
        'ht.name'         => 'ht.name — Host template',
        'gt.name'         => 'gt.name — Graph template',
        'gtg.title_cache' => 'gtg.title_cache — Graph title',
    ];
    $operators = [
        1 => 'contains',
        2 => 'does not contain',
        3 => 'begins with',
        5 => 'ends with',
        7 => 'equals (exact match)',
    ];
    $leafTypes = [
        2 => 'Graph — place matching graphs in tree',
        3 => 'Host — place matching devices in tree',
    ];
    $groupings = [
        1 => 'Graph Template',
        2 => 'Data Query Index',
    ];

    cereus_datasync_tab_bar($profileId, 'tree');

    html_start_box(
        __('Rule Templates — %s', html_escape($profile['name']), 'cereus_datasync'),
        '100%', '', '3', 'center', ''
    );
    print '<tr><td style="padding:8px 15px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:12px;color:#0369a1;">';
    print __('Each template generates one Cacti automation rule per unique location found in the Excel file. Use these placeholders in condition patterns — they are substituted with the actual values at sync time:<br><br>'
        . '&bull; <code>{site_id}</code> — Cacti site database ID (integer FK). Use with field <strong>h.site_id</strong> and operator <strong>equals</strong>. Most reliable — unambiguous, no truncation.<br>'
        . '&bull; <code>{site}</code> — Site name from Excel (truncated to 40 chars to match <strong>h.location</strong>).<br>'
        . '&bull; <code>{region}</code> — Region from Excel.<br>'
        . '&bull; <code>{country}</code> — Country from Excel.<br><br>'
        . '<strong>Grouping:</strong> each condition joins to the previous one with <strong>AND</strong> or <strong>OR</strong>, and the <strong>(</strong> / <strong>)</strong> columns add parentheses so you can build grouped logic — e.g. '
        . '<code>h.site_id&nbsp;equals&nbsp;{site_id} AND ( h.location&nbsp;begins&nbsp;Stuttgart OR h.location&nbsp;begins&nbsp;München OR h.location&nbsp;begins&nbsp;Lübeck )</code>. '
        . 'Balance every <strong>(</strong> with a matching <strong>)</strong>.',
        'cereus_datasync');
    print '</td></tr>';
    print '<tr class="even"><td style="padding:6px 15px;">';
    print '<a href="cereus_datasync_edit.php?action=edit&id=' . $profileId . '" class="cds-link">&laquo; ' . __('Back to Profile', 'cereus_datasync') . '</a>';
    print '</td></tr>';
    html_end_box();

    // ── Render each template ─────────────────────────────────────────────────
    if (cacti_sizeof($templates)) {
        foreach ($templates as $tpl) {
            $tplId = (int)$tpl['id'];
            $conds = cereus_datasync_get_rule_conditions($tplId);

            html_start_box('', '100%', '', '3', 'center', '');
            print '<tr class="tableHeader"><td colspan="2" style="padding:8px 12px;">';
            print '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
            print '<input type="text" class="cds-tpl-name ui-state-default ui-corner-all" data-id="' . $tplId . '" value="' . html_escape($tpl['name']) . '" placeholder="Template name…" style="font-weight:600;min-width:200px;">';
            print '<span style="color:#666;font-size:12px;">→</span>';
            print '<select class="cds-tpl-tree ui-state-default ui-corner-all" data-id="' . $tplId . '">';
            foreach ($trees as $tid => $tname) {
                print '<option value="' . $tid . '"' . ($tid == $tpl['tree_id'] ? ' selected' : '') . '>' . html_escape($tname) . '</option>';
            }
            print '</select>';
            print '<select class="cds-tpl-leaf ui-state-default ui-corner-all" data-id="' . $tplId . '">';
            foreach ($leafTypes as $lv => $ll) {
                print '<option value="' . $lv . '"' . ($lv == $tpl['leaf_type'] ? ' selected' : '') . '>' . html_escape($ll) . '</option>';
            }
            print '</select>';
            print '<select class="cds-tpl-grp ui-state-default ui-corner-all" data-id="' . $tplId . '">';
            foreach ($groupings as $gv => $gl) {
                print '<option value="' . $gv . '"' . ($gv == $tpl['host_grouping'] ? ' selected' : '') . '>' . html_escape($gl) . '</option>';
            }
            print '</select>';
            print '<label style="font-size:12px;"><input type="checkbox" class="cds-tpl-enabled" data-id="' . $tplId . '"' . ($tpl['enabled'] === 'on' ? ' checked' : '') . '> ' . __('Enabled', 'cereus_datasync') . '</label>';
            print '<button type="button" class="ui-button cds-tpl-del" data-id="' . $tplId . '" style="margin-left:auto;min-width:0;padding:2px 10px;color:#dc2626;border-color:#fca5a5;">&#128465; Delete</button>';
            print '</div>';
            print '</td></tr>';

            print '<tr class="even"><td colspan="2" style="padding:6px 15px 12px;">';
            print '<table class="cactiTable cds-cond-table" id="cds-cond-' . $tplId . '" style="width:100%;border-collapse:collapse;">';
            print '<thead><tr style="background:#f8fafc;">';
            print '<th style="padding:5px 8px;width:70px;text-align:left;font-size:12px;">' . __('Join', 'cereus_datasync') . '</th>';
            print '<th style="padding:5px 4px;width:44px;text-align:center;font-size:12px;" title="' . __('Opening parentheses before this condition', 'cereus_datasync') . '">(</th>';
            print '<th style="padding:5px 8px;width:22%;text-align:left;font-size:12px;">' . __('Field', 'cereus_datasync') . '</th>';
            print '<th style="padding:5px 8px;width:16%;text-align:left;font-size:12px;">' . __('Operator', 'cereus_datasync') . '</th>';
            print '<th style="padding:5px 8px;text-align:left;font-size:12px;">' . __('Pattern', 'cereus_datasync') . ' <span style="color:#0369a1;font-weight:400;">{site} {region} {country}</span></th>';
            print '<th style="padding:5px 4px;width:44px;text-align:center;font-size:12px;" title="' . __('Closing parentheses after this condition', 'cereus_datasync') . '">)</th>';
            print '<th style="width:40px;padding:5px 8px;"></th>';
            print '</tr></thead>';
            print '<tbody id="cds-cond-tbody-' . $tplId . '">';

            if (cacti_sizeof($conds)) {
                foreach ($conds as $cond) {
                    cereus_datasync_render_condition_row($tplId, $cond, $fields, $operators);
                }
            } else {
                print '<tr id="cds-cond-empty-' . $tplId . '"><td colspan="7" style="padding:10px;color:#999;font-style:italic;font-size:12px;">' . __('No conditions yet.', 'cereus_datasync') . '</td></tr>';
            }
            print '</tbody></table>';
            print '<div style="margin-top:6px;">';
            print '<button type="button" class="ui-button cds-add-cond" data-id="' . $tplId . '" style="font-size:12px;">+ ' . __('Add Condition', 'cereus_datasync') . '</button>';
            print '</div>';
            print '</td></tr>';
            html_end_box();
        }
    } else {
        html_start_box('', '100%', '', '3', 'center', '');
        print '<tr><td style="padding:20px;text-align:center;color:#999;font-style:italic;">' . __('No rule templates yet. Add one to get started.', 'cereus_datasync') . '</td></tr>';
        html_end_box();
    }

    print '<div style="margin:8px 0;">';
    print '<button type="button" class="ui-button" id="cds-add-tpl">+ ' . __('Add Rule Template', 'cereus_datasync') . '</button>';
    print '</div>';

    $fieldsJson    = json_encode($fields);
    $operatorsJson = json_encode($operators);
    $treesJson     = json_encode($trees);
    $leafJson      = json_encode($leafTypes);
    $grpJson       = json_encode($groupings);
    ?>
    <div id="cds-save-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#15803d;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        &#10003; Saved
    </div>
    <div id="cds-saving-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#2563eb;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        Saving&hellip;
    </div>

    <script>
    var cdsProfileId  = <?php print $profileId; ?>;
    var cdsFields     = <?php print $fieldsJson; ?>;
    var cdsOperators  = <?php print $operatorsJson; ?>;
    var cdsTrees      = <?php print $treesJson; ?>;
    var cdsLeafTypes  = <?php print $leafJson; ?>;
    var cdsGroupings  = <?php print $grpJson; ?>;
    var cdsSaveTimer  = null;

    function cdsShowSaved() {
        $('#cds-saving-toast').hide();
        $('#cds-save-toast').fadeIn(150);
        clearTimeout(cdsSaveTimer);
        cdsSaveTimer = setTimeout(function() { $('#cds-save-toast').fadeOut(300); }, 1800);
    }
    function cdsShowSaving() {
        $('#cds-save-toast').hide();
        $('#cds-saving-toast').fadeIn(150);
    }

    function buildSel(obj, sel) {
        return Object.entries(obj).map(function(e) {
            return '<option value="' + e[0] + '"' + (String(e[0]) === String(sel) ? ' selected' : '') + '>' + $('<div>').text(e[1]).html() + '</option>';
        }).join('');
    }

    function cdsConnSel(val) {
        var v = (String(val).toUpperCase() === 'OR') ? 'OR' : 'AND';
        return '<select class="cds-cond-conn ui-state-default ui-corner-all" style="width:100%;">'
            + '<option value="AND"' + (v === 'AND' ? ' selected' : '') + '>AND</option>'
            + '<option value="OR"'  + (v === 'OR'  ? ' selected' : '') + '>OR</option>'
            + '</select>';
    }

    function cdsParenSel(cls, val) {
        var glyph = (cls === 'cds-cond-open') ? '(' : ')';
        var v = Math.max(0, Math.min(3, parseInt(val, 10) || 0));
        var html = '<select class="' + cls + ' ui-state-default ui-corner-all" style="width:100%;text-align:center;">';
        for (var i = 0; i <= 3; i++) {
            html += '<option value="' + i + '"' + (i === v ? ' selected' : '') + '>' + (i === 0 ? '–' : glyph.repeat(i)) + '</option>';
        }
        return html + '</select>';
    }

    // Disable the connector on the very first condition row (nothing precedes it).
    function cdsRefreshConnectors(tplId) {
        $('#cds-cond-tbody-' + tplId + ' tr[data-cid]').each(function(idx) {
            $(this).find('.cds-cond-conn').prop('disabled', idx === 0);
        });
    }

    function buildCondRow(tplId, cond) {
        return '<tr data-cid="' + cond.id + '" data-rid="' + tplId + '">'
            + '<td style="padding:3px 4px;">' + cdsConnSel(cond.connector) + '</td>'
            + '<td style="padding:3px 2px;">' + cdsParenSel('cds-cond-open', cond.open_paren) + '</td>'
            + '<td style="padding:3px 6px;"><select class="cds-cond-field ui-state-default ui-corner-all" style="width:100%;">' + buildSel(cdsFields, cond.field) + '</select></td>'
            + '<td style="padding:3px 6px;"><select class="cds-cond-op ui-state-default ui-corner-all" style="width:100%;">' + buildSel(cdsOperators, cond.operator) + '</select></td>'
            + '<td style="padding:3px 6px;"><input type="text" class="cds-cond-pat ui-state-default ui-corner-all" value="' + $('<div>').text(cond.pattern).html() + '" style="width:100%;font-family:monospace;"></td>'
            + '<td style="padding:3px 2px;">' + cdsParenSel('cds-cond-close', cond.close_paren) + '</td>'
            + '<td style="padding:3px 6px;text-align:center;"><button type="button" class="ui-button cds-del-cond" data-cid="' + cond.id + '" data-tpl="' + tplId + '" style="min-width:0;padding:2px 6px;">&#128465;</button></td>'
            + '</tr>';
    }

    $(function() {
        $(document).on('blur change', '.cds-tpl-name, .cds-tpl-tree, .cds-tpl-leaf, .cds-tpl-grp, .cds-tpl-enabled', function() {
            saveTpl($(this).data('id'));
        });

        function saveTpl(tid) {
            cdsShowSaving();
            $.post('cereus_datasync_ajax.php', {
                action:        'update_rule_template',
                rule_id:       tid,
                profile_id:    cdsProfileId,
                name:          $('.cds-tpl-name[data-id="' + tid + '"]').val(),
                tree_id:       $('.cds-tpl-tree[data-id="' + tid + '"]').val(),
                leaf_type:     $('.cds-tpl-leaf[data-id="' + tid + '"]').val(),
                host_grouping: $('.cds-tpl-grp[data-id="' + tid + '"]').val(),
                enabled:       $('.cds-tpl-enabled[data-id="' + tid + '"]').is(':checked') ? 'on' : '',
                __csrf_magic:  csrfMagicToken
            }, function() { cdsShowSaved(); }, 'json');
        }

        $(document).on('click', '.cds-tpl-del', function() {
            if (!confirm('<?php print __('Delete this rule template and all its conditions?', 'cereus_datasync'); ?>')) return;
            var tid = $(this).data('id');
            $.post('cereus_datasync_ajax.php', {
                action:       'delete_tree_rule',
                rule_id:      tid,
                profile_id:   cdsProfileId,
                __csrf_magic: csrfMagicToken
            }, function() {
                loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=tree');
            }, 'json');
        });

        $('#cds-add-tpl').on('click', function() {
            $.post('cereus_datasync_ajax.php', {
                action:       'add_tree_rule',
                profile_id:   cdsProfileId,
                __csrf_magic: csrfMagicToken
            }, function(data) {
                if (data.id) {
                    loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=tree');
                }
            }, 'json');
        });

        $(document).on('click', '.cds-add-cond', function() {
            var tid   = $(this).data('id');
            var tbody = $('#cds-cond-tbody-' + tid);
            var seq   = tbody.find('tr[data-cid]').length + 1;
            $.post('cereus_datasync_ajax.php', {
                action:       'add_rule_condition',
                rule_id:      tid,
                profile_id:   cdsProfileId,
                sequence:     seq,
                __csrf_magic: csrfMagicToken
            }, function(data) {
                if (data.id) {
                    $('#cds-cond-empty-' + tid).remove();
                    tbody.append(buildCondRow(tid, {
                        id: data.id, field: 'h.location', operator: 1,
                        pattern: '{site}', connector: 'AND', open_paren: 0, close_paren: 0
                    }));
                    cdsRefreshConnectors(tid);
                }
            }, 'json');
        });

        $(document).on('click', '.cds-del-cond', function() {
            var cid = $(this).data('cid');
            var tid = $(this).data('tpl');
            $.post('cereus_datasync_ajax.php', {
                action:       'delete_rule_condition',
                condition_id: cid,
                rule_id:      tid,
                profile_id:   cdsProfileId,
                __csrf_magic: csrfMagicToken
            }, function() {
                $('tr[data-cid="' + cid + '"]').remove();
                cdsRefreshConnectors(tid);
            }, 'json');
        });

        $(document).on('blur change', '.cds-cond-field, .cds-cond-op, .cds-cond-pat, .cds-cond-conn, .cds-cond-open, .cds-cond-close', function() {
            saveCond($(this).closest('tr'));
        });

        function saveCond(row) {
            var cid = row.data('cid');
            var rid = row.data('rid');
            if (!cid || !rid) return;
            var tbody = row.closest('tbody');
            var seq   = tbody.find('tr[data-cid]').index(row) + 1;
            cdsShowSaving();
            $.post('cereus_datasync_ajax.php', {
                action:       'update_rule_condition',
                condition_id: cid,
                rule_id:      rid,
                profile_id:   cdsProfileId,
                sequence:     seq,
                connector:    row.find('.cds-cond-conn').val(),
                open_paren:   row.find('.cds-cond-open').val(),
                close_paren:  row.find('.cds-cond-close').val(),
                field:        row.find('.cds-cond-field').val(),
                operator:     row.find('.cds-cond-op').val(),
                pattern:      row.find('.cds-cond-pat').val(),
                __csrf_magic: csrfMagicToken
            }, function() { cdsShowSaved(); }, 'json');
        }

        // Grey out the connector on each template's first condition row on initial load.
        $('.cds-cond-table').each(function() {
            var tid = $(this).attr('id').replace('cds-cond-', '');
            cdsRefreshConnectors(tid);
        });
    });
    </script>
    <?php
}

// ─── Tab: Aggregate Graph Rules ───────────────────────────────────────────────

function cereus_datasync_agrules_tab(int $profileId, array $profile): void {
    $rules      = cereus_datasync_get_agg_rules($profileId);
    $trees      = cereus_datasync_get_trees_array();
    $gTpls      = cereus_datasync_get_graph_templates_array();
    $aTpls      = cereus_datasync_get_aggregate_templates_array();
    $matchFields = [
        ''            => __('— All devices —', 'cereus_datasync'),
        'description' => __('Device Description', 'cereus_datasync'),
        'hostname'    => __('Hostname / IP', 'cereus_datasync'),
        'location'    => __('Location', 'cereus_datasync'),
    ];

    cereus_datasync_tab_bar($profileId, 'aggregate');

    html_start_box(
        __('Aggregate Graph Rules — %s', html_escape($profile['name']), 'cereus_datasync'),
        '100%', '', '3', 'center', ''
    );
    print '<tr><td style="padding:8px 15px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:12px;color:#0369a1;">';
    print __('Each rule creates (or rebuilds) one aggregate graph from all graphs matching the selected Graph Template, optional device field filter, and optional graph title filter. <strong>All member graphs must share the same Graph Template.</strong> The aggregate is rebuilt on every sync run.', 'cereus_datasync');
    print '</td></tr>';
    print '<tr class="even"><td style="padding:6px 15px;">';
    print '<a href="cereus_datasync_edit.php?action=edit&id=' . $profileId . '" class="cds-link">&laquo; ' . __('Back to Profile', 'cereus_datasync') . '</a>';
    print '</td></tr>';
    html_end_box();

    if (cacti_sizeof($rules)) {
        foreach ($rules as $rule) {
            cereus_datasync_render_agg_rule_card($rule, $trees, $gTpls, $aTpls, $matchFields);
        }
    } else {
        html_start_box('', '100%', '', '3', 'center', '');
        print '<tr><td style="padding:20px;text-align:center;color:#999;font-style:italic;">'
            . __('No aggregate graph rules yet. Add one to get started.', 'cereus_datasync')
            . '</td></tr>';
        html_end_box();
    }

    print '<div style="margin:8px 0;">';
    print '<button type="button" class="ui-button" id="cds-add-agg">+ ' . __('Add Aggregate Rule', 'cereus_datasync') . '</button>';
    print '</div>';

    $treesJson   = json_encode($trees);
    $gTplsJson   = json_encode($gTpls);
    $aTplsJson   = json_encode($aTpls);
    $matchJson   = json_encode($matchFields);
    $confirmDel  = json_encode(__('Delete this aggregate rule? The aggregate graph in Cacti is NOT removed — only the rule.', 'cereus_datasync'));
    ?>
    <div id="cds-save-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#15803d;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        &#10003; Saved
    </div>
    <div id="cds-saving-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#2563eb;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        Saving&hellip;
    </div>

    <script>
    var cdsProfileId  = <?php print $profileId; ?>;
    var cdsTrees      = <?php print $treesJson; ?>;
    var cdsGTpls      = <?php print $gTplsJson; ?>;
    var cdsATpls      = <?php print $aTplsJson; ?>;
    var cdsMatchFlds  = <?php print $matchJson; ?>;
    var cdsRootLbl    = <?php print json_encode(__('(Tree Root)', 'cereus_datasync')); ?>;
    var cdsSaveTimer  = null;
    var cdsNodeXHR    = {}; // tracks in-flight loadNodes requests per rule id

    function cdsShowSaved() {
        $('#cds-saving-toast').hide();
        $('#cds-save-toast').fadeIn(150);
        clearTimeout(cdsSaveTimer);
        cdsSaveTimer = setTimeout(function() { $('#cds-save-toast').fadeOut(300); }, 1800);
    }
    function cdsShowSaving() {
        $('#cds-save-toast').hide();
        $('#cds-saving-toast').fadeIn(150);
    }

    function buildSel(obj, sel) {
        return Object.entries(obj).map(function(e) {
            return '<option value="' + e[0] + '"'
                + (String(e[0]) === String(sel) ? ' selected' : '')
                + '>' + $('<div>').text(e[1]).html() + '</option>';
        }).join('');
    }

    function saveAggRule(rid) {
        cdsShowSaving();
        $.post('cereus_datasync_ajax.php', {
            action:                'update_agg_rule',
            rule_id:               rid,
            profile_id:            cdsProfileId,
            name:                  $('#cds-agg-name-'    + rid).val(),
            graph_template_id:     $('#cds-agg-gtpl-'    + rid).val(),
            aggregate_template_id: $('#cds-agg-atpl-'    + rid).val(),
            tree_id:               $('#cds-agg-tree-'    + rid).val(),
            tree_item_id:          $('#cds-agg-node-'    + rid).val(),
            placement_mode:        $('#cds-agg-pmode-'   + rid).val(),
            site_name:             $('#cds-agg-site-'    + rid).val(),
            device_match_field:    $('#cds-agg-mfield-'  + rid).val(),
            device_match_pattern:  $('#cds-agg-mpat-'    + rid).val(),
            condition_logic:       $('#cds-agg-clogic-'  + rid).val(),
            device_match_field2:   $('#cds-agg-mfield2-' + rid).val(),
            device_match_pattern2: $('#cds-agg-mpat2-'   + rid).val(),
            graph_title_pattern:   $('#cds-agg-tpat-'    + rid).val(),
            enabled:               $('#cds-agg-enabled-' + rid).is(':checked') ? 'on' : '',
            __csrf_magic:          csrfMagicToken
        }, function() { cdsShowSaved(); }, 'json');
    }

    // Abort any in-flight request for this rule before starting a new one so
    // rapid tree switching never leaves stale nodes from a slower earlier request.
    // Cacti's themeReady() wraps every <select> with jQuery UI selectmenu(), so
    // after it runs we must go through the widget API — .prop('disabled') and
    // .html() on the underlying <select> are invisible to the widget.
    function selHasWidget(sel) {
        return !!sel.data('ui-selectmenu');
    }
    function selDisable(sel) {
        selHasWidget(sel) ? sel.selectmenu('disable') : sel.prop('disabled', true);
    }
    function selEnable(sel) {
        selHasWidget(sel) ? sel.selectmenu('enable') : sel.prop('disabled', false);
    }
    function selSetOptions(sel, html, selectedVal) {
        sel.html(html);
        if (selHasWidget(sel)) {
            // val() + refresh pushes the new option list into the widget DOM
            sel.val(selectedVal).selectmenu('refresh');
        } else {
            sel.val(selectedVal);
        }
    }

    function loadNodes(rid, treeId, selectedNodeId) {
        if (cdsNodeXHR[rid]) { cdsNodeXHR[rid].abort(); }
        var sel = $('#cds-agg-node-' + rid);
        selDisable(sel);
        cdsNodeXHR[rid] = $.ajax({
            url:      'cereus_datasync_ajax.php',
            type:     'GET',
            cache:    false,
            data:     { action: 'get_tree_nodes', tree_id: treeId },
            dataType: 'json',
            success: function(data) {
                delete cdsNodeXHR[rid];
                var html = buildSel(data.nodes || {'0': cdsRootLbl}, selectedNodeId || 0);
                selSetOptions(sel, html, selectedNodeId || 0);
                selEnable(sel);
            },
            error: function(xhr, status) {
                if (status !== 'abort') selEnable(sel);
            }
        });
    }

    // Bind the tree dropdown for one rule directly by ID (namespaced event).
    // Direct binding avoids stacking issues when the script re-runs on AJAX
    // tab re-navigation; .off('change.aggTree') removes the prior handler first.
    function bindAggTree(rid) {
        $('#cds-agg-tree-' + rid).off('change.aggTree').on('change.aggTree', function() {
            if ($('#cds-agg-pmode-' + rid).val() !== '1') {
                loadNodes(rid, this.value, 0);
            }
            saveAggRule(rid);
        });
    }

    $(function() {
        $(document).on('blur change', '.cds-agg-field', function() {
            saveAggRule($(this).data('id'));
        });

        $(document).on('change', '.cds-agg-pmode', function() {
            var rid    = $(this).data('id');
            var isSite = $(this).val() === '1';
            $('#cds-agg-node-wrap-' + rid).toggle(!isSite);
            $('#cds-agg-site-wrap-' + rid).toggle(isSite);
            if (!isSite) loadNodes(rid, $('#cds-agg-tree-' + rid).val(), 0);
            saveAggRule(rid);
        });

        $(document).on('change', '.cds-agg-mfield', function() {
            var rid   = $(this).data('id');
            var hasF1 = $(this).val() !== '';
            $('#cds-agg-mpat-wrap-'  + rid).toggle(hasF1);
            $('#cds-agg-cond2-wrap-' + rid).toggle(hasF1);
            saveAggRule(rid);
        });

        $(document).on('change', '.cds-agg-mfield2', function() {
            var rid = $(this).data('id');
            $('#cds-agg-mpat2-wrap-' + rid).toggle($(this).val() !== '');
            saveAggRule(rid);
        });

        $(document).on('click', '.cds-agg-del', function() {
            if (!confirm(<?php print $confirmDel; ?>)) return;
            var rid = $(this).data('id');
            $.post('cereus_datasync_ajax.php', {
                action:       'delete_agg_rule',
                rule_id:      rid,
                profile_id:   cdsProfileId,
                __csrf_magic: csrfMagicToken
            }, function() {
                loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=aggregate');
            }, 'json');
        });

        $('#cds-add-agg').on('click', function() {
            $.post('cereus_datasync_ajax.php', {
                action:       'add_agg_rule',
                profile_id:   cdsProfileId,
                __csrf_magic: csrfMagicToken
            }, function(data) {
                if (data.id) {
                    loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=aggregate');
                }
            }, 'json');
        });

        // Bind tree dropdowns and load nodes for fixed-mode rules
        <?php if (cacti_sizeof($rules)): foreach ($rules as $rule): ?>
        bindAggTree(<?php print (int)$rule['id']; ?>);
        <?php if ((int)($rule['placement_mode'] ?? 0) === 0): ?>
        loadNodes(<?php print (int)$rule['id']; ?>, <?php print (int)$rule['tree_id']; ?>, <?php print (int)$rule['tree_item_id']; ?>);
        <?php endif; ?>
        <?php endforeach; endif; ?>
    });
    </script>
    <?php
}

function cereus_datasync_render_agg_rule_card(array $rule, array $trees, array $gTpls, array $aTpls, array $matchFields): void {
    $rid       = (int)$rule['id'];
    $pmode     = (int)($rule['placement_mode'] ?? 0);
    $condLogic = strtoupper($rule['condition_logic'] ?? 'AND');
    $devVis    = !empty($rule['device_match_field'])  ? '' : 'display:none;';
    $devVis2   = !empty($rule['device_match_field2']) ? '' : 'display:none;';
    $cond2Vis  = !empty($rule['device_match_field'])  ? '' : 'display:none;';
    $nodeVis   = ($pmode === 1) ? 'display:none;' : '';
    $siteVis   = ($pmode === 0) ? 'display:none;' : '';

    print '<div id="cds-agg-card-' . $rid . '">';
    html_start_box('', '100%', '', '3', 'center', '');

    // ── Header — name + placement + tree + node/site + enabled + delete ─────
    print '<tr class="tableHeader"><td colspan="2" style="padding:8px 12px;">';
    print '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';

    print '<input type="text" id="cds-agg-name-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['name']) . '"'
        . ' placeholder="' . __('Aggregate graph title…', 'cereus_datasync') . '"'
        . ' style="font-weight:600;flex:1;min-width:200px;">';
    print '<span style="color:#ccc;font-size:12px;">→</span>';

    // Placement mode
    print '<select id="cds-agg-pmode-' . $rid . '" class="cds-agg-pmode ui-state-default ui-corner-all" data-id="' . $rid . '">';
    print '<option value="0"' . ($pmode === 0 ? ' selected' : '') . '>' . __('Fixed Node', 'cereus_datasync') . '</option>';
    print '<option value="1"' . ($pmode === 1 ? ' selected' : '') . '>' . __('Site', 'cereus_datasync') . '</option>';
    print '</select>';

    // Target Tree (always shown — used by both modes)
    print '<select id="cds-agg-tree-' . $rid . '" class="cds-agg-tree ui-state-default ui-corner-all" data-id="' . $rid . '">';
    foreach ($trees as $treeId => $treeName) {
        print '<option value="' . $treeId . '"' . ($treeId == $rule['tree_id'] ? ' selected' : '') . '>' . html_escape($treeName) . '</option>';
    }
    print '</select>';

    // Tree node (fixed mode only)
    print '<span id="cds-agg-node-wrap-' . $rid . '" style="' . $nodeVis . '">';
    print '<select id="cds-agg-node-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all" data-id="' . $rid . '">';
    print '<option value="0">' . __('(Tree Root)', 'cereus_datasync') . '</option>';
    print '</select>';
    print '</span>';

    // Site name (site mode only)
    print '<span id="cds-agg-site-wrap-' . $rid . '" style="' . $siteVis . '">';
    print '<input type="text" id="cds-agg-site-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['site_name'] ?? '') . '"'
        . ' placeholder="' . __('Site name…', 'cereus_datasync') . '"'
        . ' style="min-width:160px;">';
    print '</span>';

    print '<label style="font-size:12px;white-space:nowrap;">'
        . '<input type="checkbox" id="cds-agg-enabled-' . $rid . '" class="cds-agg-field" data-id="' . $rid . '"'
        . ($rule['enabled'] === 'on' ? ' checked' : '') . '> ' . __('Enabled', 'cereus_datasync') . '</label>';
    print '<button type="button" class="ui-button cds-agg-del" data-id="' . $rid . '"'
        . ' style="margin-left:auto;min-width:0;padding:2px 10px;color:#dc2626;border-color:#fca5a5;background:#fff;">&#128465; '
        . __('Delete', 'cereus_datasync') . '</button>';
    print '</div>';
    print '</td></tr>';

    // ── Body ─────────────────────────────────────────────────────────────────
    print '<tr class="even"><td colspan="2" style="padding:10px 15px 14px;">';
    print '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';

    // Graph Template
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Graph Template (all member graphs must use this)', 'cereus_datasync') . '</label>';
    print '<select id="cds-agg-gtpl-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($gTpls as $tid => $tname) {
        print '<option value="' . $tid . '"' . ($tid == $rule['graph_template_id'] ? ' selected' : '') . '>' . html_escape($tname) . '</option>';
    }
    print '</select></div>';

    // Aggregate Template
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Aggregate Template (optional)', 'cereus_datasync') . '</label>';
    print '<select id="cds-agg-atpl-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($aTpls as $atid => $atname) {
        print '<option value="' . $atid . '"' . ($atid == $rule['aggregate_template_id'] ? ' selected' : '') . '>' . html_escape($atname) . '</option>';
    }
    print '</select></div>';

    // Device Filter Field (condition 1)
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Device Filter Field', 'cereus_datasync') . '</label>';
    print '<select id="cds-agg-mfield-' . $rid . '" class="cds-agg-mfield ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($matchFields as $fv => $fl) {
        print '<option value="' . html_escape($fv) . '"' . ($fv === $rule['device_match_field'] ? ' selected' : '') . '>' . html_escape($fl) . '</option>';
    }
    print '</select></div>';

    // Device Filter Pattern (condition 1)
    print '<div id="cds-agg-mpat-wrap-' . $rid . '" style="' . $devVis . '">';
    print '<label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Device Filter Pattern (substring)', 'cereus_datasync') . '</label>';
    print '<input type="text" id="cds-agg-mpat-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['device_match_pattern']) . '"'
        . ' style="width:100%;font-family:monospace;">';
    print '</div>';

    // ── Second condition (AND/OR) — visible when first condition field is set
    print '<div id="cds-agg-cond2-wrap-' . $rid . '" style="grid-column:1/-1;' . $cond2Vis . '">';

    // AND / OR divider
    print '<div style="display:flex;align-items:center;gap:8px;margin:4px 0 8px;">';
    print '<span style="flex:1;border-top:1px solid #e2e8f0;"></span>';
    print '<select id="cds-agg-clogic-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" style="font-size:11px;font-weight:700;padding:1px 8px;">';
    print '<option value="AND"' . ($condLogic === 'AND' ? ' selected' : '') . '>AND</option>';
    print '<option value="OR"'  . ($condLogic === 'OR'  ? ' selected' : '') . '>OR</option>';
    print '</select>';
    print '<span style="flex:1;border-top:1px solid #e2e8f0;"></span>';
    print '</div>';

    // Second condition fields
    print '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Second Condition Field', 'cereus_datasync') . '</label>';
    print '<select id="cds-agg-mfield2-' . $rid . '" class="cds-agg-mfield2 ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($matchFields as $fv => $fl) {
        print '<option value="' . html_escape($fv) . '"' . ($fv === ($rule['device_match_field2'] ?? '') ? ' selected' : '') . '>' . html_escape($fl) . '</option>';
    }
    print '</select></div>';

    print '<div id="cds-agg-mpat2-wrap-' . $rid . '" style="' . $devVis2 . '">';
    print '<label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Second Condition Pattern', 'cereus_datasync') . '</label>';
    print '<input type="text" id="cds-agg-mpat2-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['device_match_pattern2'] ?? '') . '"'
        . ' style="width:100%;font-family:monospace;">';
    print '</div>';
    print '</div>'; // second condition inner grid

    print '</div>'; // cond2-wrap

    // Graph Title Filter — full width
    print '<div style="grid-column:1/-1;">';
    print '<label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Graph Title Filter (substring, optional — leave blank to include all titles)', 'cereus_datasync') . '</label>';
    print '<input type="text" id="cds-agg-tpat-' . $rid . '" class="cds-agg-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['graph_title_pattern'] ?? '') . '"'
        . ' placeholder="' . __('e.g. WAN or Traffic In/Out', 'cereus_datasync') . '"'
        . ' style="width:100%;font-family:monospace;">';
    print '</div>';

    // Status line
    if ((int)$rule['result_graph_id'] > 0) {
        print '<div style="grid-column:1/-1;font-size:11px;color:#64748b;padding-top:2px;">'
            . __('Aggregate graph ID:', 'cereus_datasync') . ' <strong>' . (int)$rule['result_graph_id'] . '</strong></div>';
    }

    print '</div>'; // body grid
    print '</td></tr>';

    html_end_box();
    print '</div>'; // #cds-agg-card-RID
}

// ─── Tab: OID Graph Rules ─────────────────────────────────────────────────────

function cereus_datasync_oid_rules_tab(int $profileId, array $profile): void {
    $rules      = cereus_datasync_get_oid_rules($profileId);
    $trees      = cereus_datasync_get_trees_array();
    $matchFields = [
        ''            => __('— All devices —', 'cereus_datasync'),
        'description' => __('Device Description', 'cereus_datasync'),
        'hostname'    => __('Hostname / IP', 'cereus_datasync'),
        'location'    => __('Location', 'cereus_datasync'),
    ];

    cereus_datasync_tab_bar($profileId, 'oid');

    html_start_box(
        __('OID Graph Rules — %s', html_escape($profile['name']), 'cereus_datasync'),
        '100%', '', '3', 'center', ''
    );
    print '<tr><td style="padding:8px 15px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:12px;color:#0369a1;">';
    print __('Each rule creates one graph per matching device using the <strong>SNMP - Generic OID Template</strong> with the specified OID. '
        . 'Graph creation is idempotent — if a graph for that device and OID already exists, it is skipped. '
        . 'Graphs are (re)created on every sync run for newly added devices. '
        . 'The <strong>Graph Title</strong> supports Cacti placeholders such as <code>|host_description|</code>.', 'cereus_datasync');
    print '</td></tr>';
    print '<tr class="even"><td style="padding:6px 15px;">';
    print '<a href="cereus_datasync_edit.php?action=edit&id=' . $profileId . '" class="cds-link">&laquo; ' . __('Back to Profile', 'cereus_datasync') . '</a>';
    print '</td></tr>';
    html_end_box();

    if (cacti_sizeof($rules)) {
        foreach ($rules as $rule) {
            cereus_datasync_render_oid_rule_card($rule, $trees, $matchFields);
        }
    } else {
        html_start_box('', '100%', '', '3', 'center', '');
        print '<tr><td style="padding:20px;text-align:center;color:#999;font-style:italic;">'
            . __('No OID graph rules yet. Add one to get started.', 'cereus_datasync')
            . '</td></tr>';
        html_end_box();
    }

    print '<div style="margin:8px 0;">';
    print '<button type="button" class="ui-button" id="cds-add-oid">+ ' . __('Add OID Graph Rule', 'cereus_datasync') . '</button>';
    print '</div>';

    $treesJson   = json_encode($trees);
    $matchJson   = json_encode($matchFields);
    $confirmDel  = json_encode(__('Delete this OID graph rule? Graphs already created in Cacti are NOT removed — only the rule.', 'cereus_datasync'));
    $lblRoot     = json_encode(__('(Tree Root)', 'cereus_datasync'));
    ?>
    <div id="cds-save-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#15803d;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        &#10003; Saved
    </div>
    <div id="cds-saving-toast" style="display:none;position:fixed;bottom:20px;right:20px;z-index:9999;
         background:#2563eb;color:#fff;padding:8px 18px;border-radius:6px;font-size:13px;
         font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;">
        Saving&hellip;
    </div>

    <script>
    var cdsProfileId  = <?php print $profileId; ?>;
    var cdsTrees      = <?php print $treesJson; ?>;
    var cdsMatchFlds  = <?php print $matchJson; ?>;
    var cdsRootLbl    = <?php print $lblRoot; ?>;
    var cdsSaveTimer  = null;
    var cdsNodeXHR    = {};

    function cdsShowSaved() {
        $('#cds-saving-toast').hide();
        $('#cds-save-toast').fadeIn(150);
        clearTimeout(cdsSaveTimer);
        cdsSaveTimer = setTimeout(function() { $('#cds-save-toast').fadeOut(300); }, 1800);
    }
    function cdsShowSaving() {
        $('#cds-save-toast').hide();
        $('#cds-saving-toast').fadeIn(150);
    }

    function buildSel(obj, sel) {
        return Object.entries(obj).map(function(e) {
            return '<option value="' + e[0] + '"'
                + (String(e[0]) === String(sel) ? ' selected' : '')
                + '>' + $('<div>').text(e[1]).html() + '</option>';
        }).join('');
    }

    function saveOidRule(rid) {
        cdsShowSaving();
        $.post('cereus_datasync_ajax.php', {
            action:               'update_oid_rule',
            rule_id:              rid,
            profile_id:           cdsProfileId,
            name:                 $('#cds-oid-name-'   + rid).val(),
            oid:                  $('#cds-oid-oid-'    + rid).val(),
            tree_id:              $('#cds-oid-tree-'   + rid).val(),
            tree_item_id:         $('#cds-oid-node-'   + rid).val(),
            device_match_field:   $('#cds-oid-mfield-' + rid).val(),
            device_match_pattern: $('#cds-oid-mpat-'   + rid).val(),
            enabled:              $('#cds-oid-enabled-'+ rid).is(':checked') ? 'on' : '',
            __csrf_magic:         csrfMagicToken
        }, function() { cdsShowSaved(); }, 'json');
    }

    function selHasWidget(sel) { return !!sel.data('ui-selectmenu'); }
    function selDisable(sel) { selHasWidget(sel) ? sel.selectmenu('disable') : sel.prop('disabled', true); }
    function selEnable(sel) { selHasWidget(sel) ? sel.selectmenu('enable') : sel.prop('disabled', false); }
    function selSetOptions(sel, html, selectedVal) {
        sel.html(html);
        if (selHasWidget(sel)) { sel.val(selectedVal).selectmenu('refresh'); } else { sel.val(selectedVal); }
    }

    function loadOidNodes(rid, treeId, selectedNodeId) {
        if (cdsNodeXHR[rid]) { cdsNodeXHR[rid].abort(); }
        var sel = $('#cds-oid-node-' + rid);
        selDisable(sel);
        cdsNodeXHR[rid] = $.ajax({
            url: 'cereus_datasync_ajax.php', type: 'GET', cache: false,
            data: { action: 'get_tree_nodes', tree_id: treeId }, dataType: 'json',
            success: function(data) {
                delete cdsNodeXHR[rid];
                selSetOptions(sel, buildSel(data.nodes || {'0': cdsRootLbl}, selectedNodeId || 0), selectedNodeId || 0);
                selEnable(sel);
            },
            error: function(xhr, status) { if (status !== 'abort') selEnable(sel); }
        });
    }

    function bindOidTree(rid) {
        $('#cds-oid-tree-' + rid).off('change.oidTree').on('change.oidTree', function() {
            loadOidNodes(rid, this.value, 0);
            saveOidRule(rid);
        });
    }

    $(function() {
        $(document).on('blur change', '.cds-oid-field', function() {
            saveOidRule($(this).data('id'));
        });

        $(document).on('change', '.cds-oid-mfield', function() {
            var rid = $(this).data('id');
            $('#cds-oid-mpat-wrap-' + rid).toggle($(this).val() !== '');
            saveOidRule(rid);
        });

        $(document).on('click', '.cds-oid-del', function() {
            if (!confirm(<?php print $confirmDel; ?>)) return;
            var rid = $(this).data('id');
            $.post('cereus_datasync_ajax.php', {
                action: 'delete_oid_rule', rule_id: rid,
                profile_id: cdsProfileId, __csrf_magic: csrfMagicToken
            }, function() {
                loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=oid');
            }, 'json');
        });

        $('#cds-add-oid').on('click', function() {
            $.post('cereus_datasync_ajax.php', {
                action: 'add_oid_rule', profile_id: cdsProfileId, __csrf_magic: csrfMagicToken
            }, function(data) {
                if (data.id) {
                    loadPageNoHeader('cereus_datasync_rules.php?header=false&profile_id=' + cdsProfileId + '&tab=oid');
                }
            }, 'json');
        });

        // Bind tree dropdowns and load nodes for all existing rules
        <?php if (cacti_sizeof($rules)): foreach ($rules as $rule): ?>
        bindOidTree(<?php print (int)$rule['id']; ?>);
        loadOidNodes(<?php print (int)$rule['id']; ?>, <?php print (int)$rule['tree_id']; ?>, <?php print (int)$rule['tree_item_id']; ?>);
        <?php endforeach; endif; ?>
    });
    </script>
    <?php
}

function cereus_datasync_render_oid_rule_card(array $rule, array $trees, array $matchFields): void {
    $rid    = (int)$rule['id'];
    $devVis = !empty($rule['device_match_field']) ? '' : 'display:none;';

    print '<div id="cds-oid-card-' . $rid . '">';
    html_start_box('', '100%', '', '3', 'center', '');

    // Green header row
    print '<tr class="tableHeader"><td colspan="2" style="padding:8px 12px;">';
    print '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    print '<input type="text" id="cds-oid-name-' . $rid . '" class="cds-oid-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['name']) . '"'
        . ' placeholder="' . __('Graph title… (|host_description| supported)', 'cereus_datasync') . '"'
        . ' style="font-weight:600;flex:1;min-width:200px;">';
    print '<label style="font-size:12px;white-space:nowrap;">'
        . '<input type="checkbox" id="cds-oid-enabled-' . $rid . '" class="cds-oid-field" data-id="' . $rid . '"'
        . ($rule['enabled'] === 'on' ? ' checked' : '') . '> ' . __('Enabled', 'cereus_datasync') . '</label>';
    print '<button type="button" class="ui-button cds-oid-del" data-id="' . $rid . '"'
        . ' style="margin-left:auto;min-width:0;padding:2px 10px;color:#dc2626;border-color:#fca5a5;background:#fff;">&#128465; '
        . __('Delete', 'cereus_datasync') . '</button>';
    print '</div>';
    print '</td></tr>';

    // Body
    print '<tr class="even"><td colspan="2" style="padding:10px 15px 14px;">';
    print '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';

    // OID — full width
    print '<div style="grid-column:1/-1;">';
    print '<label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('OID (e.g. .1.3.6.1.2.1.1.3.0)', 'cereus_datasync') . '</label>';
    print '<input type="text" id="cds-oid-oid-' . $rid . '" class="cds-oid-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['oid']) . '"'
        . ' placeholder=".1.3.6.1.2.1.1.3.0"'
        . ' style="width:100%;font-family:monospace;">';
    print '</div>';

    // Device Filter Field
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Device Filter Field', 'cereus_datasync') . '</label>';
    print '<select id="cds-oid-mfield-' . $rid . '" class="cds-oid-mfield ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($matchFields as $fv => $fl) {
        print '<option value="' . html_escape($fv) . '"' . ($fv === $rule['device_match_field'] ? ' selected' : '') . '>' . html_escape($fl) . '</option>';
    }
    print '</select></div>';

    // Device Filter Pattern
    print '<div id="cds-oid-mpat-wrap-' . $rid . '" style="' . $devVis . '">';
    print '<label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Device Filter Pattern (substring)', 'cereus_datasync') . '</label>';
    print '<input type="text" id="cds-oid-mpat-' . $rid . '" class="cds-oid-field ui-state-default ui-corner-all"'
        . ' data-id="' . $rid . '" value="' . html_escape($rule['device_match_pattern']) . '"'
        . ' style="width:100%;font-family:monospace;">';
    print '</div>';

    // Target Tree
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Target Tree (optional)', 'cereus_datasync') . '</label>';
    print '<select id="cds-oid-tree-' . $rid . '" class="cds-oid-tree ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    foreach ($trees as $treeId => $treeName) {
        print '<option value="' . $treeId . '"' . ($treeId == $rule['tree_id'] ? ' selected' : '') . '>' . html_escape($treeName) . '</option>';
    }
    print '</select></div>';

    // Tree Node — populated by JS
    print '<div><label style="font-size:11px;color:#64748b;display:block;margin-bottom:3px;">'
        . __('Tree Node', 'cereus_datasync') . '</label>';
    print '<select id="cds-oid-node-' . $rid . '" class="cds-oid-field ui-state-default ui-corner-all" data-id="' . $rid . '" style="width:100%;">';
    print '<option value="0">' . __('(Tree Root)', 'cereus_datasync') . '</option>';
    print '</select></div>';

    print '</div>'; // grid
    print '</td></tr>';

    html_end_box();
    print '</div>'; // #cds-oid-card-RID
}

// ─── Condition row helper (tree rules tab) ───────────────────────────────────

function cereus_datasync_render_condition_row(int $tplId, array $cond, array $fields, array $operators): void {
    $connector = (strtoupper($cond['connector'] ?? 'AND') === 'OR') ? 'OR' : 'AND';
    $open      = max(0, min(5, (int)($cond['open_paren']  ?? 0)));
    $close     = max(0, min(5, (int)($cond['close_paren'] ?? 0)));
    print '<tr data-cid="' . (int)$cond['id'] . '" data-rid="' . $tplId . '">';

    // Join connector (AND/OR) — disabled/blank on the first row, which JS keeps in sync on reorder/delete.
    print '<td style="padding:3px 4px;"><select class="cds-cond-conn ui-state-default ui-corner-all" style="width:100%;">';
    foreach (['AND' => 'AND', 'OR' => 'OR'] as $cv => $cl) {
        print '<option value="' . $cv . '"' . ($cv === $connector ? ' selected' : '') . '>' . $cl . '</option>';
    }
    print '</select></td>';

    print '<td style="padding:3px 2px;">' . cereus_datasync_paren_select('cds-cond-open', $open) . '</td>';

    print '<td style="padding:3px 6px;"><select class="cds-cond-field ui-state-default ui-corner-all" style="width:100%;">';
    foreach ($fields as $fv => $fl) {
        print '<option value="' . html_escape($fv) . '"' . ($fv === $cond['field'] ? ' selected' : '') . '>' . html_escape($fl) . '</option>';
    }
    print '</select></td>';

    print '<td style="padding:3px 6px;"><select class="cds-cond-op ui-state-default ui-corner-all" style="width:100%;">';
    foreach ($operators as $ov => $ol) {
        print '<option value="' . $ov . '"' . ($ov == $cond['operator'] ? ' selected' : '') . '>' . html_escape($ol) . '</option>';
    }
    print '</select></td>';

    print '<td style="padding:3px 6px;"><input type="text" class="cds-cond-pat ui-state-default ui-corner-all" value="' . html_escape($cond['pattern']) . '" style="width:100%;font-family:monospace;"></td>';
    print '<td style="padding:3px 2px;">' . cereus_datasync_paren_select('cds-cond-close', $close) . '</td>';
    print '<td style="padding:3px 6px;text-align:center;"><button type="button" class="ui-button cds-del-cond" data-cid="' . (int)$cond['id'] . '" data-tpl="' . $tplId . '" style="min-width:0;padding:2px 6px;">&#128465;</button></td>';
    print '</tr>';
}

// Small 0-3 parenthesis-count dropdown used by the tree-rule condition editor.
function cereus_datasync_paren_select(string $class, int $value): string {
    $html = '<select class="' . $class . ' ui-state-default ui-corner-all" style="width:100%;text-align:center;">';
    for ($i = 0; $i <= 3; $i++) {
        $label = $i === 0 ? '–' : str_repeat($class === 'cds-cond-open' ? '(' : ')', $i);
        $html .= '<option value="' . $i . '"' . ($i === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select>';
}
