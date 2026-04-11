# pfSense Xray Plugin — Project Context

## What We're Building

A native pfSense package that integrates **Xray-core** (proxy engine) with **tun2socks** to provide
GUI-managed VPN tunnels with support for VLESS+Reality and other protocols.

The plugin creates TUN interfaces that pfSense recognizes as standard interfaces — allowing users to
route traffic through Xray using pfSense's native tools: Aliases, Firewall Rules, Policy-Based Routing.

## Origin

Ported from the OPNsense plugin at `/Users/kostya/PhpstormProjects/os-xray`.
Key logic (config generation, VLESS link parser, process management, watchdog) is adapted from there.
The framework layer (config storage, GUI, service hooks) is rewritten for pfSense.

## Target Platform

- pfSense CE 2.7.x / 2.8.1
- FreeBSD 14.x
- PHP 8.2

## Architecture

```
pfSense-pkg-xray/
├── pkg/
│   └── xray.xml                      # Package manifest (menus, hooks, install/deinstall)
├── files/
│   ├── usr/local/www/xray/
│   │   ├── xray_instances.php        # Instance list with per-instance status
│   │   ├── xray_edit.php             # Create/edit instance (VLESS link import + manual)
│   │   ├── xray_settings.php         # Global settings (enable, watchdog)
│   │   ├── xray_diagnostics.php      # TUN stats, logs, connection test
│   │   └── xray_ajax.php             # AJAX: start/stop/status/import/validate/logs
│   ├── usr/local/pkg/
│   │   ├── xray.inc                  # Core: config read/write, TUN registration, hooks
│   │   ├── xray_validate.inc         # Input validation before save
│   │   └── xray/includes/
│   │       ├── xray.inc              # Symlink target for package manifest include path
│   │       └── xray_foot.inc         # Footer include (empty)
│   ├── usr/local/scripts/xray/
│   │   ├── xray-service-control.php  # Process management: start/stop/restart/status/validate
│   │   ├── xray-watchdog.php         # Crash recovery daemon (cron every minute)
│   │   ├── xray-ifstats.php          # TUN interface statistics + ping
│   │   └── xray-testconnect.php      # SOCKS5 connectivity test
│   └── usr/local/etc/rc.d/
│       └── xray.sh                   # rc script: start/stop/status all instances
└── install.sh                        # Downloads xray-core + tun2socks binaries
```

## Config Storage

pfSense stores package config in `$config['installedpackages']`.

```php
$config['installedpackages']['xray']['config'][0] = [
    'enabled'          => 'on' | '',
    'watchdog_enabled' => 'on' | '',
];

$config['installedpackages']['xrayinstances']['config'][] = [
    'uuid'                => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'name'                => 'My VPN',
    'config_mode'         => 'wizard' | 'custom',
    'custom_config'       => '',            // raw JSON if config_mode=custom
    'server_address'      => '1.2.3.4',
    'server_port'         => '443',
    'vless_uuid'          => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'flow'                => 'xtls-rprx-vision' | 'none',
    'reality_sni'         => 'www.cloudflare.com',
    'reality_pubkey'      => '',
    'reality_shortid'     => '',
    'reality_fingerprint' => 'chrome' | 'firefox' | 'safari' | 'edge' | 'random',
    'socks5_listen'       => '127.0.0.1',
    'socks5_port'         => '10808',
    'tun_interface'       => 'proxytun0',
    'mtu'                 => '1500',
    'loglevel'            => 'debug' | 'info' | 'warning' | 'error' | 'none',
    'bypass_networks'     => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
];
```

## Key Design Decisions

### Multi-instance
Each instance has a UUID. All runtime files are named by UUID:
- `/var/run/xray_core_{uuid}.pid`
- `/var/run/tun2socks_{uuid}.pid`
- `/usr/local/etc/xray-core/config-{uuid}.json`
- `/usr/local/tun2socks/config-{uuid}.yaml`
- `/var/run/xray_stopped_{uuid}.flag`   ← intentional stop marker (watchdog skip)
- `/var/run/xray_start_{uuid}.lock`     ← per-instance start lock (flock)

### Binaries
- `/usr/local/bin/xray-core`
- `/usr/local/tun2socks/tun2socks`

### Constants (defined in `xray-service-control.php`)
```php
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('T2S_BIN',           '/usr/local/tun2socks/tun2socks');
define('T2S_CONF_DIR',      '/usr/local/tun2socks');
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log');
define('XRAY_VERSION_FILE', '/usr/local/etc/xray-core/version.txt');
define('XRAY_CTRL',         '/usr/local/scripts/xray/xray-service-control.php');
define('WATCHDOG_LOG',      '/var/log/xray-watchdog.log');
```

### TUN IP Assignment
`xray_tun_ip_for_uuid()` derives a deterministic `/30` subnet from the UUID using CRC32.
This ensures the TUN interface always gets the same IP across restarts without extra config.

### pfSense Routing Integration
TUN interface is registered in `$config['interfaces']` so it appears in pfSense UI.
Users route traffic via: Firewall → Rules → set Gateway to the Xray interface.
Aliases work natively — no changes needed to xray-core routing config.

### Protocol extensibility
`config_mode = 'wizard'` uses the built-in VLESS+Reality form fields.
`config_mode = 'custom'` accepts raw xray-core JSON — supports any protocol/transport.

## Key Functions by File

### `xray.inc` — Config R/W + TUN registration
```php
xray_get_global_config(): array
xray_is_enabled(): bool
xray_get_instances(): array
xray_get_instance_by_uuid(string $uuid): ?array
xray_save_global_config(array $global): void
xray_save_instance(array $instance): void
xray_delete_instance(string $uuid): void
xray_tun_ip_for_uuid(string $uuid): string       // deterministic /30 from CRC32
xray_register_tun_interface(string $ifname, string $uuid): void
xray_unregister_tun_interface(string $ifname): void
xray_unregister_tun_interface_by_uuid(string $uuid): void
xray_resync(): void
xray_install(): void
xray_deinstall(): void
```

### `xray_validate.inc` — Input validation
```php
xray_validate_input(array $post, ?string $editUuid): void
xray_validate_tun_unique(string $ifname, ?string $editUuid): void
xray_validate_socks_port_unique(int $port, ?string $editUuid): void
xray_is_valid_ip_or_hostname(string $value): bool
xray_is_valid_cidr(string $net): bool
```

### `xray-service-control.php` — Process management
```php
xray_get_config(string $inst_uuid = ''): array
xray_build_config_array(array $c): array
xray_normalize_transport(string $json): string    // xhttp ↔ splithttp compat
xray_write_config(array $c): void
t2s_write_config(array $c): void
proc_is_running(string $pidfile): bool
proc_kill(string $pidfile): void
proc_start(string $bin, string $args, string $pidfile): void
lock_acquire(string $inst_uuid): resource|false
lock_release($fd, string $inst_uuid): void
xray_validate_config(string $confFile): bool
lo0_alias_ensure(string $addr): void
lo0_alias_remove(string $addr): void
xray_configure_tun(array $c): void
do_stop(string $inst_uuid, ?string $tunIface = null): void
do_start(array $c): bool
do_status(string $inst_uuid = ''): void
do_status_all(): void
```

CLI commands: `start [uuid]`, `stop [uuid]`, `restart [uuid]`, `reconfigure [uuid]`,
`status [uuid]`, `statusall`, `validate [uuid]`, `version`

### `xray_ajax.php` — AJAX dispatcher + VLESS parser
```php
xray_parse_vless_link(string $link, string $socksListen, int $socksPort): array
xray_build_custom_config(string $uuid, string $host, int $port, array $params, ...): string
xray_build_stream_settings(string $type, string $security, array $params): array
```

AJAX actions: `statusall`, `status`, `start`, `stop`, `restart`, `import`,
`ifstats`, `testconnect`, `validate`, `log`, `watchdoglog`, `version`

## What's Reused from OPNsense Plugin (os-xray)

| File | Reuse |
|------|-------|
| `xray_build_config_array()` | Direct port, reads from pfSense $config array |
| `parseVless()` / `buildCustomConfig()` | Direct port → `xray_ajax.php` |
| `buildStreamSettings()` | Direct port → `xray_ajax.php` |
| `xray-service-control.php` logic | Port: replace OPNsense Config class with pfSense config read |
| `xray-watchdog.php` | Port: replace config reader |
| `xray-ifstats.php` | Port: replace config reader |
| `proc_start/kill/is_running` helpers | Direct port |
| Lock/PID/stopped-flag patterns | Direct port |

## pfSense Package Conventions

- Package manifest: `pkg/xray.xml` (name `xray`, version `1.0.0`, menu under VPN)
- PHP pages live in `/usr/local/www/xray/`
- Include files: `/usr/local/pkg/xray.inc` (also at `/usr/local/pkg/xray/includes/xray.inc`)
- Use `mwexec()` instead of `exec()` for system commands where appropriate
- Use `write_config()` to save `$config` changes
- CSRF: AJAX endpoints use `$nocsrf = true`; form pages use standard pfSense CSRF
- Bootstrap 3 (pfSense uses Bootstrap 3 in 2.7/2.8)
- Processes launched via `/usr/sbin/daemon` for backgrounding

## Conventions for This Codebase

- No raw SQL, no `$_GET`/`$_POST` directly — use pfSense helpers
- All scripts: strict input validation, `escapeshellarg()` everywhere
- No comments inside methods — self-documenting code
- Type hints on all functions
- Early returns to reduce nesting
