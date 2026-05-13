#!/usr/local/bin/php
<?php

/**
 * xray-subscription-update.php — Fetch and sync a subscription group.
 *
 * Usage: xray-subscription-update.php <group_uuid>
 * Output: JSON {"added":N,"updated":N,"removed":N} | {"error":"..."}
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('/usr/local/pkg/xray/includes/xray_vless.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');

$group_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$group_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $group_uuid);
if (strlen($group_uuid) < 36) {
    echo json_encode(['error' => 'Invalid group UUID']) . "\n";
    exit(1);
}

$group = null;
foreach (config_get_path('installedpackages/xraygroups/config', []) as $g) {
    if (($g['uuid'] ?? '') === $group_uuid) {
        $group = $g;
        break;
    }
}

if ($group === null) {
    echo json_encode(['error' => 'Group not found']) . "\n";
    exit(1);
}

if (($group['type'] ?? 'manual') !== 'subscription') {
    echo json_encode(['error' => 'Group is not a subscription group']) . "\n";
    exit(1);
}

$result = xray_update_subscription_group($group);
echo json_encode($result) . "\n";
exit(isset($result['error']) ? 1 : 0);
