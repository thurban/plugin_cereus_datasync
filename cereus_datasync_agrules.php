<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Redirect to the unified rules page — aggregate tab lives there now.
$pid = isset($_GET['profile_id']) ? (int)$_GET['profile_id'] : 0;
header('Location: cereus_datasync_rules.php?profile_id=' . $pid . '&tab=aggregate');
exit;
