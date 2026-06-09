<?php
/**
 * xray_gateway_status.php — Gateway health check API endpoint
 * 
 * Provides real-time TCP-based health status for Xray gateways.
 * Called from JavaScript to update gateway status indicators in the UI.
 * 
 * Usage:
 *   GET /xray/xray_gateway_status.php?uuid=<instance-uuid>
 *   GET /xray/xray_gateway_status.php?action=all
 */

##|+PRIV
##|*IDENT=page-vpn-xray-gateway-status
##|*NAME=VPN: Xray: Gateway Status
##|*DESCR=Allow access to the 'VPN: Xray: Gateway Status' API.
##|*MATCH=xray_gateway_status.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_gateway.inc');

header('Content-Type: application/json; charset=utf-8');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Determine what to check
$action = $_GET['action'] ?? $_POST['action'] ?? 'single';
$uuid = xray_sanitize_uuid($_GET['uuid'] ?? $_POST['uuid'] ?? '');

// If requesting all statuses
if ($action === 'all') {
    $allStatuses = xray_get_all_gateway_status();
    echo json_encode([
        'action' => 'all',
        'timestamp' => time(),
        'gateways' => $allStatuses,
        'count' => count($allStatuses)
    ]);
    exit;
}

// Single gateway status
if ($uuid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'UUID parameter required']);
    exit;
}

// Get instance to verify it exists
$instance = xray_get_instance_by_uuid($uuid);
if ($instance === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Instance not found']);
    exit;
}

// Perform health check
$health = xray_gateway_health_check($uuid);
$gateway = xray_get_gateway_by_uuid($uuid);

// Build response
$response = [
    'uuid' => $uuid,
    'instance_name' => $instance['name'],
    'tun_interface' => $instance['tun_interface'],
    'gateway' => $gateway ? [
        'name' => $gateway['name'],
        'ip' => $gateway['gateway'],
    ] : null,
    'health' => [
        'status' => $health['status'],
        'latency_ms' => $health['latency_ms'],
        'monitor_ip' => $health['monitor_ip'],
        'monitor_port' => $health['monitor_port'],
        'error' => $health['error'] ?? null
    ],
    'timestamp' => time()
];

echo json_encode($response);
?>
