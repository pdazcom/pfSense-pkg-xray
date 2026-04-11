<?php

/**
 * xray_ajax.php — AJAX dispatcher for Xray package GUI.
 *
 * Handles: statusall, start, stop, restart, import (VLESS parser),
 *          ifstats, testconnect, validate, log, version.
 */

$nocsrf = true;
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action parameter']);
    exit;
}

$uuid = trim($_POST['uuid'] ?? $_GET['uuid'] ?? '');
$uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $uuid);
if (strlen($uuid) < 36) {
    $uuid = '';
}



// ─── Dispatcher ───────────────────────────────────────────────────────────────
switch ($action) {

    case 'statusall':
        $out = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php statusall 2>/dev/null', $out);
        $json  = implode('', $out);
        $data  = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode([]);
        break;

    case 'status':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php status' . $args . ' 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['status' => 'unknown']);
        break;

    case 'start':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php start' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'stop':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php stop' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'restart':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php restart' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result' => $rc === 0 ? 'ok' : 'failed',
            'output' => implode("\n", $out),
        ]);
        break;

    case 'import':
        $rawBody = file_get_contents('php://input');
        $link = '';
        $socksListen = '127.0.0.1';
        $socksPort   = 10808;

        if (!empty($rawBody)) {
            $bodyJson = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($bodyJson['link_b64'])) {
                    $decoded = base64_decode($bodyJson['link_b64'], true);
                    $link = $decoded !== false ? trim($decoded) : '';
                } elseif (!empty($bodyJson['link'])) {
                    $link = trim($bodyJson['link']);
                }
                if (isset($bodyJson['socks5_listen'])) {
                    $socksListen = trim((string)$bodyJson['socks5_listen']);
                }
                if (isset($bodyJson['socks5_port'])) {
                    $socksPort = (int)$bodyJson['socks5_port'];
                }
            }
        }

        if ($link === '') {
            $b64 = trim($_POST['link_b64'] ?? '');
            if ($b64 !== '') {
                $decoded = base64_decode($b64, true);
                $link    = $decoded !== false ? trim($decoded) : '';
            }
        }
        if ($link === '') {
            $link = trim($_POST['link'] ?? '');
        }
        if ($socksListen === '127.0.0.1') {
            $postListen = trim($_POST['socks5_listen'] ?? '');
            if ($postListen !== '') {
                $socksListen = $postListen;
            }
        }
        if ($socksPort === 10808 && isset($_POST['socks5_port'])) {
            $socksPort = (int)$_POST['socks5_port'];
        }

        if ($link === '') {
            echo json_encode(['status' => 'error', 'message' => 'No VLESS link provided']);
            break;
        }
        if (strlen($link) > 2048) {
            echo json_encode(['status' => 'error', 'message' => 'Link too long']);
            break;
        }

        if (!filter_var($socksListen, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $socksListen = '127.0.0.1';
        }
        if ($socksPort < 1 || $socksPort > 65535) {
            $socksPort = 10808;
        }

        $data = xray_parse_vless_link($link, $socksListen, $socksPort);
        if (isset($data['error'])) {
            echo json_encode(['status' => 'error', 'message' => $data['error']]);
        } else {
            $data['status'] = 'ok';
            echo json_encode($data);
        }
        break;

    case 'ifstats':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-ifstats.php' . $args . ' 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['error' => 'Failed to get interface stats']);
        break;

    case 'testconnect':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-testconnect.php' . $args . ' 2>/dev/null', $out, $rc);
        $code = (int)trim(implode('', $out));
        echo json_encode([
            'result'    => ($code >= 200 && $code < 400) ? 'ok' : 'failed',
            'http_code' => $code,
        ]);
        break;

    case 'validate':
        $args = $uuid !== '' ? (' ' . escapeshellarg($uuid)) : '';
        $out  = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php validate' . $args . ' 2>&1', $out, $rc);
        echo json_encode([
            'result'  => $rc === 0 ? 'ok' : 'failed',
            'message' => implode("\n", $out),
        ]);
        break;

    case 'log':
        $lines = [];
        exec('/usr/bin/tail -n 200 /var/log/xray-core.log 2>/dev/null', $lines);
        echo json_encode(['log' => implode("\n", $lines)]);
        break;

    case 'watchdoglog':
        $lines = [];
        exec('/usr/bin/tail -n 100 /var/log/xray-watchdog.log 2>/dev/null', $lines);
        echo json_encode(['log' => implode("\n", $lines)]);
        break;

    case 'version':
        $out = [];
        exec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php version 2>/dev/null', $out);
        $json = implode('', $out);
        $data = json_decode($json, true);
        echo ($data !== null) ? $json : json_encode(['version' => 'unknown']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')]);
}

// ─── VLESS parser ────────────────────────────────────────────────────────────

function xray_parse_vless_link(string $link, string $socksListen = '127.0.0.1', int $socksPort = 10808): array
{
    $link = trim($link, " \t\n\r\0\x0B\"'");

    if (strpos($link, 'vless://') !== 0) {
        return ['error' => 'Link must start with vless://'];
    }

    $rest = substr($link, 8);

    $name = '';
    if (($hashPos = strrpos($rest, '#')) !== false) {
        $name = htmlspecialchars(urldecode(substr($rest, $hashPos + 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rest = substr($rest, 0, $hashPos);
    }

    $query = '';
    if (($qPos = strpos($rest, '?')) !== false) {
        $query = substr($rest, $qPos + 1);
        $rest  = substr($rest, 0, $qPos);
    }

    $atPos = strrpos($rest, '@');
    if ($atPos === false) {
        return ['error' => 'Missing @ separator between UUID and host'];
    }

    $uuid     = substr($rest, 0, $atPos);
    $hostport = substr($rest, $atPos + 1);

    if (substr($hostport, 0, 1) === '[') {
        $closeBracket = strpos($hostport, ']');
        if ($closeBracket === false) {
            return ['error' => 'Invalid IPv6 address format'];
        }
        $host    = substr($hostport, 1, $closeBracket - 1);
        $portStr = ltrim(substr($hostport, $closeBracket + 1), ':');
    } else {
        $lastColon = strrpos($hostport, ':');
        if ($lastColon === false) {
            return ['error' => 'Missing port in host:port'];
        }
        $host    = substr($hostport, 0, $lastColon);
        $portStr = substr($hostport, $lastColon + 1);
    }

    $port = (int)$portStr;
    if ($port <= 0 || $port > 65535) {
        return ['error' => 'Invalid port: ' . $portStr];
    }

    if (empty($uuid)) {
        return ['error' => 'UUID is empty'];
    }
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $uuid)) {
        return ['error' => 'Invalid UUID format (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'];
    }
    if (empty($host)) {
        return ['error' => 'Host is empty'];
    }

    parse_str($query, $params);

    $type     = $params['type']     ?? 'tcp';
    $security = $params['security'] ?? 'reality';
    $isWizard = ($type === 'tcp' || $type === '') && $security === 'reality';

    $customConfig = '';
    if (!$isWizard) {
        $customConfig = xray_build_custom_config($uuid, $host, $port, $params, $socksListen, $socksPort);
    }

    $flow = $params['flow'] ?? '';
    if (empty($flow)) {
        $flow = 'xtls-rprx-vision';
    }

    $fp = $params['fp'] ?? '';
    if (empty($fp)) {
        $fp = 'chrome';
    }

    $allowedFlow = ['xtls-rprx-vision', 'none', ''];
    $allowedFp   = ['chrome', 'firefox', 'safari', 'edge', 'random'];
    $flow = in_array($flow, $allowedFlow, true) ? $flow : 'xtls-rprx-vision';
    $fp   = in_array($fp,   $allowedFp,   true) ? $fp   : 'chrome';

    $sanitize = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $result = [
        'vless_uuid'  => $sanitize($uuid),
        'host'        => $sanitize($host),
        'port'        => $port,
        'flow'        => $flow,
        'sni'         => $sanitize($params['sni'] ?? ''),
        'pbk'         => $sanitize($params['pbk'] ?? ''),
        'sid'         => $sanitize($params['sid'] ?? ''),
        'fp'          => $fp,
        'type'        => $sanitize($type),
        'security'    => $sanitize($security),
        'name'        => $name,
        'config_mode' => $isWizard ? 'wizard' : 'custom',
    ];

    if (!$isWizard) {
        $result['custom_config'] = $customConfig;
    }

    return $result;
}

function xray_build_custom_config(
    string $uuid,
    string $host,
    int $port,
    array $params,
    string $socksListen = '127.0.0.1',
    int $socksPort = 10808
): string {
    $type       = $params['type']       ?? 'tcp';
    $security   = $params['security']   ?? 'none';
    $encryption = $params['encryption'] ?? 'none';
    $flow       = $params['flow']       ?? '';

    if ($type !== 'tcp') {
        $flow = '';
    }

    $config = [
        'log' => ['loglevel' => 'warning'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $socksPort,
            'listen'   => $socksListen,
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $socksListen],
        ]],
        'outbounds' => [
            [
                'tag'      => 'proxy',
                'protocol' => 'vless',
                'settings' => [
                    'vnext' => [[
                        'address' => $host,
                        'port'    => $port,
                        'users'   => [[
                            'id'         => $uuid,
                            'encryption' => $encryption,
                            'flow'       => $flow,
                        ]],
                    ]],
                ],
                'streamSettings' => xray_build_stream_settings($type, $security, $params),
            ],
            ['tag' => 'direct', 'protocol' => 'freedom'],
        ],
        'routing' => [
            'domainStrategy' => 'IPIfNonMatch',
            'rules' => [[
                'type'        => 'field',
                'ip'          => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
                'outboundTag' => 'direct',
            ]],
        ],
    ];

    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function xray_build_stream_settings(string $type, string $security, array $params): array
{
    $ss = [
        'network'  => $type,
        'security' => $security,
    ];

    if ($security === 'reality') {
        $ss['realitySettings'] = [
            'serverName'  => $params['sni'] ?? '',
            'fingerprint' => $params['fp']  ?? 'chrome',
            'show'        => false,
            'publicKey'   => $params['pbk'] ?? '',
            'shortId'     => $params['sid'] ?? '',
            'spiderX'     => $params['spx'] ?? '',
        ];
    } elseif ($security === 'tls') {
        $tls = [
            'serverName'  => $params['sni'] ?? '',
            'fingerprint' => $params['fp']  ?? 'chrome',
        ];
        if (!empty($params['alpn'])) {
            $tls['alpn'] = explode(',', $params['alpn']);
        }
        $ss['tlsSettings'] = $tls;
    }

    switch ($type) {
        case 'xhttp':
            $xhttp = [];
            if (!empty($params['path'])) {
                $xhttp['path'] = $params['path'];
            }
            if (!empty($params['host'])) {
                $xhttp['host'] = $params['host'];
            }
            if (!empty($params['mode'])) {
                $xhttp['mode'] = $params['mode'];
            }
            if (!empty($xhttp)) {
                $ss['xhttpSettings'] = $xhttp;
            }
            break;

        case 'ws':
            $ws = [];
            if (!empty($params['path'])) {
                $ws['path'] = $params['path'];
            }
            if (!empty($params['host'])) {
                $ws['headers'] = ['Host' => $params['host']];
            }
            if (!empty($ws)) {
                $ss['wsSettings'] = $ws;
            }
            break;

        case 'grpc':
            $grpc = [];
            if (!empty($params['serviceName'])) {
                $grpc['serviceName'] = $params['serviceName'];
            }
            if (!empty($params['mode'])) {
                $grpc['multiMode'] = ($params['mode'] === 'multi');
            }
            if (!empty($grpc)) {
                $ss['grpcSettings'] = $grpc;
            }
            break;

        case 'h2':
        case 'http':
            $h2 = [];
            if (!empty($params['path'])) {
                $h2['path'] = $params['path'];
            }
            if (!empty($params['host'])) {
                $h2['host'] = [$params['host']];
            }
            if (!empty($h2)) {
                $ss['httpSettings'] = $h2;
            }
            break;

        case 'kcp':
            $kcp = [];
            if (!empty($params['headerType'])) {
                $kcp['header'] = ['type' => $params['headerType']];
            }
            if (!empty($params['seed'])) {
                $kcp['seed'] = $params['seed'];
            }
            if (!empty($kcp)) {
                $ss['kcpSettings'] = $kcp;
            }
            break;

        case 'tcp':
            if (!empty($params['headerType']) && $params['headerType'] === 'http') {
                $tcp = ['header' => ['type' => 'http']];
                if (!empty($params['path'])) {
                    $tcp['header']['request'] = ['path' => explode(',', $params['path'])];
                }
                if (!empty($params['host'])) {
                    if (!isset($tcp['header']['request'])) {
                        $tcp['header']['request'] = [];
                    }
                    $tcp['header']['request']['headers'] = ['Host' => explode(',', $params['host'])];
                }
                $ss['tcpSettings'] = $tcp;
            }
            break;
    }

    return $ss;
}
