#!/usr/local/bin/php
<?php

/**
 * xray-watchdog.php — Crash recovery daemon for all Xray instances.
 *
 * Runs every minute via cron. For each instance:
 *   1. Checks watchdog_enabled global flag.
 *   2. Skips instances with xray_stopped_<uuid>.flag (manual stop).
 *   3. If xray-core OR tun2socks died — triggers restart.
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');

define('XRAY_CTRL',    '/usr/local/scripts/xray/xray-service-control.php');
define('WATCHDOG_LOG', '/var/log/xray-watchdog.log');

function wlog(string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents(WATCHDOG_LOG, $line, FILE_APPEND | LOCK_EX);
}

function proc_alive(string $pidfile): bool
{
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

// ─── Read config from pfSense $config array ───────────────────────────────────
$globalCfg       = config_get_path('installedpackages/xray/config/0', []);
$enabled         = isset($globalCfg['enabled']) && $globalCfg['enabled'] === 'on';
$watchdogEnabled = isset($globalCfg['watchdog_enabled']) && $globalCfg['watchdog_enabled'] === 'on';

if (!$enabled || !$watchdogEnabled) {
    exit(0);
}

$instancesCfg = config_get_path('installedpackages/xrayinstances/config', []);
if (empty($instancesCfg)) {
    exit(0);
}

if (!file_exists(XRAY_CTRL)) {
    wlog('ERROR: ' . XRAY_CTRL . ' not found — cannot restart');
    exit(1);
}

// ─── Iterate all instances ────────────────────────────────────────────────────
$anyFailed = false;

foreach ($instancesCfg as $inst) {
    $inst_uuid = $inst['uuid'] ?? '';
    if ($inst_uuid === '') {
        continue;
    }

    $name = $inst['name'] ?? $inst_uuid;

    $stoppedFlag = "/var/run/xray_stopped_{$inst_uuid}.flag";
    if (file_exists($stoppedFlag)) {
        continue;
    }

    $xrayPid = "/var/run/xray_core_{$inst_uuid}.pid";
    $t2sPid  = "/var/run/tun2socks_{$inst_uuid}.pid";

    $xrayAlive = proc_alive($xrayPid);
    $t2sAlive  = proc_alive($t2sPid);

    if ($xrayAlive && $t2sAlive) {
        continue;
    }

    $died = [];
    if (!$xrayAlive) {
        $died[] = 'xray-core';
    }
    if (!$t2sAlive) {
        $died[] = 'tun2socks';
    }

    wlog("WATCHDOG [{$name}]: " . implode(', ', $died) . " not running — triggering restart");

    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' restart ' . escapeshellarg($inst_uuid) . ' 2>&1', $out, $rc);
    $output = trim(implode("\n", $out));

    if ($rc === 0) {
        wlog("WATCHDOG [{$name}]: restart OK — " . ($output ?: 'no output'));
    } else {
        wlog("WATCHDOG [{$name}]: restart FAILED (exit {$rc}) — {$output}");
        $anyFailed = true;
    }
}

exit($anyFailed ? 1 : 0);
