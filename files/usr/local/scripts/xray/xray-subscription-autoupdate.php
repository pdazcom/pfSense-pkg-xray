#!/usr/local/bin/php
<?php

/**
 * xray-subscription-autoupdate.php — Cron wrapper for subscription autoupdate.
 *
 * Runs every 30 minutes via cron. Updates all subscription groups
 * that have autoupdate=on.
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');

define('SUB_UPDATE_SCRIPT', '/usr/local/scripts/xray/xray-subscription-update.php');
define('AUTOUPDATE_LOG',    '/var/log/xray-watchdog.log');

function sublog(string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] AUTOUPDATE: {$msg}\n";
    file_put_contents(AUTOUPDATE_LOG, $line, FILE_APPEND | LOCK_EX);
}

$globalCfg = config_get_path('installedpackages/xray/config/0', []);
$enabled   = isset($globalCfg['enabled']) && $globalCfg['enabled'] === 'on';

if (!$enabled) {
    exit(0);
}

$groupsCfg = config_get_path('installedpackages/xraygroups/config', []);
if (empty($groupsCfg)) {
    exit(0);
}

if (!file_exists(SUB_UPDATE_SCRIPT)) {
    sublog('ERROR: ' . SUB_UPDATE_SCRIPT . ' not found');
    exit(1);
}

foreach ($groupsCfg as $group) {
    if (($group['type'] ?? 'manual') !== 'subscription') {
        continue;
    }
    if (($group['autoupdate'] ?? '') !== 'on') {
        continue;
    }

    $uuid = $group['uuid'] ?? '';
    $name = $group['name'] ?? $uuid;

    $out = [];
    exec('/usr/local/bin/php ' . escapeshellarg(SUB_UPDATE_SCRIPT) . ' ' . escapeshellarg($uuid) . ' 2>&1', $out, $rc);
    $outputStr = trim(implode(' ', $out));

    if ($rc === 0) {
        sublog("Group '{$name}' ({$uuid}): {$outputStr}");
    } else {
        sublog("Group '{$name}' ({$uuid}): FAILED — {$outputStr}");
    }
}
