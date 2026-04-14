<?php

/**
 * xray.inc — pfSense package include for Xray VPN
 *
 * Provides config read/write, TUN interface registration,
 * install/deinstall hooks, and resync logic.
 */

require_once('config.inc');
require_once('interfaces.inc');
require_once('filter.inc');
require_once('util.inc');

// ─── Config accessors ─────────────────────────────────────────────────────────

function xray_get_global_config(): array
{
    global $config;
    return $config['installedpackages']['xray']['config'][0] ?? [];
}

function xray_is_enabled(): bool
{
    $g = xray_get_global_config();
    return isset($g['enabled']) && $g['enabled'] === 'on';
}

function xray_get_instances(): array
{
    global $config;
    return $config['installedpackages']['xrayinstances']['config'] ?? [];
}

function xray_get_instance_by_uuid(string $uuid): ?array
{
    foreach (xray_get_instances() as $inst) {
        if (($inst['uuid'] ?? '') === $uuid) {
            return $inst;
        }
    }
    return null;
}

function xray_save_global_config(array $global): void
{
    global $config;
    $config['installedpackages']['xray']['config'][0] = $global;
    write_config('Xray: save global settings');
}

function xray_save_instance(array $instance): void
{
    global $config;

    if (!isset($config['installedpackages']['xrayinstances']['config'])) {
        $config['installedpackages']['xrayinstances']['config'] = [];
    }

    $instances = &$config['installedpackages']['xrayinstances']['config'];
    $uuid      = $instance['uuid'] ?? '';
    $found     = false;

    foreach ($instances as $idx => $inst) {
        if (($inst['uuid'] ?? '') === $uuid) {
            $instances[$idx] = $instance;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $instances[] = $instance;
    }

    write_config('Xray: save instance ' . $uuid);
}

function xray_delete_instance(string $uuid): void
{
    global $config;

    if (!isset($config['installedpackages']['xrayinstances']['config'])) {
        return;
    }

    $instances = $config['installedpackages']['xrayinstances']['config'];
    $updated   = [];
    foreach ($instances as $inst) {
        if (($inst['uuid'] ?? '') !== $uuid) {
            $updated[] = $inst;
        }
    }
    $config['installedpackages']['xrayinstances']['config'] = $updated;

    xray_unregister_tun_interface_by_uuid($uuid);

    write_config('Xray: delete instance ' . $uuid);
}

// ─── TUN interface registration ───────────────────────────────────────────────

/**
 * Derives a deterministic pfSense interface key for a given TUN device name.
 * Searches existing $config['interfaces'] first; if not found, picks the next
 * free optN slot (opt1, opt2, …).
 */
function xray_find_interface_key(string $ifname): string
{
    global $config;

    foreach ($config['interfaces'] as $key => $iface) {
        if (($iface['if'] ?? '') === $ifname) {
            return $key;
        }
    }

    $n = 1;
    while (isset($config['interfaces']['opt' . $n])) {
        $n++;
    }
    return 'opt' . $n;
}

/**
 * Derives a /30 point-to-point IP from the UUID (deterministic, collision-rare).
 * Returns the "local" end of the link (e.g. 10.100.42.1).
 */
function xray_tun_ip_for_uuid(string $uuid): string
{
    $crc   = crc32($uuid) & 0xFFFF;
    $octet = ($crc % 62) + 1;
    $block = ($crc >> 6) % 254 + 1;
    return "10.100.{$block}.{$octet}";
}

function xray_register_tun_interface(string $ifname, string $uuid): void
{
    global $config;

    $key = xray_find_interface_key($ifname);
    $ip  = xray_tun_ip_for_uuid($uuid);
    $descr = 'Xray-' . substr($uuid, 0, 8);

    $config['interfaces'][$key] = [
        'if'      => $ifname,
        'descr'   => $descr,
        'enable'  => '',
        'ipaddr'  => $ip,
        'subnet'  => '30',
        'spoofmac'=> '',
        'type'    => 'none',
    ];

    write_config('Xray: register TUN interface ' . $ifname);
}

function xray_unregister_tun_interface(string $ifname): void
{
    global $config;

    foreach ($config['interfaces'] as $key => $iface) {
        if (($iface['if'] ?? '') === $ifname) {
            unset($config['interfaces'][$key]);
            write_config('Xray: unregister TUN interface ' . $ifname);
            return;
        }
    }
}

function xray_unregister_tun_interface_by_uuid(string $uuid): void
{
    $inst = xray_get_instance_by_uuid($uuid);
    if ($inst === null) {
        return;
    }
    $ifname = $inst['tun_interface'] ?? '';
    if ($ifname !== '') {
        xray_unregister_tun_interface($ifname);
    }
}

// ─── Resync ───────────────────────────────────────────────────────────────────

function xray_resync(): void
{
    mwexec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php reconfigure');
    filter_configure();
}

// ─── Install / Deinstall ─────────────────────────────────────────────────────

function xray_install(): void
{
    $dirs = [
        '/usr/local/etc/xray-core',
        '/usr/local/tun2socks',
        '/usr/local/scripts/xray',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    chmod('/usr/local/scripts/xray', 0755);

    $newsyslog = <<<'EOT'
/var/log/xray-core.log      root:wheel  644  5  600  *  B
/var/log/xray-watchdog.log  root:wheel  644  5  200  *  B
EOT;
    @mkdir('/etc/newsyslog.conf.d', 0755, true);
    file_put_contents('/etc/newsyslog.conf.d/xray.conf', $newsyslog . "\n");

    mwexec('/usr/sbin/sysrc -f /etc/rc.conf.local xray_enable=YES');

    install_cron_job(
        '/usr/local/bin/php /usr/local/scripts/xray/xray-watchdog.php',
        true,
        '*', '*', '*', '*', '*',
        'root'
    );
}

function xray_deinstall(): void
{
    mwexec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php stop 2>/dev/null');

    global $config;
    $instances = xray_get_instances();
    foreach ($instances as $inst) {
        $ifname = $inst['tun_interface'] ?? '';
        if ($ifname !== '') {
            xray_unregister_tun_interface($ifname);
        }
    }

    if (!empty($instances)) {
        write_config('Xray: deinstall — unregister all TUN interfaces');
    }

    @unlink('/etc/newsyslog.conf.d/xray.conf');

    mwexec('/usr/sbin/sysrc -f /etc/rc.conf.local xray_enable=NO');

    install_cron_job(
        '/usr/local/bin/php /usr/local/scripts/xray/xray-watchdog.php',
        false
    );

    $patterns = [
        '/var/run/xray_core_*.pid',
        '/var/run/tun2socks_*.pid',
        '/var/run/xray_start_*.lock',
        '/var/run/xray_stopped_*.flag',
        '/usr/local/etc/xray-core/config-*.json',
        '/usr/local/tun2socks/config-*.yaml',
    ];
    foreach ($patterns as $pattern) {
        array_map('unlink', glob($pattern) ?: []);
    }
}
