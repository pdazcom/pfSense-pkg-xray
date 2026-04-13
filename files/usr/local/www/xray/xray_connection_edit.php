<?php
/*
 * xray_connection_edit.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-connection-edit
##|*NAME=VPN: Xray: Edit Connection
##|*DESCR=Allow access to the 'VPN: Xray: Edit Connection' page.
##|*MATCH=xray_connection_edit.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_vless.inc');

$editUuid        = xray_sanitize_uuid($_GET['uuid'] ?? $_POST['uuid'] ?? '');
$isNew           = ($editUuid === '');
$presetGroupUuid = xray_sanitize_uuid($_GET['group_uuid'] ?? '');

$existing   = null;
$storedJson = '';

if (!$isNew) {
    $existing   = xray_get_connection_by_uuid($editUuid);
    $storedJson = trim($existing['custom_config'] ?? '');
    if ($storedJson === '' && $existing !== null) {
        $storedJson = xray_wizard_fields_to_json($existing);
    }
}

$connName    = $existing['name']       ?? '';
$connGroup   = $existing['group_uuid'] ?? ($presetGroupUuid !== '' ? $presetGroupUuid : XRAY_DEFAULT_GROUP_UUID);

$input_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    switch ($_POST['act']) {

        case 'import':
            header('Content-Type: application/json; charset=utf-8');

            $b64 = trim($_POST['link_b64'] ?? '');
            $link = '';
            if ($b64 !== '') {
                $decoded = base64_decode($b64, true);
                $link    = $decoded !== false ? trim($decoded) : '';
            }
            if ($link === '') {
                $link = trim($_POST['link'] ?? '');
            }

            if ($link === '') {
                echo json_encode(['status' => 'error', 'message' => 'No link provided']);
                exit;
            }
            if (strlen($link) > 4096) {
                echo json_encode(['status' => 'error', 'message' => 'Link too long']);
                exit;
            }

            $data = xray_parse_vless_link($link);
            if (isset($data['error'])) {
                echo json_encode(['status' => 'error', 'message' => $data['error']]);
            } else {
                $data['status'] = 'ok';
                echo json_encode($data);
            }
            exit;

        case 'save':
            $postName      = trim($_POST['name'] ?? '');
            $postGroupUuid = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? ''));
            $postJson      = trim($_POST['custom_config'] ?? '');

            if ($postName === '') {
                $input_errors[] = gettext('Name is required.');
            } elseif (strlen($postName) > 64) {
                $input_errors[] = gettext('Name must be 64 characters or less.');
            }

            if ($postJson === '') {
                $input_errors[] = gettext('Connection config (JSON) is required.');
            } elseif (json_decode($postJson) === null) {
                $input_errors[] = gettext('Connection config is not valid JSON.');
            }

            if (empty($input_errors)) {
                $newUuid = $isNew ? xray_generate_uuid() : $editUuid;

                $connection = [
                    'uuid'          => $newUuid,
                    'group_uuid'    => $postGroupUuid ?: XRAY_DEFAULT_GROUP_UUID,
                    'name'          => $postName,
                    'custom_config' => $postJson,
                    'test_result'   => $isNew ? '' : ($existing['test_result'] ?? ''),
                ];

                xray_save_connection($connection);

                $redirectGroup = $connection['group_uuid'];
                header('Location: /xray/xray_connections.php?group_uuid=' . urlencode($redirectGroup));
                exit;
            }

            $connName    = trim($_POST['name'] ?? '');
            $connGroup   = xray_sanitize_uuid(trim($_POST['group_uuid'] ?? '')) ?: XRAY_DEFAULT_GROUP_UUID;
            $storedJson  = trim($_POST['custom_config'] ?? '');
            break;
    }
}

$groups = xray_get_groups();

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Connections'),
            $isNew ? gettext('Add Connection') : gettext('Edit Connection')];
$pglinks = ['', '/xray/xray_instances.php', '/xray/xray_connections.php', '@self'];

$tab_array = xray_build_tab_array('connections');

include('head.inc');

if (!empty($input_errors)) {
    print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$groupOptions = [];
foreach ($groups as $g) {
    $groupOptions[$g['uuid']] = htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8')
        . (($g['type'] ?? 'manual') === 'subscription' ? ' (' . gettext('subscription') . ')' : '');
}
if (empty($groupOptions)) {
    $groupOptions[XRAY_DEFAULT_GROUP_UUID] = 'Default';
}

// ── Import panel ─────────────────────────────────────────────────────────────
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">
            <a data-toggle="collapse" href="#importPanel">
                <i class="fa fa-download"></i> <?=gettext('Import from Link')?>
            </a>
        </h2>
    </div>
    <div id="importPanel" class="panel-collapse collapse<?=$storedJson === '' ? ' in' : ''?>">
        <div class="panel-body">
            <div class="form-horizontal">
            <div class="form-group">
                <label class="col-sm-2 control-label"><?=gettext('Link')?></label>
                <div class="col-sm-8">
                    <textarea id="vless_link_input" class="form-control" rows="3"
                              placeholder="vless://UUID@host:port?...#name"></textarea>
                    <span class="help-block"><?=gettext('Paste a VLESS link to auto-fill the fields below.')?></span>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-2 col-sm-10">
                    <button type="button" id="import-btn" class="btn btn-primary btn-sm">
                        <i class="fa fa-magic icon-embed-btn"></i><?=gettext('Parse & Fill')?>
                    </button>
                    <span id="import-status" class="text-muted" style="margin-left:10px"></span>
                </div>
            </div>
            </div>
        </div>
    </div>
</div>

<?php
$form = new Form(false);

$form->addGlobal(new Form_Input('act',           '', 'hidden', 'save'));
$form->addGlobal(new Form_Input('uuid',          '', 'hidden', $editUuid));
$form->addGlobal(new Form_Input('custom_config', '', 'hidden', ''));

// ── Section: Connection Settings ────────────────────────────────────────────

$sectionCommon = new Form_Section(gettext('Connection Settings'));

$sectionCommon->addInput(new Form_Input(
    'name',
    gettext('*Name'),
    'text',
    $connName,
    ['placeholder' => gettext('My Server'), 'maxlength' => '64']
));

$sectionCommon->addInput(new Form_Select(
    'group_uuid',
    gettext('Group'),
    $connGroup,
    $groupOptions
));

$form->add($sectionCommon);

// ── Section: Protocol ────────────────────────────────────────────────────────

$sectionProto = new Form_Section(gettext('Protocol'));
$sectionProto->setAttribute('id', 'proto-section');

$sectionProto->addInput(new Form_Select(
    'protocol',
    gettext('Protocol'),
    'vless',
    [
        'vless'       => 'VLESS',
        'vmess'       => 'VMess',
        'trojan'      => 'Trojan',
        'shadowsocks' => 'Shadowsocks',
        'http'        => 'HTTP',
        'socks'       => 'Socks',
    ]
));

$sectionProto->addInput(new Form_Input(
    'server_address',
    gettext('*Server'),
    'text',
    '',
    ['placeholder' => 'vpn.example.com or 1.2.3.4']
));

$sectionProto->addInput(new Form_Input(
    'server_port',
    gettext('*Port'),
    'number',
    '443',
    ['min' => '1', 'max' => '65535']
));

$form->add($sectionProto);

// ── Section: VLESS Settings ──────────────────────────────────────────────────

$sectionVless = new Form_Section(gettext('VLESS Settings'));
$sectionVless->setAttribute('id', 'vless-section');

$sectionVless->addInput(new Form_Input(
    'vless_uuid',
    gettext('*UUID'),
    'text',
    '',
    ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx']
));

$sectionVless->addInput(new Form_Input(
    'encryption',
    gettext('Encryption'),
    'text',
    'none',
    ['placeholder' => 'none']
));

$sectionVless->addInput(new Form_Select(
    'flow',
    gettext('Flow'),
    'xtls-rprx-vision',
    ['xtls-rprx-vision' => 'xtls-rprx-vision', 'none' => 'none']
))->setHelp(gettext('Use xtls-rprx-vision with Reality/XTLS. Use none for other transports.'));

$form->add($sectionVless);

// ── Section: VMess Settings ──────────────────────────────────────────────────

$sectionVmess = new Form_Section(gettext('VMess Settings'));
$sectionVmess->setAttribute('id', 'vmess-section');

$sectionVmess->addInput(new Form_Input(
    'vmess_uuid',
    gettext('*UUID'),
    'text',
    '',
    ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx']
));

$sectionVmess->addInput(new Form_Input(
    'vmess_alterId',
    gettext('Alter ID'),
    'number',
    '0',
    ['min' => '0', 'max' => '65535']
))->setHelp(gettext('Set to 0 for AEAD (recommended).'));

$sectionVmess->addInput(new Form_Select(
    'vmess_security',
    gettext('Encryption'),
    'auto',
    ['auto' => 'auto', 'aes-128-gcm' => 'aes-128-gcm', 'chacha20-poly1305' => 'chacha20-poly1305', 'none' => 'none']
));

$form->add($sectionVmess);

// ── Section: Trojan Settings ─────────────────────────────────────────────────

$sectionTrojan = new Form_Section(gettext('Trojan Settings'));
$sectionTrojan->setAttribute('id', 'trojan-section');

$sectionTrojan->addInput(new Form_Input(
    'trojan_password',
    gettext('*Password'),
    'password',
    ''
));

$form->add($sectionTrojan);

// ── Section: Shadowsocks Settings ────────────────────────────────────────────

$sectionSs = new Form_Section(gettext('Shadowsocks Settings'));
$sectionSs->setAttribute('id', 'ss-section');

$sectionSs->addInput(new Form_Select(
    'ss_method',
    gettext('*Method'),
    'aes-256-gcm',
    [
        'aes-256-gcm'                   => 'aes-256-gcm',
        'aes-128-gcm'                   => 'aes-128-gcm',
        'chacha20-poly1305'             => 'chacha20-poly1305',
        'chacha20-ietf-poly1305'        => 'chacha20-ietf-poly1305',
        '2022-blake3-aes-256-gcm'       => '2022-blake3-aes-256-gcm',
        '2022-blake3-chacha20-poly1305' => '2022-blake3-chacha20-poly1305',
    ]
));

$sectionSs->addInput(new Form_Input(
    'ss_password',
    gettext('*Password'),
    'password',
    ''
));

$form->add($sectionSs);

// ── Section: HTTP / Socks Settings ───────────────────────────────────────────

$sectionHttp = new Form_Section(gettext('Auth (optional)'));
$sectionHttp->setAttribute('id', 'http-section');

$sectionHttp->addInput(new Form_Input('http_username', gettext('Username'), 'text', ''));
$sectionHttp->addInput(new Form_Input('http_password', gettext('Password'), 'password', ''));

$form->add($sectionHttp);

// ── Section: Transport ───────────────────────────────────────────────────────

$sectionSettings = new Form_Section(gettext('Transport'));
$sectionSettings->setAttribute('id', 'settings-section');

$sectionSettings->addInput(new Form_Select(
    'network',
    gettext('Network'),
    'raw',
    [
        'raw'         => 'raw (TCP)',
        'xhttp'       => 'xhttp',
        'ws'          => 'ws',
        'grpc'        => 'grpc',
        'httpupgrade' => 'httpupgrade',
        'h2'          => 'h2',
    ]
));

$sectionSettings->addInput(new Form_Select(
    'security',
    gettext('Security'),
    'reality',
    ['none' => 'none', 'tls' => 'tls', 'reality' => 'reality']
));

$sectionSettings->addInput(new Form_Select(
    'mux',
    gettext('Multiplex'),
    '',
    ['' => gettext('Keep Default'), 'enabled' => gettext('Enabled'), 'disabled' => gettext('Disabled')]
));

$form->add($sectionSettings);

// ── Section: TLS ─────────────────────────────────────────────────────────────

$sectionTls = new Form_Section(gettext('TLS Settings'));
$sectionTls->setAttribute('id', 'tls-section');

$sectionTls->addInput(new Form_Input('tls_sni', gettext('SNI'), 'text', '', ['placeholder' => 'example.com']));
$sectionTls->addInput(new Form_Select(
    'tls_fingerprint',
    gettext('Fingerprint'),
    'chrome',
    ['chrome' => 'chrome', 'firefox' => 'firefox', 'safari' => 'safari', 'edge' => 'edge',
     '360' => '360', 'qq' => 'qq', 'ios' => 'ios', 'android' => 'android', 'random' => 'random', 'randomized' => 'randomized']
));
$sectionTls->addInput(new Form_Input('tls_alpn', gettext('ALPN'), 'text', '', ['placeholder' => 'h2,http/1.1']));

$form->add($sectionTls);

// ── Section: Reality ─────────────────────────────────────────────────────────

$sectionReality = new Form_Section(gettext('Reality Settings'));
$sectionReality->setAttribute('id', 'reality-section');

$sectionReality->addInput(new Form_Input('reality_sni',         gettext('SNI'),              'text', 'www.cloudflare.com', ['placeholder' => 'www.cloudflare.com']));
$sectionReality->addInput(new Form_Select(
    'reality_fingerprint',
    gettext('Fingerprint'),
    'chrome',
    ['chrome' => 'chrome', 'firefox' => 'firefox', 'safari' => 'safari', 'edge' => 'edge',
     '360' => '360', 'qq' => 'qq', 'ios' => 'ios', 'android' => 'android', 'random' => 'random', 'randomized' => 'randomized']
));
$sectionReality->addInput(new Form_Input('reality_pubkey',      gettext('*Reality Pbk'),     'text', '', ['placeholder' => 'Base64 public key']));
$sectionReality->addInput(new Form_Input('reality_shortid',     gettext('Reality SID'),      'text', '', ['placeholder' => 'a1b2c3d4']));
$sectionReality->addInput(new Form_Input('reality_spiderx',     gettext('Reality SpiderX'),  'text', '/', ['placeholder' => '/']));

$form->add($sectionReality);

// ── Section: Network transport path/host ─────────────────────────────────────

$sectionTransport = new Form_Section(gettext('Network Settings'));
$sectionTransport->setAttribute('id', 'transport-path-section');

$sectionTransport->addInput(new Form_Input('transport_host',    gettext('Host'),    'text', '', ['placeholder' => 'example.com']));
$sectionTransport->addInput(new Form_Input('transport_path',    gettext('Path'),    'text', '', ['placeholder' => '/']));
$sectionTransport->addInput(new Form_Input('transport_headers', gettext('Headers'), 'text', '', ['placeholder' => 'Key: Value']));

$form->add($sectionTransport);

// ── Section: xhttp ───────────────────────────────────────────────────────────

$sectionXhttp = new Form_Section(gettext('xhttp Settings'));
$sectionXhttp->setAttribute('id', 'xhttp-section');

$sectionXhttp->addInput(new Form_Select(
    'xhttp_mode',
    gettext('Mode'),
    'stream-one',
    ['auto' => 'auto', 'packet-up' => 'packet-up', 'stream-up' => 'stream-up', 'stream-one' => 'stream-one']
));

$form->add($sectionXhttp);

// ── Section: gRPC ────────────────────────────────────────────────────────────

$sectionGrpc = new Form_Section(gettext('gRPC Settings'));
$sectionGrpc->setAttribute('id', 'grpc-section');

$sectionGrpc->addInput(new Form_Input('grpc_service_name', gettext('Service Name'), 'text', '', ['placeholder' => 'GunService']));

$form->add($sectionGrpc);

// ── Section: Raw JSON ────────────────────────────────────────────────────────

$sectionRaw = new Form_Section(gettext('Config JSON'));
$sectionRaw->setAttribute('id', 'raw-section');

$sectionRaw->addInput(new Form_Textarea(
    'raw_json_editor',
    gettext('xray-core JSON'),
    '',
    ['rows' => '40', 'style' => 'font-family:monospace;font-size:12px;width:100%',
     'placeholder' => '{"log": {...}, "inbounds": [...], "outbounds": [...], "routing": {...}}']
))->setHelp(gettext('Complete xray-core config.json. Supports any protocol or transport.'));

$form->add($sectionRaw);

print($form);
?>

<nav class="action-buttons">
    <a href="/xray/xray_connections.php" class="btn btn-sm btn-default">
        <i class="fa fa-times icon-embed-btn"></i><?=gettext('Cancel')?>
    </a>
    <button type="button" id="saveform" class="btn btn-primary btn-sm">
        <i class="fa fa-save icon-embed-btn"></i><?=gettext('Save')?>
    </button>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    // ── Constants ────────────────────────────────────────────────────────────
    var PROTO_WITH_STREAM  = ['vless', 'vmess', 'trojan'];
    var NETWORKS_WITH_PATH = ['xhttp', 'ws', 'h2', 'httpupgrade'];
    var SOCKS5_LISTEN      = '127.0.0.1';
    var SOCKS5_PORT        = 10808;
    var LOGLEVEL           = 'warning';
    var BYPASS_NETS        = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    // Initial JSON from server (empty string for new connections)
    var storedJson = <?= json_encode($storedJson) ?>;

    // Mode: 'form' or 'raw'. Start in raw if we have unrecognized JSON,
    // form otherwise (including new connections).
    var currentMode = 'form';

    // ── JSON → form fields ───────────────────────────────────────────────────

    function parseConfigJson(json) {
        if (!json) { return null; }
        var cfg;
        try { cfg = JSON.parse(json); } catch(e) { return null; }
        if (!cfg || typeof cfg !== 'object') { return null; }

        var outbounds = cfg.outbounds || [];
        var proxy = null;
        for (var i = 0; i < outbounds.length; i++) {
            if (!proxy || outbounds[i].tag === 'proxy') {
                proxy = outbounds[i];
                if (outbounds[i].tag === 'proxy') { break; }
            }
        }
        if (!proxy) { return null; }

        var protocol = proxy.protocol || 'unknown';
        var ss       = proxy.streamSettings || {};
        var settings = proxy.settings || {};

        var wireNetwork = ss.network || 'tcp';
        var network     = wireNetwork === 'tcp' ? 'raw' : wireNetwork;
        var security    = ss.security || 'none';

        var f = { protocol: protocol, network: network, security: security,
                  server_address: '', server_port: '443' };

        switch (protocol) {
            case 'vless':
                var vnext = (settings.vnext || [{}])[0] || {};
                var user  = (vnext.users || [{}])[0] || {};
                f.server_address = vnext.address || '';
                f.server_port    = String(vnext.port || 443);
                f.vless_uuid     = user.id || '';
                f.encryption     = user.encryption || 'none';
                f.flow           = user.flow || '';
                break;

            case 'vmess':
                var vnext = (settings.vnext || [{}])[0] || {};
                var user  = (vnext.users || [{}])[0] || {};
                f.server_address = vnext.address || '';
                f.server_port    = String(vnext.port || 443);
                f.vmess_uuid     = user.id || '';
                f.vmess_alterId  = String(user.alterId !== undefined ? user.alterId : 0);
                f.vmess_security = user.security || 'auto';
                break;

            case 'trojan':
                var server = (settings.servers || [{}])[0] || {};
                f.server_address  = server.address || '';
                f.server_port     = String(server.port || 443);
                f.trojan_password = server.password || '';
                break;

            case 'shadowsocks':
                var server = (settings.servers || [{}])[0] || {};
                f.server_address = server.address || '';
                f.server_port    = String(server.port || 443);
                f.ss_method      = server.method || 'aes-256-gcm';
                f.ss_password    = server.password || '';
                break;

            case 'http':
            case 'socks':
                var server = (settings.servers || [{}])[0] || {};
                f.server_address  = server.address || '';
                f.server_port     = String(server.port || (protocol === 'socks' ? 1080 : 443));
                var u = (server.users || [{}])[0] || {};
                f.http_username   = u.user || '';
                f.http_password   = u.pass || '';
                break;

            default:
                return null;
        }

        var realityCfg = ss.realitySettings || {};
        var tlsCfg     = ss.tlsSettings     || {};

        f.reality_sni         = realityCfg.serverName  || '';
        f.reality_fingerprint = realityCfg.fingerprint || 'chrome';
        f.reality_pubkey      = realityCfg.publicKey   || '';
        f.reality_shortid     = realityCfg.shortId     || '';
        f.reality_spiderx     = realityCfg.spiderX     || '/';

        f.tls_sni         = tlsCfg.serverName  || '';
        f.tls_fingerprint = tlsCfg.fingerprint || 'chrome';
        f.tls_alpn        = (tlsCfg.alpn || []).join(',');

        var xhttpCfg = ss.xhttpSettings || ss.splithttpSettings || {};
        var wsCfg    = ss.wsSettings    || {};
        var h2Cfg    = ss.httpSettings  || {};
        var grpcCfg  = ss.grpcSettings  || {};
        var huCfg    = ss.httpupgradeSettings || {};

        switch (network) {
            case 'xhttp':
                f.transport_path = xhttpCfg.path  || '';
                f.transport_host = xhttpCfg.host  || '';
                f.xhttp_mode     = xhttpCfg.mode  || 'stream-one';
                break;
            case 'ws':
                f.transport_path = wsCfg.path || '';
                f.transport_host = (wsCfg.headers || {}).Host || '';
                break;
            case 'h2':
                f.transport_path = h2Cfg.path || '';
                f.transport_host = (h2Cfg.host || [])[0] || '';
                break;
            case 'grpc':
                f.grpc_service_name = grpcCfg.serviceName || '';
                break;
            case 'httpupgrade':
                f.transport_path = huCfg.path || '';
                f.transport_host = huCfg.host || '';
                break;
        }

        var muxCfg = proxy.mux;
        if (!muxCfg) {
            f.mux = '';
        } else if (muxCfg.enabled) {
            f.mux = 'enabled';
        } else {
            f.mux = 'disabled';
        }

        return f;
    }

    // ── form fields → JSON ───────────────────────────────────────────────────

    function buildStreamSettings(network, security, f) {
        var wireType = network === 'raw' ? 'tcp' : network;
        var ss = { network: wireType, security: security };

        if (security === 'reality') {
            ss.realitySettings = {
                serverName:  f.reality_sni         || '',
                fingerprint: f.reality_fingerprint || 'chrome',
                show:        false,
                publicKey:   f.reality_pubkey      || '',
                shortId:     f.reality_shortid     || '',
                spiderX:     f.reality_spiderx     || '/'
            };
        } else if (security === 'tls') {
            var tls = {
                serverName:  f.tls_sni         || '',
                fingerprint: f.tls_fingerprint || 'chrome'
            };
            if (f.tls_alpn) {
                tls.alpn = f.tls_alpn.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            }
            ss.tlsSettings = tls;
        }

        switch (wireType) {
            case 'xhttp':
                var xhttp = {};
                if (f.transport_path) { xhttp.path = f.transport_path; }
                if (f.transport_host) { xhttp.host = f.transport_host; }
                if (f.xhttp_mode)     { xhttp.mode = f.xhttp_mode; }
                if (Object.keys(xhttp).length) { ss.xhttpSettings = xhttp; }
                break;
            case 'ws':
                var ws = {};
                if (f.transport_path) { ws.path = f.transport_path; }
                if (f.transport_host) { ws.headers = { Host: f.transport_host }; }
                if (Object.keys(ws).length) { ss.wsSettings = ws; }
                break;
            case 'grpc':
                var grpc = {};
                if (f.grpc_service_name) { grpc.serviceName = f.grpc_service_name; }
                if (Object.keys(grpc).length) { ss.grpcSettings = grpc; }
                break;
            case 'http':
                var h2 = {};
                if (f.transport_path) { h2.path = f.transport_path; }
                if (f.transport_host) { h2.host = [f.transport_host]; }
                if (Object.keys(h2).length) { ss.httpSettings = h2; }
                break;
            case 'httpupgrade':
                var hu = {};
                if (f.transport_path) { hu.path = f.transport_path; }
                if (f.transport_host) { hu.host = f.transport_host; }
                if (Object.keys(hu).length) { ss.httpupgradeSettings = hu; }
                break;
        }

        return ss;
    }

    function buildConfigJson(f) {
        var protocol = f.protocol || 'vless';
        var network  = f.network  || 'raw';
        var security = f.security || 'none';
        var host     = f.server_address || '';
        var port     = parseInt(f.server_port || 443, 10);

        var outboundSettings;
        var streamSettings = null;

        switch (protocol) {
            case 'vmess':
                outboundSettings = { vnext: [{ address: host, port: port, users: [{
                    id:       f.vmess_uuid     || '',
                    alterId:  parseInt(f.vmess_alterId || 0, 10),
                    security: f.vmess_security || 'auto'
                }]}]};
                streamSettings = buildStreamSettings(network, security, f);
                break;

            case 'trojan':
                outboundSettings = { servers: [{ address: host, port: port, password: f.trojan_password || '' }] };
                streamSettings = buildStreamSettings(network, security, f);
                break;

            case 'shadowsocks':
                outboundSettings = { servers: [{ address: host, port: port,
                    method: f.ss_method || 'aes-256-gcm', password: f.ss_password || '' }] };
                break;

            case 'http':
            case 'socks':
                var srv = { address: host, port: port };
                if (f.http_username) { srv.users = [{ user: f.http_username, pass: f.http_password || '' }]; }
                outboundSettings = { servers: [srv] };
                break;

            default: // vless
                var flow = f.flow || 'xtls-rprx-vision';
                if (flow === 'none') { flow = ''; }
                if (network !== 'raw') { flow = ''; }
                outboundSettings = { vnext: [{ address: host, port: port, users: [{
                    id:         f.vless_uuid  || '',
                    encryption: f.encryption  || 'none',
                    flow:       flow
                }]}]};
                streamSettings = buildStreamSettings(network, security, f);
                break;
        }

        var outbound = { tag: 'proxy', protocol: protocol, settings: outboundSettings };
        if (streamSettings) { outbound.streamSettings = streamSettings; }

        if (f.mux === 'enabled') {
            outbound.mux = { enabled: true,  concurrency: 8 };
        } else if (f.mux === 'disabled') {
            outbound.mux = { enabled: false, concurrency: -1 };
        }

        var cfg = {
            log: { loglevel: LOGLEVEL },
            inbounds: [{
                tag: 'socks-in', port: SOCKS5_PORT, listen: SOCKS5_LISTEN,
                protocol: 'socks',
                settings: { auth: 'noauth', udp: true, ip: SOCKS5_LISTEN }
            }],
            outbounds: [outbound, { tag: 'direct', protocol: 'freedom' }],
            routing: {
                domainStrategy: 'IPIfNonMatch',
                rules: [{ type: 'field', ip: BYPASS_NETS, outboundTag: 'direct' }]
            }
        };

        return JSON.stringify(cfg, null, 2);
    }

    // ── Populate form from parsed fields ────────────────────────────────────

    function setVal(name, val) {
        if (val === undefined || val === null) { return; }
        var $el = $('[name="' + name + '"]');
        if ($el.length) { $el.val(String(val)); }
    }

    function populateForm(f) {
        setVal('protocol',          f.protocol);
        setVal('server_address',    f.server_address);
        setVal('server_port',       f.server_port);
        setVal('vless_uuid',        f.vless_uuid);
        setVal('encryption',        f.encryption);
        setVal('flow',              f.flow || 'xtls-rprx-vision');
        setVal('vmess_uuid',        f.vmess_uuid);
        setVal('vmess_alterId',     f.vmess_alterId);
        setVal('vmess_security',    f.vmess_security);
        setVal('trojan_password',   f.trojan_password);
        setVal('ss_method',         f.ss_method);
        setVal('ss_password',       f.ss_password);
        setVal('http_username',     f.http_username);
        setVal('http_password',     f.http_password);
        setVal('network',           f.network);
        setVal('security',          f.security);
        setVal('mux',               f.mux);
        setVal('tls_sni',           f.tls_sni);
        setVal('tls_fingerprint',   f.tls_fingerprint);
        setVal('tls_alpn',          f.tls_alpn);
        setVal('reality_sni',       f.reality_sni);
        setVal('reality_fingerprint', f.reality_fingerprint);
        setVal('reality_pubkey',    f.reality_pubkey);
        setVal('reality_shortid',   f.reality_shortid);
        setVal('reality_spiderx',   f.reality_spiderx);
        setVal('transport_path',    f.transport_path);
        setVal('transport_host',    f.transport_host);
        setVal('xhttp_mode',        f.xhttp_mode);
        setVal('grpc_service_name', f.grpc_service_name);
    }

    // ── Collect form fields into a plain object ──────────────────────────────

    function collectForm() {
        var f = {};
        var fieldNames = [
            'protocol', 'server_address', 'server_port',
            'vless_uuid', 'encryption', 'flow',
            'vmess_uuid', 'vmess_alterId', 'vmess_security',
            'trojan_password',
            'ss_method', 'ss_password',
            'http_username', 'http_password',
            'network', 'security', 'mux',
            'tls_sni', 'tls_fingerprint', 'tls_alpn',
            'reality_sni', 'reality_fingerprint', 'reality_pubkey',
            'reality_shortid', 'reality_spiderx',
            'transport_path', 'transport_host', 'transport_headers',
            'xhttp_mode', 'grpc_service_name'
        ];
        for (var i = 0; i < fieldNames.length; i++) {
            var name = fieldNames[i];
            f[name] = $('[name="' + name + '"]').val() || '';
        }
        return f;
    }

    // ── Visibility logic ─────────────────────────────────────────────────────

    function updateVisibility() {
        if (currentMode === 'raw') {
            $('#raw-section').show();
            $('#proto-section, #vless-section, #vmess-section, #trojan-section, ' +
              '#ss-section, #http-section, #settings-section, #tls-section, ' +
              '#reality-section, #transport-path-section, #xhttp-section, #grpc-section').hide();
            return;
        }

        $('#raw-section').hide();
        $('#proto-section').show();

        var proto    = $('[name="protocol"]').val();
        var network  = $('[name="network"]').val();
        var security = $('[name="security"]').val();

        $('#vless-section')[proto  === 'vless'       ? 'show' : 'hide']();
        $('#vmess-section')[proto  === 'vmess'        ? 'show' : 'hide']();
        $('#trojan-section')[proto === 'trojan'       ? 'show' : 'hide']();
        $('#ss-section')[proto     === 'shadowsocks'  ? 'show' : 'hide']();
        $('#http-section')[proto === 'http' || proto === 'socks' ? 'show' : 'hide']();

        var hasStream = PROTO_WITH_STREAM.indexOf(proto) !== -1;
        $('#settings-section')[hasStream ? 'show' : 'hide']();

        if (!hasStream) {
            $('#tls-section, #reality-section, #transport-path-section, #xhttp-section, #grpc-section').hide();
            return;
        }

        $('#tls-section')[security     === 'tls'     ? 'show' : 'hide']();
        $('#reality-section')[security === 'reality' ? 'show' : 'hide']();

        var hasPath = NETWORKS_WITH_PATH.indexOf(network) !== -1;
        $('#transport-path-section')[hasPath             ? 'show' : 'hide']();
        $('#xhttp-section')[network  === 'xhttp'         ? 'show' : 'hide']();
        $('#grpc-section')[network   === 'grpc'          ? 'show' : 'hide']();
    }

    // ── Switch between form mode and raw JSON mode ───────────────────────────

    function switchToForm(fields) {
        currentMode = 'form';
        populateForm(fields);
        updateVisibility();
    }

    function switchToRaw(json) {
        currentMode = 'raw';
        $('#raw_json_editor').val(json || '');
        updateVisibility();
    }

    // ── Inject toggle buttons into panel titles ──────────────────────────────

    $('#proto-section .panel-title').append(
        ' <a href="#" id="switch-to-raw" class="btn btn-info btn-xs" style="margin-left:10px;font-weight:normal">' +
        '<i class="fa fa-code"></i> <?=gettext('Edit as JSON')?></a>'
    );
    $('#raw-section .panel-title').append(
        ' <a href="#" id="switch-to-form" class="btn btn-info btn-xs" style="margin-left:10px;font-weight:normal">' +
        '<i class="fa fa-th-list"></i> <?=gettext('Edit as Form')?></a>'
    );

    // ── Initialise ───────────────────────────────────────────────────────────

    if (storedJson !== '') {
        var parsed = parseConfigJson(storedJson);
        if (parsed) {
            switchToForm(parsed);
        } else {
            switchToRaw(storedJson);
        }
    } else {
        currentMode = 'form';
        updateVisibility();
    }

    // ── React to selector changes ────────────────────────────────────────────

    $('[name="protocol"], [name="network"], [name="security"]').on('change', updateVisibility);

    // ── Save: build JSON from current mode and submit ────────────────────────

    $('#saveform').on('click', function() {
        var json;
        if (currentMode === 'raw') {
            json = $.trim($('#raw_json_editor').val());
        } else {
            json = buildConfigJson(collectForm());
        }
        $('input[name="custom_config"]').val(json);
        $(form).submit();
    });

    // ── Toggle raw/form via a link in the raw-section ───────────────────────

    $(document).on('click', '#switch-to-form', function(e) {
        e.preventDefault();
        var json = $.trim($('#raw_json_editor').val());
        var parsed = parseConfigJson(json);
        if (parsed) {
            switchToForm(parsed);
        } else {
            alert('<?=gettext('Cannot parse JSON into form fields — unknown structure.')?>');
        }
    });

    $(document).on('click', '#switch-to-raw', function(e) {
        e.preventDefault();
        var json = currentMode === 'form' ? buildConfigJson(collectForm()) : $('#raw_json_editor').val();
        switchToRaw(json);
    });

    // ── VLESS link import ────────────────────────────────────────────────────

    $('#import-btn').on('click', function() {
        var link = $.trim($('#vless_link_input').val());
        if (!link) {
            $('#import-status').text('<?=gettext('Please paste a link first.')?>').removeClass().addClass('text-danger');
            return;
        }

        $.ajax({
            url:      window.location.pathname,
            type:     'post',
            data:     { act: 'import', link: link },
            dataType: 'json',
            success: function(data) {
                if (!data || data.status === 'error') {
                    $('#import-status').text(data ? data.message : '<?=gettext('Parse failed.')?>').removeClass().addClass('text-danger');
                    return;
                }

                var sec = data.security || 'none';
                var f = {
                    protocol:            data.protocol        || 'vless',
                    server_address:      data.host            || '',
                    server_port:         String(data.port     || 443),
                    vless_uuid:          data.vless_uuid      || '',
                    flow:                data.flow            || 'none',
                    vmess_uuid:          data.vmess_uuid      || '',
                    vmess_alterId:       String(data.vmess_alterId  || 0),
                    vmess_security:      data.vmess_security  || 'auto',
                    trojan_password:     data.trojan_password || '',
                    ss_method:           data.ss_method       || 'aes-256-gcm',
                    ss_password:         data.ss_password     || '',
                    network:             data.network         || 'raw',
                    security:            sec,
                    tls_sni:            sec === 'tls'     ? (data.sni || '') : '',
                    tls_fingerprint:    sec === 'tls'     ? (data.fp  || 'chrome') : 'chrome',
                    reality_sni:        sec === 'reality' ? (data.sni || '') : '',
                    reality_fingerprint:sec === 'reality' ? (data.fp  || 'chrome') : 'chrome',
                    reality_pubkey:     data.pbk               || '',
                    reality_shortid:    data.sid               || '',
                    reality_spiderx:    data.spx               || '/',
                    transport_path:     data.transport_path    || '',
                    transport_host:     data.transport_host    || '',
                    xhttp_mode:         data.xhttp_mode        || 'stream-one',
                    grpc_service_name:  data.grpc_service_name || ''
                };

                if (data.name && !$('[name="name"]').val()) {
                    $('[name="name"]').val(data.name);
                }

                switchToForm(f);
                $('#import-status').text('<?=gettext('Fields populated successfully.')?>').removeClass().addClass('text-success');
            },
            error: function() {
                $('#import-status').text('<?=gettext('Request failed.')?>').removeClass().addClass('text-danger');
            }
        });
    });
});
//]]>
</script>

<?php
include('xray/includes/xray_foot.inc');
include('foot.inc');
?>
