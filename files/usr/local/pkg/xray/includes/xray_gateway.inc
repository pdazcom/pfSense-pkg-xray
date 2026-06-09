<?php
/**
 * xray_gateway.inc — Gateway registration with TCP-based health monitoring
 * 
 * Instead of ICMP (which VPN tunnels don't support), we use TCP port
 * reachability to known services to determine tunnel health.
 * 
 * This solves the "Monitor IP: leave blank" limitation by providing
 * a working health check mechanism for VPN gateways.
 */

require_once('config.inc');

// ─── Configuration ────────────────────────────────────────────────────────────

define('XRAY_MONITOR_PORT', 443);           // HTTPS/TLS test port
define('XRAY_MONITOR_IP', '1.1.1.1');      // Cloudflare DNS (reliable)
define('XRAY_MONITOR_INTERVAL', 300);       // 5 minutes (pfSense default)
define('XRAY_MONITOR_TIMEOUT', 5);          // 5 second TCP timeout

// ─── Gateway Registration ─────────────────────────────────────────────────────

/**
 * Register a TUN interface as a pfSense gateway with TCP-based health monitoring.
 * 
 * This creates a gateway in the pfSense routing system that can be used in:
 * - Policy-based routing
 * - Multi-WAN load balancing
 * - Gateway failover groups
 * 
 * @param string $ifname TUN interface name (e.g., tunproxy0)
 * @param string $uuid Instance UUID
 * @param string $gatewayName Human-readable gateway name (e.g., "XRAY_12345678")
 * @return bool Success status
 */
function xray_register_gateway(string $ifname, string $uuid, string $gatewayName): bool
{
    global $config;
    
    // Ensure gateway array exists
    if (!isset($config['gateways'])) {
        $config['gateways'] = [];
    }
    if (!isset($config['gateways']['gateway_item']) || !is_array($config['gateways']['gateway_item'])) {
        $config['gateways']['gateway_item'] = [];
    }
    
    $ip = xray_tun_ip_for_uuid($uuid);
    
    // Check if gateway already exists and update it
    foreach ($config['gateways']['gateway_item'] as $idx => $gw) {
        if (($gw['name'] ?? '') === $gatewayName) {
            // Update existing gateway configuration
            $config['gateways']['gateway_item'][$idx] = xray_gateway_config_array(
                $gatewayName, $ifname, $ip, $uuid
            );
            write_config('Xray: update gateway ' . $gatewayName);
            return true;
        }
    }
    
    // Create new gateway entry
    $config['gateways']['gateway_item'][] = xray_gateway_config_array(
        $gatewayName, $ifname, $ip, $uuid
    );
    
    write_config('Xray: register gateway ' . $gatewayName);
    return true;
}

/**
 * Build gateway configuration array for pfSense
 * 
 * @param string $name Gateway name
 * @param string $ifname Interface name
 * @param string $ip Gateway IP address
 * @param string $uuid Instance UUID
 * @return array Gateway configuration
 */
function xray_gateway_config_array(string $name, string $ifname, string $ip, string $uuid): array
{
    return [
        'name'           => $name,
        'interface'      => $ifname,
        'ipprotocol'     => 'inet',
        'gateway'        => $ip,
        'monitor'        => XRAY_MONITOR_IP,          // TCP target IP
        'monitorip'      => XRAY_MONITOR_IP,          // TCP monitor IP (not ICMP)
        'monitor_method' => 'tcp',                    // Use TCP, not ICMP
        'monitor_port'   => XRAY_MONITOR_PORT,        // HTTPS port
        'weight'         => '1',
        'status'         => 'active',
        'disabled'       => '',
        'descr'          => 'Xray ' . substr(strtoupper($uuid), 0, 8),
        'monitor_disable'=> '',                        // Enable monitoring
    ];
}

/**
 * Unregister a gateway by name
 * 
 * @param string $gatewayName Gateway name to remove
 * @return bool Success status
 */
function xray_unregister_gateway(string $gatewayName): bool
{
    global $config;
    
    if (!isset($config['gateways']['gateway_item']) || !is_array($config['gateways']['gateway_item'])) {
        return true;
    }
    
    foreach ($config['gateways']['gateway_item'] as $idx => $gw) {
        if (($gw['name'] ?? '') === $gatewayName) {
            unset($config['gateways']['gateway_item'][$idx]);
            // Re-index array
            $config['gateways']['gateway_item'] = array_values($config['gateways']['gateway_item']);
            write_config('Xray: unregister gateway ' . $gatewayName);
            return true;
        }
    }
    
    return false;
}

/**
 * Get gateway by instance UUID
 * 
 * @param string $uuid Instance UUID
 * @return array|null Gateway config or null if not found
 */
function xray_get_gateway_by_uuid(string $uuid): ?array
{
    global $config;
    
    if (!isset($config['gateways']['gateway_item'])) {
        return null;
    }
    
    $searchName = 'XRAY_' . substr(strtoupper($uuid), 0, 8);
    
    foreach ($config['gateways']['gateway_item'] as $gw) {
        if (($gw['name'] ?? '') === $searchName) {
            return $gw;
        }
    }
    
    return null;
}

// ─── Health Checking ──────────────────────────────────────────────────────────

/**
 * Perform TCP health check on a gateway
 * 
 * Tests TCP connectivity to a specified IP:port combination.
 * This is more reliable for VPN tunnels than ICMP (ping).
 * 
 * @param string $ip Target IP address
 * @param int $port Target port (default 443 for HTTPS)
 * @param int $timeout Timeout in seconds
 * @return array ['status' => 'up'|'down', 'latency_ms' => int, 'error' => ?string]
 */
function xray_tcp_health_check(string $ip, int $port = XRAY_MONITOR_PORT, int $timeout = XRAY_MONITOR_TIMEOUT): array
{
    $start = microtime(true);
    
    // Validate IP address
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'status' => 'down',
            'latency_ms' => -1,
            'error' => 'Invalid IP address'
        ];
    }
    
    // Attempt TCP connection
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    
    $elapsed = round((microtime(true) - $start) * 1000);
    
    if ($sock !== false) {
        @fclose($sock);
        return [
            'status' => 'up',
            'latency_ms' => max(1, $elapsed),  // At least 1ms
            'error' => null
        ];
    } else {
        // Connection failed
        $error = '';
        if ($errno === 110) {
            $error = 'Connection timed out';
        } elseif ($errno === 111) {
            $error = 'Connection refused';
        } elseif ($errno !== 0) {
            $error = 'Connection error: ' . $errstr;
        } else {
            $error = 'TCP connection failed';
        }
        
        return [
            'status' => 'down',
            'latency_ms' => -1,
            'error' => $error
        ];
    }
}

/**
 * Check health of a gateway via its monitor IP
 * 
 * @param string $uuid Instance UUID
 * @return array Health check result
 */
function xray_gateway_health_check(string $uuid): array
{
    $gateway = xray_get_gateway_by_uuid($uuid);
    
    if ($gateway === null) {
        return [
            'status' => 'unknown',
            'error' => 'Gateway not found',
            'gateway_name' => null
        ];
    }
    
    $monitorIp = $gateway['monitorip'] ?? XRAY_MONITOR_IP;
    $monitorPort = $gateway['monitor_port'] ?? XRAY_MONITOR_PORT;
    
    $result = xray_tcp_health_check($monitorIp, (int)$monitorPort);
    
    return array_merge($result, [
        'gateway_name' => $gateway['name'],
        'interface' => $gateway['interface'],
        'monitor_ip' => $monitorIp,
        'monitor_port' => $monitorPort
    ]);
}

/**
 * Get status of all Xray gateways
 * 
 * Useful for monitoring dashboard
 * 
 * @return array Array of [uuid => health_data]
 */
function xray_get_all_gateway_status(): array
{
    $instances = xray_get_instances();
    $status = [];
    
    foreach ($instances as $inst) {
        $uuid = $inst['uuid'] ?? '';
        if ($uuid !== '') {
            $status[$uuid] = xray_gateway_health_check($uuid);
        }
    }
    
    return $status;
}

// ─── Configuration ────────────────────────────────────────────────────────────

/**
 * Get Xray gateway monitoring configuration from settings
 * 
 * @return array Configuration array
 */
function xray_get_gateway_settings(): array
{
    $defaults = [
        'enable_gateway_monitoring' => '1',
        'monitor_ip' => XRAY_MONITOR_IP,
        'monitor_port' => XRAY_MONITOR_PORT,
        'monitor_timeout' => XRAY_MONITOR_TIMEOUT,
        'monitor_interval' => XRAY_MONITOR_INTERVAL,
    ];
    
    global $config;
    $saved = $config['installedpackages']['xray']['gateway_settings'] ?? [];
    
    return array_merge($defaults, $saved);
}

/**
 * Save Xray gateway monitoring settings
 * 
 * @param array $settings Settings to save
 * @return void
 */
function xray_save_gateway_settings(array $settings): void
{
    global $config;
    
    if (!isset($config['installedpackages']['xray'])) {
        $config['installedpackages']['xray'] = [];
    }
    
    $config['installedpackages']['xray']['gateway_settings'] = $settings;
    write_config('Xray: update gateway settings');
}

?>
