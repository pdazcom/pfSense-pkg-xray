<?php

/**
 * xray_vless.inc — VLESS link parser and config builder.
 *
 * Shared between xray_ajax.php (web context) and CLI scripts
 * (xray-subscription-update.php, etc.).
 */

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
