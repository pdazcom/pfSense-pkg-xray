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
require_once('xray/includes/xray_validate.inc');
require_once('xray/includes/xray_vless.inc');

$editUuid        = xray_sanitize_uuid($_GET['uuid'] ?? $_POST['uuid'] ?? '');
$isNew           = ($editUuid === '');
$presetGroupUuid = xray_sanitize_uuid($_GET['group_uuid'] ?? '');

$defaults = [
    'name'                => '',
    'group_uuid'          => $presetGroupUuid !== '' ? $presetGroupUuid : XRAY_DEFAULT_GROUP_UUID,
    'config_mode'         => 'wizard',
    'server_address'      => '',
    'server_port'         => '443',
    'vless_uuid'          => '',
    'flow'                => 'xtls-rprx-vision',
    'reality_sni'         => 'www.cloudflare.com',
    'reality_pubkey'      => '',
    'reality_shortid'     => '',
    'reality_fingerprint' => 'chrome',
    'custom_config'       => '',
];

if (!$isNew) {
    $existing = xray_get_connection_by_uuid($editUuid);
    $pconfig  = $existing !== null ? array_merge($defaults, $existing) : $defaults;
} else {
    $pconfig = $defaults;
}

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
                echo json_encode(['status' => 'error', 'message' => 'No VLESS link provided']);
                exit;
            }
            if (strlen($link) > 2048) {
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
            xray_validate_connection($_POST, $isNew ? null : $editUuid);

            if (empty($input_errors)) {
                $newUuid    = $isNew ? xray_generate_uuid() : $editUuid;
                $configMode = in_array($_POST['config_mode'] ?? '', ['wizard', 'custom'], true)
                    ? $_POST['config_mode'] : 'wizard';

                $connection = [
                    'uuid'                => $newUuid,
                    'group_uuid'          => trim($_POST['group_uuid'] ?? ''),
                    'name'                => trim($_POST['name'] ?? ''),
                    'config_mode'         => $configMode,
                    'server_address'      => trim($_POST['server_address'] ?? ''),
                    'server_port'         => (string)(int)($_POST['server_port'] ?? 443),
                    'vless_uuid'          => trim($_POST['vless_uuid'] ?? ''),
                    'flow'                => in_array($_POST['flow'] ?? '', ['xtls-rprx-vision', 'none'], true)
                                             ? $_POST['flow'] : 'xtls-rprx-vision',
                    'reality_sni'         => trim($_POST['reality_sni'] ?? ''),
                    'reality_pubkey'      => trim($_POST['reality_pubkey'] ?? ''),
                    'reality_shortid'     => trim($_POST['reality_shortid'] ?? ''),
                    'reality_fingerprint' => in_array(
                                                 $_POST['reality_fingerprint'] ?? '',
                                                 ['chrome', 'firefox', 'safari', 'edge', 'random'],
                                                 true
                                             ) ? $_POST['reality_fingerprint'] : 'chrome',
                    'custom_config'       => trim($_POST['custom_config'] ?? ''),
                    'test_result'         => $isNew ? '' : ($existing['test_result'] ?? ''),
                ];

                xray_save_connection($connection);

                $redirectGroup = $connection['group_uuid'] ?: XRAY_DEFAULT_GROUP_UUID;
                header('Location: /xray/xray_connections.php?group_uuid=' . urlencode($redirectGroup));
                exit;
            }

            $pconfig = array_merge($defaults, $_POST);
            $pconfig['uuid'] = $editUuid;
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

// ── VLESS Import panel ──────────────────────────────────────────────────────
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">
            <a data-toggle="collapse" href="#importPanel">
                <i class="fa fa-download"></i> <?=gettext('Import from VLESS Link')?>
            </a>
        </h2>
    </div>
    <div id="importPanel" class="panel-collapse collapse<?=($pconfig['vless_uuid'] ?? '') === '' ? ' in' : ''?>">
        <div class="panel-body">
            <div class="form-horizontal">
            <div class="form-group">
                <label class="col-sm-2 control-label"><?=gettext('VLESS Link')?></label>
                <div class="col-sm-8">
                    <textarea id="vless_link_input" class="form-control" rows="3"
                              placeholder="vless://UUID@host:port?type=tcp&amp;security=reality&amp;...#name"></textarea>
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

$form->addGlobal(new Form_Input('act',  '', 'hidden', 'save'));
$form->addGlobal(new Form_Input('uuid', '', 'hidden', $editUuid));

$sectionCommon = new Form_Section(gettext('Connection Settings'));

$sectionCommon->addInput(new Form_Input(
    'name',
    gettext('*Name'),
    'text',
    $pconfig['name'],
    ['placeholder' => gettext('My Server'), 'maxlength' => '64']
));

$groupOptions = [];
foreach ($groups as $g) {
    $groupOptions[$g['uuid']] = htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8')
        . (($g['type'] ?? 'manual') === 'subscription' ? ' (' . gettext('subscription') . ')' : '');
}
if (empty($groupOptions)) {
    $groupOptions[XRAY_DEFAULT_GROUP_UUID] = 'Default';
}

$sectionCommon->addInput(new Form_Select(
    'group_uuid',
    gettext('Group'),
    $pconfig['group_uuid'],
    $groupOptions
));

$sectionCommon->addInput(new Form_Select(
    'config_mode',
    gettext('Config Mode'),
    $pconfig['config_mode'],
    ['wizard' => gettext('Wizard (VLESS + Reality)'), 'custom' => gettext('Custom JSON')]
))->setHelp(gettext('Wizard mode uses built-in VLESS+Reality fields. Custom mode accepts a raw xray-core config.json.'));

$form->add($sectionCommon);

$sectionWizard = new Form_Section(gettext('VLESS + Reality Settings'));
$sectionWizard->setAttribute('id', 'wizard-section');

$sectionWizard->addInput(new Form_Input(
    'server_address',
    gettext('*Server Address'),
    'text',
    $pconfig['server_address'],
    ['placeholder' => 'vpn.example.com or 1.2.3.4']
));

$sectionWizard->addInput(new Form_Input(
    'server_port',
    gettext('*Server Port'),
    'number',
    $pconfig['server_port'],
    ['min' => '1', 'max' => '65535']
));

$sectionWizard->addInput(new Form_Input(
    'vless_uuid',
    gettext('*VLESS UUID'),
    'text',
    $pconfig['vless_uuid'],
    ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx']
));

$sectionWizard->addInput(new Form_Select(
    'flow',
    gettext('Flow'),
    $pconfig['flow'],
    ['xtls-rprx-vision' => 'xtls-rprx-vision', 'none' => 'none']
));

$sectionWizard->addInput(new Form_Input(
    'reality_sni',
    gettext('Reality SNI'),
    'text',
    $pconfig['reality_sni'],
    ['placeholder' => 'www.cloudflare.com']
));

$sectionWizard->addInput(new Form_Input(
    'reality_pubkey',
    gettext('*Public Key'),
    'text',
    $pconfig['reality_pubkey'],
    ['placeholder' => gettext('Base64 reality public key')]
));

$sectionWizard->addInput(new Form_Input(
    'reality_shortid',
    gettext('Short ID'),
    'text',
    $pconfig['reality_shortid'],
    ['placeholder' => 'e.g. a1b2c3d4']
));

$sectionWizard->addInput(new Form_Select(
    'reality_fingerprint',
    gettext('Fingerprint'),
    $pconfig['reality_fingerprint'],
    ['chrome' => 'chrome', 'firefox' => 'firefox', 'safari' => 'safari', 'edge' => 'edge', 'random' => 'random']
));

$form->add($sectionWizard);

$sectionCustom = new Form_Section(gettext('Custom Config (JSON)'));
$sectionCustom->setAttribute('id', 'custom-section');

$sectionCustom->addInput(new Form_Textarea(
    'custom_config',
    gettext('xray-core JSON'),
    $pconfig['custom_config'],
    ['rows' => '20', 'style' => 'font-family:monospace;font-size:12px',
     'placeholder' => '{"log": {...}, "inbounds": [...], "outbounds": [...], "routing": {...}}']
))->setHelp(gettext('Paste a complete xray-core config.json. Supports any protocol or transport.'));

$form->add($sectionCustom);

print($form);
?>

<nav class="action-buttons">
    <a href="/xray/xray_connections.php" class="btn btn-sm btn-default">
        <i class="fa fa-times icon-embed-btn"></i><?=gettext('Cancel')?>
    </a>
    <button type="submit" id="saveform" class="btn btn-primary btn-sm">
        <i class="fa fa-save icon-embed-btn"></i><?=gettext('Save')?>
    </button>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    function updateConfigMode() {
        var mode = $('#config_mode').val();
        if (mode === 'wizard') {
            $('#wizard-section').show();
            $('#custom-section').hide();
        } else {
            $('#wizard-section').hide();
            $('#custom-section').show();
        }
    }

    $('#config_mode').change(updateConfigMode);
    updateConfigMode();

    $('#saveform').click(function() {
        $(form).submit();
    });

    function parseVlessLink(link) {
        var m = link.match(/^vless:\/\/([0-9a-f\-]+)@([^:@\[\]]+|\[[^\]]+\]):(\d+)\??([^#]*)(?:#(.*))?$/i);
        if (!m) { return null; }
        var result = {
            vless_uuid: m[1],
            host:       m[2].replace(/^\[|\]$/g, ''),
            port:       m[3],
            name:       m[5] ? decodeURIComponent(m[5]) : ''
        };
        var params = {};
        if (m[4]) {
            m[4].split('&').forEach(function(p) {
                var kv = p.split('=');
                if (kv.length === 2) { params[kv[0]] = decodeURIComponent(kv[1]); }
            });
        }
        result.flow = params['flow'] || 'xtls-rprx-vision';
        result.sni  = params['sni']  || '';
        result.pbk  = params['pbk']  || '';
        result.sid  = params['sid']  || '';
        result.fp   = params['fp']   || 'chrome';
        return result;
    }

    $('#import-btn').on('click', function() {
        var link = $.trim($('#vless_link_input').val());
        if (!link) {
            $('#import-status').text('<?=gettext('Please paste a VLESS link first.')?>').removeClass().addClass('text-danger');
            return;
        }

        var data = parseVlessLink(link);
        if (!data) {
            $('#import-status').text('<?=gettext('Invalid VLESS link format.')?>').removeClass().addClass('text-danger');
            return;
        }

        if (data.host)       { $('input[name=server_address]').val(data.host); }
        if (data.port)       { $('input[name=server_port]').val(data.port); }
        if (data.vless_uuid) { $('input[name=vless_uuid]').val(data.vless_uuid); }
        if (data.flow)       { $('select[name=flow]').val(data.flow); }
        if (data.sni)        { $('input[name=reality_sni]').val(data.sni); }
        if (data.pbk)        { $('input[name=reality_pubkey]').val(data.pbk); }
        if (data.sid)        { $('input[name=reality_shortid]').val(data.sid); }
        if (data.fp)         { $('select[name=reality_fingerprint]').val(data.fp); }
        if (data.name && !$('input[name=name]').val()) {
            $('input[name=name]').val(data.name);
        }

        $('select[name=config_mode]').val('wizard').change();
        $('#import-status').text('<?=gettext('Fields populated successfully.')?>').removeClass().addClass('text-success');
    });
});
//]]>
</script>

<?php
include('xray/includes/xray_foot.inc');
include('foot.inc');
?>
