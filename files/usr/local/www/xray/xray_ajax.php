<?php

/**
 * xray_ajax.php — AJAX dispatcher for Xray package GUI.
 *
 * Handles: statusall, start, stop, restart, import (VLESS parser),
 *          ifstats, testconnect, validate, log, version.
 */

$nocsrf = true;
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_vless.inc');

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action parameter']);
    exit;
}

$uuid = xray_sanitize_uuid(trim($_POST['uuid'] ?? $_GET['uuid'] ?? ''));

// ─── Dispatcher ───────────────────────────────────────────────────────────────
switch ($action) {

    case 'statusall':
        $out = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php statusall 2>/dev/null', $out);
        $json  = implode('', $out);
        $data  = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode([]);
        break;

    case 'status':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php status' . $args . ' 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['status' => 'unknown']);
        break;

    case 'start':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php start' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'stop':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php stop' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'restart':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php restart' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'import':
        $rawBody = file_get_contents('php://input');
        $link = '';
        $socksListen = '127.0.0.1';
        $socksPort   = 10808;

        if (!empty($rawBody)) {
            $bodyJson = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($bodyJson['link_b64'])) {
                    $decoded = base64_decode($bodyJson['link_b64'], true);
                    $link = $decoded !== false ? trim($decoded) : '';
                } elseif (!empty($bodyJson['link'])) {
                    $link = trim($bodyJson['link']);
                }
                if (isset($bodyJson['socks5_listen'])) {
                    $socksListen = trim((string)$bodyJson['socks5_listen']);
                }
                if (isset($bodyJson['socks5_port'])) {
                    $socksPort = (int)$bodyJson['socks5_port'];
                }
            }
        }

        if ($link === '') {
            $b64 = trim($_POST['link_b64'] ?? '');
            if ($b64 !== '') {
                $decoded = base64_decode($b64, true);
                $link    = $decoded !== false ? trim($decoded) : '';
            }
        }
        if ($link === '') {
            $link = trim($_POST['link'] ?? '');
        }
        if ($socksListen === '127.0.0.1') {
            $postListen = trim($_POST['socks5_listen'] ?? '');
            if ($postListen !== '') {
                $socksListen = $postListen;
            }
        }
        if ($socksPort === 10808 && isset($_POST['socks5_port'])) {
            $socksPort = (int)$_POST['socks5_port'];
        }

        if ($link === '') {
            echo json_encode(['status' => 'error', 'message' => 'No VLESS link provided']);
            break;
        }
        if (strlen($link) > 2048) {
            echo json_encode(['status' => 'error', 'message' => 'Link too long']);
            break;
        }

        if (!filter_var($socksListen, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $socksListen = '127.0.0.1';
        }
        if ($socksPort < 1 || $socksPort > 65535) {
            $socksPort = 10808;
        }

        $data = xray_parse_vless_link($link, $socksListen, $socksPort);
        if (isset($data['error'])) {
            echo json_encode(['status' => 'error', 'message' => $data['error']]);
        } else {
            $data['status'] = 'ok';
            echo json_encode($data);
        }
        break;

    case 'ifstats':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-ifstats.php' . $args . ' 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['error' => 'Failed to get interface stats']);
        break;

    case 'testconnect':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-testconnect.php' . $args . ' 2>/dev/null', $out, $rc);
        $code = (int)trim(implode('', $out));
        echo json_encode([
            'result'    => ($code >= 200 && $code < 400) ? 'ok' : 'failed',
            'http_code' => $code,
        ]);
        break;

    case 'validate':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php validate' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result'  => $rc === 0 ? 'ok' : 'failed',
            'message' => implode("\n", $out),
        ]);
        break;

    case 'log':
        $lines = [];
        exec('/usr/bin/tail -n 200 /var/log/xray-core.log 2>/dev/null', $lines);
        echo json_encode(['log' => implode("\n", $lines)]);
        break;

    case 'watchdoglog':
        $lines = [];
        exec('/usr/bin/tail -n 100 /var/log/xray-watchdog.log 2>/dev/null', $lines);
        echo json_encode(['log' => implode("\n", $lines)]);
        break;

    case 'version':
        $out = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php version 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['version' => 'unknown']);
        break;

    case 'urltest':
        $connUuid = xray_sanitize_uuid(trim($_POST['connection_uuid'] ?? $_GET['connection_uuid'] ?? ''));
        if ($connUuid === '') {
            echo json_encode(['status' => 'unavailable', 'error' => 'Invalid connection UUID']);
            break;
        }
        $out = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-urltest.php ' . escapeshellarg($connUuid) . ' 2>/dev/null', $out, $rc);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['status' => 'unavailable', 'error' => 'No output']);
        break;

    case 'urltest_group_start':
        $groupUuid = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? $_GET['group_uuid'] ?? ''));
        if ($groupUuid === '') {
            echo json_encode(['error' => 'Invalid group UUID']);
            break;
        }
        $progressFile = '/tmp/xray-grptest-' . $groupUuid . '.json';
        @unlink($progressFile);
        exec('/usr/sbin/daemon -f /usr/local/bin/php /usr/local/scripts/xray/xray-urltest-group.php '
            . escapeshellarg($groupUuid) . ' > /dev/null 2>&1 &');
        echo json_encode(['status' => 'started']);
        break;

    case 'urltest_group_status':
        $groupUuid = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? $_GET['group_uuid'] ?? ''));
        if ($groupUuid === '') {
            echo json_encode(['error' => 'Invalid group UUID']);
            break;
        }
        $progressFile = '/tmp/xray-grptest-' . $groupUuid . '.json';
        if (!file_exists($progressFile)) {
            echo json_encode(['done' => false, 'results' => []]);
            break;
        }
        $raw  = file_get_contents($progressFile);
        $data = json_decode($raw, true);
        echo ($data !== null) ? $raw : json_encode(['done' => false, 'results' => []]);
        break;

    case 'urltest_group_stop':
        $groupUuid = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? $_GET['group_uuid'] ?? ''));
        if ($groupUuid === '') {
            echo json_encode(['error' => 'Invalid group UUID']);
            break;
        }
        $selfPidFile = '/tmp/xray-grptest-' . $groupUuid . '.pid';
        if (file_exists($selfPidFile)) {
            $pid = (int)trim(file_get_contents($selfPidFile));
            if ($pid > 0) {
                exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
            }
        }
        echo json_encode(['result' => 'ok']);
        break;

    case 'update_subscription':
        $groupUuid = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? $_GET['group_uuid'] ?? ''));
        if ($groupUuid === '') {
            echo json_encode(['error' => 'Invalid group UUID']);
            break;
        }
        echo json_encode(xray_ajax_update_subscription($groupUuid));
        break;

    case 'delete_connection':
        if ($uuid === '') {
            echo json_encode(['error' => 'Missing UUID']);
            break;
        }
        $err = xray_delete_connection($uuid);
        if ($err !== '') {
            echo json_encode(['error' => $err]);
        } else {
            echo json_encode(['result' => 'ok']);
        }
        break;

    case 'delete_group':
        $groupUuid = $uuid !== '' ? $uuid : xray_sanitize_uuid(trim($_POST['group_uuid'] ?? ''));
        if ($groupUuid === '') {
            echo json_encode(['error' => 'Invalid group UUID']);
            break;
        }
        $err = xray_delete_group($groupUuid);
        if ($err !== '') {
            echo json_encode(['error' => $err]);
        } else {
            echo json_encode(['result' => 'ok']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')]);
}

function xray_fetch_links_from_url(string $url): array|false
{
    $curlBin = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';
    $rawOut  = [];
    exec(
        $curlBin . ' -s -L --max-time 30 -A "xray-pfsense/1.0"'
        . ' ' . escapeshellarg($url)
        . ' 2>/dev/null',
        $rawOut,
        $curlRc
    );

    if ($curlRc !== 0 || empty($rawOut)) {
        return false;
    }

    $raw     = implode("\n", $rawOut);
    $decoded = base64_decode(trim($raw), true);
    if ($decoded !== false && strpos($decoded, 'vless://') !== false) {
        $raw = $decoded;
    }

    $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw)));
    return array_values(array_filter($lines, static function (string $line): bool {
        return strpos($line, 'vless://') === 0;
    }));
}

function xray_ajax_update_subscription(string $groupUuid): array
{
    $group = xray_get_group_by_uuid($groupUuid);
    if ($group === null) {
        return ['error' => 'Group not found'];
    }
    if (($group['type'] ?? 'manual') !== 'subscription') {
        return ['error' => 'Group is not a subscription group'];
    }

    $urls = xray_group_sub_urls($group);
    if (empty($urls)) {
        return ['error' => 'Subscription URL is empty'];
    }

    $fetchedLinks = [];
    $seenKeys     = [];
    foreach ($urls as $url) {
        $links = xray_fetch_links_from_url($url);
        if ($links === false) {
            return ['error' => 'Failed to fetch subscription URL: ' . $url];
        }
        foreach ($links as $link) {
            $key = md5($link);
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $fetchedLinks[] = $link;
            }
        }
    }

    if (empty($fetchedLinks)) {
        return ['error' => 'No vless:// links found in subscription'];
    }

    $existingConns = xray_get_connections();

    $groupConns = [];
    $otherConns = [];
    foreach ($existingConns as $conn) {
        if (($conn['group_uuid'] ?? '') === $groupUuid) {
            $groupConns[] = $conn;
        } else {
            $otherConns[] = $conn;
        }
    }

    $added   = 0;
    $updated = 0;
    $removed = 0;

    $parsedConns = [];
    foreach ($fetchedLinks as $link) {
        $data = xray_parse_vless_link($link);
        if (isset($data['error'])) {
            continue;
        }
        $parsedConns[] = [
            'server_address'      => $data['host'],
            'server_port'         => (string)$data['port'],
            'vless_uuid'          => $data['vless_uuid'],
            'flow'                => $data['flow'],
            'reality_sni'         => $data['sni'],
            'reality_pubkey'      => $data['pbk'],
            'reality_shortid'     => $data['sid'],
            'reality_fingerprint' => $data['fp'],
            'config_mode'         => $data['config_mode'],
            'custom_config'       => $data['custom_config'] ?? '',
            'name'                => $data['name'] !== '' ? $data['name']
                                     : ($data['host'] . ':' . $data['port']),
        ];
    }

    $usedUuids = [];
    foreach ($parsedConns as $parsed) {
        $matched  = null;
        $matchIdx = null;
        foreach ($groupConns as $idx => $existing) {
            if (
                ($existing['server_address'] ?? '') === $parsed['server_address']
                && ($existing['server_port'] ?? '') === $parsed['server_port']
                && ($existing['vless_uuid'] ?? '') === $parsed['vless_uuid']
            ) {
                $matched  = $existing;
                $matchIdx = $idx;
                break;
            }
        }

        if ($matched !== null) {
            $needsUpdate = false;
            foreach (['flow', 'reality_sni', 'reality_pubkey', 'reality_shortid', 'reality_fingerprint', 'config_mode', 'custom_config'] as $field) {
                if (($matched[$field] ?? '') !== ($parsed[$field] ?? '')) {
                    $needsUpdate = true;
                    break;
                }
            }
            if ($needsUpdate) {
                foreach ($parsed as $k => $v) {
                    $groupConns[$matchIdx][$k] = $v;
                }
                $updated++;
            }
            $usedUuids[] = $matched['uuid'];
        } else {
            $newConn = array_merge($parsed, [
                'uuid'        => xray_generate_uuid(),
                'group_uuid'  => $groupUuid,
                'test_result' => '',
            ]);
            $groupConns[] = $newConn;
            $usedUuids[]  = $newConn['uuid'];
            $added++;
        }
    }

    $filteredGroup = [];
    foreach ($groupConns as $conn) {
        if (in_array($conn['uuid'] ?? '', $usedUuids, true)) {
            $filteredGroup[] = $conn;
        } else {
            $removed++;
        }
    }

    $allConns = array_merge($otherConns, $filteredGroup);

    xray_save_connections($allConns);

    return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
}

