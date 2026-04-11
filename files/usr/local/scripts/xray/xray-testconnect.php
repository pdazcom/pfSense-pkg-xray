#!/usr/local/bin/php
<?php

/**
 * xray-testconnect.php — SOCKS5 connectivity test for a given Xray instance.
 *
 * Usage: xray-testconnect.php [uuid]
 * Output: HTTP status code (e.g. "200"), exit 0 on success
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');

$inst_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
if (strlen($inst_uuid) < 36) {
    $inst_uuid = '';
}

// ─── Find instance config ─────────────────────────────────────────────────────
$instancesCfg = config_get_path('installedpackages/xrayinstances/config', []);
$inst = null;

if ($inst_uuid !== '') {
    foreach ($instancesCfg as $i) {
        if (($i['uuid'] ?? '') === $inst_uuid) {
            $inst = $i;
            break;
        }
    }
}

if ($inst === null && !empty($instancesCfg)) {
    $inst = $instancesCfg[0];
}

if ($inst === null) {
    echo "0\n";
    exit(1);
}

$listen = ($inst['socks5_listen'] ?? '127.0.0.1') ?: '127.0.0.1';
$port   = (int)($inst['socks5_port'] ?? 10808);

if ($port < 1 || $port > 65535) {
    $port = 10808;
}

$connectAddr = ($listen === '0.0.0.0') ? '127.0.0.1' : $listen;
$proxy   = $connectAddr . ':' . $port;
$target  = 'https://1.1.1.1';
$timeout = '10';

exec(
    '/usr/local/bin/curl'
    . ' --socks5 ' . escapeshellarg($proxy)
    . ' -s -L -o /dev/null'
    . ' -w %{http_code}'
    . ' ' . escapeshellarg($target)
    . ' --max-time ' . $timeout,
    $out,
    $rc
);

echo implode('', $out) . "\n";
exit($rc);
