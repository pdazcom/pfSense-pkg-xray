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

define('XRAY_BIN',      '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR', '/usr/local/etc/xray-core');

$conn_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$conn_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $conn_uuid);
if (strlen($conn_uuid) < 36) {
    echo json_encode(['status' => 'unavailable', 'error' => 'Invalid connection UUID']) . "\n";
    exit(1);
}

$conn = xray_get_connection_by_uuid($conn_uuid);

if ($conn === null) {
    echo json_encode(['status' => 'unavailable', 'error' => 'Connection not found']) . "\n";
    exit(1);
}

$globalCfg = config_get_path('installedpackages/xray/config/0', []);
$testUrl   = trim($globalCfg['test_url'] ?? '');
if ($testUrl === '') {
    $testUrl = 'https://www.google.com';
}

$tempPort = 49000 + (abs(crc32($conn_uuid)) % 1000);
$tempConf = "/tmp/xray-test-{$conn_uuid}.json";
$tempPid  = "/tmp/xray-test-{$conn_uuid}.pid";

function xray_test_build_config(array $conn, int $socksPort): ?string
{
    $configMode = $conn['config_mode'] ?? 'wizard';

    if ($configMode === 'custom') {
        $raw = trim($conn['custom_config'] ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return null;
        }
        $decoded['inbounds'] = [[
            'tag'      => 'socks-in',
            'port'     => $socksPort,
            'listen'   => '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => false, 'ip' => '127.0.0.1'],
        ]];
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $flow = ($conn['flow'] ?? 'xtls-rprx-vision');
    if ($flow === 'none' || $flow === '') {
        $flow = '';
    }

    $cfg = [
        'log'      => ['loglevel' => 'error'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $socksPort,
            'listen'   => '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => false, 'ip' => '127.0.0.1'],
        ]],
        'outbounds' => [
            [
                'tag'      => 'proxy',
                'protocol' => 'vless',
                'settings' => [
                    'vnext' => [[
                        'address' => $conn['server_address'] ?? '',
                        'port'    => (int)($conn['server_port'] ?? 443),
                        'users'   => [[
                            'id'         => $conn['vless_uuid'] ?? '',
                            'encryption' => 'none',
                            'flow'       => $flow,
                        ]],
                    ]],
                ],
                'streamSettings' => [
                    'network'         => 'tcp',
                    'security'        => 'reality',
                    'realitySettings' => [
                        'serverName'  => $conn['reality_sni']         ?? '',
                        'fingerprint' => $conn['reality_fingerprint']  ?? 'chrome',
                        'show'        => false,
                        'publicKey'   => $conn['reality_pubkey']       ?? '',
                        'shortId'     => $conn['reality_shortid']      ?? '',
                        'spiderX'     => '',
                    ],
                ],
            ],
            ['tag' => 'direct', 'protocol' => 'freedom'],
        ],
        'routing' => [
            'domainStrategy' => 'IPIfNonMatch',
            'rules' => [[
                'type'        => 'field',
                'ip'          => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
                'outboundTag' => 'direct',
            ]],
        ],
    ];

    return json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$configJson = xray_test_build_config($conn, $tempPort);
if ($configJson === null) {
    echo json_encode(['status' => 'unavailable', 'error' => 'Failed to build test config']) . "\n";
    exit(1);
}

file_put_contents($tempConf, $configJson);
chmod($tempConf, 0600);

$tempPidInt = null;
$result     = ['status' => 'unavailable', 'error' => 'Test did not complete'];

try {
    if (!file_exists(XRAY_BIN)) {
        $result = ['status' => 'unavailable', 'error' => 'xray-core binary not found'];
        exit_with_result($result, $conn_uuid, $tempConf, $tempPid, null);
    }

    exec('/usr/sbin/daemon -p ' . escapeshellarg($tempPid)
        . ' ' . escapeshellarg(XRAY_BIN)
        . ' run -c ' . escapeshellarg($tempConf)
        . ' >> /dev/null 2>&1 &');

    usleep(1500000);

    if (!file_exists($tempPid)) {
        $result = ['status' => 'unavailable', 'error' => 'xray-core failed to start'];
        exit_with_result($result, $conn_uuid, $tempConf, $tempPid, null);
    }

    $tempPidInt = (int)trim(file_get_contents($tempPid));

    $curlBin = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';

    $curlOut = [];
    exec(
        $curlBin . ' -s -o /dev/null'
        . ' --socks5 127.0.0.1:' . $tempPort
        . ' --max-time 10'
        . ' -w "%{time_starttransfer}\n%{http_code}"'
        . ' ' . escapeshellarg($testUrl)
        . ' 2>/dev/null',
        $curlOut,
        $curlRc
    );

    $timeStartTransfer = (float)trim($curlOut[0] ?? '0');
    $httpCode          = (int)trim($curlOut[1] ?? '0');

    if ($curlRc === 0 && $httpCode >= 200 && $httpCode < 400) {
        $pingMs = (int)round($timeStartTransfer * 1000);
        $result = ['status' => 'ok', 'ping_ms' => $pingMs];
    } else {
        $result = ['status' => 'unavailable', 'error' => "HTTP {$httpCode}"];
    }
} finally {
    exit_with_result($result, $conn_uuid, $tempConf, $tempPid, $tempPidInt);
}

function exit_with_result(array $result, string $conn_uuid, string $tempConf, string $tempPid, ?int $pid): never
{
    if ($pid !== null && $pid > 0) {
        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        usleep(300000);
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
        if ($rc === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($tempConf);
    @unlink($tempPid);

    $conn = xray_get_connection_by_uuid($conn_uuid);
    if ($conn !== null) {
        $conn['test_result'] = json_encode($result);
        xray_save_connection($conn);
    }

    echo json_encode($result) . "\n";
    exit($result['status'] === 'ok' ? 0 : 1);
}
