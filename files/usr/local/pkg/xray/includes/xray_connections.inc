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
