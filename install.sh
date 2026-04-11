#!/bin/sh
#
# install.sh — Install pfSense Xray package.
#
# Run from the cloned repository root on pfSense:
#   git clone https://github.com/YOUR_ORG/pfsense-xray.git
#   cd pfsense-xray
#   sh install.sh [command] [options]
#
# Commands:
#   install              Full install (default)
#   update               Re-deploy files + restart services
#   uninstall            Stop services, remove files, clean config
#   download-binaries    Download xray-core + tun2socks only
#
# Options:
#   --xray-version VER   xray-core version (default: 25.4.30)
#   --t2s-version VER    tun2socks version (default: 2.5.2)
#   --no-binaries        Skip binary download (use existing)

set -e
set -u

# ─── Defaults ─────────────────────────────────────────────────────────────────
COMMAND="install"
XRAY_VERSION="25.4.30"
T2S_VERSION="2.5.2"
SKIP_BINARIES=0

# ─── Parse arguments ──────────────────────────────────────────────────────────
while [ $# -gt 0 ]; do
    case "$1" in
        install|update|uninstall|download-binaries)
            COMMAND="$1"
            shift
            ;;
        --xray-version)
            XRAY_VERSION="$2"
            shift 2
            ;;
        --t2s-version)
            T2S_VERSION="$2"
            shift 2
            ;;
        --no-binaries)
            SKIP_BINARIES=1
            shift
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
done

# ─── Helpers ──────────────────────────────────────────────────────────────────
info()  { echo "==> $*"; }
ok()    { echo "    [OK] $*"; }
die()   { echo "[ERROR] $*" >&2; exit 1; }

REPO_ROOT="$(cd "$(dirname "$0")" && pwd)"

# ─── Verify we're running on pfSense ─────────────────────────────────────────
if [ ! -f /etc/inc/config.inc ]; then
    die "This script must be run on pfSense (FreeBSD). /etc/inc/config.inc not found."
fi

# ─── Architecture detection ───────────────────────────────────────────────────
ARCH=$(uname -m)
case "${ARCH}" in
    amd64)   XRAY_ARCH="64";        T2S_ARCH="amd64" ;;
    aarch64) XRAY_ARCH="arm64-v8a"; T2S_ARCH="arm64" ;;
    *)       die "Unsupported architecture: ${ARCH}" ;;
esac

# ─── Binary download ──────────────────────────────────────────────────────────
cmd_download_binaries() {
    TMPDIR="/tmp/xray-install-$$"
    mkdir -p "${TMPDIR}"

    trap 'rm -rf "${TMPDIR}"' EXIT

    mkdir -p /usr/local/etc/xray-core
    mkdir -p /usr/local/tun2socks

    # xray-core
    XRAY_URL="https://github.com/XTLS/Xray-core/releases/download/v${XRAY_VERSION}/Xray-freebsd-${XRAY_ARCH}.zip"
    info "Downloading xray-core ${XRAY_VERSION}..."
    fetch -q -o "${TMPDIR}/xray.zip" "${XRAY_URL}" || die "Failed to download xray-core"

    mkdir -p "${TMPDIR}/xray-core"
    unzip -q "${TMPDIR}/xray.zip" -d "${TMPDIR}/xray-core/" || die "Failed to unzip xray-core"

    if [ -f "${TMPDIR}/xray-core/xray" ]; then
        install -m 755 "${TMPDIR}/xray-core/xray" /usr/local/bin/xray-core
    else
        die "xray binary not found in archive"
    fi

    echo "${XRAY_VERSION}" > /usr/local/etc/xray-core/version.txt
    ok "xray-core $(/usr/local/bin/xray-core version 2>/dev/null | head -1)"

    # tun2socks
    T2S_URL="https://github.com/xjasonlyu/tun2socks/releases/download/v${T2S_VERSION}/tun2socks-freebsd-${T2S_ARCH}.zip"
    info "Downloading tun2socks ${T2S_VERSION}..."
    fetch -q -o "${TMPDIR}/tun2socks.zip" "${T2S_URL}" || die "Failed to download tun2socks"

    mkdir -p "${TMPDIR}/tun2socks"
    unzip -q "${TMPDIR}/tun2socks.zip" -d "${TMPDIR}/tun2socks/" || die "Failed to unzip tun2socks"

    T2S_BIN=""
    for candidate in "${TMPDIR}/tun2socks/tun2socks" "${TMPDIR}/tun2socks/tun2socks-freebsd-${T2S_ARCH}"; do
        if [ -f "${candidate}" ]; then
            T2S_BIN="${candidate}"
            break
        fi
    done
    [ -z "${T2S_BIN}" ] && die "tun2socks binary not found in archive"

    install -m 755 "${T2S_BIN}" /usr/local/tun2socks/tun2socks
    ok "tun2socks $(/usr/local/tun2socks/tun2socks --version 2>/dev/null | head -1)"
}

# ─── Deploy package files from repo ──────────────────────────────────────────
cmd_deploy_files() {
    info "Deploying package files..."

    # Create directories
    mkdir -p /usr/local/scripts/xray
    mkdir -p /usr/local/www/xray
    mkdir -p /usr/local/pkg/xray/includes
    mkdir -p /usr/local/etc/rc.d
    chmod 750 /usr/local/etc/xray-core 2>/dev/null || true
    chmod 750 /usr/local/tun2socks      2>/dev/null || true
    chmod 755 /usr/local/scripts/xray

    # Scripts
    cp "${REPO_ROOT}/files/usr/local/scripts/xray/"*.php /usr/local/scripts/xray/
    chmod +x /usr/local/scripts/xray/*.php

    # rc script
    cp "${REPO_ROOT}/files/usr/local/etc/rc.d/xray.sh" /usr/local/etc/rc.d/xray.sh
    chmod +x /usr/local/etc/rc.d/xray.sh

    # Package includes
    cp "${REPO_ROOT}/files/usr/local/pkg/xray/includes/"* /usr/local/pkg/xray/includes/

    # GUI pages
    cp "${REPO_ROOT}/files/usr/local/www/xray/"*.php /usr/local/www/xray/

    ok "Files deployed"
}

# ─── System configuration ─────────────────────────────────────────────────────
cmd_configure_system() {
    info "Configuring system..."

    # Load TUN kernel module
    kldload if_tun 2>/dev/null || true
    ok "if_tun kernel module loaded"

    # Log rotation
    mkdir -p /etc/newsyslog.conf.d
    cat > /etc/newsyslog.conf.d/xray.conf << 'EOF'
/var/log/xray-core.log      root:wheel  644  3  600  *  JG
/var/log/xray-watchdog.log  root:wheel  644  3  200  *  JG
EOF
    ok "Log rotation configured"
}

# ─── Register package in pfSense config ───────────────────────────────────────
cmd_register_package() {
    info "Registering package in pfSense..."

    php -r "
set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear');
require_once('globals.inc');
require_once('config.inc');
require_once('/usr/local/pkg/xray/includes/xray.inc');
xray_install();
write_config('Xray: package installed');
echo 'done';
" || die "Failed to register package"

    ok "Package registered (VPN → Xray menu added)"
}

# ─── Stop all xray instances ──────────────────────────────────────────────────
cmd_stop_all() {
    if [ -f /usr/local/scripts/xray/xray-service-control.php ]; then
        info "Stopping all Xray instances..."
        php /usr/local/scripts/xray/xray-service-control.php stop 2>/dev/null || true
        ok "Instances stopped"
    fi
}

# ─── Deregister package from pfSense config ───────────────────────────────────
cmd_deregister_package() {
    info "Removing package from pfSense config..."

    php -r "
set_include_path('/etc/inc' . PATH_SEPARATOR . '/usr/local/share/pear');
require_once('globals.inc');
require_once('config.inc');
require_once('/usr/local/pkg/xray/includes/xray.inc');
xray_deinstall();
write_config('Xray: package removed');
echo 'done';
" 2>/dev/null || true

    ok "Package deregistered"
}

# ─── Remove files ─────────────────────────────────────────────────────────────
cmd_remove_files() {
    info "Removing package files..."

    rm -rf /usr/local/www/xray
    rm -rf /usr/local/scripts/xray
    rm -rf /usr/local/pkg/xray
    rm -f  /usr/local/etc/rc.d/xray.sh
    rm -f  /etc/newsyslog.conf.d/xray.conf

    ok "Package files removed"
}

cmd_remove_binaries() {
    info "Removing binaries..."

    rm -f  /usr/local/bin/xray-core
    rm -rf /usr/local/tun2socks
    rm -rf /usr/local/etc/xray-core

    ok "Binaries removed"
}

# ─── Commands ─────────────────────────────────────────────────────────────────
case "${COMMAND}" in

    install)
        info "Installing pfSense Xray package..."
        echo ""

        if [ "${SKIP_BINARIES}" -eq 0 ]; then
            cmd_download_binaries
        fi

        cmd_deploy_files
        cmd_configure_system
        cmd_register_package

        echo ""
        info "Installation complete!"
        echo ""
        echo "  Next steps:"
        echo "  1. Go to VPN → Xray → Settings and enable the package"
        echo "  2. Go to VPN → Xray → Instances → Add to create a tunnel"
        echo "  3. After starting an instance, add a Gateway in"
        echo "     System → Routing → Gateways pointing to the TUN interface"
        echo ""
        ;;

    update)
        info "Updating pfSense Xray package..."
        echo ""

        cmd_stop_all

        if [ "${SKIP_BINARIES}" -eq 0 ]; then
            cmd_download_binaries
        fi

        cmd_deploy_files

        info "Restarting instances..."
        php /usr/local/scripts/xray/xray-service-control.php start 2>/dev/null || true
        ok "Instances restarted"

        echo ""
        info "Update complete!"
        ;;

    uninstall)
        info "Uninstalling pfSense Xray package..."
        echo ""

        cmd_stop_all
        cmd_deregister_package
        cmd_remove_files
        cmd_remove_binaries

        echo ""
        info "Uninstall complete. Config data removed from pfSense."
        echo "  Note: manually remove Gateway and Firewall Rules if you added them."
        echo ""
        ;;

    download-binaries)
        cmd_download_binaries
        ;;

esac
