<?php
// SPDX-License-Identifier: GPL-2.0-or-later

function cereus_datasync_config_arrays() {
    global $menu;

    $menu[__('Cereus Suite', 'cereus_datasync')]['plugins/cereus_datasync/cereus_datasync.php'] =
        __('Data Sync', 'cereus_datasync');
}

function cereus_datasync_draw_navigation($nav) {
    $nav['cereus_datasync.php:'] = array(
        'title' => __('Data Sync', 'cereus_datasync'),
        'mapping' => 'index.php:',
        'url' => 'cereus_datasync.php',
        'level' => '1',
    );
    $nav['cereus_datasync.php:edit'] = array(
        'title' => __('Edit Profile', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync.php',
        'level' => '2',
    );
    $nav['cereus_datasync.php:actions'] = array(
        'title' => __('Actions', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync.php',
        'level' => '2',
    );
    $nav['cereus_datasync_edit.php:'] = array(
        'title' => __('Edit Profile', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_edit.php',
        'level' => '2',
    );
    $nav['cereus_datasync_edit.php:save'] = array(
        'title' => __('Edit Profile', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_edit.php',
        'level' => '2',
    );
    $nav['cereus_datasync_rules.php:'] = array(
        'title' => __('Tree Rules', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_rules.php',
        'level' => '2',
    );
    $nav['cereus_datasync_agrules.php:'] = array(
        'title' => __('Aggregate Rules', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_agrules.php',
        'level' => '2',
    );
    $nav['cereus_datasync_log.php:'] = array(
        'title' => __('Sync Log', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_log.php',
        'level' => '2',
    );
    $nav['cereus_datasync_log.php:view'] = array(
        'title' => __('Run Details', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:,cereus_datasync_log.php:',
        'url' => 'cereus_datasync_log.php',
        'level' => '3',
    );
    $nav['cereus_datasync_ajax.php:'] = array(
        'title' => __('Data Sync', 'cereus_datasync'),
        'mapping' => 'index.php:,cereus_datasync.php:',
        'url' => 'cereus_datasync_ajax.php',
        'level' => '2',
    );

    return $nav;
}
