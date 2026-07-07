<?php
// SPDX-License-Identifier: GPL-2.0-or-later

function cereus_datasync_license_tier(): string {
    if (function_exists('cereus_license_get_tier')) {
        return cereus_license_get_tier('cereus_datasync');
    }
    return 'community';
}

function cereus_datasync_license_at_least(string $minimum): bool {
    $levels = ['community' => 0, 'professional' => 1, 'enterprise' => 2];
    $current  = $levels[cereus_datasync_license_tier()] ?? 0;
    $required = $levels[$minimum] ?? 99;
    return $current >= $required;
}

// The entire plugin requires at least Professional
function cereus_datasync_license_ok(): bool {
    return cereus_datasync_license_at_least('professional');
}

// Profile limit: Professional=3, Enterprise=unlimited
function cereus_datasync_max_profiles(): int {
    if (cereus_datasync_license_at_least('enterprise')) return PHP_INT_MAX;
    if (cereus_datasync_license_at_least('professional')) return 3;
    return 0;
}

// Scheduled sync only on Enterprise
function cereus_datasync_has_scheduling(): bool {
    return cereus_datasync_license_at_least('enterprise');
}

// Tree automation rules: Professional up to 10 per profile, Enterprise unlimited
function cereus_datasync_max_tree_rules(): int {
    if (cereus_datasync_license_at_least('enterprise')) return PHP_INT_MAX;
    return 10;
}
