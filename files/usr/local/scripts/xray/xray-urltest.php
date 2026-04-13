#!/usr/local/bin/php
<?php

/**
 * xray-urltest.php — URL test a connection via a temporary xray-core instance.
 *
 * Starts xray-core in SOCKS5-only mode (no tun2socks), tests the URL,
 * then kills the temporary process. Stores the result in the connection record.
 *
 * Usage: xray-urltest.php <connection_uuid>
 * Output: JSON {"status":"ok","ping_ms":42} | {"status":"unavailable","error":"..."}
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');
require_once(__DIR__ . '/xray-urltest.inc');

$conn_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$conn_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $conn_uuid);
if (strlen($conn_uuid) < 36) {
    finish(['status' => 'unavailable', 'error' => 'Invalid connection UUID'], null, null, null);
}

$conn = xray_get_connection_by_uuid($conn_uuid);
if ($conn === null) {
    finish(['status' => 'unavailable', 'error' => 'Connection not found'], $conn_uuid, null, null);
}

$testUrl  = urltest_get_test_url();
$tempPort = 49000 + (abs(crc32($conn_uuid)) % 1000);
$tempConf = "/tmp/xray-test-{$conn_uuid}.json";
$tempPid  = "/tmp/xray-test-{$conn_uuid}.pid";

if (!file_exists(URLTEST_XRAY_BIN)) {
    finish(['status' => 'unavailable', 'error' => 'xray-core binary not found'], $conn_uuid, null, null);
}

$configJson = urltest_build_config($conn, $tempPort);
if ($configJson === null) {
    finish(['status' => 'unavailable', 'error' => 'Failed to build test config'], $conn_uuid, null, null);
}

file_put_contents($tempConf, $configJson);
chmod($tempConf, 0600);

exec('/usr/sbin/daemon -p ' . escapeshellarg($tempPid)
    . ' ' . escapeshellarg(URLTEST_XRAY_BIN)
    . ' run -c ' . escapeshellarg($tempConf)
    . ' >> /dev/null 2>&1 &');

usleep(1500000);

if (!file_exists($tempPid)) {
    finish(['status' => 'unavailable', 'error' => 'xray-core failed to start'], $conn_uuid, $tempConf, null);
}

$test = urltest_socks5_http('127.0.0.1', $tempPort, $testUrl, 10);

if ($test['error'] === null && $test['status'] >= 200 && $test['status'] < 400) {
    $result = ['status' => 'ok', 'ping_ms' => $test['latency_ms']];
} else {
    $result = ['status' => 'unavailable', 'error' => $test['error'] ?? "HTTP {$test['status']}"];
}

finish($result, $conn_uuid, $tempConf, $tempPid);

function finish(array $result, ?string $conn_uuid, ?string $tempConf, ?string $tempPid): never
{
    if ($tempPid !== null) {
        urltest_kill_pid($tempPid);
    }
    if ($tempConf !== null) {
        @unlink($tempConf);
    }

    if ($conn_uuid !== null) {
        $conn = xray_get_connection_by_uuid($conn_uuid);
        if ($conn !== null) {
            $conn['test_result'] = json_encode($result);
            xray_save_connection($conn);
        }
    }

    echo json_encode($result) . "\n";
    exit($result['status'] === 'ok' ? 0 : 1);
}
