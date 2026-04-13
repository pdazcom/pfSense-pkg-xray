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

define('XRAY_DEFAULT_GROUP_UUID', '00000000-0000-4000-8000-000000000001');

// ─── UI helpers ──────────────────────────────────────────────────────────────

function xray_sanitize_uuid(string $raw): string
{
    $clean = preg_replace('/[^0-9a-fA-F\-]/', '', $raw);
    return strlen($clean) === 36 ? $clean : '';
}

/**
 * Build the standard four-tab navigation array.
 *
 * @param  string $active  One of: 'connections', 'instances', 'settings', 'diagnostics'
 * @return array
 */
function xray_build_tab_array(string $active): array
{
    return [
        [gettext('Connections'), $active === 'connections', '/xray/xray_connections.php'],
        [gettext('Instances'),   $active === 'instances',   '/xray/xray_instances.php'],
        [gettext('Settings'),    $active === 'settings',    '/xray/xray_settings.php'],
        [gettext('Diagnostics'), $active === 'diagnostics', '/xray/xray_diagnostics.php'],
    ];
}

function xray_connection_mode_label(array $conn): string
{
    require_once(dirname(__FILE__) . '/xray_vless.inc');

    $json = trim($conn['custom_config'] ?? '');

    if ($json === '') {
        $json = xray_wizard_fields_to_json($conn);
    }

    if ($json === '') {
        return 'Custom JSON';
    }

    $fields = xray_parse_config_json($json);

    if (!($fields['recognized'] ?? false)) {
        return 'Custom JSON';
    }

    $proto    = $fields['protocol'] ?? 'vless';
    $network  = $fields['network']  ?? 'raw';
    $security = $fields['security'] ?? 'none';

    $netLabel = $network === 'raw' ? 'TCP' : strtoupper($network);
    $secLabel = $security !== 'none' ? ' + ' . $security : '';

    return strtoupper($proto) . ' (' . $netLabel . $secLabel . ')';
}

/**
 * Return "host:port" for display in the connections table.
 * Reads from custom_config JSON if available, falls back to wizard fields.
 */
function xray_connection_server_label(array $conn): string
{
    $json = trim($conn['custom_config'] ?? '');

    if ($json !== '') {
        require_once(dirname(__FILE__) . '/xray_vless.inc');
        $fields = xray_parse_config_json($json);
        if ($fields['recognized'] ?? false) {
            $addr = $fields['server_address'] ?? '';
            $port = $fields['server_port']    ?? '';
            if ($addr !== '') {
                return $addr . ':' . $port;
            }
        }
    }

    $addr = trim($conn['server_address'] ?? '');
    if ($addr !== '') {
        return $addr . ':' . ($conn['server_port'] ?? '443');
    }

    return '';
}

/**
 * Build xray-core JSON from legacy wizard fields stored directly on a connection.
 * Returns empty string if the connection has no wizard fields.
 */
function xray_wizard_fields_to_json(array $conn): string
{
    $server = trim($conn['server_address'] ?? '');
    if ($server === '') {
        return '';
    }

    require_once(dirname(__FILE__) . '/xray_vless.inc');

    $security = 'reality';
    $network  = 'raw';
    $flow     = $conn['flow'] ?? 'xtls-rprx-vision';

    $f = [
        'protocol'            => 'vless',
        'server_address'      => $server,
        'server_port'         => $conn['server_port'] ?? '443',
        'vless_uuid'          => $conn['vless_uuid'] ?? '',
        'encryption'          => 'none',
        'flow'                => ($flow === '' || $flow === 'none') ? 'none' : 'xtls-rprx-vision',
        'network'             => $network,
        'security'            => $security,
        'reality_sni'         => $conn['reality_sni'] ?? '',
        'reality_fingerprint' => $conn['reality_fingerprint'] ?? 'chrome',
        'reality_pubkey'      => $conn['reality_pubkey'] ?? '',
        'reality_shortid'     => $conn['reality_shortid'] ?? '',
        'reality_spiderx'     => $conn['reality_spiderx'] ?? '/',
        'tls_sni'             => '',
        'tls_fingerprint'     => 'chrome',
        'tls_alpn'            => '',
        'transport_path'      => '',
        'transport_host'      => '',
        'xhttp_mode'          => 'stream-one',
        'grpc_service_name'   => '',
        'mux'                 => '',
    ];

    return xray_build_config_json($f);
}

// ─── UUID generator ───────────────────────────────────────────────────────────

function xray_generate_uuid(): string
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

// ─── Group CRUD ──────────────────────────────────────────────────────────────

function xray_get_groups(): array
{
    global $config;
    return $config['installedpackages']['xraygroups']['config'] ?? [];
}

function xray_get_group_by_uuid(string $uuid): ?array
{
    foreach (xray_get_groups() as $group) {
        if (($group['uuid'] ?? '') === $uuid) {
            return $group;
        }
    }
    return null;
}

function xray_save_group(array $group): void
{
    global $config;

    if (!isset($config['installedpackages']['xraygroups']['config'])) {
        $config['installedpackages']['xraygroups']['config'] = [];
    }

    $groups = &$config['installedpackages']['xraygroups']['config'];
    $uuid   = $group['uuid'] ?? '';
    $found  = false;

    foreach ($groups as $idx => $g) {
        if (($g['uuid'] ?? '') === $uuid) {
            $groups[$idx] = $group;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $groups[] = $group;
    }

    write_config('Xray: save group ' . $uuid);
}

function xray_delete_group(string $uuid): string
{
    global $config;

    if ($uuid === XRAY_DEFAULT_GROUP_UUID) {
        return 'Cannot delete the Default group.';
    }

    $connections = xray_get_connections_by_group($uuid);
    foreach ($connections as $conn) {
        $connUuid = $conn['uuid'] ?? '';
        if ($connUuid === '') {
            continue;
        }
        $blocking = [];
        foreach (xray_get_instances() as $inst) {
            if (($inst['connection_uuid'] ?? '') === $connUuid) {
                $blocking[] = $inst['name'] ?? $inst['uuid'];
            }
        }
        if (!empty($blocking)) {
            return 'Connection "' . ($conn['name'] ?? $connUuid) . '" is used by instance(s): ' . implode(', ', $blocking) . '.';
        }
    }

    if (isset($config['installedpackages']['xrayconnections']['config'])) {
        $allConns = $config['installedpackages']['xrayconnections']['config'];
        $remaining = [];
        foreach ($allConns as $conn) {
            if (($conn['group_uuid'] ?? '') !== $uuid) {
                $remaining[] = $conn;
            }
        }
        $config['installedpackages']['xrayconnections']['config'] = $remaining;
    }

    if (!isset($config['installedpackages']['xraygroups']['config'])) {
        return '';
    }

    $groups  = $config['installedpackages']['xraygroups']['config'];
    $updated = [];
    foreach ($groups as $g) {
        if (($g['uuid'] ?? '') !== $uuid) {
            $updated[] = $g;
        }
    }
    $config['installedpackages']['xraygroups']['config'] = $updated;
    write_config('Xray: delete group ' . $uuid);
    return '';
}

// ─── Connection CRUD (JSON file storage — avoids pfSense XML size limits) ────

require_once(dirname(__FILE__) . '/xray_connections.inc');

function xray_delete_connection(string $uuid): string
{
    $blocking = [];
    foreach (xray_get_instances() as $inst) {
        if (($inst['connection_uuid'] ?? '') === $uuid) {
            $blocking[] = $inst['name'] ?? $inst['uuid'];
        }
    }
    if (!empty($blocking)) {
        return 'Connection is used by instance(s): ' . implode(', ', $blocking) . '.';
    }

    $connections = xray_get_connections();
    $updated     = [];
    foreach ($connections as $conn) {
        if (($conn['uuid'] ?? '') !== $uuid) {
            $updated[] = $conn;
        }
    }
    xray_save_connections($updated);
    return '';
}

function xray_get_connection_for_instance(array $inst): ?array
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

    $connections = xray_get_connections_by_group($groupUuid);
    return !empty($connections) ? $connections[0] : null;
}

// ─── Migration: old instances → connections ───────────────────────────────────

function xray_migrate_instances_to_connections(): void
{
    global $config;

    // Step 1: migrate connections stored in XML config to JSON file
    $xmlConns = $config['installedpackages']['xrayconnections']['config'] ?? [];
    if (!empty($xmlConns) && !file_exists(XRAY_CONNECTIONS_FILE)) {
        if (isset($xmlConns['uuid'])) {
            $xmlConns = [$xmlConns];
        }
        xray_save_connections(array_values($xmlConns));
        unset($config['installedpackages']['xrayconnections']);
        write_config('Xray: migrated connections from XML to JSON file');
    }

    // Step 2: migrate old-style instances (with protocol fields) to connections
    $jsonConns = xray_get_connections();
    $instances = xray_get_instances();
    if (empty($instances)) {
        return;
    }

    $hasProtocolFields = false;
    foreach ($instances as $inst) {
        if (isset($inst['server_address']) && $inst['server_address'] !== '') {
            $hasProtocolFields = true;
            break;
        }
    }
    if (!$hasProtocolFields) {
        return;
    }

    require_once(dirname(__FILE__) . '/xray_vless.inc');

    $newConns = $jsonConns;
    foreach ($instances as $idx => $inst) {
        if (empty($inst['server_address'])) {
            continue;
        }

        $connUuid = xray_generate_uuid();

        $customConfig = trim($inst['custom_config'] ?? '');
        if ($customConfig === '') {
            $customConfig = xray_wizard_fields_to_json($inst);
        }

        $newConns[] = [
            'uuid'          => $connUuid,
            'group_uuid'    => XRAY_DEFAULT_GROUP_UUID,
            'name'          => $inst['name'] ?? 'Migrated connection',
            'custom_config' => $customConfig,
            'test_result'   => '',
        ];

        $config['installedpackages']['xrayinstances']['config'][$idx]['connection_mode']        = 'fixed';
        $config['installedpackages']['xrayinstances']['config'][$idx]['connection_uuid']        = $connUuid;
        $config['installedpackages']['xrayinstances']['config'][$idx]['connection_group_uuid']  = '';
        $config['installedpackages']['xrayinstances']['config'][$idx]['active_connection_uuid'] = '';
        $config['installedpackages']['xrayinstances']['config'][$idx]['webhook_url']            = '';

        foreach (['config_mode', 'server_address', 'server_port', 'vless_uuid', 'flow',
                  'reality_sni', 'reality_pubkey', 'reality_shortid', 'reality_fingerprint', 'custom_config'] as $field) {
            unset($config['installedpackages']['xrayinstances']['config'][$idx][$field]);
        }
    }

    $config['installedpackages']['xrayinstances']['config'] = array_values(
        $config['installedpackages']['xrayinstances']['config']
    );

    xray_save_connections($newConns);
    write_config('Xray: migrated instances to connections');
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
/var/log/xray-core.log      root:wheel  644  3  600  *  JG
/var/log/xray-watchdog.log  root:wheel  644  3  200  *  JG
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

    install_cron_job(
        '/usr/local/bin/php /usr/local/scripts/xray/xray-subscription-autoupdate.php',
        true,
        '*/30', '*', '*', '*', '*',
        'root'
    );

    xray_bootstrap_default_group();
    xray_migrate_instances_to_connections();
}

function xray_bootstrap_default_group(): void
{
    global $config;

    $groups = xray_get_groups();
    foreach ($groups as $g) {
        if (($g['uuid'] ?? '') === XRAY_DEFAULT_GROUP_UUID) {
            return;
        }
    }

    if (!isset($config['installedpackages']['xraygroups']['config'])) {
        $config['installedpackages']['xraygroups']['config'] = [];
    }

    array_unshift($config['installedpackages']['xraygroups']['config'], [
        'uuid'       => XRAY_DEFAULT_GROUP_UUID,
        'name'       => 'Default',
        'type'       => 'manual',
        'sub_url'    => '',
        'autoupdate' => '',
    ]);

    write_config('Xray: bootstrap Default group');
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

    install_cron_job(
        '/usr/local/bin/php /usr/local/scripts/xray/xray-subscription-autoupdate.php',
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
