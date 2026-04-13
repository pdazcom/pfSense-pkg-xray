# pfSense-pkg-xray

[![License](https://img.shields.io/badge/license-BSD%202--Clause-blue)](LICENSE)
[![pfSense](https://img.shields.io/badge/pfSense-CE%202.7.x%20%2F%202.8.x-blue)](https://www.pfsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)
[![PHP](https://img.shields.io/badge/PHP-8.2-purple)](https://php.net)

**Xray-core VPN package for pfSense CE** — native GUI integration for VLESS+Reality tunnels with selective routing via pfSense Aliases and Firewall Rules.

Ported from [os-xray](https://github.com/MrTheory/os-xray) (OPNsense plugin). All core logic (config generation, VLESS parser, process management, watchdog) is preserved; only the framework layer is rewritten for pfSense.

---

## How It Works

```
xray-core  (VLESS+Reality outbound)
    ↓  SOCKS5  (127.0.0.1:10808, configurable)
tun2socks
    ↓  TUN interface  (e.g. proxytun0)
pfSense Gateway  →  Firewall Rules  →  Selective routing
```

Traffic you want to tunnel is sent to the Xray gateway via pfSense policy-based routing — no changes to xray-core routing config are needed. Aliases and firewall rules work natively.

---

## Features

- **Multi-instance** — run several independent VPN tunnels simultaneously, each with its own UUID, TUN interface, SOCKS5 port, and config
- **Wizard mode** — VLESS+Reality fields in the GUI (UUID, SNI, PublicKey, ShortID, Fingerprint, flow)
- **Custom JSON mode** — paste any xray-core `config.json` directly; supports all protocols and transports (xhttp, ws, grpc, h2, kcp, tcp)
- **VLESS link import** — paste a `vless://` link to auto-fill wizard fields; non-Reality transports automatically fall back to Custom JSON mode
- **Per-instance start / stop / restart** — without page reload, via AJAX
- **Live status badges** — xray-core + tun2socks status polled every 10 s
- **Config validation** — dry-run via `xray -test` before start, without touching the running service
- **Test Connection** — verifies the tunnel is actually proxying (HTTP check through SOCKS5)
- **Diagnostics page** — TUN IP, MTU, bytes/packets in/out, process uptime, ping RTT to VPN server
- **Log viewer** — last 200 lines of xray-core log and last 100 lines of watchdog log in the GUI
- **Watchdog** — cron-based crash recovery (per minute); respects manual stop (stopped flag)
- **Auto-start on boot** — FreeBSD rc.d script (`/usr/local/etc/rc.d/xray.sh`)
- **Bypass Networks** — configurable CIDR list routed directly, not through Xray

---

## Requirements

| Component  | Version                     |
| ---------- | --------------------------- |
| pfSense CE | 2.7.x / 2.8.x               |
| FreeBSD    | 14.x amd64 / aarch64        |
| PHP        | 8.2                         |
| xray-core  | 24.x or later (recommended) |
| tun2socks  | 2.x                         |

> **Architecture note:** `install.sh` auto-detects `amd64` and `aarch64`. Other architectures are not supported by the upstream binaries.

---

## Installation

### Step 1 — Clone the repository on pfSense

SSH into pfSense and run:

```sh
cd /tmp
git clone https://github.com/MrTheory/pfsense-xray.git
cd pfsense-xray
```

### Step 2 — Run install.sh

```sh
sh install.sh
```

The script will:

- Download `xray-core` and `tun2socks` from GitHub Releases via `fetch`
- Install binaries to `/usr/local/bin/xray-core` and `/usr/local/tun2socks/tun2socks`
- Copy all package files to the correct pfSense filesystem locations
- Load the `if_tun` kernel module
- Configure log rotation (`/etc/newsyslog.conf.d/xray.conf`)
- Register the package in pfSense (adds **VPN → Xray** menu)

### Step 3 — Enable the package

Open pfSense web UI → **VPN → Xray → Settings** → check **Enable Xray** → **Save**.

---

## install.sh Commands

```sh
# Full install (default)
sh install.sh

# Update files after git pull, restart instances
sh install.sh update

# Update files only, skip binary download
sh install.sh update --no-binaries

# Download/update binaries only
sh install.sh download-binaries

# Pin specific versions
sh install.sh --xray-version 25.4.30 --t2s-version 2.5.2

# Full uninstall (stops services, removes files, cleans pfSense config)
sh install.sh uninstall
```

---

## GUI Setup

### Add an instance

**VPN → Xray → Instances → Add Instance**

1. Expand **Import from VLESS Link** → paste link → **Parse & Fill**
   - Standard `tcp+reality` links fill wizard fields automatically
   - Other transports (`xhttp`, `ws`, `grpc`, `h2`, `kcp`) generate Custom JSON automatically
2. Or fill the **Wizard** tab fields manually
3. Or switch to **Custom JSON** tab and paste a full `config.json`
4. Set **TUN Interface** name (e.g. `proxytun0`) — must be unique per instance
5. Set **SOCKS5 Port** — must be unique per instance (default: `10808`)
6. **Save** → **Start**

---

## Selective Routing

After starting an instance, configure pfSense to route selected traffic through Xray.

### 1. Create a Gateway

**System → Routing → Gateways → Add**:

- Interface: select the Xray TUN interface (appears as OPTx)
- Gateway IP: second address of the /30 subnet shown in **Diagnostics → TUN IP**
  (e.g. if TUN IP is `10.100.66.46/30`, gateway is `10.100.66.45`)
- Name: `XRAY_GW`
- Monitor IP: same as Gateway IP (`10.100.66.45`) — **important**: do not use an external IP,
  ICMP won't pass through the tunnel and the gateway will be marked down

### 2. Create an Alias

**Firewall → Aliases → Add**:

- Type: Network(s), Host(s), or URL Table
- Add the IPs, subnets, or domains you want to route through Xray
- Example URL Table: `https://antifilter.download/list/allyouneed.lst`

### 3. Create a Firewall Rule

**Firewall → Rules → LAN → Add** (place above the default allow rule):

- Action: Pass
- Protocol: TCP/UDP
- Source: LAN net (or specific hosts)
- Destination: your Alias
- Advanced Options → Gateway: `XRAY_GW`
- **Save** → **Apply Changes**

---

## Diagnostics

**VPN → Xray → Diagnostics**

- Select instance from dropdown
- **Refresh** — loads TUN interface stats (IP, MTU, bytes in/out, process uptime)
- **Test Connection** — sends HTTP request through SOCKS5 proxy, shows HTTP status code
- **xray-core Log** — last 200 lines of `/var/log/xray-core.log`
- **Watchdog Log** — last 100 lines of `/var/log/xray-watchdog.log`

---

## Updating

```sh
cd /tmp/pfsense-xray
git pull
sh install.sh update
```

---

## Uninstalling

```sh
cd /tmp/pfsense-xray
sh install.sh uninstall
```

Then manually remove in pfSense UI:

- **System → Routing → Gateways** — delete `XRAY_GW`
- **Firewall → Rules** — delete rules that used `XRAY_GW`

---

## File Structure

```
pfSense-pkg-xray/
├── pkg/
│   └── xray.xml                          # Package manifest (menus, hooks, cron)
├── files/
│   ├── usr/local/www/xray/
│   │   ├── xray_instances.php            # Instance list + live status (AJAX polling)
│   │   ├── xray_edit.php                 # Create/edit instance + VLESS import
│   │   ├── xray_settings.php             # Global settings (enable, watchdog)
│   │   ├── xray_diagnostics.php          # TUN stats, logs, connection test
│   │   └── xray_ajax.php                 # AJAX dispatcher + VLESS parser
│   ├── usr/local/pkg/
│   │   └── xray/includes/
│   │       ├── xray.inc                  # Config R/W, TUN registration, hooks
│   │       ├── xray_validate.inc         # Input validation
│   │       └── xray_foot.inc             # Footer include
│   ├── usr/local/scripts/xray/
│   │   ├── xray-service-control.php      # Process management (start/stop/status/validate)
│   │   ├── xray-watchdog.php             # Crash recovery daemon
│   │   ├── xray-ifstats.php              # TUN interface statistics + ping
│   │   └── xray-testconnect.php          # SOCKS5 connectivity test
│   └── usr/local/etc/rc.d/
│       └── xray.sh                       # FreeBSD rc.d boot script
└── install.sh                            # Install / update / uninstall script
```

---

## Runtime Files

All runtime files are named by instance UUID to avoid conflicts between instances:

| File                                          | Purpose                             |
| --------------------------------------------- | ----------------------------------- |
| `/usr/local/etc/xray-core/config-{uuid}.json` | xray-core config                    |
| `/usr/local/tun2socks/config-{uuid}.yaml`     | tun2socks config                    |
| `/var/run/xray_core_{uuid}.pid`               | xray-core PID                       |
| `/var/run/tun2socks_{uuid}.pid`               | tun2socks PID                       |
| `/var/run/xray_start_{uuid}.lock`             | Per-instance startup lock (flock)   |
| `/var/run/xray_stopped_{uuid}.flag`           | Manual stop marker (watchdog skips) |
| `/var/log/xray-core.log`                      | xray-core + tun2socks stderr output |
| `/var/log/xray-watchdog.log`                  | Watchdog restart events             |

---

## Troubleshooting

**Service won't start**

```sh
# Check xray-core config syntax
php /usr/local/scripts/xray/xray-service-control.php validate <uuid>

# Check xray-core log
tail -50 /var/log/xray-core.log

# Manual start
php /usr/local/scripts/xray/xray-service-control.php start <uuid>
```

**TUN interface doesn't appear**

```sh
# Check if if_tun module is loaded
kldstat | grep if_tun
kldload if_tun

# Check tun2socks is running
ps aux | grep tun2socks

# Check interface
ifconfig proxytun0
```

**Gateway is marked down**

Make sure **Monitor IP** is set to the local TUN peer address (e.g. `10.100.66.45`), not an external IP. tun2socks does not forward ICMP, so pinging external IPs through the gateway will always fail.

**Traffic not routing through tunnel**

```sh
# Test SOCKS5 directly
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w '%{http_code}' https://1.1.1.1

# Check firewall rule has the correct gateway
# Check the alias contains the destination IPs
pfctl -t <alias_name> -T show | head -20
```

**Watchdog keeps restarting**

```sh
tail -f /var/log/xray-watchdog.log
tail -100 /var/log/xray-core.log
```

---

## Credits

- [Xray-core](https://github.com/XTLS/Xray-core) — the proxy engine
- [tun2socks](https://github.com/xjasonlyu/tun2socks) — SOCKS5 to TUN bridge
- [os-xray](https://github.com/MrTheory/os-xray) — OPNsense plugin this is ported from

---

## License

BSD 2-Clause License.

This package is a derivative work of [os-xray](https://github.com/MrTheory/os-xray)
by Pavel, licensed under the BSD 2-Clause License. The original copyright notice
is retained in the [LICENSE](LICENSE) file as required by the license terms.

Core logic ported from os-xray:

- `xray-service-control.php` — config generation, process management, VLESS+Reality config builder
- `xray-watchdog.php` — crash recovery daemon
- `xray-ifstats.php` — TUN interface statistics
- `xray-testconnect.php` — SOCKS5 connectivity test
- VLESS link parser (`xray_ajax.php`) — ported from `ImportController.php`

The pfSense framework layer (package manifest, GUI pages, `xray.inc`, `xray_validate.inc`, `xray.sh`) is original work.
