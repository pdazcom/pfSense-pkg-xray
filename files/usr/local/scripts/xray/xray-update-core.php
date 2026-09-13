<?php

declare(strict_types=1);

require_once('/usr/local/pkg/xray/includes/xray.inc');

const XRAY_CORE_BIN = '/usr/local/bin/xray-core';
const XRAY_CORE_VERSION_FILE = '/usr/local/etc/xray-core/version.txt';
const XRAY_CORE_CONF_DIR = '/usr/local/etc/xray-core';
const XRAY_SERVICE_CONTROL = '/usr/local/scripts/xray/xray-service-control.php';

function xray_update_run(string $command, array &$output): int
{
    exec($command . ' 2>&1', $output, $rc);
    return $rc;
}

function xray_update_latest_version(array &$output): ?string
{
    $json = [];
    if (xray_update_run('/usr/bin/fetch -q -o - https://api.github.com/repos/XTLS/Xray-core/releases/latest', $json) !== 0) {
        return null;
    }
    $release = json_decode(implode("\n", $json), true);
    $tag = is_array($release) ? (string)($release['tag_name'] ?? '') : '';
    return preg_match('/^v?([0-9]+(?:\.[0-9]+){2})$/', $tag, $m) ? $m[1] : null;
}

function xray_update_arch(): ?string
{
    $arch = trim((string)shell_exec('/usr/bin/uname -m'));
    return ['amd64' => '64', 'aarch64' => 'arm64-v8a'][$arch] ?? null;
}

$output = [];
$version = xray_update_latest_version($output);
$arch = xray_update_arch();
if ($version === null || $arch === null) {
    $output[] = 'Could not determine the latest Xray version or system architecture.';
    echo implode("\n", $output) . "\n";
    exit(1);
}

$current = is_file(XRAY_CORE_VERSION_FILE) ? trim((string)file_get_contents(XRAY_CORE_VERSION_FILE)) : '';
if ($current === $version) {
    echo 'Xray core is already up to date (' . $version . ').' . "\n";
    exit(0);
}

$tmp = '/tmp/xray-core-update-' . getmypid();
$backup = XRAY_CORE_BIN . '.previous';
$running = [];
$swapped = false;
if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
    echo "Unable to create temporary directory.\n";
    exit(1);
}

try {
    $archive = $tmp . '/xray.zip';
    $unpacked = $tmp . '/unpacked';
    $url = 'https://github.com/XTLS/Xray-core/releases/download/v' . $version . '/Xray-freebsd-' . $arch . '.zip';
    if (xray_update_run('/usr/bin/fetch -q -o ' . escapeshellarg($archive) . ' ' . escapeshellarg($url), $output) !== 0
        || xray_update_run('/usr/bin/unzip -q ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($unpacked), $output) !== 0
        || !is_file($unpacked . '/xray')) {
        throw new RuntimeException('Download or extraction of Xray core failed.');
    }
    $candidate = $unpacked . '/xray';
    if (xray_update_run(escapeshellarg($candidate) . ' version', $output) !== 0) {
        throw new RuntimeException('Downloaded Xray binary cannot run.');
    }

    foreach (glob(XRAY_CORE_CONF_DIR . '/config-*.json') ?: [] as $config) {
        if (xray_update_run(escapeshellarg($candidate) . ' -test -c ' . escapeshellarg($config), $output) !== 0) {
            throw new RuntimeException('New Xray core rejected ' . basename($config) . '.');
        }
    }

    $status = [];
    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_SERVICE_CONTROL) . ' statusall 2>/dev/null', $status);
    foreach (json_decode(implode('', $status), true) ?: [] as $uuid => $item) {
        if (($item['xray_core'] ?? '') === 'running' && xray_sanitize_uuid((string)$uuid) !== '') {
            $running[] = $uuid;
        }
    }
    foreach ($running as $uuid) {
        xray_update_run('/usr/local/bin/php ' . escapeshellarg(XRAY_SERVICE_CONTROL) . ' stop ' . escapeshellarg($uuid), $output);
    }

    @unlink($backup);
    if (!@rename(XRAY_CORE_BIN, $backup) || !@rename($candidate, XRAY_CORE_BIN)) {
        if (is_file($backup)) {
            @rename($backup, XRAY_CORE_BIN);
        }
        throw new RuntimeException('Could not install the new Xray binary.');
    }
    $swapped = true;
    chmod(XRAY_CORE_BIN, 0755);
    file_put_contents(XRAY_CORE_VERSION_FILE, $version . "\n");
    foreach ($running as $uuid) {
        if (xray_update_run('/usr/local/bin/php ' . escapeshellarg(XRAY_SERVICE_CONTROL) . ' start ' . escapeshellarg($uuid), $output) !== 0) {
            throw new RuntimeException('Xray core was updated, but instance ' . $uuid . ' did not restart.');
        }
    }
    echo 'Updated Xray core from ' . ($current ?: 'unknown') . ' to ' . $version . '.' . "\n";
    exit(0);
} catch (Throwable $e) {
    if ($swapped && is_file($backup)) {
        @unlink(XRAY_CORE_BIN);
        @rename($backup, XRAY_CORE_BIN);
        if ($current !== '') {
            file_put_contents(XRAY_CORE_VERSION_FILE, $current . "\n");
        }
        foreach ($running as $uuid) {
            xray_update_run('/usr/local/bin/php ' . escapeshellarg(XRAY_SERVICE_CONTROL) . ' start ' . escapeshellarg($uuid), $output);
        }
        $output[] = 'Previous Xray core was restored.';
    }
    $output[] = 'ERROR: ' . $e->getMessage();
    echo implode("\n", $output) . "\n";
    exit(1);
} finally {
    exec('/bin/rm -rf ' . escapeshellarg($tmp));
}
