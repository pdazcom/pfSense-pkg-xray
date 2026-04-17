#!/usr/local/bin/php
<?php

/**
 * xray-ifstats.php — TUN interface and process statistics for the Diagnostics page.
 *
 * Usage: xray-ifstats.php [uuid]
 * Output: JSON
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');

$inst_uuid = isset($argv[1]) ? trim($argv[1]) : '';
$inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
if (strlen($inst_uuid) < 36) {
    $inst_uuid = '';
}

// ─── Find instance config ─────────────────────────────────────────────────────
$instancesCfg = config_get_path('installedpackages/xrayinstances/config', []);
$inst = null;

if ($inst_uuid !== '') {
    foreach ($instancesCfg as $i) {
        if (($i['uuid'] ?? '') === $inst_uuid) {
            $inst      = $i;
            break;
        }
    }
}

if ($inst === null && !empty($instancesCfg)) {
    $inst      = $instancesCfg[0];
    $inst_uuid = $inst['uuid'] ?? '';
}

if ($inst === null) {
    echo json_encode(['error' => 'No instances configured']) . "\n";
    exit(1);
}

$tunIface  = $inst['tun_interface'] ?? 'proxytun0';
$xrayPid   = "/var/run/xray_core_{$inst_uuid}.pid";
$t2sPid    = "/var/run/tunnel_{$inst_uuid}.pid";

$serverLabel = '';
$serverHost  = '';
$connUuid    = $inst['connection_uuid'] ?? ($inst['active_connection_uuid'] ?? '');
if ($connUuid !== '') {
    $conn = xray_get_connection_by_uuid($connUuid);
    if ($conn !== null) {
        [$serverHost, $serverLabel] = ifstats_server_parts($conn);
    }
}
if ($serverLabel === '') {
    $groupUuid = $inst['connection_group_uuid'] ?? '';
    if ($groupUuid !== '') {
        $groupConns = xray_get_connections_by_group($groupUuid);
        if (!empty($groupConns)) {
            [$serverHost, $serverLabel] = ifstats_server_parts($groupConns[0]);
        }
    }
}

function ifstats_server_parts(array $conn): array
{
    $json = trim($conn['custom_config'] ?? '');
    if ($json !== '') {
        $decoded = json_decode($json, true);
        $address = $decoded['outbounds'][0]['settings']['vnext'][0]['address'] ?? '';
        $port    = $decoded['outbounds'][0]['settings']['vnext'][0]['port']    ?? '';
        if ($address !== '') {
            return [$address, $address . ':' . $port];
        }
    }
    $addr = trim($conn['server_address'] ?? '');
    if ($addr !== '') {
        return [$addr, $addr . ':' . ($conn['server_port'] ?? '443')];
    }
    return ['', ''];
}

// ─── Process uptime ───────────────────────────────────────────────────────────
function proc_uptime(string $pidfile): ?int
{
    if (!file_exists($pidfile)) {
        return null;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return null;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        return null;
    }
    $etime = trim((string)shell_exec('ps -o etime= -p ' . $pid . ' 2>/dev/null'));
    if (empty($etime)) {
        return null;
    }
    $parts = explode(':', strrev($etime));
    $secs  = (int)strrev($parts[0] ?? '0');
    $mins  = (int)strrev($parts[1] ?? '0');
    $hrs   = (int)strrev($parts[2] ?? '0');
    $days  = 0;
    if (isset($parts[3])) {
        $dayPart = strrev($parts[3]);
        $dashPos = strpos($dayPart, '-');
        if ($dashPos !== false) {
            $days = (int)substr($dayPart, 0, $dashPos);
            $hrs  = (int)substr($dayPart, $dashPos + 1);
        }
    }
    return $days * 86400 + $hrs * 3600 + $mins * 60 + $secs;
}

function xray_format_uptime(?int $secs): string
{
    if ($secs === null) {
        return 'stopped';
    }
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    if ($d > 0) {
        return "{$d}d {$h}h {$m}m";
    }
    if ($h > 0) {
        return "{$h}h {$m}m {$s}s";
    }
    return "{$m}m {$s}s";
}

function xray_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

// ─── TUN interface statistics via ifconfig / netstat ─────────────────────────
$ifOut = [];
exec('/sbin/ifconfig ' . escapeshellarg($tunIface) . ' 2>/dev/null', $ifOut, $ifRc);
$ifconfig = implode("\n", $ifOut);

$tunIp     = '';
$tunMask   = '';
$tunStatus = 'no carrier';
$bytesIn   = 0;
$bytesOut  = 0;
$pktsIn    = 0;
$pktsOut   = 0;
$mtu       = 0;

if ($ifRc === 0) {
    if (preg_match('/inet\s+(\S+)(?:\s+-->\s+\S+)?\s+netmask\s+(\S+)/', $ifconfig, $m)) {
        $tunIp  = $m[1];
        $hexMask = $m[2];
        if (str_starts_with($hexMask, '0x')) {
            $bits = 0;
            $dec  = hexdec(substr($hexMask, 2));
            for ($i = 31; $i >= 0; $i--) {
                if ($dec & (1 << $i)) {
                    $bits++;
                }
            }
            $tunMask = '/' . $bits;
        } else {
            $tunMask = '/' . $hexMask;
        }
    }
    if (preg_match('/flags=\S+<([^>]+)>/', $ifconfig, $m)) {
        $flags     = explode(',', strtolower($m[1]));
        $tunStatus = in_array('running', $flags, true) ? 'running' : 'down';
    }
    if (preg_match('/mtu\s+(\d+)/', $ifconfig, $m)) {
        $mtu = (int)$m[1];
    }

    $nsOut = [];
    exec('netstat -ibn -I ' . escapeshellarg($tunIface) . ' 2>/dev/null', $nsOut);
    foreach ($nsOut as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if ($parts[0] === $tunIface && isset($parts[2]) && strpos($parts[2], '<Link') === 0) {
            $ipktsIdx = null;
            for ($pi = 3; $pi < count($parts); $pi++) {
                if (ctype_digit($parts[$pi])) {
                    $ipktsIdx = $pi;
                    break;
                }
            }
            if ($ipktsIdx !== null) {
                $pktsIn   = (int)$parts[$ipktsIdx];
                $bytesIn  = (int)$parts[$ipktsIdx + 3];
                $pktsOut  = (int)$parts[$ipktsIdx + 4];
                $bytesOut = (int)$parts[$ipktsIdx + 6];
            }
            break;
        }
    }
}

// ─── Process uptime ───────────────────────────────────────────────────────────
$xrayUptimeSecs = proc_uptime($xrayPid);
$t2sUptimeSecs  = proc_uptime($t2sPid);

// ─── Ping RTT to VPN server ───────────────────────────────────────────────────
$pingRtt = 'N/A';
if ($serverHost !== '') {
    $pingBin = filter_var($serverHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '/sbin/ping6' : '/sbin/ping';
    exec($pingBin . ' -c 3 -W 2 ' . escapeshellarg($serverHost) . ' 2>/dev/null', $pingOut, $pingRc);
    if ($pingRc === 0) {
        $pingOutput = implode("\n", $pingOut);
        if (preg_match('/round-trip.+=\s*[\d.]+\/([\d.]+)\//', $pingOutput, $pm)) {
            $pingRtt = $pm[1] . ' ms';
        }
    }
}

// ─── Result ───────────────────────────────────────────────────────────────────
$result = [
    'inst_uuid'             => $inst_uuid,
    'tun_interface'         => $tunIface,
    'tun_status'            => $tunIface !== '' ? $tunStatus : 'no interface configured',
    'tun_ip'                => $tunIp ? ($tunIp . $tunMask) : '',
    'mtu'                   => $mtu,
    'bytes_in'              => $bytesIn,
    'bytes_out'             => $bytesOut,
    'pkts_in'               => $pktsIn,
    'pkts_out'              => $pktsOut,
    'bytes_in_hr'           => xray_format_bytes($bytesIn),
    'bytes_out_hr'          => xray_format_bytes($bytesOut),
    'xray_uptime'           => xray_format_uptime($xrayUptimeSecs),
    'xray_uptime_secs'      => $xrayUptimeSecs,
    'tun2socks_uptime'      => xray_format_uptime($t2sUptimeSecs),
    'tun2socks_uptime_secs' => $t2sUptimeSecs,
    'server_address'        => $serverLabel,
    'ping_rtt'              => $pingRtt,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
