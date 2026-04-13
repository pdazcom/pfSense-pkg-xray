<?php

/**
 * xray-urltest.inc — Shared helpers for URL-testing connections via temporary xray-core instances.
 *
 * Included by xray-urltest.php and xray-urltest-group.php.
 */

define('URLTEST_XRAY_BIN',  '/usr/local/bin/xray-core');
define('URLTEST_DEFAULT_URL', 'https://www.google.com');

// ─── Config builder ───────────────────────────────────────────────────────────

function urltest_build_config(array $conn, int $socksPort): ?string
{
    $configMode = $conn['config_mode'] ?? 'wizard';

    if ($configMode === 'custom') {
        $raw     = trim($conn['custom_config'] ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if ($decoded === null) {
            return null;
        }
        $decoded['inbounds'] = [urltest_socks_inbound($socksPort)];
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $flow = ($conn['flow'] ?? 'xtls-rprx-vision');
    if ($flow === 'none' || $flow === '') {
        $flow = '';
    }

    $cfg = [
        'log'      => ['loglevel' => 'error'],
        'inbounds' => [urltest_socks_inbound($socksPort)],
        'outbounds' => [
            [
                'tag'      => 'proxy',
                'protocol' => 'vless',
                'settings' => [
                    'vnext' => [[
                        'address' => $conn['server_address'] ?? '',
                        'port'    => (int)($conn['server_port'] ?? 443),
                        'users'   => [[
                            'id'         => $conn['vless_uuid'] ?? '',
                            'encryption' => 'none',
                            'flow'       => $flow,
                        ]],
                    ]],
                ],
                'streamSettings' => [
                    'network'         => 'tcp',
                    'security'        => 'reality',
                    'realitySettings' => [
                        'serverName'  => $conn['reality_sni']        ?? '',
                        'fingerprint' => $conn['reality_fingerprint'] ?? 'chrome',
                        'show'        => false,
                        'publicKey'   => $conn['reality_pubkey']      ?? '',
                        'shortId'     => $conn['reality_shortid']     ?? '',
                        'spiderX'     => '',
                    ],
                ],
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

    return json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function urltest_socks_inbound(int $port): array
{
    return [
        'tag'      => 'socks-in',
        'port'     => $port,
        'listen'   => '127.0.0.1',
        'protocol' => 'socks',
        'settings' => ['auth' => 'noauth', 'udp' => false, 'ip' => '127.0.0.1'],
    ];
}

// ─── Process helpers ──────────────────────────────────────────────────────────

function urltest_kill_pid(string $pidFile, int $waitMs = 500): void
{
    if (!file_exists($pidFile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid <= 0) {
        @unlink($pidFile);
        return;
    }
    exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
    $deadline = microtime(true) + $waitMs / 1000.0;
    while (microtime(true) < $deadline) {
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
        if ($rc !== 0) {
            break;
        }
        usleep(50000);
    }
    exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
    @unlink($pidFile);
}

// ─── Native SOCKS5 HTTP test (no fork/exec — much lower latency than curl) ───

/**
 * Connects to a SOCKS5 proxy, sends an HTTP GET, measures time to first byte.
 *
 * Returns ['latency_ms' => int, 'status' => int, 'error' => string|null]
 */
function urltest_socks5_http(string $proxyHost, int $proxyPort, string $url, int $timeoutSec = 5): array
{
    $parsed     = parse_url($url);
    $targetHost = $parsed['host'] ?? '';
    $targetPort = isset($parsed['port']) ? (int)$parsed['port'] : 80;
    $targetPath = ($parsed['path'] ?? '/') ?: '/';
    if (isset($parsed['query'])) {
        $targetPath .= '?' . $parsed['query'];
    }

    if ($targetHost === '') {
        return ['latency_ms' => 0, 'status' => 0, 'error' => 'invalid url'];
    }

    $errno  = 0;
    $errstr = '';
    $start  = hrtime(true);

    $sock = @fsockopen($proxyHost, $proxyPort, $errno, $errstr, $timeoutSec);
    if ($sock === false) {
        return ['latency_ms' => 0, 'status' => 0, 'error' => "proxy connect failed: $errstr ($errno)"];
    }

    stream_set_timeout($sock, $timeoutSec);

    // SOCKS5 greeting: no-auth
    if (fwrite($sock, "\x05\x01\x00") === false) {
        fclose($sock);
        return ['latency_ms' => 0, 'status' => 0, 'error' => 'socks5 greeting write failed'];
    }

    $resp = fread($sock, 2);
    if ($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05" || $resp[1] !== "\x00") {
        fclose($sock);
        return ['latency_ms' => 0, 'status' => 0, 'error' => 'socks5 greeting rejected'];
    }

    // SOCKS5 CONNECT by domain name
    $req = "\x05\x01\x00\x03" . chr(strlen($targetHost)) . $targetHost . pack('n', $targetPort);
    if (fwrite($sock, $req) === false) {
        fclose($sock);
        return ['latency_ms' => 0, 'status' => 0, 'error' => 'socks5 connect write failed'];
    }

    $resp = fread($sock, 10);
    if ($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05" || $resp[1] !== "\x00") {
        fclose($sock);
        $code = isset($resp[1]) ? ord($resp[1]) : -1;
        return ['latency_ms' => 0, 'status' => 0, 'error' => "socks5 connect refused (code $code)"];
    }

    // HTTP GET — we only care about the status line (time to first byte)
    $httpReq = "GET $targetPath HTTP/1.1\r\nHost: $targetHost\r\nConnection: close\r\n\r\n";
    if (fwrite($sock, $httpReq) === false) {
        fclose($sock);
        return ['latency_ms' => 0, 'status' => 0, 'error' => 'http request write failed'];
    }

    $statusLine = fgets($sock, 256);
    $latencyMs  = (int)round((hrtime(true) - $start) / 1_000_000);
    fclose($sock);

    if ($statusLine === false || $statusLine === '') {
        return ['latency_ms' => $latencyMs, 'status' => 0, 'error' => 'no http response'];
    }

    $httpStatus = 0;
    if (preg_match('/HTTP\/[\d.]+ (\d{3})/', $statusLine, $m)) {
        $httpStatus = (int)$m[1];
    }

    return ['latency_ms' => $latencyMs, 'status' => $httpStatus, 'error' => null];
}

// ─── Test URL from global config ──────────────────────────────────────────────

function urltest_get_test_url(): string
{
    $globalCfg = config_get_path('installedpackages/xray/config/0', []);
    $url       = trim($globalCfg['test_url'] ?? '');
    return $url !== '' ? $url : URLTEST_DEFAULT_URL;
}
