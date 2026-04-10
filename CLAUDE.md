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
│   │   └── xray_ajax.php             # AJAX: start/stop/status/import/validate
│   ├── usr/local/pkg/
│   │   ├── xray.inc                  # Core: config read/write, resync, cron, rc hooks
│   │   └── xray_validate.inc         # Input validation before save
│   ├── usr/local/scripts/xray/
│   │   ├── xray-service-control.php  # Process management: start/stop/restart/status
│   │   ├── xray-watchdog.php         # Crash recovery daemon (cron every minute)
│   │   ├── xray-ifstats.php          # TUN interface statistics
│   │   └── xray-testconnect.php      # SOCKS5 connectivity test
│   └── usr/local/etc/rc.d/
│       └── xray.sh                   # rc script: start/stop/status all instances
└── install.sh                        # Downloads xray-core + tun2socks binaries
```

## Config Storage

pfSense stores package config in `$config['installedpackages']['xray']`.

Structure:
```php
$config['installedpackages']['xray']['config'][0] = [
    'enabled'         => 'on',
    'watchdog_enabled'=> 'on',
];

$config['installedpackages']['xrayinstances']['config'][] = [
    'uuid'            => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',  // instance UUID
    'name'            => 'My VPN',
    'config_mode'     => 'wizard',   // 'wizard' | 'custom'
    'custom_config'   => '',         // raw JSON if config_mode=custom
    'server_address'  => '1.2.3.4',
    'server_port'     => '443',
    'vless_uuid'      => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'flow'            => 'xtls-rprx-vision',
    'reality_sni'     => 'www.cloudflare.com',
    'reality_pubkey'  => '',
    'reality_shortid' => '',
    'reality_fingerprint' => 'chrome',
    'socks5_listen'   => '127.0.0.1',
    'socks5_port'     => '10808',
    'tun_interface'   => 'proxytun0',
    'mtu'             => '1500',
    'loglevel'        => 'warning',
    'bypass_networks' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
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
- `/var/run/xray_start_{uuid}.lock`     ← per-instance start lock

### Binaries
- `/usr/local/bin/xray-core`
- `/usr/local/tun2socks/tun2socks`

### pfSense Routing Integration
TUN interface is registered in `$config['interfaces']` so it appears in pfSense UI.
Users route traffic via: Firewall → Rules → set Gateway to the Xray interface.
Aliases work natively — no changes needed to xray-core routing config.

### Protocol extensibility
`config_mode = 'wizard'` uses the built-in VLESS+Reality form fields.
`config_mode = 'custom'` accepts raw xray-core JSON — supports any protocol/transport.
Adding new wizard modes in the future = new `config_mode` value + new form section.

## What's Reused from OPNsense Plugin (os-xray)

| File | Reuse |
|------|-------|
| `xray_build_config_array()` | Direct port, reads from pfSense $config array |
| `parseVless()` / `buildCustomConfig()` | Direct port |
| `buildStreamSettings()` | Direct port |
| `xray-service-control.php` logic | Port: replace OPNsense Config class with pfSense config read |
| `xray-watchdog.php` | Port: replace config reader |
| `xray-ifstats.php` | Port: replace config reader |
| `proc_start/kill/is_running` helpers | Direct port |
| Lock/PID/stopped-flag patterns | Direct port |

## pfSense Package Conventions

- Package manifest: `pkg/xray.xml`
- PHP pages live in `/usr/local/www/xray/`
- Include files: `/usr/local/pkg/xray.inc`
- Use `mwexec()` instead of `exec()` for system commands where appropriate
- Use `write_config()` to save `$config` changes
- Use `pfSense_module_load()` for kernel modules if needed
- CSRF: all forms must use `gen_customcsrf()` / `csrf_magic`
- Bootstrap 3 (pfSense uses Bootstrap 3 in 2.7/2.8)

## Conventions for This Codebase

- No raw SQL, no `$_GET`/`$_POST` directly — use pfSense helpers
- All scripts: strict input validation, escapeshellarg() everywhere
- No comments inside methods — self-documenting code
- Type hints on all functions
- Early returns to reduce nesting
