#!/usr/local/bin/php
<?php

/**
 * xray-service-control.php — Process management for Xray VPN instances.
 */

set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear' . PATH_SEPARATOR . get_include_path());
require_once('globals.inc');
require_once('config.inc');
require_once('config.lib.inc');
require_once('/usr/local/pkg/xray/includes/xray_connections.inc');

// ─── Shared constants ─────────────────────────────────────────────────────────
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('T2S_BIN',           '/usr/local/tun2socks/tun2socks');
define('T2S_CONF_DIR',      '/usr/local/tun2socks');
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log');
define('XRAY_VERSION_FILE', '/usr/local/etc/xray-core/version.txt');
if (!defined('XRAY_DEFAULT_GROUP_UUID')) {
    define('XRAY_DEFAULT_GROUP_UUID', '00000000-0000-4000-8000-000000000001');
}
define('XRAY_ROTATION_SCRIPT', '/usr/local/scripts/xray/xray-rotation.php');

// ─── Per-instance path functions ─────────────────────────────────────────────
function xray_conf_path(string $inst_uuid): string
{
    return XRAY_CONF_DIR . "/config-{$inst_uuid}.json";
}

function xray_pid_path(string $inst_uuid): string
{
    return "/var/run/xray_core_{$inst_uuid}.pid";
}

function t2s_conf_path(string $inst_uuid): string
{
    return T2S_CONF_DIR . "/config-{$inst_uuid}.yaml";
}

function t2s_pid_path(string $inst_uuid): string
{
    return "/var/run/tun2socks_{$inst_uuid}.pid";
}

function xray_lock_path(string $inst_uuid): string
{
    return "/var/run/xray_start_{$inst_uuid}.lock";
}

function xray_stopped_flag(string $inst_uuid): string
{
    return "/var/run/xray_stopped_{$inst_uuid}.flag";
}

// ─── Read config from pfSense $config array ──────────────────────────────────

function xray_resolve_connection_for_instance(array $inst): ?array
{
    $mode = $inst['connection_mode'] ?? 'fixed';

    if ($mode === 'fixed') {
        $connUuid = $inst['connection_uuid'] ?? '';
        if ($connUuid === '') {
            return null;
        }
        return xray_get_connection_by_uuid($connUuid);
    }

    $activeUuid = $inst['active_connection_uuid'] ?? '';
    if ($activeUuid !== '') {
        $conn = xray_get_connection_by_uuid($activeUuid);
        if ($conn !== null) {
            return $conn;
        }
    }

    $groupUuid = $inst['connection_group_uuid'] ?? '';
    if ($groupUuid === '') {
        return null;
    }
    $groupConns = xray_get_connections_by_group($groupUuid);
    return $groupConns[0] ?? null;
}

function xray_parse_instance_array(array $inst, array $conn, bool $globalEnabled): array
{
    $rawLevel = $inst['loglevel'] ?? 'warning';
    $levelMap = [
        'e'              => 'error',
        'loglevel_error' => 'error',
    ];
    $loglevel = $levelMap[$rawLevel] ?? ($rawLevel ?: 'warning');

    return [
        'enabled'                => $globalEnabled,
        'name'                   => $inst['name']                ?? 'default',
        'server'                 => $conn['server_address']      ?? '',
        'port'                   => (int)($conn['server_port']   ?? 443),
        'vless_uuid'             => $conn['vless_uuid']          ?? '',
        'flow'                   => $conn['flow']                ?? 'xtls-rprx-vision',
        'sni'                    => $conn['reality_sni']         ?? '',
        'pubkey'                 => $conn['reality_pubkey']      ?? '',
        'shortid'                => $conn['reality_shortid']     ?? '',
        'fingerprint'            => $conn['reality_fingerprint'] ?? 'chrome',
        'config_mode'            => ($conn['config_mode'] ?? 'wizard') ?: 'wizard',
        'custom_config'          => $conn['custom_config'] ?? '',
        'socks5_listen'          => ($inst['socks5_listen'] ?? '127.0.0.1') ?: '127.0.0.1',
        'socks5_port'            => (int)($inst['socks5_port'] ?? 10808) ?: 10808,
        'tun_iface'              => $inst['tun_interface']       ?? 'proxytun0',
        'mtu'                    => (int)($inst['mtu'] ?? 1500),
        'loglevel'               => $loglevel,
        'bypass_networks'        => ($inst['bypass_networks'] ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
                                    ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        'connection_mode'        => $inst['connection_mode'] ?? 'fixed',
        'connection_group_uuid'  => $inst['connection_group_uuid'] ?? '',
        'webhook_url'            => $inst['webhook_url'] ?? '',
        'connection_uuid'        => $conn['uuid'] ?? '',
    ];
}

function xray_get_all_instances(): array
{
    $globalCfg     = config_get_path('installedpackages/xray/config/0', []);
    $globalEnabled = isset($globalCfg['enabled']) && $globalCfg['enabled'] === 'on';
    $instancesCfg  = config_get_path('installedpackages/xrayinstances/config', []);

    $result = [];
    foreach ($instancesCfg as $inst) {
        $inst_uuid = $inst['uuid'] ?? '';
        if ($inst_uuid === '') {
            continue;
        }
        $conn = xray_resolve_connection_for_instance($inst);
        if ($conn === null) {
            continue;
        }
        $c = xray_parse_instance_array($inst, $conn, $globalEnabled);
        $c['inst_uuid'] = $inst_uuid;
        $result[$inst_uuid] = $c;
    }
    return $result;
}

function xray_get_config(string $inst_uuid = ''): array
{
    $all = xray_get_all_instances();
    if (empty($all)) {
        return [];
    }
    if ($inst_uuid !== '' && isset($all[$inst_uuid])) {
        return $all[$inst_uuid];
    }
    return reset($all);
}

// ─── Build xray config array ─────────────────────────────────────────────────
function xray_build_config_array(array $c): array
{
    $flow = ($c['flow'] === 'none' || $c['flow'] === '') ? '' : $c['flow'];

    $bypassRaw  = $c['bypass_networks'] ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
    $bypassNets = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }

    return [
        'log'      => ['loglevel' => $c['loglevel'] ?: 'warning'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $c['socks5_port'],
            'listen'   => $c['socks5_listen'],
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $c['socks5_listen']],
        ]],
        'outbounds' => [
            [
                'tag'      => 'proxy',
                'protocol' => 'vless',
                'settings' => [
                    'vnext' => [[
                        'address' => $c['server'],
                        'port'    => $c['port'],
                        'users'   => [[
                            'id'         => $c['vless_uuid'],
                            'encryption' => 'none',
                            'flow'       => $flow,
                        ]],
                    ]],
                ],
                'streamSettings' => [
                    'network'         => 'tcp',
                    'security'        => 'reality',
                    'realitySettings' => [
                        'serverName'  => $c['sni'],
                        'fingerprint' => $c['fingerprint'],
                        'show'        => false,
                        'publicKey'   => $c['pubkey'],
                        'shortId'     => $c['shortid'],
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
                'ip'          => $bypassNets,
                'outboundTag' => 'direct',
            ]],
        ],
    ];
}

function xray_normalize_transport(string $json): string
{
    if (!file_exists(XRAY_BIN)) {
        return $json;
    }
    exec(escapeshellarg(XRAY_BIN) . ' version 2>/dev/null', $out);
    $verLine = $out[0] ?? '';
    if (preg_match('/Xray\s+1\./', $verLine)) {
        $json = str_replace('"xhttp"', '"splithttp"', $json);
        $json = str_replace('"xhttpSettings"', '"splithttpSettings"', $json);
    }
    return $json;
}

// ─── Write configs ────────────────────────────────────────────────────────────
function xray_write_config(array $c): void
{
    if (!is_dir(XRAY_CONF_DIR)) {
        mkdir(XRAY_CONF_DIR, 0750, true);
    }

    $inst_uuid = $c['inst_uuid'];
    $confFile  = xray_conf_path($inst_uuid);

    if (($c['config_mode'] ?? 'wizard') === 'custom') {
        $raw = trim($c['custom_config'] ?? '');
        if ($raw === '') {
            echo "ERROR: custom_config is empty\n";
            return;
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            echo "ERROR: custom_config is not valid JSON\n";
            return;
        }
        $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = xray_normalize_transport($json);
    } else {
        $cfg  = xray_build_config_array($c);
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    file_put_contents($confFile, $json);
    chmod($confFile, 0640);
}

function t2s_write_config(array $c): void
{
    if (!is_dir(T2S_CONF_DIR)) {
        mkdir(T2S_CONF_DIR, 0750, true);
    }
    $inst_uuid = $c['inst_uuid'];
    $yaml = "proxy: socks5://{$c['socks5_listen']}:{$c['socks5_port']}\n"
          . "device: {$c['tun_iface']}\n"
          . "mtu: {$c['mtu']}\n"
          . "loglevel: info\n";
    file_put_contents(t2s_conf_path($inst_uuid), $yaml);
    chmod(t2s_conf_path($inst_uuid), 0640);
}

// ─── PID helpers ─────────────────────────────────────────────────────────────
function proc_is_running(string $pidfile): bool
{
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function proc_kill(string $pidfile): void
{
    if (!file_exists($pidfile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid > 0) {
        $comm = trim((string)shell_exec('ps -o comm= -p ' . $pid . ' 2>/dev/null'));
        if ($comm === '' || (strpos($comm, 'xray') === false && strpos($comm, 'tun2socks') === false)) {
            @unlink($pidfile);
            return;
        }

        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile): void
{
    $log = escapeshellarg(XRAY_DAEMON_LOG);
    exec('/usr/sbin/daemon -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' >> ' . $log . ' 2>&1 &');
}

// ─── Per-instance lock helpers ────────────────────────────────────────────────
/**
 * @return resource|false
 */
function lock_acquire(string $inst_uuid)
{
    $lockPath = xray_lock_path($inst_uuid);
    $fd = fopen($lockPath, 'c');
    if ($fd === false) {
        return false;
    }
    if (!flock($fd, LOCK_EX | LOCK_NB)) {
        fclose($fd);
        return false;
    }
    fwrite($fd, (string)getmypid());
    fflush($fd);
    return $fd;
}

/**
 * @param resource $fd
 */
function lock_release($fd, string $inst_uuid): void
{
    flock($fd, LOCK_UN);
    fclose($fd);
    @unlink(xray_lock_path($inst_uuid));
}

// ─── Config validation ────────────────────────────────────────────────────────
function xray_validate_config(string $confFile): bool
{
    if (!file_exists(XRAY_BIN)) {
        return true;
    }
    if (!file_exists($confFile)) {
        echo "ERROR: config file not found: {$confFile}\n";
        return false;
    }
    exec(escapeshellarg(XRAY_BIN) . ' -test -c ' . escapeshellarg($confFile) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "ERROR: xray config validation failed:\n" . implode("\n", $out) . "\n";
        return false;
    }
    return true;
}

// ─── lo0 alias management ─────────────────────────────────────────────────────
function lo0_needs_alias(string $addr): bool
{
    if ($addr === '127.0.0.1' || $addr === '0.0.0.0') {
        return false;
    }
    $parts = explode('.', $addr);
    return count($parts) === 4 && $parts[0] === '127';
}

function lo0_alias_ensure(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        echo "WARNING: Cannot read lo0 interface\n";
        return;
    }
    $ifOutput = implode("\n", $out);
    if (strpos($ifOutput, $addr) !== false) {
        return;
    }
    exec('/sbin/ifconfig lo0 alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out2, $rc2);
    if ($rc2 !== 0) {
        echo "WARNING: Failed to add lo0 alias {$addr}\n";
    } else {
        echo "INFO: Added lo0 alias {$addr}\n";
    }
}

function lo0_alias_remove(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 -alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) {
        echo "INFO: Removed lo0 alias {$addr}\n";
    }
}

// ─── TUN interface setup ──────────────────────────────────────────────────────
function xray_configure_tun(array $c): void
{
    $iface = $c['tun_iface'];
    $mtu   = (int)$c['mtu'];

    for ($i = 0; $i < 10; $i++) {
        exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $o, $rc);
        if ($rc === 0) {
            break;
        }
        sleep(1);
    }

    if ($rc !== 0) {
        echo "WARNING: TUN interface {$iface} did not appear after 10s\n";
        return;
    }

    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' mtu ' . $mtu . ' up 2>/dev/null');

    $ip = xray_get_tun_ip_from_pfsense_config($iface);
    if ($ip !== '') {
        exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' inet ' . escapeshellarg($ip) . ' 2>/dev/null');
    }

    echo "INFO: TUN interface {$iface} configured\n";
}

function xray_get_tun_ip_from_pfsense_config(string $ifname): string
{
    $interfaces = config_get_path('interfaces', []);
    foreach ($interfaces as $iface) {
        if (($iface['if'] ?? '') === $ifname && isset($iface['ipaddr'])) {
            $ip     = $iface['ipaddr'];
            $subnet = $iface['subnet'] ?? '';
            return $subnet !== '' ? "{$ip}/{$subnet}" : $ip;
        }
    }
    return '';
}

// ─── TUN teardown ─────────────────────────────────────────────────────────────
function tun_destroy(string $iface): void
{
    if (empty($iface)) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' destroy 2>/dev/null');
}

// ─── High-level per-instance actions ─────────────────────────────────────────
function do_stop(string $inst_uuid, ?string $tunIface = null): void
{
    if ($tunIface === null) {
        $c        = xray_get_config($inst_uuid);
        $tunIface = $c['tun_iface'] ?? 'proxytun0';
    }

    proc_kill(t2s_pid_path($inst_uuid));
    proc_kill(xray_pid_path($inst_uuid));

    $c2 = xray_get_config($inst_uuid);
    lo0_alias_remove($c2['socks5_listen'] ?? '127.0.0.1');

    file_put_contents(xray_stopped_flag($inst_uuid), date('Y-m-d H:i:s'));

    echo "Stopped.\n";
}

function do_start(array $c): bool
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core not found at " . XRAY_BIN . "\n";
        return false;
    }
    if (!file_exists(T2S_BIN)) {
        echo "ERROR: tun2socks not found at " . T2S_BIN . "\n";
        return false;
    }

    $inst_uuid = $c['inst_uuid'];

    if (($c['connection_mode'] ?? 'fixed') === 'rotation') {
        if (file_exists(XRAY_ROTATION_SCRIPT)) {
            $rotOut = [];
            exec('/usr/local/bin/php ' . escapeshellarg(XRAY_ROTATION_SCRIPT) . ' ' . escapeshellarg($inst_uuid) . ' 2>&1', $rotOut, $rotRc);
            $rotJson = trim(implode('', $rotOut));
            $rotData = json_decode($rotJson, true);
            if ($rotData === null || ($rotData['status'] ?? '') !== 'ok') {
                echo "ERROR: Rotation found no working connection for instance {$inst_uuid}.\n";
                return false;
            }
            $winnerUuid = $rotData['connection_uuid'] ?? '';
            if ($winnerUuid !== '') {
                $instancesCfg = config_get_path('installedpackages/xrayinstances/config', []);
                foreach ($instancesCfg as $idx => $inst) {
                    if (($inst['uuid'] ?? '') === $inst_uuid) {
                        config_set_path(
                            'installedpackages/xrayinstances/config/' . $idx . '/active_connection_uuid',
                            $winnerUuid
                        );
                        break;
                    }
                }
                write_config('Xray: rotation selected connection ' . $winnerUuid . ' for instance ' . $inst_uuid);
            }
            $newConn = xray_get_connection_by_uuid($winnerUuid);
            if ($newConn !== null) {
                $globalCfg     = config_get_path('installedpackages/xray/config/0', []);
                $globalEnabled = isset($globalCfg['enabled']) && $globalCfg['enabled'] === 'on';
                $instRaw = null;
                $instancesCfg2 = config_get_path('installedpackages/xrayinstances/config', []);
                foreach ($instancesCfg2 as $inst) {
                    if (($inst['uuid'] ?? '') === $inst_uuid) {
                        $instRaw = $inst;
                        break;
                    }
                }
                if ($instRaw !== null) {
                    $c = xray_parse_instance_array($instRaw, $newConn, $globalEnabled);
                    $c['inst_uuid'] = $inst_uuid;
                }
            }
        }
    }

    $lock = lock_acquire($inst_uuid);
    if ($lock === false) {
        echo "INFO: Another start is already in progress for instance {$inst_uuid}. Skipping.\n";
        return true;
    }

    try {
        @unlink(xray_stopped_flag($inst_uuid));

        xray_write_config($c);
        t2s_write_config($c);

        lo0_alias_ensure($c['socks5_listen']);

        if (!xray_validate_config(xray_conf_path($inst_uuid))) {
            return false;
        }

        if (!proc_is_running(xray_pid_path($inst_uuid))) {
            proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(xray_conf_path($inst_uuid)), xray_pid_path($inst_uuid));
            usleep(800000);
        }
        if (!proc_is_running(t2s_pid_path($inst_uuid))) {
            proc_start(T2S_BIN, '-config ' . escapeshellarg(t2s_conf_path($inst_uuid)), t2s_pid_path($inst_uuid));
            usleep(800000);
        }

        xray_configure_tun($c);

        echo "Started.\n";
        return true;
    } finally {
        lock_release($lock, $inst_uuid);
    }
}

function do_status(string $inst_uuid = ''): void
{
    if ($inst_uuid !== '') {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $t2s  = proc_is_running(t2s_pid_path($inst_uuid));
        echo json_encode([
            'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'tun2socks' => $t2s  ? 'running' : 'stopped',
            'inst_uuid' => $inst_uuid,
        ]) . "\n";
        return;
    }

    $all = xray_get_all_instances();
    if (empty($all)) {
        echo json_encode(['status' => 'stopped', 'xray_core' => 'stopped', 'tun2socks' => 'stopped']) . "\n";
        return;
    }
    $first = reset($all);
    $uuid0 = $first['inst_uuid'];
    $xray  = proc_is_running(xray_pid_path($uuid0));
    $t2s   = proc_is_running(t2s_pid_path($uuid0));
    echo json_encode([
        'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
        'xray_core' => $xray ? 'running' : 'stopped',
        'tun2socks' => $t2s  ? 'running' : 'stopped',
    ]) . "\n";
}

function do_status_all(): void
{
    $all    = xray_get_all_instances();
    $result = [];
    foreach ($all as $inst_uuid => $c) {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $t2s  = proc_is_running(t2s_pid_path($inst_uuid));
        $result[$inst_uuid] = [
            'name'      => $c['name'],
            'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'tun2socks' => $t2s  ? 'running' : 'stopped',
        ];
    }
    echo json_encode($result) . "\n";
}

// ─── Main ─────────────────────────────────────────────────────────────────────
$action    = $argv[1] ?? 'status';
$inst_uuid = isset($argv[2]) ? trim($argv[2]) : '';

if ($inst_uuid !== '') {
    $inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
    if (strlen($inst_uuid) < 36) {
        $inst_uuid = '';
    }
}

switch ($action) {
    case 'start':
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
                exit(0);
            }
            $ok = do_start($c);
            exit($ok ? 0 : 1);
        }
        $all = xray_get_all_instances();
        if (empty($all)) {
            echo "No instances configured.\n";
            exit(0);
        }
        $anyFailed = false;
        foreach ($all as $uuid => $c) {
            if (!$c['enabled']) {
                continue;
            }
            if (!do_start($c)) {
                $anyFailed = true;
            }
        }
        exit($anyFailed ? 1 : 0);

    case 'stop':
        if ($inst_uuid !== '') {
            do_stop($inst_uuid);
        } else {
            foreach (array_keys(xray_get_all_instances()) as $uuid) {
                do_stop($uuid);
            }
        }
        break;

    case 'restart':
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                do_start($c);
            }
        } else {
            $all = xray_get_all_instances();
            foreach ($all as $uuid => $c) {
                do_stop($uuid, $c['tun_iface'] ?? 'proxytun0');
            }
            sleep(1);
            foreach ($all as $uuid => $c) {
                if ($c['enabled']) {
                    do_start($c);
                }
            }
        }
        break;

    case 'reconfigure':
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                $ok = do_start($c);
                if ($ok) {
                    echo "OK\n";
                    exit(0);
                } else {
                    echo "ERROR: Failed to start Xray services for instance {$inst_uuid}.\n";
                    exit(1);
                }
            } else {
                echo "Xray disabled — services stopped.\n";
                exit(0);
            }
        }
        $all       = xray_get_all_instances();
        $allStopped = [];
        foreach ($all as $uuid => $c) {
            do_stop($uuid, $c['tun_iface'] ?? 'proxytun0');
            $allStopped[$uuid] = $c;
        }
        sleep(1);
        $anyFailed = false;
        foreach ($allStopped as $uuid => $c) {
            if ($c['enabled']) {
                if (!do_start($c)) {
                    $anyFailed = true;
                }
            }
        }
        if ($anyFailed) {
            echo "ERROR: One or more instances failed to start.\n";
            exit(1);
        }
        echo "OK\n";
        exit(0);

    case 'status':
        do_status($inst_uuid);
        break;

    case 'statusall':
        do_status_all();
        break;

    case 'validate':
        $c = $inst_uuid !== '' ? xray_get_config($inst_uuid) : xray_get_config();
        if (empty($c)) {
            echo "ERROR: No xray config found\n";
            exit(1);
        }
        $tmpBase = tempnam('/tmp', 'xray-validate-');
        if ($tmpBase === false) {
            echo "ERROR: Cannot create temp file for validation\n";
            exit(1);
        }
        $tmpConf = $tmpBase . '.json';
        @unlink($tmpBase);
        try {
            if (($c['config_mode'] ?? 'wizard') === 'custom') {
                $raw = trim($c['custom_config'] ?? '');
                if ($raw === '') {
                    echo "ERROR: custom_config is empty\n";
                    exit(1);
                }
                $decoded = json_decode($raw, true);
                if ($decoded === null) {
                    echo "ERROR: custom_config is not valid JSON\n";
                    exit(1);
                }
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $json = xray_normalize_transport($json);
            } else {
                $json = json_encode(
                    xray_build_config_array($c),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            }
            file_put_contents($tmpConf, $json);
            chmod($tmpConf, 0600);
            if (xray_validate_config($tmpConf)) {
                echo "OK: config is valid\n";
                exit(0);
            } else {
                exit(1);
            }
        } finally {
            @unlink($tmpConf);
        }

    case 'version':
        $ver = file_exists(XRAY_VERSION_FILE) ? trim(file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    default:
        echo "Unknown action: {$action}\n";
        exit(1);
}
