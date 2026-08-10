<?php
// SPDX-License-Identifier: GPL-2.0-or-later

function plugin_cereus_datasync_install() {
    api_plugin_register_hook('cereus_datasync', 'config_arrays',        'cereus_datasync_config_arrays',   'includes/arrays.php');
    api_plugin_register_hook('cereus_datasync', 'draw_navigation_text', 'cereus_datasync_draw_navigation', 'includes/arrays.php');
    api_plugin_register_hook('cereus_datasync', 'page_head',            'cereus_datasync_page_head',       'setup.php');
    api_plugin_register_hook('cereus_datasync', 'poller_bottom',        'cereus_datasync_poller_bottom',   'setup.php');
    api_plugin_register_hook('cereus_datasync', 'device_remove',        'cereus_datasync_device_remove',        'lib/device_delete_cleanup.php');
    api_plugin_register_hook('cereus_datasync', 'device_action_bottom', 'cereus_datasync_device_action_bottom', 'lib/device_delete_cleanup.php');

    api_plugin_register_realm('cereus_datasync',
        'cereus_datasync.php,cereus_datasync_edit.php,cereus_datasync_rules.php,cereus_datasync_agrules.php,cereus_datasync_log.php,cereus_datasync_ajax.php,cereus_datasync_sample.php',
        'Plugin: Cereus Data Sync', 1
    );

    cereus_datasync_setup_tables();
}

function plugin_cereus_datasync_uninstall() {
    db_execute('DROP TABLE IF EXISTS plugin_cds_run_details');
    db_execute('DROP TABLE IF EXISTS plugin_cds_runs');
    db_execute('DROP TABLE IF EXISTS plugin_cds_rule_conditions');
    db_execute('DROP TABLE IF EXISTS plugin_cds_tree_rules');
    db_execute('DROP TABLE IF EXISTS plugin_cds_agg_conditions');
    db_execute('DROP TABLE IF EXISTS plugin_cds_aggregate_rules');
    db_execute('DROP TABLE IF EXISTS plugin_cds_oid_rules');
    db_execute('DROP TABLE IF EXISTS plugin_cds_function_maps');
    db_execute('DROP TABLE IF EXISTS plugin_cds_column_maps');
    db_execute('DROP TABLE IF EXISTS plugin_cds_profiles');
}

function plugin_cereus_datasync_version() {
    global $config;
    $info = parse_ini_file($config['base_path'] . '/plugins/cereus_datasync/INFO', true);
    return $info['info'];
}

function plugin_cereus_datasync_check_config() {
    return true;
}

function plugin_cereus_datasync_upgrade() {
    cereus_datasync_setup_tables();

    // Register hooks added after the initial install (idempotent).
    api_plugin_register_hook('cereus_datasync', 'device_remove',        'cereus_datasync_device_remove',        'lib/device_delete_cleanup.php', true);
    api_plugin_register_hook('cereus_datasync', 'device_action_bottom', 'cereus_datasync_device_action_bottom', 'lib/device_delete_cleanup.php', true);

    cereus_datasync_backfill_title_caches();

    return true;
}

/**
 * One-off backfill for the stale graph title caches left by releases before 1.5.1.
 *
 * Cacti stores the substituted graph title in graph_templates_graph.title_cache and
 * the data source name in data_template_data.name_cache. Only api_device_save()
 * refreshes them, and the sync wrote host.description directly — so every device the
 * sync tagged for deletion or renamed kept its old title on all of its graphs. The
 * sync now refreshes them inline; this catches up the devices that already drifted.
 *
 * Selection is by evidence rather than by tag: any device whose graph title is built
 * from |host_description| but whose cached title no longer contains the device's
 * current description. That covers tagged and renamed devices alike, and skips
 * installs with nothing to fix. LOCATE() is used instead of LIKE so that a
 * description containing % or _ is matched literally.
 */
function cereus_datasync_backfill_title_caches() {
    global $config;

    if (read_config_option('cereus_datasync_title_cache_backfill') == 1) {
        return;
    }

    if (!function_exists('update_graph_title_cache_from_host')) {
        require_once $config['base_path'] . '/lib/variables.php';
    }

    $hosts = db_fetch_assoc("SELECT DISTINCT gl.host_id
        FROM graph_local AS gl
        INNER JOIN graph_templates_graph AS gtg
        ON gtg.local_graph_id = gl.id
        INNER JOIN host AS h
        ON h.id = gl.host_id
        WHERE gl.host_id > 0
        AND gtg.title LIKE '%|host_description|%'
        AND h.description != ''
        AND LOCATE(h.description, gtg.title_cache) = 0");

    foreach ($hosts as $host) {
        update_data_source_title_cache_from_host((int)$host['host_id']);
        update_graph_title_cache_from_host((int)$host['host_id']);
    }

    set_config_option('cereus_datasync_title_cache_backfill', 1);

    cacti_log('cereus_datasync: refreshed stale graph title caches for ' . cacti_sizeof($hosts) . ' device(s)',
        false, 'CEREUS_DATASYNC');
}

// ─── Page head hook ──────────────────────────────────────────────────────────

function cereus_datasync_page_head() {
    global $config;
    $base = $config['url_path'] . 'plugins/cereus_datasync/';

    // Cache-bust CSS/JS with the plugin version so browsers reload them after an
    // upgrade instead of serving a stale copy.
    $info = parse_ini_file($config['base_path'] . '/plugins/cereus_datasync/INFO', true);
    $ver  = isset($info['info']['version']) ? $info['info']['version'] : '0';

    print '<link rel="stylesheet" type="text/css" href="' . $base . 'css/cereus_datasync.css?v=' . urlencode($ver) . '">' . PHP_EOL;
    print '<script src="' . $base . 'js/cereus_datasync.js?v=' . urlencode($ver) . '"></script>' . PHP_EOL;

    // Signal to the frontend whether the device-delete empty-container cleanup
    // option should be offered (Professional tier and up).
    require_once $config['base_path'] . '/plugins/cereus_datasync/lib/license_check.php';
    print '<script>window.cereusDatasyncDeleteCleanup = ' . (cereus_datasync_license_ok() ? 'true' : 'false') . ';</script>' . PHP_EOL;
}

// ─── Poller hook ─────────────────────────────────────────────────────────────

function cereus_datasync_poller_bottom() {
    global $config;

    if ($config['poller_id'] != 1) return;

    require_once $config['base_path'] . '/plugins/cereus_datasync/lib/license_check.php';
    if (!cereus_datasync_license_ok()) return;

    require_once $config['base_path'] . '/plugins/cereus_datasync/lib/SyncEngine.php';

    $now = time();
    $hour = (int)date('G', $now);
    $wday = (int)date('w', $now);

    $profiles = db_fetch_assoc(
        "SELECT * FROM plugin_cds_profiles WHERE enabled = 'on' AND schedule_type != 'manual' ORDER BY id"
    );

    if (!cacti_sizeof($profiles)) return;

    foreach ($profiles as $profile) {
        $due = false;
        switch ($profile['schedule_type']) {
            case 'every_poller':
                $due = true;
                break;
            case 'hourly':
                // Run once per hour on the 0-minute mark (poller fires every minute, check last run)
                $last = strtotime($profile['last_run_at'] ?? '1970-01-01');
                $due  = ($now - $last) >= 3500;
                break;
            case 'daily':
                $due = ($hour == (int)$profile['schedule_hour']) &&
                       (empty($profile['last_run_at']) || date('Y-m-d', strtotime($profile['last_run_at'])) !== date('Y-m-d', $now));
                break;
            case 'weekly':
                $due = ($wday == (int)$profile['schedule_wday']) &&
                       ($hour == (int)$profile['schedule_hour']) &&
                       (empty($profile['last_run_at']) || date('Y-W', strtotime($profile['last_run_at'])) !== date('Y-W', $now));
                break;
        }

        if ($due) {
            try {
                $engine = new CereusDatasyncEngine();
                $engine->run((int)$profile['id'], false);
            } catch (Exception $e) {
                cacti_log('cereus_datasync: poller error for profile ' . $profile['id'] . ': ' . $e->getMessage(), false, 'SYSTEM');
            }
        }
    }
}

// ─── Table creation ──────────────────────────────────────────────────────────

function cereus_datasync_setup_tables() {
    $charset = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_profiles (
        id                         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        name                       VARCHAR(100)     NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        description                TEXT,
        enabled                    CHAR(2)          NOT NULL DEFAULT 'on',
        excel_path                 VARCHAR(512)     NOT NULL DEFAULT '',
        excel_file_mode            VARCHAR(20)      NOT NULL DEFAULT 'latest_in_dir',
        sheet_name                 VARCHAR(64)      NOT NULL DEFAULT 'Sheet1',
        data_start_row             SMALLINT UNSIGNED NOT NULL DEFAULT 4,
        schedule_type              VARCHAR(20)      NOT NULL DEFAULT 'manual',
        schedule_hour              TINYINT UNSIGNED NOT NULL DEFAULT 2,
        schedule_wday              TINYINT UNSIGNED NOT NULL DEFAULT 1,
        snmp_check_l2              TINYINT(1)       NOT NULL DEFAULT 1,
        snmp_wan_pattern           VARCHAR(64)      NOT NULL DEFAULT '-WAN-',
        snmp_timeout_ms            INT UNSIGNED     NOT NULL DEFAULT 2000,
        snmp_enable_parallel       TINYINT(1)       NOT NULL DEFAULT 0,
        snmp_parallel_workers      TINYINT UNSIGNED NOT NULL DEFAULT 20,
        default_snmp_version       TINYINT UNSIGNED NOT NULL DEFAULT 2,
        default_snmp_community     VARCHAR(100)     NOT NULL DEFAULT 'public',
        default_snmp_port          SMALLINT UNSIGNED NOT NULL DEFAULT 161,
        default_snmp_timeout       SMALLINT UNSIGNED NOT NULL DEFAULT 500,
        default_availability       TINYINT UNSIGNED NOT NULL DEFAULT 2,
        default_ping_method        TINYINT UNSIGNED NOT NULL DEFAULT 2,
        default_poller_id          INT UNSIGNED     NOT NULL DEFAULT 1,
        deletion_tag               VARCHAR(64)      NOT NULL DEFAULT '[TO BE DELETED]',
        deletion_skip_localhost    TINYINT(1)       NOT NULL DEFAULT 1,
        mark_empty_sites           TINYINT(1)       NOT NULL DEFAULT 1,
        mark_empty_tree_items      TINYINT(1)       NOT NULL DEFAULT 1,
        auto_graph_rules           TINYINT(1)       NOT NULL DEFAULT 0,
        auto_create_graphs         TINYINT(1)       NOT NULL DEFAULT 0,
        graph_query_type_id        INT UNSIGNED     NOT NULL DEFAULT 0,
        last_run_at                TIMESTAMP        NULL DEFAULT NULL,
        last_run_status            VARCHAR(20)      NOT NULL DEFAULT '',
        last_run_stats             JSON,
        created                    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modified                   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_enabled (enabled),
        INDEX idx_schedule (schedule_type, enabled)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_column_maps (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        profile_id  INT UNSIGNED NOT NULL,
        field_name  VARCHAR(32)  NOT NULL DEFAULT '',
        col_letter  VARCHAR(4)   NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY uq_profile_field (profile_id, field_name),
        INDEX idx_profile (profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_function_maps (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        profile_id       INT UNSIGNED NOT NULL,
        device_function  VARCHAR(64)  NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        host_template_id INT UNSIGNED NOT NULL DEFAULT 0,
        snmp_check       TINYINT(1)   NOT NULL DEFAULT 0,
        enabled          CHAR(2)      NOT NULL DEFAULT 'on',
        PRIMARY KEY (id),
        INDEX idx_profile (profile_id)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_tree_rules (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        profile_id   INT UNSIGNED NOT NULL,
        rule_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        enabled      CHAR(2)      NOT NULL DEFAULT 'on',
        name         VARCHAR(128) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        tree_id      INT UNSIGNED NOT NULL DEFAULT 0,
        leaf_type    TINYINT UNSIGNED NOT NULL DEFAULT 2,
        host_grouping TINYINT UNSIGNED NOT NULL DEFAULT 1,
        branch_path  VARCHAR(255) NOT NULL DEFAULT '{region}/{country}/{site}' COLLATE utf8mb4_unicode_ci,
        PRIMARY KEY (id),
        INDEX idx_profile_order (profile_id, rule_order)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_rule_conditions (
        id        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        rule_id   INT UNSIGNED      NOT NULL,
        sequence  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        operation TINYINT UNSIGNED  NOT NULL DEFAULT 0,
        field     VARCHAR(64)       NOT NULL DEFAULT 'h.location',
        operator  TINYINT UNSIGNED  NOT NULL DEFAULT 1,
        pattern   VARCHAR(256)      NOT NULL DEFAULT '{site}' COLLATE utf8mb4_unicode_ci,
        PRIMARY KEY (id),
        INDEX idx_rule (rule_id)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_runs (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        profile_id     INT UNSIGNED NOT NULL,
        profile_name   VARCHAR(100) NOT NULL DEFAULT '',
        started_at     DATETIME     NOT NULL,
        completed_at   DATETIME     NULL DEFAULT NULL,
        status         VARCHAR(20)  NOT NULL DEFAULT 'running',
        dry_run        TINYINT(1)   NOT NULL DEFAULT 0,
        excel_file     VARCHAR(512) NOT NULL DEFAULT '',
        excel_total         INT UNSIGNED NOT NULL DEFAULT 0,
        excel_raw_total     INT UNSIGNED NOT NULL DEFAULT 0,
        excel_skipped_load  INT UNSIGNED NOT NULL DEFAULT 0,
        cacti_total         INT UNSIGNED NOT NULL DEFAULT 0,
        added               INT UNSIGNED NOT NULL DEFAULT 0,
        updated             INT UNSIGNED NOT NULL DEFAULT 0,
        marked_deleted      INT UNSIGNED NOT NULL DEFAULT 0,
        skipped             INT UNSIGNED NOT NULL DEFAULT 0,
        failed              INT UNSIGNED NOT NULL DEFAULT 0,
        tree_placed         INT UNSIGNED NOT NULL DEFAULT 0,
        graphs_found        INT UNSIGNED NOT NULL DEFAULT 0,
        graphs_created      INT UNSIGNED NOT NULL DEFAULT 0,
        empty_sites_marked  INT UNSIGNED NOT NULL DEFAULT 0,
        empty_trees_marked  INT UNSIGNED NOT NULL DEFAULT 0,
        sites_unmarked      INT UNSIGNED NOT NULL DEFAULT 0,
        trees_unmarked      INT UNSIGNED NOT NULL DEFAULT 0,
        error_message  TEXT,
        PRIMARY KEY (id),
        INDEX idx_profile (profile_id),
        INDEX idx_started (started_at)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_run_details (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_id        INT UNSIGNED    NOT NULL,
        action        VARCHAR(32)     NOT NULL DEFAULT '',
        device_hostname VARCHAR(255)  NOT NULL DEFAULT '',
        device_ip     VARCHAR(45)     NOT NULL DEFAULT '',
        device_id     INT UNSIGNED    NULL DEFAULT NULL,
        details       VARCHAR(512)    NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        INDEX idx_run (run_id),
        INDEX idx_action (action)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_aggregate_rules (
        id                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        profile_id            INT UNSIGNED      NOT NULL,
        rule_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        enabled               CHAR(2)           NOT NULL DEFAULT 'on',
        name                  VARCHAR(128)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        graph_template_id     INT UNSIGNED      NOT NULL DEFAULT 0,
        aggregate_template_id INT UNSIGNED      NOT NULL DEFAULT 0,
        tree_id               INT UNSIGNED      NOT NULL DEFAULT 0,
        tree_item_id          INT UNSIGNED      NOT NULL DEFAULT 0,
        device_match_field    VARCHAR(64)       NOT NULL DEFAULT '',
        device_match_pattern  VARCHAR(256)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        result_graph_id       INT UNSIGNED      NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        INDEX idx_profile (profile_id)
    ) $charset");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_oid_rules (
        id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        profile_id           INT UNSIGNED      NOT NULL,
        rule_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        enabled              CHAR(2)           NOT NULL DEFAULT 'on',
        name                 VARCHAR(128)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        oid                  VARCHAR(256)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        device_match_field   VARCHAR(64)       NOT NULL DEFAULT '',
        device_match_pattern VARCHAR(256)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        tree_id              INT UNSIGNED      NOT NULL DEFAULT 0,
        tree_item_id         INT UNSIGNED      NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        INDEX idx_profile (profile_id)
    ) $charset");

    // Migration: tree-rule conditions — per-condition boolean grammar (AND/OR + parenthesis grouping).
    // `operation` (legacy 0=none/1=AND) is superseded by `connector` + `open_paren`/`close_paren`,
    // which materialise into a full Cacti automation_match_rule_items token stream at sync time.
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_rule_conditions LIKE 'connector'"))) {
        db_execute("ALTER TABLE plugin_cds_rule_conditions
            ADD COLUMN connector   CHAR(3)          NOT NULL DEFAULT 'AND' AFTER operation,
            ADD COLUMN open_paren  TINYINT UNSIGNED NOT NULL DEFAULT 0      AFTER connector,
            ADD COLUMN close_paren TINYINT UNSIGNED NOT NULL DEFAULT 0      AFTER open_paren");
        // Preserve existing behaviour: prior rows used operation 2=OR, anything else=AND.
        db_execute("UPDATE plugin_cds_rule_conditions SET connector = 'OR'  WHERE operation = 2");
        db_execute("UPDATE plugin_cds_rule_conditions SET connector = 'AND' WHERE operation <> 2");
    }

    // Migration: tree-rule templates — operator-defined branch path. The generated
    // branch was fixed at Region/Country/Site; branch_path lets each template pick
    // its own levels (or a literal collector branch). The default reproduces the
    // former hierarchy so existing templates keep their rules and their names.
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_tree_rules LIKE 'branch_path'"))) {
        db_execute("ALTER TABLE plugin_cds_tree_rules
            ADD COLUMN branch_path VARCHAR(255) NOT NULL DEFAULT '{region}/{country}/{site}'
            COLLATE utf8mb4_unicode_ci AFTER host_grouping");
    }

    // Migration: add graph_title_pattern to aggregate rules table
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'graph_title_pattern'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN graph_title_pattern VARCHAR(256) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci
            AFTER device_match_pattern");
    }

    // Migration: add result_graph_id to aggregate rules table
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'result_graph_id'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN result_graph_id INT UNSIGNED NOT NULL DEFAULT 0");
    }

    // Migration: add placement_mode to aggregate rules table (0=fixed node, 1=site)
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'placement_mode'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN placement_mode TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tree_item_id");
    }

    // Migration: add site_name to aggregate rules table
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'site_name'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN site_name VARCHAR(128) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci AFTER placement_mode");
    }

    // Migration: add condition_logic to aggregate rules table (AND/OR between two device conditions)
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'condition_logic'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN condition_logic CHAR(3) NOT NULL DEFAULT 'AND' AFTER device_match_pattern");
    }

    // Migration: add device_match_field2 to aggregate rules table
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'device_match_field2'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN device_match_field2 VARCHAR(64) NOT NULL DEFAULT '' AFTER condition_logic");
    }

    // Migration: add device_match_pattern2 to aggregate rules table
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_aggregate_rules LIKE 'device_match_pattern2'"))) {
        db_execute("ALTER TABLE plugin_cds_aggregate_rules
            ADD COLUMN device_match_pattern2 VARCHAR(256) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci AFTER device_match_field2");
    }

    // Aggregate rule device-match conditions — unbounded N-condition list with the same
    // AND/OR + parenthesis grammar as tree-rule conditions, superseding the fixed
    // primary/secondary (device_match_field/field2 + condition_logic) columns. Those legacy
    // columns are retained but no longer read; the one-time block below migrates their data.
    $aggCondNew = !cacti_sizeof(db_fetch_assoc("SHOW TABLES LIKE 'plugin_cds_agg_conditions'"));
    db_execute("CREATE TABLE IF NOT EXISTS plugin_cds_agg_conditions (
        id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        agg_rule_id INT UNSIGNED      NOT NULL,
        sequence    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        connector   CHAR(3)           NOT NULL DEFAULT 'AND',
        open_paren  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
        close_paren TINYINT UNSIGNED  NOT NULL DEFAULT 0,
        field       VARCHAR(32)       NOT NULL DEFAULT 'description',
        operator    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
        pattern     VARCHAR(256)      NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
        PRIMARY KEY (id),
        INDEX idx_agg_rule (agg_rule_id)
    ) $charset");

    if ($aggCondNew) {
        // Migrate the legacy primary/secondary conditions into the new list (operator 1 = contains).
        $legacy = db_fetch_assoc("SELECT * FROM plugin_cds_aggregate_rules WHERE device_match_field <> ''");
        if (cacti_sizeof($legacy)) {
            foreach ($legacy as $r) {
                db_execute_prepared(
                    "INSERT INTO plugin_cds_agg_conditions (agg_rule_id, sequence, connector, open_paren, close_paren, field, operator, pattern)
                     VALUES (?, 1, 'AND', 0, 0, ?, 1, ?)",
                    [(int)$r['id'], $r['device_match_field'], $r['device_match_pattern']]
                );
                if (trim($r['device_match_field2'] ?? '') !== '') {
                    $conn = (strtoupper($r['condition_logic'] ?? 'AND') === 'OR') ? 'OR' : 'AND';
                    db_execute_prepared(
                        "INSERT INTO plugin_cds_agg_conditions (agg_rule_id, sequence, connector, open_paren, close_paren, field, operator, pattern)
                         VALUES (?, 2, ?, 0, 0, ?, 1, ?)",
                        [(int)$r['id'], $conn, $r['device_match_field2'], $r['device_match_pattern2'] ?? '']
                    );
                }
            }
        }
    }

    // Migration: add auto-graph-rules columns to existing installs
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_profiles LIKE 'auto_graph_rules'"))) {
        db_execute("ALTER TABLE plugin_cds_profiles
            ADD COLUMN auto_graph_rules    TINYINT(1)   NOT NULL DEFAULT 0 AFTER deletion_skip_localhost,
            ADD COLUMN auto_create_graphs  TINYINT(1)   NOT NULL DEFAULT 0 AFTER auto_graph_rules,
            ADD COLUMN graph_query_type_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER auto_create_graphs");
    }

    // Migration: add graph creation columns to existing installs
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_profiles LIKE 'auto_create_graphs'"))) {
        db_execute("ALTER TABLE plugin_cds_profiles
            ADD COLUMN auto_create_graphs  TINYINT(1)   NOT NULL DEFAULT 0 AFTER auto_graph_rules,
            ADD COLUMN graph_query_type_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER auto_create_graphs");
    }

    // Migration: add empty-container marking toggles to existing installs
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_profiles LIKE 'mark_empty_sites'"))) {
        db_execute("ALTER TABLE plugin_cds_profiles
            ADD COLUMN mark_empty_sites      TINYINT(1) NOT NULL DEFAULT 1 AFTER deletion_skip_localhost,
            ADD COLUMN mark_empty_tree_items TINYINT(1) NOT NULL DEFAULT 1 AFTER mark_empty_sites");
    }

    // Migration: add empty-container run counters to existing installs
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_runs LIKE 'empty_sites_marked'"))) {
        db_execute("ALTER TABLE plugin_cds_runs
            ADD COLUMN empty_sites_marked INT UNSIGNED NOT NULL DEFAULT 0 AFTER graphs_created,
            ADD COLUMN empty_trees_marked INT UNSIGNED NOT NULL DEFAULT 0 AFTER empty_sites_marked");
    }

    // Migration: add tag-reconciliation (un-mark) run counters to existing installs
    if (!cacti_sizeof(db_fetch_assoc("SHOW COLUMNS FROM plugin_cds_runs LIKE 'sites_unmarked'"))) {
        db_execute("ALTER TABLE plugin_cds_runs
            ADD COLUMN sites_unmarked INT UNSIGNED NOT NULL DEFAULT 0 AFTER empty_trees_marked,
            ADD COLUMN trees_unmarked INT UNSIGNED NOT NULL DEFAULT 0 AFTER sites_unmarked");
    }
}
