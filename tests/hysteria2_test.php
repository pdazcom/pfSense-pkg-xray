<?php

require_once(__DIR__ . '/../files/usr/local/pkg/xray/includes/xray_vless.inc');

$fields = xray_parse_hysteria2_link('hy2://test-password@vpn.example.test:443?sni=cdn.example.test#Example');
$config = json_decode(xray_build_config_json($fields), true);
$outbound = $config['outbounds'][0] ?? [];
$stream = $outbound['streamSettings'] ?? [];

$checks = [
    ($fields['protocol'] ?? '') === 'hysteria',
    ($fields['hysteria_auth'] ?? '') === 'test-password',
    ($outbound['settings']['version'] ?? 0) === 2,
    ($stream['network'] ?? '') === 'hysteria',
    ($stream['security'] ?? '') === 'tls',
    ($stream['hysteriaSettings']['auth'] ?? '') === 'test-password',
    ($stream['tlsSettings']['serverName'] ?? '') === 'cdn.example.test',
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Hysteria2 parsing or config generation failed\n");
    exit(1);
}

echo "Hysteria2 parsing and config generation passed\n";
