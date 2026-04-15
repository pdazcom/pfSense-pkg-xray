#!/usr/local/bin/php
<?php

/**
 * xray-rotation.php — Select a working connection from a group for an instance.
 *
 * Iterates all connections in the instance's group, URL-tests each one,
 * and returns the first working one. On complete failure, sends pfSense
 * notification and calls webhook(s) if configured.
 *
 * Usage: xray-rotation.php <instance_uuid>
 * Output: JSON {"status":"ok","connection_uuid":"xxx"} | {"status":"no_working_connection"}
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('util.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');

define('XRAY_BIN',      '/usr/local/bin/xray-core');
define('URLTEST_TEMP_PORT_BASE', 49000);

$GLOBALS['curl_bin'] = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';

$inst_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
if (strlen($inst_uuid) < 36) {
    echo json_encode(['status' => 'no_working_connection', 'error' => 'Invalid instance UUID']) . "\n";
    exit(1);
}

$instancesCfg = config_get_path('installedpackages/xrayinstances/config', []);
$inst = null;
$instIdx = null;
foreach ($instancesCfg as $idx => $i) {
    if (($i['uuid'] ?? '') === $inst_uuid) {
        $inst    = $i;
        $instIdx = $idx;
        break;
    }
}

if ($inst === null) {
    echo json_encode(['status' => 'no_working_connection', 'error' => 'Instance not found']) . "\n";
    exit(1);
}

$groupUuid = $inst['connection_group_uuid'] ?? '';
if ($groupUuid === '') {
    echo json_encode(['status' => 'no_working_connection', 'error' => 'No group configured']) . "\n";
    exit(1);
}

$groupConnections = xray_get_connections_by_group($groupUuid);

if (empty($groupConnections)) {
    echo json_encode(['status' => 'no_working_connection', 'error' => 'No connections in group']) . "\n";
    exit(1);
}

$globalCfg = config_get_path('installedpackages/xray/config/0', []);
$testUrl   = trim($globalCfg['test_url'] ?? '');
if ($testUrl === '') {
    $testUrl = 'https://www.google.com';
}

function rotation_urltest(array $conn, string $testUrl): array
{
    $connUuid = $conn['uuid'];
    $tempPort = URLTEST_TEMP_PORT_BASE + (abs(crc32($connUuid)) % 1000);
    $tempConf = "/tmp/xray-test-{$connUuid}.json";
    $tempPid  = "/tmp/xray-test-{$connUuid}.pid";

    $configJson = rotation_build_config($conn, $tempPort);
    if ($configJson === null) {
        return ['status' => 'unavailable', 'error' => 'Failed to build config'];
    }

    file_put_contents($tempConf, $configJson);
    chmod($tempConf, 0600);

    $pid = null;
    try {
        if (!file_exists(XRAY_BIN)) {
            return ['status' => 'unavailable', 'error' => 'xray-core not found'];
        }

        exec('/usr/sbin/daemon -p ' . escapeshellarg($tempPid)
            . ' ' . escapeshellarg(XRAY_BIN)
            . ' run -c ' . escapeshellarg($tempConf)
            . ' >> /dev/null 2>&1 &');

        usleep(1500000);

        if (!file_exists($tempPid)) {
            return ['status' => 'unavailable', 'error' => 'xray-core failed to start'];
        }

        $pid = (int)trim(file_get_contents($tempPid));

        $curlOut = [];
        exec(
            $GLOBALS['curl_bin'] . ' -s -o /dev/null'
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
            return ['status' => 'ok', 'ping_ms' => (int)round($timeStartTransfer * 1000)];
        }
        return ['status' => 'unavailable', 'error' => "HTTP {$httpCode}"];
    } finally {
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
    }
}

function rotation_build_config(array $conn, int $socksPort): ?string
{
    $configMode = trim($conn['custom_config'] ?? '') !== '' ? 'custom' : (($conn['config_mode'] ?? 'wizard') ?: 'wizard');

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

function rotation_save_test_result(string $conn_uuid, array $testResult): void
{
    $conn = xray_get_connection_by_uuid($conn_uuid);
    if ($conn !== null) {
        $conn['test_result'] = json_encode($testResult);
        xray_save_connection($conn);
    }
}

function rotation_send_notifications(array $inst, string $groupUuid): void
{
    $instName    = $inst['name'] ?? $inst['uuid'];
    $webhookUrl  = trim($inst['webhook_url'] ?? '');
    $globalCfg   = config_get_path('installedpackages/xray/config/0', []);
    $globalHook  = trim($globalCfg['notification_webhook'] ?? '');

    $message = "Xray: Instance '{$instName}' — no working connection found in rotation group.";

    if (function_exists('file_notice')) {
        file_notice('xray', $message, 'Xray Rotation Failed', '', 2);
    }

    $payload = json_encode([
        'instance'   => $instName,
        'group_uuid' => $groupUuid,
        'status'     => 'no_working_connection',
        'timestamp'  => date('c'),
    ]);

    foreach (array_filter([$webhookUrl, $globalHook]) as $url) {
        exec(
            $GLOBALS['curl_bin'] . ' -s -o /dev/null'
            . ' -X POST'
            . ' -H "Content-Type: application/json"'
            . ' -d ' . escapeshellarg($payload)
            . ' --max-time 10'
            . ' ' . escapeshellarg($url)
            . ' 2>/dev/null'
        );
    }
}

$winnerUuid = null;
foreach ($groupConnections as $conn) {
    $connUuid  = $conn['uuid'];
    $testResult = rotation_urltest($conn, $testUrl);
    rotation_save_test_result($connUuid, $testResult);

    if ($testResult['status'] === 'ok') {
        $winnerUuid = $connUuid;
        break;
    }
}

if ($winnerUuid !== null) {
    if ($instIdx !== null) {
        global $config;
        $config['installedpackages']['xrayinstances']['config'][$instIdx]['active_connection_uuid'] = $winnerUuid;
        write_config('Xray: rotation selected connection ' . $winnerUuid . ' for instance ' . $inst_uuid);
    }

    echo json_encode(['status' => 'ok', 'connection_uuid' => $winnerUuid]) . "\n";
    exit(0);
}

rotation_send_notifications($inst, $groupUuid);
echo json_encode(['status' => 'no_working_connection']) . "\n";
exit(1);
