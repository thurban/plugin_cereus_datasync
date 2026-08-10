<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Cereus Data Sync — empty-container cleanup on device deletion.
 *
 * When an operator deletes one or more devices from Console → Devices, Cacti's
 * core delete-confirmation screen gains an extra opt-in checkbox (injected
 * client-side, see js/cereus_datasync.js). If ticked, any Site or graph-tree
 * branch that the deleted device(s) leave behind empty is removed in the same
 * action.
 *
 * Timing relies on two core hooks fired within a single host.php request:
 *
 *   device_remove        (fires inside api_device_remove_multi, BEFORE the host
 *                         rows and their graph_tree_items are purged)
 *                        → snapshot the sites + tree branches the devices touch.
 *
 *   device_action_bottom (fires after api_device_remove_multi returns)
 *                        → the containers are now genuinely empty; prune them.
 *
 * The snapshot is carried between the two hooks in a request-scoped global.
 * No Cacti core file is modified.
 */

/** Request var that the injected checkbox sets when the operator opts in. */
function cereus_ds_cleanup_requested(): bool {
    return isset($_POST['cereus_ds_cleanup_empty']) && $_POST['cereus_ds_cleanup_empty'] == '1';
}

function cereus_ds_cleanup_licensed(): bool {
    global $config;
    require_once $config['base_path'] . '/plugins/cereus_datasync/lib/license_check.php';
    return cereus_datasync_license_ok();
}

/**
 * Hook: device_remove — snapshot the containers referenced by the devices about
 * to be deleted, while they still exist. Must return the untouched device id
 * array (Cacti passes it through the hook chain).
 */
function cereus_datasync_device_remove($device_ids) {
    if (!cereus_ds_cleanup_requested() || !cereus_ds_cleanup_licensed()) {
        return $device_ids;
    }

    if (!is_array($device_ids)) {
        $device_ids = array($device_ids);
    }

    // Keep only positive integers — these interpolate straight into IN() lists.
    $ids = array_filter(array_map('intval', $device_ids), function ($v) { return $v > 0; });
    if (!cacti_sizeof($ids)) {
        return $device_ids;
    }

    $in = implode(', ', $ids);

    // Sites the doomed devices belong to.
    $site_ids = array_rekey(
        db_fetch_assoc("SELECT DISTINCT site_id FROM host WHERE id IN ($in) AND site_id > 0"),
        'site_id', 'site_id'
    );

    // Tree branches (header parents) that directly hold the doomed devices.
    // parent = 0 means the device sits at a tree root, so there is no branch to prune.
    $branches = db_fetch_assoc("SELECT DISTINCT graph_tree_id, parent
        FROM graph_tree_items
        WHERE host_id IN ($in) AND parent > 0");

    $GLOBALS['cereus_ds_delete_snapshot'] = array(
        'site_ids' => array_values($site_ids),
        'branches' => cacti_sizeof($branches) ? $branches : array(),
    );

    return $device_ids;
}

/**
 * Hook: device_action_bottom — runs after the delete has completed. Prunes any
 * snapshotted Site / tree branch that is now empty. $data is
 * array($drp_action, $selected_items); it is returned unchanged.
 */
function cereus_datasync_device_action_bottom($data) {
    // Only the delete action (drp_action == 1) leaves containers behind.
    $drp_action = is_array($data) && isset($data[0]) ? $data[0] : '';
    if ($drp_action != '1') {
        return $data;
    }

    if (!cereus_ds_cleanup_requested() || !cereus_ds_cleanup_licensed()) {
        return $data;
    }

    $snapshot = $GLOBALS['cereus_ds_delete_snapshot'] ?? null;
    if (!is_array($snapshot)) {
        return $data;
    }

    $sites_removed  = cereus_ds_prune_empty_sites($snapshot['site_ids'] ?? array());
    $branches_removed = cereus_ds_prune_empty_branches($snapshot['branches'] ?? array());

    if ($sites_removed || $branches_removed) {
        set_config_option('time_last_change_site_device', time());
        set_config_option('time_last_change_tree', time());

        raise_message('cereus_ds_cleanup',
            __('Cereus Data Sync removed %d now-empty Site(s) and %d now-empty Tree branch(es).',
                $sites_removed, $branches_removed, 'cereus_datasync'),
            MESSAGE_LEVEL_INFO);

        cacti_log(sprintf('cereus_datasync: device-delete cleanup removed %d empty site(s), %d empty tree branch(es)',
            $sites_removed, $branches_removed), false, 'SYSTEM');
    }

    unset($GLOBALS['cereus_ds_delete_snapshot']);

    return $data;
}

/**
 * Delete each snapshotted site that no longer holds a live device. Mirrors the
 * cleanup Cacti's own sites.php performs on delete (detach any lingering host
 * references first, defensively).
 *
 * @return int count of sites removed
 */
function cereus_ds_prune_empty_sites(array $site_ids): int {
    $removed = 0;

    foreach ($site_ids as $site_id) {
        $site_id = (int)$site_id;
        if ($site_id <= 0) {
            continue;
        }

        // Live device = a host row not soft-deleted. Hard-deleted (poller 1)
        // hosts are already gone; remote-poller hosts carry deleted='on'.
        $live = db_fetch_cell_prepared("SELECT COUNT(*)
            FROM host
            WHERE site_id = ? AND deleted = ''",
            array($site_id));

        if ($live > 0) {
            continue;
        }

        db_execute_prepared('UPDATE host SET site_id = 0 WHERE deleted = "" AND site_id = ?', array($site_id));
        db_execute_prepared('DELETE FROM sites WHERE id = ?', array($site_id));
        $removed++;
    }

    return $removed;
}

/**
 * Prune empty tree branches, walking upward: a header with no remaining children
 * is deleted, then its own parent is re-evaluated (it may have just become empty).
 *
 * @param array $branches rows of array('graph_tree_id' => .., 'parent' => ..)
 * @return int count of branch items removed
 */
function cereus_ds_prune_empty_branches(array $branches): int {
    $removed = 0;

    foreach ($branches as $branch) {
        $tree_id = (int)($branch['graph_tree_id'] ?? 0);
        $item_id = (int)($branch['parent'] ?? 0);
        cereus_ds_prune_branch_upward($tree_id, $item_id, $removed);
    }

    return $removed;
}

/**
 * Delete tree item $item_id if it is an empty header, then recurse into its
 * parent. Leaves (host / graph items) and non-empty headers are left untouched.
 */
function cereus_ds_prune_branch_upward(int $tree_id, int $item_id, int &$removed): void {
    if ($tree_id <= 0 || $item_id <= 0) {
        return;
    }

    $item = db_fetch_row_prepared('SELECT id, parent, host_id, local_graph_id
        FROM graph_tree_items
        WHERE id = ? AND graph_tree_id = ?',
        array($item_id, $tree_id));

    if (!cacti_sizeof($item)) {
        return;
    }

    // Only prune header nodes — never a leaf that points at a surviving device/graph.
    if ((int)$item['host_id'] !== 0 || (int)$item['local_graph_id'] !== 0) {
        return;
    }

    $children = db_fetch_cell_prepared('SELECT COUNT(*)
        FROM graph_tree_items
        WHERE graph_tree_id = ? AND parent = ?',
        array($tree_id, $item_id));

    if ($children > 0) {
        return; // still holds content
    }

    $parent = (int)$item['parent'];

    db_execute_prepared('DELETE FROM graph_tree_items WHERE id = ?', array($item_id));
    $removed++;

    if ($parent > 0) {
        cereus_ds_prune_branch_upward($tree_id, $parent, $removed);
    }
}
