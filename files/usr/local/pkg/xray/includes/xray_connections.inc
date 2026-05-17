<?php

/**
 * xray_connections.inc — Connection CRUD backed by a JSON file.
 *
 * Kept in a separate include with no heavy pfSense dependencies so it can be
 * safely required from CLI scripts (xray-service-control.php, xray-ifstats.php,
 * xray-urltest.php, xray-rotation.php) without pulling in interfaces.inc /
 * filter.inc which are not available in all CLI contexts.
 */

if (!defined('XRAY_CONNECTIONS_FILE')) {
    define('XRAY_CONNECTIONS_FILE', '/usr/local/etc/xray-core/connections.json');
}

if (!defined('XRAY_DEFAULT_GROUP_UUID')) {
    define('XRAY_DEFAULT_GROUP_UUID', '00000000-0000-4000-8000-000000000001');
}

function xray_get_connections(): array
{
    if (!file_exists(XRAY_CONNECTIONS_FILE)) {
        return [];
    }
    $raw  = file_get_contents(XRAY_CONNECTIONS_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function xray_save_connections(array $connections): void
{
    $dir = dirname(XRAY_CONNECTIONS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        XRAY_CONNECTIONS_FILE,
        json_encode(array_values($connections), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function xray_get_connection_by_uuid(string $uuid): ?array
{
    foreach (xray_get_connections() as $conn) {
        if (($conn['uuid'] ?? '') === $uuid) {
            return $conn;
        }
    }
    return null;
}

function xray_get_connections_by_group(string $group_uuid): array
{
    $result = [];
    foreach (xray_get_connections() as $conn) {
        if (($conn['group_uuid'] ?? '') === $group_uuid) {
            $result[] = $conn;
        }
    }
    return $result;
}

function xray_fetch_subscription_raw(string $url, array $hapHeaders = []): string|false
{
    $curlBin = file_exists('/usr/local/bin/curl') ? '/usr/local/bin/curl' : '/usr/bin/curl';
    $ua  = !empty($hapHeaders['happ_ua']) ? $hapHeaders['happ_ua'] : 'xray-pfsense/1.0';
    $cmd = $curlBin . ' -s -L --compressed --max-time 30 -A ' . escapeshellarg($ua);

    foreach ([
        'hwid'           => 'X-Hwid',
        'happ_device_os' => 'X-Device-Os',
        'happ_locale'    => 'X-Device-Locale',
        'happ_model'     => 'X-Device-Model',
        'happ_os_ver'    => 'X-Ver-Os',
    ] as $key => $header) {
        if (!empty($hapHeaders[$key])) {
            $cmd .= ' -H ' . escapeshellarg($header . ': ' . $hapHeaders[$key]);
        }
    }

    $cmd .= ' ' . escapeshellarg($url) . ' 2>/dev/null';

    $out = [];
    exec($cmd, $out, $rc);

    if ($rc !== 0 || empty($out)) {
        return false;
    }

    return implode("\n", $out);
}

function xray_update_subscription_group(array $group): array
{
    require_once('/usr/local/pkg/xray/includes/xray_vless.inc');

    $groupUuid  = $group['uuid'] ?? '';
    $isHappMode = ($group['happ_enabled'] ?? '') === 'on';
    $hapHeaders = $isHappMode
        ? array_intersect_key($group, array_flip(['hwid', 'happ_ua', 'happ_device_os', 'happ_model', 'happ_locale', 'happ_os_ver']))
        : [];

    $urls = [];
    if (!empty($group['sub_urls'])) {
        $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $group['sub_urls']))));
    } elseif (!empty($group['sub_url'])) {
        $urls = [trim($group['sub_url'])];
    }

    if (empty($urls)) {
        return ['error' => 'Subscription URL is empty'];
    }

    $parsedConns = [];
    $seenKeys    = [];

    foreach ($urls as $url) {
        $raw = xray_fetch_subscription_raw($url, $hapHeaders);
        if ($raw === false) {
            return ['error' => 'Failed to fetch subscription URL: ' . $url];
        }

        if ($isHappMode) {
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json[0]) && is_array($json[0])) {
                foreach ($json as $entry) {
                    $conn = xray_happ_parse_entry($entry);
                    if ($conn === null) {
                        continue;
                    }
                    $key = md5($conn['server_address'] . ':' . $conn['server_port'] . ':' . $conn['vless_uuid']);
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $parsedConns[]  = $conn;
                    }
                }
                continue;
            }
        }

        $decoded = base64_decode(trim($raw), true);
        if ($decoded !== false && strpos($decoded, '://') !== false) {
            $raw = $decoded;
        }
        foreach (array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw))) as $line) {
            $scheme = strtolower(substr($line, 0, strpos($line . ':', ':')));
            if (!in_array($scheme, ['vless', 'vmess', 'trojan', 'ss'], true)) {
                continue;
            }
            $key = md5($line);
            if (isset($seenKeys[$key])) {
                continue;
            }
            $data = xray_parse_vless_link($line);
            if (isset($data['error'])) {
                continue;
            }
            $seenKeys[$key] = true;
            $addr = $data['server_address'] ?? ($data['host'] ?? '');
            $port = isset($data['server_port']) ? (string)$data['server_port'] : (string)($data['port'] ?? '');
            $parsedConns[]  = [
                'server_address' => $addr,
                'server_port'    => $port,
                'vless_uuid'     => $data['vless_uuid'] ?? '',
                'flow'           => $data['flow'] ?? '',
                'config_mode'    => 'custom',
                'custom_config'  => $data['custom_config'] ?? '',
                'name'           => ($data['name'] ?? '') !== '' ? $data['name'] : ($addr . ':' . $port),
            ];
        }
    }

    if (empty($parsedConns)) {
        return ['error' => $isHappMode
            ? 'No valid entries found in Happ subscription'
            : 'No valid proxy links found in subscription'];
    }

    $groupConns = [];
    $otherConns = [];
    foreach (xray_get_connections() as $conn) {
        if (($conn['group_uuid'] ?? '') === $groupUuid) {
            $groupConns[] = $conn;
        } else {
            $otherConns[] = $conn;
        }
    }

    $added     = 0;
    $updated   = 0;
    $removed   = 0;
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
                'uuid'        => xray_generate_connection_uuid(),
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

    xray_save_connections(array_merge($otherConns, $filteredGroup));

    return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
}

function xray_generate_connection_uuid(): string
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

function xray_save_connection(array $connection): void
{
    $connections = xray_get_connections();
    $uuid        = $connection['uuid'] ?? '';
    $found       = false;

    foreach ($connections as $idx => $conn) {
        if (($conn['uuid'] ?? '') === $uuid) {
            $connections[$idx] = $connection;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $connections[] = $connection;
    }

    xray_save_connections($connections);
}
