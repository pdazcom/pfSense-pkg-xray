<?php

/**
 * xray_vless.inc — VLESS link parser and config builder.
 *
 * Shared between xray_ajax.php (web context) and CLI scripts
 * (xray-subscription-update.php, etc.).
 */

/**
 * Parse an xray-core config JSON into structured form fields.
 * Returns an array with 'recognized' => bool and all form fields.
 * If 'recognized' is false, only 'protocol' = 'unknown' is set.
 */
function xray_parse_config_json(string $json): array
{
    if ($json === '') {
        return ['recognized' => false];
    }

    $cfg = json_decode($json, true);
    if (!is_array($cfg)) {
        return ['recognized' => false];
    }

    $outbounds = $cfg['outbounds'] ?? [];
    $proxy = null;
    foreach ($outbounds as $ob) {
        if (($ob['tag'] ?? '') === 'proxy' || $proxy === null) {
            $proxy = $ob;
            if (($ob['tag'] ?? '') === 'proxy') {
                break;
            }
        }
    }

    if ($proxy === null) {
        return ['recognized' => false];
    }

    $protocol = $proxy['protocol'] ?? 'unknown';
    $ss       = $proxy['streamSettings'] ?? [];
    $settings = $proxy['settings'] ?? [];

    $wireNetwork = $ss['network'] ?? 'tcp';
    $network     = ($wireNetwork === 'tcp') ? 'raw' : $wireNetwork;
    $security    = $ss['security'] ?? 'none';

    $fields = [
        'recognized' => true,
        'protocol'   => $protocol,
        'network'    => $network,
        'security'   => $security,
    ];

    $inbound = null;
    foreach (($cfg['inbounds'] ?? []) as $ib) {
        if (($ib['protocol'] ?? '') === 'socks') {
            $inbound = $ib;
            break;
        }
    }

    switch ($protocol) {
        case 'vless':
            $vnext = $settings['vnext'][0] ?? [];
            $user  = $vnext['users'][0]    ?? [];
            $fields['server_address'] = $vnext['address'] ?? '';
            $fields['server_port']    = (string)($vnext['port'] ?? 443);
            $fields['vless_uuid']     = $user['id']           ?? '';
            $fields['encryption']     = $user['encryption']   ?? 'none';
            $fields['flow']           = $user['flow']         ?? '';
            break;

        case 'vmess':
            $vnext = $settings['vnext'][0] ?? [];
            $user  = $vnext['users'][0]    ?? [];
            $fields['server_address'] = $vnext['address'] ?? '';
            $fields['server_port']    = (string)($vnext['port'] ?? 443);
            $fields['vless_uuid']     = $user['id']       ?? '';
            $fields['vmess_alterId']  = (string)($user['alterId'] ?? 0);
            $fields['vmess_security'] = $user['security'] ?? 'auto';
            break;

        case 'trojan':
            $server = $settings['servers'][0] ?? [];
            $fields['server_address']  = $server['address']  ?? '';
            $fields['server_port']     = (string)($server['port'] ?? 443);
            $fields['trojan_password'] = $server['password'] ?? '';
            break;

        case 'shadowsocks':
            $server = $settings['servers'][0] ?? [];
            $fields['server_address'] = $server['address']  ?? '';
            $fields['server_port']    = (string)($server['port'] ?? 443);
            $fields['ss_method']      = $server['method']   ?? 'aes-256-gcm';
            $fields['ss_password']    = $server['password'] ?? '';
            break;

        case 'http':
            $server = $settings['servers'][0] ?? [];
            $fields['server_address'] = $server['address']  ?? '';
            $fields['server_port']    = (string)($server['port'] ?? 443);
            $user = $server['users'][0] ?? [];
            $fields['http_username']  = $user['user']     ?? '';
            $fields['http_password']  = $user['pass']     ?? '';
            break;

        case 'socks':
            $server = $settings['servers'][0] ?? [];
            $fields['server_address'] = $server['address'] ?? '';
            $fields['server_port']    = (string)($server['port'] ?? 1080);
            $user = $server['users'][0] ?? [];
            $fields['http_username']  = $user['user'] ?? '';
            $fields['http_password']  = $user['pass'] ?? '';
            break;

        default:
            $fields['recognized'] = false;
            return $fields;
    }

    $realityCfg = $ss['realitySettings'] ?? [];
    $tlsCfg     = $ss['tlsSettings']     ?? [];

    $fields['reality_sni']         = $realityCfg['serverName']  ?? '';
    $fields['reality_fingerprint'] = $realityCfg['fingerprint'] ?? 'chrome';
    $fields['reality_pubkey']      = $realityCfg['publicKey']   ?? '';
    $fields['reality_shortid']     = $realityCfg['shortId']     ?? '';
    $fields['reality_spiderx']     = $realityCfg['spiderX']     ?? '/';

    $fields['tls_sni']         = $tlsCfg['serverName']  ?? '';
    $fields['tls_fingerprint'] = $tlsCfg['fingerprint'] ?? 'chrome';
    $fields['tls_alpn']        = isset($tlsCfg['alpn']) ? implode(',', $tlsCfg['alpn']) : '';

    $xhttpCfg = $ss['xhttpSettings'] ?? $ss['splithttpSettings'] ?? [];
    $wsCfg    = $ss['wsSettings']    ?? [];
    $h2Cfg    = $ss['httpSettings']  ?? [];
    $grpcCfg  = $ss['grpcSettings']  ?? [];
    $huCfg    = $ss['httpupgradeSettings'] ?? [];

    switch ($network) {
        case 'xhttp':
            $fields['transport_path']    = $xhttpCfg['path']  ?? '';
            $fields['transport_host']    = $xhttpCfg['host']  ?? '';
            $fields['xhttp_mode']        = $xhttpCfg['mode']  ?? 'stream-one';
            break;
        case 'ws':
            $fields['transport_path']    = $wsCfg['path']                  ?? '';
            $fields['transport_host']    = $wsCfg['headers']['Host']       ?? '';
            break;
        case 'h2':
            $fields['transport_path']    = $h2Cfg['path']                  ?? '';
            $fields['transport_host']    = $h2Cfg['host'][0]               ?? '';
            break;
        case 'grpc':
            $fields['grpc_service_name'] = $grpcCfg['serviceName']         ?? '';
            break;
        case 'httpupgrade':
            $fields['transport_path']    = $huCfg['path']                  ?? '';
            $fields['transport_host']    = $huCfg['host']                  ?? '';
            break;
    }

    $muxCfg = $proxy['mux'] ?? null;
    if ($muxCfg === null) {
        $fields['mux'] = '';
    } elseif (!empty($muxCfg['enabled'])) {
        $fields['mux'] = 'enabled';
    } else {
        $fields['mux'] = 'disabled';
    }

    return $fields;
}

/**
 * Build an xray-core config JSON from structured form fields.
 * $f — form fields array, $inst — instance settings (socks5_listen/port, loglevel, bypass_networks).
 */
function xray_build_config_json(array $f, array $inst = []): string
{
    $socksListen  = $inst['socks5_listen']   ?? '127.0.0.1';
    $socksPort    = (int)($inst['socks5_port']    ?? 10808);
    $loglevel     = $inst['loglevel']        ?? 'warning';
    $bypassRaw    = $inst['bypass_networks'] ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
    $bypassNets   = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }

    $protocol = $f['protocol'] ?? 'vless';
    $network  = $f['network']  ?? 'raw';
    $security = $f['security'] ?? 'none';
    $host     = $f['server_address'] ?? '';
    $port     = (int)($f['server_port'] ?? 443);

    $streamParams = [
        'sni'         => $security === 'tls' ? ($f['tls_sni'] ?? '')         : ($f['reality_sni'] ?? ''),
        'fp'          => $security === 'tls' ? ($f['tls_fingerprint'] ?? 'chrome') : ($f['reality_fingerprint'] ?? 'chrome'),
        'pbk'         => $f['reality_pubkey']      ?? '',
        'sid'         => $f['reality_shortid']     ?? '',
        'spx'         => $f['reality_spiderx']     ?? '/',
        'alpn'        => $f['tls_alpn']            ?? '',
        'path'        => $f['transport_path']      ?? '',
        'host'        => $f['transport_host']      ?? '',
        'mode'        => $f['xhttp_mode']          ?? 'stream-one',
        'serviceName' => $f['grpc_service_name']   ?? '',
    ];

    $streamSettings = xray_build_stream_settings($network, $security, $streamParams);

    switch ($protocol) {
        case 'vmess':
            $outboundSettings = [
                'vnext' => [[
                    'address' => $host,
                    'port'    => $port,
                    'users'   => [[
                        'id'       => $f['vless_uuid']     ?? '',
                        'alterId'  => (int)($f['vmess_alterId']  ?? 0),
                        'security' => $f['vmess_security'] ?? 'auto',
                    ]],
                ]],
            ];
            break;

        case 'trojan':
            $outboundSettings = [
                'servers' => [[
                    'address'  => $host,
                    'port'     => $port,
                    'password' => $f['trojan_password'] ?? '',
                ]],
            ];
            break;

        case 'shadowsocks':
            $outboundSettings = [
                'servers' => [[
                    'address'  => $host,
                    'port'     => $port,
                    'method'   => $f['ss_method']   ?? 'aes-256-gcm',
                    'password' => $f['ss_password'] ?? '',
                ]],
            ];
            $streamSettings = [];
            break;

        case 'http':
        case 'socks':
            $server = ['address' => $host, 'port' => $port];
            $user   = $f['http_username'] ?? '';
            $pass   = $f['http_password'] ?? '';
            if ($user !== '') {
                $server['users'] = [['user' => $user, 'pass' => $pass]];
            }
            $outboundSettings = ['servers' => [$server]];
            $streamSettings   = [];
            break;

        default:
            $flow = ($f['flow'] ?? 'xtls-rprx-vision');
            if ($flow === 'none') {
                $flow = '';
            }
            if ($network !== 'raw') {
                $flow = '';
            }
            $outboundSettings = [
                'vnext' => [[
                    'address' => $host,
                    'port'    => $port,
                    'users'   => [[
                        'id'         => $f['vless_uuid']  ?? '',
                        'encryption' => $f['encryption']  ?? 'none',
                        'flow'       => $flow,
                    ]],
                ]],
            ];
    }

    $outbound = [
        'tag'      => 'proxy',
        'protocol' => $protocol,
        'settings' => $outboundSettings,
    ];
    if (!empty($streamSettings)) {
        $outbound['streamSettings'] = $streamSettings;
    }

    $mux = $f['mux'] ?? '';
    if ($mux === 'enabled') {
        $outbound['mux'] = ['enabled' => true,  'concurrency' => 8];
    } elseif ($mux === 'disabled') {
        $outbound['mux'] = ['enabled' => false, 'concurrency' => -1];
    }

    $config = [
        'log'      => ['loglevel' => $loglevel],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $socksPort,
            'listen'   => $socksListen,
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $socksListen],
        ]],
        'outbounds' => [
            $outbound,
            ['tag' => 'direct', 'protocol' => 'freedom'],
        ],
        'routing' => [
            'domainStrategy' => 'IPIfNonMatch',
            'rules' => [[
                'type'        => 'field',
                'ip'          => $bypassNets,
                'outboundTag' => 'direct',
            ]],
        ],
    ];

    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Parse any supported proxy share link into form fields.
 * Supported schemes: vless://, vmess://, trojan://, ss://
 * Returns array with form fields on success, or ['error' => '...'] on failure.
 */
function xray_parse_vless_link(string $link): array
{
    $link = trim($link, " \t\n\r\0\x0B\"'");

    if (strpos($link, 'vmess://') === 0) {
        return xray_parse_vmess_link($link);
    }
    if (strpos($link, 'trojan://') === 0) {
        return xray_parse_trojan_link($link);
    }
    if (strpos($link, 'ss://') === 0) {
        return xray_parse_ss_link($link);
    }
    if (strpos($link, 'vless://') === 0) {
        return xray_parse_vless_link_inner($link);
    }

    return ['error' => 'Unsupported link scheme. Supported: vless://, vmess://, trojan://, ss://'];
}

function xray_parse_vless_link_inner(string $link): array
{
    $sanitize = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

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

    [$host, $port, $err] = xray_split_hostport($hostport);
    if ($err !== '') {
        return ['error' => $err];
    }

    if (empty($uuid)) {
        return ['error' => 'UUID is empty'];
    }
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $uuid)) {
        return ['error' => 'Invalid UUID format'];
    }

    parse_str($query, $params);

    $type     = $params['type']     ?? 'tcp';
    $security = $params['security'] ?? 'none';
    $type     = ($type === 'tcp' || $type === '') ? 'raw' : $type;

    $allowedFp = ['chrome', 'firefox', 'safari', 'edge', '360', 'qq', 'ios', 'android', 'random', 'randomized'];
    $fp        = in_array($params['fp'] ?? '', $allowedFp, true) ? $params['fp'] : 'chrome';
    $flow      = ($params['flow'] ?? '') === 'xtls-rprx-vision' ? 'xtls-rprx-vision' : 'none';

    return [
        'protocol'          => 'vless',
        'host'              => $sanitize($host),
        'port'              => $port,
        'vless_uuid'        => $sanitize($uuid),
        'flow'              => $flow,
        'network'           => $sanitize($type),
        'security'          => $sanitize($security),
        'sni'               => $sanitize($params['sni'] ?? ''),
        'pbk'               => $sanitize($params['pbk'] ?? ''),
        'sid'               => $sanitize($params['sid'] ?? ''),
        'spx'               => $sanitize($params['spx'] ?? '/'),
        'fp'                => $fp,
        'transport_path'    => $sanitize($params['path'] ?? ''),
        'transport_host'    => $sanitize($params['host'] ?? ''),
        'xhttp_mode'        => $sanitize($params['mode'] ?? 'stream-one'),
        'grpc_service_name' => $sanitize($params['serviceName'] ?? ''),
        'name'              => $name,
    ];
}

function xray_parse_vmess_link(string $link): array
{
    $b64 = substr($link, 8);
    $json = base64_decode($b64, true);
    if ($json === false) {
        return ['error' => 'Invalid base64 in vmess:// link'];
    }
    $v = json_decode($json, true);
    if (!is_array($v)) {
        return ['error' => 'Invalid JSON in vmess:// link'];
    }

    $host = $v['add'] ?? '';
    $port = (int)($v['port'] ?? 0);
    if ($host === '' || $port <= 0) {
        return ['error' => 'Missing host or port in vmess link'];
    }

    $net      = $v['net']  ?? 'tcp';
    $network  = ($net === 'tcp' || $net === '') ? 'raw' : $net;
    $security = $v['tls']  ?? 'none';
    if ($security === '') {
        $security = 'none';
    }

    $allowedFp = ['chrome', 'firefox', 'safari', 'edge', '360', 'qq', 'ios', 'android', 'random', 'randomized'];
    $fp        = in_array($v['fp'] ?? '', $allowedFp, true) ? $v['fp'] : 'chrome';

    $sanitize = static function (string $val): string {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    return [
        'protocol'          => 'vmess',
        'host'              => $sanitize($host),
        'port'              => $port,
        'vmess_uuid'        => $sanitize($v['id']       ?? ''),
        'vmess_alterId'     => (string)(int)($v['aid']  ?? 0),
        'vmess_security'    => $sanitize($v['scy']       ?? 'auto') ?: 'auto',
        'network'           => $sanitize($network),
        'security'          => $sanitize($security),
        'sni'               => $sanitize($v['sni']       ?? ''),
        'fp'                => $fp,
        'transport_path'    => $sanitize($v['path']      ?? ''),
        'transport_host'    => $sanitize($v['host']      ?? ''),
        'grpc_service_name' => $sanitize($v['serviceName'] ?? ($v['path'] ?? '')),
        'name'              => htmlspecialchars($v['ps'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ];
}

function xray_parse_trojan_link(string $link): array
{
    $sanitize = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $rest = substr($link, 9);

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
        return ['error' => 'Missing @ in trojan link'];
    }

    $password = urldecode(substr($rest, 0, $atPos));
    $hostport = substr($rest, $atPos + 1);

    [$host, $port, $err] = xray_split_hostport($hostport);
    if ($err !== '') {
        return ['error' => $err];
    }

    parse_str($query, $params);

    $type     = $params['type']     ?? 'tcp';
    $security = $params['security'] ?? 'tls';
    $network  = ($type === 'tcp' || $type === '') ? 'raw' : $type;
    if ($security === '') {
        $security = 'tls';
    }

    $allowedFp = ['chrome', 'firefox', 'safari', 'edge', '360', 'qq', 'ios', 'android', 'random', 'randomized'];
    $fp        = in_array($params['fp'] ?? '', $allowedFp, true) ? $params['fp'] : 'chrome';

    return [
        'protocol'          => 'trojan',
        'host'              => $sanitize($host),
        'port'              => $port,
        'trojan_password'   => $sanitize($password),
        'network'           => $sanitize($network),
        'security'          => $sanitize($security),
        'sni'               => $sanitize($params['sni'] ?? ''),
        'fp'                => $fp,
        'transport_path'    => $sanitize($params['path'] ?? ''),
        'transport_host'    => $sanitize($params['host'] ?? ''),
        'grpc_service_name' => $sanitize($params['serviceName'] ?? ''),
        'name'              => $name,
    ];
}

function xray_parse_ss_link(string $link): array
{
    $sanitize = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $rest = substr($link, 5);

    $name = '';
    if (($hashPos = strrpos($rest, '#')) !== false) {
        $name = htmlspecialchars(urldecode(substr($rest, $hashPos + 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rest = substr($rest, 0, $hashPos);
    }

    $atPos = strrpos($rest, '@');
    if ($atPos === false) {
        $decoded = base64_decode($rest, true);
        if ($decoded !== false) {
            $rest  = $decoded;
            $atPos = strrpos($rest, '@');
        }
    }

    if ($atPos === false) {
        return ['error' => 'Cannot parse ss:// link: missing @ separator'];
    }

    $userinfo = substr($rest, 0, $atPos);
    $hostport = substr($rest, $atPos + 1);

    if (strpos($userinfo, ':') === false) {
        $decoded = base64_decode($userinfo, true);
        if ($decoded !== false) {
            $userinfo = $decoded;
        }
    }

    $colonPos = strpos($userinfo, ':');
    if ($colonPos === false) {
        return ['error' => 'Cannot parse method:password in ss:// link'];
    }

    $method   = substr($userinfo, 0, $colonPos);
    $password = substr($userinfo, $colonPos + 1);

    [$host, $port, $err] = xray_split_hostport($hostport);
    if ($err !== '') {
        return ['error' => $err];
    }

    $allowedMethods = [
        'aes-256-gcm', 'aes-128-gcm', 'chacha20-poly1305', 'chacha20-ietf-poly1305',
        '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305',
    ];
    if (!in_array($method, $allowedMethods, true)) {
        return ['error' => 'Unsupported Shadowsocks method: ' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8')];
    }

    return [
        'protocol'    => 'shadowsocks',
        'host'        => $sanitize($host),
        'port'        => $port,
        'ss_method'   => $sanitize($method),
        'ss_password' => $sanitize($password),
        'network'     => 'raw',
        'security'    => 'none',
        'name'        => $name,
    ];
}

function xray_split_hostport(string $hostport): array
{
    if (substr($hostport, 0, 1) === '[') {
        $closeBracket = strpos($hostport, ']');
        if ($closeBracket === false) {
            return ['', 0, 'Invalid IPv6 address format'];
        }
        $host    = substr($hostport, 1, $closeBracket - 1);
        $portStr = ltrim(substr($hostport, $closeBracket + 1), ':');
    } else {
        $lastColon = strrpos($hostport, ':');
        if ($lastColon === false) {
            return ['', 0, 'Missing port in host:port'];
        }
        $host    = substr($hostport, 0, $lastColon);
        $portStr = substr($hostport, $lastColon + 1);
    }

    $port = (int)$portStr;
    if ($port <= 0 || $port > 65535) {
        return ['', 0, 'Invalid port: ' . $portStr];
    }
    if ($host === '') {
        return ['', 0, 'Host is empty'];
    }

    return [$host, $port, ''];
}

function xray_build_custom_config(
    string $uuid,
    string $host,
    int $port,
    array $params,
    string $socksListen = '127.0.0.1',
    int $socksPort = 10808
): string {
    $type       = $params['type']       ?? 'raw';
    $security   = $params['security']   ?? 'none';
    $encryption = $params['encryption'] ?? 'none';
    $flow       = $params['flow']       ?? '';

    if ($type !== 'raw' && $type !== 'tcp') {
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
    $wireType = ($type === 'raw') ? 'tcp' : $type;

    $ss = [
        'network'  => $wireType,
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

    switch ($wireType) {
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

        case 'httpupgrade':
            $hu = [];
            if (!empty($params['path'])) {
                $hu['path'] = $params['path'];
            }
            if (!empty($params['host'])) {
                $hu['host'] = $params['host'];
            }
            if (!empty($hu)) {
                $ss['httpupgradeSettings'] = $hu;
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
