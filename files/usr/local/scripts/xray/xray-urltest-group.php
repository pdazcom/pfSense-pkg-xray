#!/usr/local/bin/php
<?php

/**
 * xray-urltest-group.php — Parallel URL-test of all connections in a group.
 *
 * Splits connections into batches of GRPTEST_BATCH_SIZE. For each batch:
 *   1. Writes a config per connection (port 49500+i).
 *   2. Starts all xray-core instances in parallel via daemon.
 *   3. Waits until all ports are ready (or timeout).
 *   4. Runs all SOCKS5 HTTP tests in parallel via pcntl_fork.
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
require_once(__DIR__ . '/xray-urltest.inc');

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

$testUrl = urltest_get_test_url();

$results = [];
foreach ($connections as $conn) {
    $results[$conn['uuid']] = null;
}
progress_write($progressFile, false, $results);

if (!file_exists(URLTEST_XRAY_BIN)) {
    foreach ($connections as $conn) {
        $results[$conn['uuid']] = ['status' => 'unavailable', 'error' => 'xray-core not found'];
    }
    progress_write($progressFile, true, $results);
    @unlink($selfPidFile);
    exit(1);
}

cleanup_batch_files(range(0, GRPTEST_BATCH_SIZE - 1));

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

        urltest_kill_pid($pidFile);

        $configJson = urltest_build_config($conn, $port);
        if ($configJson === null) {
            $results[$conn['uuid']] = ['status' => 'unavailable', 'error' => 'Failed to build config'];
            progress_write($progressFile, false, $results);
            $slot[$i] = null;
            continue;
        }

        file_put_contents($confFile, $configJson);
        chmod($confFile, 0600);

        exec('/usr/sbin/daemon -p ' . escapeshellarg($pidFile)
            . ' ' . escapeshellarg(URLTEST_XRAY_BIN)
            . ' run -c ' . escapeshellarg($confFile)
            . ' >> /dev/null 2>&1');

        $slot[$i] = [
            'conn'     => $conn,
            'port'     => $port,
            'pidFile'  => $pidFile,
            'confFile' => $confFile,
        ];
    }

    // Wait until all SOCKS5 ports are accepting connections
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
            urltest_kill_pid($s['pidFile']);
            @unlink($s['confFile']);
        }
        break;
    }

    foreach ($pending as $i => $s) {
        $results[$s['conn']['uuid']] = ['status' => 'unavailable', 'error' => 'xray-core did not start'];
        progress_write($progressFile, false, $results);
        urltest_kill_pid($s['pidFile']);
        @unlink($s['confFile']);
        $slot[$i] = null;
    }

    $activeSlots = array_filter($slot);

    // Run all HTTP tests in parallel via pcntl_fork + shared temp files
    $forkResults = [];
    foreach ($activeSlots as $i => $s) {
        $resultFile = '/tmp/xray-grptest-result-' . $i . '.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            $results[$s['conn']['uuid']] = ['status' => 'unavailable', 'error' => 'fork failed'];
            progress_write($progressFile, false, $results);
            continue;
        }
        if ($pid === 0) {
            // Child: run test, write result, exit
            $test = urltest_socks5_http('127.0.0.1', $s['port'], $testUrl, 10);
            if ($test['error'] === null && $test['status'] >= 200 && $test['status'] < 400) {
                $r = ['status' => 'ok', 'ping_ms' => $test['latency_ms']];
            } else {
                $r = ['status' => 'unavailable', 'error' => $test['error'] ?? "HTTP {$test['status']}"];
            }
            file_put_contents($resultFile, json_encode($r), LOCK_EX);
            exit(0);
        }
        $forkResults[$i] = ['pid' => $pid, 'slot' => $s, 'resultFile' => $resultFile];
    }

    // Collect children
    foreach ($forkResults as $i => $f) {
        pcntl_waitpid($f['pid'], $status);

        $raw = @file_get_contents($f['resultFile']);
        $r   = $raw !== false ? json_decode($raw, true) : null;
        if ($r === null) {
            $r = ['status' => 'unavailable', 'error' => 'no result'];
        }
        @unlink($f['resultFile']);

        $connUuid            = $f['slot']['conn']['uuid'];
        $results[$connUuid]  = $r;

        $savedConn = xray_get_connection_by_uuid($connUuid);
        if ($savedConn !== null) {
            $savedConn['test_result'] = json_encode($r);
            xray_save_connection($savedConn);
        }

        progress_write($progressFile, false, $results);
    }

    foreach ($activeSlots as $s) {
        urltest_kill_pid($s['pidFile']);
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
    file_put_contents(
        $file,
        json_encode(['done' => $done, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function cleanup_batch_files(array $indices): void
{
    foreach ($indices as $i) {
        urltest_kill_pid('/tmp/xray-grptest-' . $i . '.pid');
        @unlink('/tmp/xray-grptest-' . $i . '.conf.json');
        @unlink('/tmp/xray-grptest-result-' . $i . '.json');
    }
}
