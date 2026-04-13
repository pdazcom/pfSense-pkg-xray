#!/usr/local/bin/php
<?php

/**
 * xray-urltest-group.php — Parallel URL-test of all connections in a group.
 *
 * Splits connections into batches of GRPTEST_BATCH_SIZE. For each batch:
 *   1. Writes a config per connection (port 49500+i).
 *   2. Starts all xray-core instances in parallel via daemon.
 *   3. Waits until all ports are ready (or timeout).
 *   4. Runs all curl tests in parallel via proc_open.
 *   5. Collects results, kills all xray-core instances, writes progress.
 *
 * Progress is written to /tmp/xray-grptest-{group_uuid}.json for GUI polling.
 * Own PID is written to /tmp/xray-grptest-{group_uuid}.pid so the GUI can stop it.
 *
 * Usage: xray-urltest-group.php <group_uuid>
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');

define('XRAY_BIN',              '/usr/local/bin/xray-core');
define('GRPTEST_PORT_BASE',     49500);
define('GRPTEST_BATCH_SIZE',    5);
define('GRPTEST_READY_WAIT_MS', 3000);
define('GRPTEST_POLL_MS',       100);

$group_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$group_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $group_uuid);
if (strlen($group_uuid) < 36) {
    exit(1);
}

$progressFile = '/tmp/xray-grptest-' . $group_uuid . '.json';
$selfPidFile  = '/tmp/xray-grptest-' . $group_uuid . '.pid';

file_put_contents($selfPidFile, getmypid(), LOCK_EX);

$stopRequested = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$stopRequested) { $stopRequested = true; });
    pcntl_signal(SIGINT,  function () use (&$stopRequested) { $stopRequested = true; });
}

$connections = xray_get_connections_by_group($group_uuid);
if (empty($connections)) {
    file_put_contents($progressFile, json_encode(['done' => true, 'results' => []]), LOCK_EX);
    @unlink($selfPidFile);
    exit(0);
}

$globalCfg = config_get_path('installedpackages/xray/config/0', []);
$testUrl   = trim($globalCfg['test_url'] ?? '');
if ($testUrl === '') {
    $testUrl = 'https://www.google.com';
}

$curlBin = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';

$results = [];
foreach ($connections as $conn) {
    $results[$conn['uuid']] = null;
}
progress_write($progressFile, false, $results);

if (!file_exists(XRAY_BIN)) {
    foreach ($connections as $conn) {
        $results[$conn['uuid']] = ['status' => 'unavailable', 'error' => 'xray-core not found'];
    }
    progress_write($progressFile, true, $results);
    @unlink($selfPidFile);
    exit(1);
}

batch_kill_all(range(0, GRPTEST_BATCH_SIZE - 1));

$batches = array_chunk($connections, GRPTEST_BATCH_SIZE);

foreach ($batches as $batch) {
    if ($stopRequested) {
        break;
    }

    $slot = [];

    foreach ($batch as $i => $conn) {
        $port     = GRPTEST_PORT_BASE + $i;
        $confFile = '/tmp/xray-grptest-' . $i . '.conf.json';
        $pidFile  = '/tmp/xray-grptest-' . $i . '.pid';

        batch_kill_pid($pidFile);

        $configJson = grptest_build_config($conn, $port);
        if ($configJson === null) {
            $results[$conn['uuid']] = ['status' => 'unavailable', 'error' => 'Failed to build config'];
            progress_write($progressFile, false, $results);
            $slot[$i] = null;
            continue;
        }

        file_put_contents($confFile, $configJson);
        chmod($confFile, 0600);

        exec('/usr/sbin/daemon -p ' . escapeshellarg($pidFile)
            . ' ' . escapeshellarg(XRAY_BIN)
            . ' run -c ' . escapeshellarg($confFile)
            . ' >> /dev/null 2>&1');

        $slot[$i] = [
            'conn'     => $conn,
            'port'     => $port,
            'pidFile'  => $pidFile,
            'confFile' => $confFile,
        ];
    }

    $deadline = microtime(true) + GRPTEST_READY_WAIT_MS / 1000.0;
    $pending  = array_filter($slot);
    while (!empty($pending) && microtime(true) < $deadline && !$stopRequested) {
        foreach ($pending as $i => $s) {
            $sock = @fsockopen('127.0.0.1', $s['port'], $errno, $errstr, 0.05);
            if ($sock !== false) {
                fclose($sock);
                unset($pending[$i]);
            }
        }
        if (!empty($pending)) {
            usleep(GRPTEST_POLL_MS * 1000);
        }
    }

    if ($stopRequested) {
        foreach (array_filter($slot) as $s) {
            batch_kill_pid($s['pidFile']);
            @unlink($s['confFile']);
        }
        break;
    }

    foreach ($pending as $i => $s) {
        $results[$s['conn']['uuid']] = ['status' => 'unavailable', 'error' => 'xray-core did not start'];
        progress_write($progressFile, false, $results);
        batch_kill_pid($s['pidFile']);
        @unlink($s['confFile']);
        $slot[$i] = null;
    }

    $activeSlots = array_filter($slot);

    $procs = [];
    foreach ($activeSlots as $i => $s) {
        $cmd = $curlBin . ' -s -o /dev/null'
            . ' --socks5 127.0.0.1:' . $s['port']
            . ' --max-time 10'
            . ' -w "%{time_starttransfer}\n%{http_code}"'
            . ' ' . escapeshellarg($testUrl)
            . ' 2>/dev/null';

        $pipes = [];
        $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($proc !== false) {
            stream_set_blocking($pipes[1], false);
            $procs[$i] = ['proc' => $proc, 'pipe' => $pipes[1], 'output' => '', 'slot' => $s];
        } else {
            $results[$s['conn']['uuid']] = ['status' => 'unavailable', 'error' => 'Failed to start curl'];
            progress_write($progressFile, false, $results);
        }
    }

    while (!empty($procs)) {
        if ($stopRequested) {
            foreach ($procs as $p) {
                proc_terminate($p['proc']);
                fclose($p['pipe']);
                proc_close($p['proc']);
            }
            $procs = [];
            break;
        }

        foreach ($procs as $i => $p) {
            $chunk = fread($p['pipe'], 4096);
            if ($chunk !== false && $chunk !== '') {
                $procs[$i]['output'] .= $chunk;
            }

            $status = proc_get_status($p['proc']);
            if (!$status['running']) {
                $remaining = stream_get_contents($p['pipe']);
                if ($remaining !== false) {
                    $procs[$i]['output'] .= $remaining;
                }
                fclose($p['pipe']);
                $exitCode = proc_close($p['proc']);

                $lines             = explode("\n", trim($procs[$i]['output']));
                $timeStartTransfer = (float)trim($lines[0] ?? '0');
                $httpCode          = (int)trim($lines[1] ?? '0');

                if ($exitCode === 0 && $httpCode >= 200 && $httpCode < 400) {
                    $result = ['status' => 'ok', 'ping_ms' => (int)round($timeStartTransfer * 1000)];
                } else {
                    $result = ['status' => 'unavailable', 'error' => "HTTP {$httpCode}"];
                }

                $connUuid = $p['slot']['conn']['uuid'];
                $results[$connUuid] = $result;

                $savedConn = xray_get_connection_by_uuid($connUuid);
                if ($savedConn !== null) {
                    $savedConn['test_result'] = json_encode($result);
                    xray_save_connection($savedConn);
                }

                progress_write($progressFile, false, $results);
                unset($procs[$i]);
            }
        }
        if (!empty($procs)) {
            usleep(50000);
        }
    }

    foreach ($activeSlots as $s) {
        batch_kill_pid($s['pidFile']);
        @unlink($s['confFile']);
    }

    if ($stopRequested) {
        break;
    }
}

foreach ($results as $uuid => $r) {
    if ($r === null) {
        $results[$uuid] = ['status' => 'unavailable', 'error' => 'Stopped'];
    }
}

progress_write($progressFile, true, $results);
@unlink($selfPidFile);
exit(0);


function progress_write(string $file, bool $done, array $results): void
{
    $data = ['done' => $done, 'results' => $results];
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function batch_kill_pid(string $pidFile): void
{
    if (!file_exists($pidFile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0) {
        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $deadline = microtime(true) + 0.5;
        while (microtime(true) < $deadline) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(50000);
        }
        exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
    }
    @unlink($pidFile);
}

function batch_kill_all(array $indices): void
{
    foreach ($indices as $i) {
        batch_kill_pid('/tmp/xray-grptest-' . $i . '.pid');
        @unlink('/tmp/xray-grptest-' . $i . '.conf.json');
    }
}

function grptest_build_config(array $conn, int $socksPort): ?string
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
                        'serverName'  => $conn['reality_sni']        ?? '',
                        'fingerprint' => $conn['reality_fingerprint'] ?? 'chrome',
                        'show'        => false,
                        'publicKey'   => $conn['reality_pubkey']      ?? '',
                        'shortId'     => $conn['reality_shortid']     ?? '',
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
