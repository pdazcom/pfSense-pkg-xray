<?php

/**
 * xray_validate.inc — Input validation for Xray package forms.
 *
 * Called by pfSense via <custom_php_validation_command> in xray.xml
 * and directly from form pages before saving.
 *
 * Populates global $input_errors[] with human-readable error messages.
 */

// ─── Instance validation ──────────────────────────────────────────────────────

function xray_validate_input(array $post, ?string $editUuid): void
{
    global $input_errors;

    if (!is_array($input_errors)) {
        $input_errors = [];
    }

    $name = trim($post['name'] ?? '');
    if ($name === '') {
        $input_errors[] = 'Instance name is required.';
    } elseif (strlen($name) > 64) {
        $input_errors[] = 'Instance name must not exceed 64 characters.';
    }

    $connectionMode = $post['connection_mode'] ?? 'fixed';
    if (!in_array($connectionMode, ['fixed', 'rotation'], true)) {
        $input_errors[] = 'Connection mode must be "fixed" or "rotation".';
    } elseif ($connectionMode === 'fixed') {
        $connUuid = trim($post['connection_uuid'] ?? '');
        if ($connUuid === '') {
            $input_errors[] = 'A connection must be selected when using Fixed mode.';
        } else {
            require_once('/usr/local/pkg/xray/includes/xray.inc');
            if (xray_get_connection_by_uuid($connUuid) === null) {
                $input_errors[] = 'Selected connection does not exist.';
            }
        }
    } elseif ($connectionMode === 'rotation') {
        $groupUuid = trim($post['connection_group_uuid'] ?? '');
        if ($groupUuid === '') {
            $input_errors[] = 'A connection group must be selected when using Rotation mode.';
        } else {
            require_once('/usr/local/pkg/xray/includes/xray.inc');
            if (xray_get_group_by_uuid($groupUuid) === null) {
                $input_errors[] = 'Selected connection group does not exist.';
            }
        }
        $webhookUrl = trim($post['webhook_url'] ?? '');
        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $input_errors[] = 'Webhook URL must be a valid URL.';
        }
    }

    $socksListen = trim($post['socks5_listen'] ?? '');
    if ($socksListen === '') {
        $input_errors[] = 'SOCKS5 listen address is required.';
    } elseif (!filter_var($socksListen, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $input_errors[] = 'SOCKS5 listen address must be a valid IPv4 address.';
    }

    $socksPort = (int)($post['socks5_port'] ?? 0);
    if ($socksPort < 1 || $socksPort > 65535) {
        $input_errors[] = 'SOCKS5 port must be between 1 and 65535.';
    }

    $tunIface = trim($post['tun_interface'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9]{0,13}[0-9]$/', $tunIface)) {
        $input_errors[] = 'TUN interface name must start with a lowercase letter, end with a digit, and be at most 15 characters (e.g. proxytun0).';
    } else {
        xray_validate_tun_unique($tunIface, $editUuid);
    }

    xray_validate_socks_port_unique($socksPort, $editUuid);

    $mtu = (int)($post['mtu'] ?? 0);
    if ($mtu < 576 || $mtu > 9000) {
        $input_errors[] = 'MTU must be between 576 and 9000.';
    }

    $bypass = trim($post['bypass_networks'] ?? '');
    if ($bypass !== '') {
        foreach (array_map('trim', explode(',', $bypass)) as $net) {
            if ($net !== '' && !xray_is_valid_cidr($net)) {
                $input_errors[] = 'Bypass networks: "' . htmlspecialchars($net, ENT_QUOTES, 'UTF-8') . '" is not a valid CIDR notation.';
            }
        }
    }
}

// ─── Group validation ─────────────────────────────────────────────────────────

function xray_validate_group(array $post, ?string $editUuid): void
{
    global $input_errors;

    if (!is_array($input_errors)) {
        $input_errors = [];
    }

    $name = trim($post['name'] ?? '');
    if ($name === '') {
        $input_errors[] = 'Group name is required.';
    } elseif (strlen($name) > 64) {
        $input_errors[] = 'Group name must not exceed 64 characters.';
    }

    $type = $post['type'] ?? '';
    if (!in_array($type, ['manual', 'subscription'], true)) {
        $input_errors[] = 'Group type must be "manual" or "subscription".';
    }

    if ($type === 'subscription') {
        $subUrl = trim($post['sub_url'] ?? '');
        if ($subUrl === '') {
            $input_errors[] = 'Subscription URL is required for subscription groups.';
        } elseif (!filter_var($subUrl, FILTER_VALIDATE_URL)) {
            $input_errors[] = 'Subscription URL must be a valid URL.';
        }
    }
}

// ─── Connection validation ────────────────────────────────────────────────────

function xray_validate_connection(array $post, ?string $editUuid): void
{
    global $input_errors;

    if (!is_array($input_errors)) {
        $input_errors = [];
    }

    $name = trim($post['name'] ?? '');
    if ($name === '') {
        $input_errors[] = 'Connection name is required.';
    } elseif (strlen($name) > 64) {
        $input_errors[] = 'Connection name must not exceed 64 characters.';
    }

    $groupUuid = trim($post['group_uuid'] ?? '');
    if ($groupUuid === '') {
        $input_errors[] = 'A group must be selected.';
    } else {
        require_once('/usr/local/pkg/xray/includes/xray.inc');
        if (xray_get_group_by_uuid($groupUuid) === null) {
            $input_errors[] = 'Selected group does not exist.';
        }
    }

    $configMode = $post['config_mode'] ?? 'wizard';
    if (!in_array($configMode, ['wizard', 'custom'], true)) {
        $input_errors[] = 'Config mode must be "wizard" or "custom".';
    }

    if ($configMode === 'wizard') {
        $server = trim($post['server_address'] ?? '');
        if ($server === '') {
            $input_errors[] = 'Server address is required.';
        } elseif (!xray_is_valid_ip_or_hostname($server)) {
            $input_errors[] = 'Server address must be a valid IPv4 address or hostname.';
        }

        $serverPort = (int)($post['server_port'] ?? 0);
        if ($serverPort < 1 || $serverPort > 65535) {
            $input_errors[] = 'Server port must be between 1 and 65535.';
        }

        $vlessUuid = trim($post['vless_uuid'] ?? '');
        if (!preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/',
            $vlessUuid
        )) {
            $input_errors[] = 'VLESS UUID must be in xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx format.';
        }

        $pubkey = trim($post['reality_pubkey'] ?? '');
        if ($pubkey === '') {
            $input_errors[] = 'Reality public key is required in wizard mode.';
        }
    }

    if ($configMode === 'custom') {
        $json = trim($post['custom_config'] ?? '');
        if ($json === '') {
            $input_errors[] = 'Custom config JSON is required when mode is Custom.';
        } elseif (json_decode($json, true) === null) {
            $input_errors[] = 'Custom config must be valid JSON.';
        }
    }
}

// ─── Uniqueness checks ────────────────────────────────────────────────────────

function xray_validate_tun_unique(string $ifname, ?string $editUuid): void
{
    global $input_errors;

    require_once('/usr/local/pkg/xray/includes/xray.inc');
    foreach (xray_get_instances() as $inst) {
        if (($inst['uuid'] ?? '') === $editUuid) {
            continue;
        }
        if (($inst['tun_interface'] ?? '') === $ifname) {
            $input_errors[] = 'TUN interface name "' . htmlspecialchars($ifname, ENT_QUOTES, 'UTF-8') . '" is already used by another instance.';
            return;
        }
    }
}

function xray_validate_socks_port_unique(int $port, ?string $editUuid): void
{
    global $input_errors;

    require_once('/usr/local/pkg/xray/includes/xray.inc');
    foreach (xray_get_instances() as $inst) {
        if (($inst['uuid'] ?? '') === $editUuid) {
            continue;
        }
        if ((int)($inst['socks5_port'] ?? 0) === $port) {
            $input_errors[] = 'SOCKS5 port ' . $port . ' is already used by another instance.';
            return;
        }
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function xray_is_valid_ip_or_hostname(string $value): bool
{
    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return true;
    }

    return (bool)preg_match(
        '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/',
        $value
    );
}

function xray_is_valid_cidr(string $net): bool
{
    if (strpos($net, '/') === false) {
        return false;
    }
    [$addr, $prefix] = explode('/', $net, 2);
    if (!filter_var($addr, FILTER_VALIDATE_IP)) {
        return false;
    }
    $prefix = (int)$prefix;
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $prefix >= 0 && $prefix <= 32;
    }
    return $prefix >= 0 && $prefix <= 128;
}
