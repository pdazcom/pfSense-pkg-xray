#!/bin/sh
#
# PROVIDE: xray
# REQUIRE: NETWORKING
# KEYWORD: shutdown

. /etc/rc.subr

name="xray"
rcvar="xray_enable"
start_cmd="xray_start"
stop_cmd="xray_stop"
status_cmd="xray_status"

CTRL="/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php"

xray_start()
{
    echo "Starting Xray..."
    kldload if_tun 2>/dev/null || true
    ${CTRL} start
}

xray_stop()
{
    echo "Stopping Xray..."
    ${CTRL} stop
}

xray_status()
{
    ${CTRL} statusall
}

load_rc_config ${name}
: ${xray_enable:=NO}
run_rc_command "$1"
