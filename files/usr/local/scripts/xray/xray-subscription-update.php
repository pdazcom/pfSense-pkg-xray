#!/usr/local/bin/php
<?php

/**
 * xray-subscription-update.php — Fetch and sync a subscription group.
 *
 * Fetches the subscription URL, parses vless:// links (base64-encoded or plain),
 * then syncs connections: add new, update changed, remove absent ones.
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

$groupsCfg = config_get_path('installedpackages/xraygroups/config', []);
$group = null;
foreach ($groupsCfg as $g) {
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

$subUrls = [];
if (isset($group['sub_urls']) && $group['sub_urls'] !== '') {
    $subUrls = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $group['sub_urls']))));
} elseif (($group['sub_url'] ?? '') !== '') {
    $subUrls = [trim($group['sub_url'])];
}

if (empty($subUrls)) {
    echo json_encode(['error' => 'Subscription URL is empty']) . "\n";
    exit(1);
}

$curlBin = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';

$fetchedLinks = [];
$seenKeys     = [];

foreach ($subUrls as $subUrl) {
    $curlOut = [];
    exec(
        $curlBin . ' -s -L --max-time 30 -A "xray-pfsense/1.0"'
        . ' ' . escapeshellarg($subUrl)
        . ' 2>/dev/null',
        $curlOut,
        $curlRc
    );

    if ($curlRc !== 0 || empty($curlOut)) {
        echo json_encode(['error' => 'Failed to fetch subscription URL: ' . $subUrl]) . "\n";
        exit(1);
    }

    $raw = implode("\n", $curlOut);

    $decoded = base64_decode(trim($raw), true);
    if ($decoded !== false && strpos($decoded, 'vless://') !== false) {
        $raw = $decoded;
    }

    $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw)));
    foreach ($lines as $line) {
        if (strpos($line, 'vless://') === 0) {
            $key = md5($line);
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $fetchedLinks[] = $line;
            }
        }
    }
}

if (empty($fetchedLinks)) {
    echo json_encode(['error' => 'No vless:// links found in subscription']) . "\n";
    exit(1);
}

$groupConns = xray_get_connections_by_group($group_uuid);
$otherConns = [];
foreach (xray_get_connections() as $conn) {
    if (($conn['group_uuid'] ?? '') !== $group_uuid) {
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
    $matched = null;
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
            'uuid'       => subscription_generate_uuid(),
            'group_uuid' => $group_uuid,
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

echo json_encode(['added' => $added, 'updated' => $updated, 'removed' => $removed]) . "\n";

function subscription_generate_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
