-- SPDX-License-Identifier: GPL-2.0-or-later
-- Cereus Data Sync — Database Tables

CREATE TABLE IF NOT EXISTS plugin_cds_profiles (
    id                         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name                       VARCHAR(100)     NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
    description                TEXT,
    enabled                    CHAR(2)          NOT NULL DEFAULT 'on',
    excel_path                 VARCHAR(512)     NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
    excel_file_mode            VARCHAR(20)      NOT NULL DEFAULT 'latest_in_dir',
    sheet_name                 VARCHAR(64)      NOT NULL DEFAULT 'Sheet1' COLLATE utf8mb4_unicode_ci,
    data_start_row             SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    schedule_type              VARCHAR(20)      NOT NULL DEFAULT 'manual',
    schedule_hour              TINYINT UNSIGNED NOT NULL DEFAULT 2,
    schedule_wday              TINYINT UNSIGNED NOT NULL DEFAULT 1,
    snmp_check_l2              TINYINT(1)       NOT NULL DEFAULT 1,
    snmp_wan_pattern           VARCHAR(64)      NOT NULL DEFAULT '-WAN-',
    snmp_timeout_ms            INT UNSIGNED     NOT NULL DEFAULT 2000,
    snmp_enable_parallel       TINYINT(1)       NOT NULL DEFAULT 0,
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
    last_run_at                TIMESTAMP        NULL DEFAULT NULL,
    last_run_status            VARCHAR(20)      NOT NULL DEFAULT '',
    last_run_stats             JSON,
    created                    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified                   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_enabled (enabled),
    INDEX idx_schedule (schedule_type, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS plugin_cds_column_maps (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id  INT UNSIGNED NOT NULL,
    field_name  VARCHAR(32)  NOT NULL DEFAULT '',
    col_letter  VARCHAR(4)   NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_profile_field (profile_id, field_name),
    INDEX idx_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS plugin_cds_function_maps (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id       INT UNSIGNED NOT NULL,
    device_function  VARCHAR(64)  NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
    host_template_id INT UNSIGNED NOT NULL DEFAULT 0,
    enabled          CHAR(2)      NOT NULL DEFAULT 'on',
    PRIMARY KEY (id),
    INDEX idx_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS plugin_cds_tree_rules (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id   INT UNSIGNED NOT NULL,
    rule_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    enabled      CHAR(2)      NOT NULL DEFAULT 'on',
    match_field  VARCHAR(32)  NOT NULL DEFAULT 'description',
    operator     VARCHAR(20)  NOT NULL DEFAULT 'contains',
    pattern      VARCHAR(256) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci,
    tree_id      INT UNSIGNED NOT NULL DEFAULT 0,
    tree_item_id INT UNSIGNED NOT NULL DEFAULT 0,
    host_grouping TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    INDEX idx_profile_order (profile_id, rule_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS plugin_cds_runs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id     INT UNSIGNED NOT NULL,
    profile_name   VARCHAR(100) NOT NULL DEFAULT '',
    started_at     DATETIME     NOT NULL,
    completed_at   DATETIME     NULL DEFAULT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'running',
    dry_run        TINYINT(1)   NOT NULL DEFAULT 0,
    excel_file     VARCHAR(512) NOT NULL DEFAULT '',
    excel_total    INT UNSIGNED NOT NULL DEFAULT 0,
    cacti_total    INT UNSIGNED NOT NULL DEFAULT 0,
    added          INT UNSIGNED NOT NULL DEFAULT 0,
    updated        INT UNSIGNED NOT NULL DEFAULT 0,
    marked_deleted INT UNSIGNED NOT NULL DEFAULT 0,
    skipped        INT UNSIGNED NOT NULL DEFAULT 0,
    failed         INT UNSIGNED NOT NULL DEFAULT 0,
    tree_placed    INT UNSIGNED NOT NULL DEFAULT 0,
    empty_sites_marked INT UNSIGNED NOT NULL DEFAULT 0,
    empty_trees_marked INT UNSIGNED NOT NULL DEFAULT 0,
    sites_unmarked     INT UNSIGNED NOT NULL DEFAULT 0,
    trees_unmarked     INT UNSIGNED NOT NULL DEFAULT 0,
    error_message  TEXT,
    PRIMARY KEY (id),
    INDEX idx_profile (profile_id),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS plugin_cds_run_details (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
