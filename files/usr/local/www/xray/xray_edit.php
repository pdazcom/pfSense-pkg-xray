<?php
/*
 * xray_edit.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-edit
##|*NAME=VPN: Xray: Edit Instance
##|*DESCR=Allow access to the 'VPN: Xray: Edit Instance' page.
##|*MATCH=xray_edit.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_validate.inc');

$editUuid = xray_sanitize_uuid($_GET['uuid'] ?? $_POST['uuid'] ?? '');
$isNew = ($editUuid === '');

$defaults = [
    'name'                   => '',
    'connection_mode'        => 'fixed',
    'connection_uuid'        => '',
    'connection_group_uuid'  => '',
    'webhook_url'            => '',
    'socks5_listen'          => '127.0.0.1',
    'socks5_port'            => '10808',
    'tun_interface'          => 'proxytun0',
    'mtu'                    => '1500',
    'loglevel'               => 'warning',
    'bypass_networks'        => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
];

if (!$isNew) {
    $existing = xray_get_instance_by_uuid($editUuid);
    $pconfig  = $existing !== null ? array_merge($defaults, $existing) : $defaults;
} else {
    $pconfig = $defaults;
}

$input_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'save') {
    xray_validate_input($_POST, $isNew ? null : $editUuid);

    if (empty($input_errors)) {
        $newUuid = $isNew ? xray_generate_uuid() : $editUuid;

        $connectionMode = in_array($_POST['connection_mode'] ?? '', ['fixed', 'rotation'], true)
            ? $_POST['connection_mode'] : 'fixed';

        $oldGroupUuid = $pconfig['connection_group_uuid'] ?? '';
        $newGroupUuid = trim($_POST['connection_group_uuid'] ?? '');

        $activeConnUuid = $isNew ? '' : ($pconfig['active_connection_uuid'] ?? '');
        if ($connectionMode === 'rotation' && $newGroupUuid !== $oldGroupUuid) {
            $activeConnUuid = '';
        }

        $instance = [
            'uuid'                   => $newUuid,
            'name'                   => trim($_POST['name'] ?? ''),
            'connection_mode'        => $connectionMode,
            'connection_uuid'        => $connectionMode === 'fixed' ? trim($_POST['connection_uuid'] ?? '') : '',
            'connection_group_uuid'  => $connectionMode === 'rotation' ? $newGroupUuid : '',
            'active_connection_uuid' => $activeConnUuid,
            'webhook_url'            => trim($_POST['webhook_url'] ?? ''),
            'socks5_listen'          => trim($_POST['socks5_listen'] ?? '127.0.0.1'),
            'socks5_port'            => (string)(int)($_POST['socks5_port'] ?? 10808),
            'tun_interface'          => trim($_POST['tun_interface'] ?? ''),
            'mtu'                    => (string)(int)($_POST['mtu'] ?? 1500),
            'loglevel'               => in_array(
                                             $_POST['loglevel'] ?? '',
                                             ['debug', 'info', 'warning', 'error', 'none'],
                                             true
                                         ) ? $_POST['loglevel'] : 'warning',
            'bypass_networks'        => trim($_POST['bypass_networks'] ?? ''),
        ];

        xray_save_instance($instance);
        xray_register_tun_interface($instance['tun_interface'], $newUuid);
        write_config('Xray: save instance and register TUN ' . $newUuid);
        xray_resync();

        header('Location: /xray/xray_instances.php');
        exit;
    }

    $pconfig = array_merge($defaults, $_POST);
    $pconfig['uuid'] = $editUuid;
}

$allConnections = xray_get_connections();
$allGroups      = xray_get_groups();

$connectionOptions = [];
foreach ($allConnections as $conn) {
    $groupName = '';
    foreach ($allGroups as $g) {
        if ($g['uuid'] === $conn['group_uuid']) {
            $groupName = $g['name'];
            break;
        }
    }
    $label = ($groupName !== '' ? $groupName . ' / ' : '') . ($conn['name'] ?? '')
        . ' (' . ($conn['server_address'] ?? '') . ':' . ($conn['server_port'] ?? '') . ')';
    $connectionOptions[$conn['uuid']] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
}

$groupOptions = [];
foreach ($allGroups as $g) {
    $groupOptions[$g['uuid']] = htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8');
}

$pgtitle = [gettext('VPN'), gettext('Xray'), $isNew ? gettext('Add Instance') : gettext('Edit Instance')];
$pglinks = ['', '/xray/xray_instances.php', '/xray/xray_instances.php', '@self'];

$tab_array = xray_build_tab_array('instances');

include('head.inc');

if (!empty($input_errors)) {
    print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$form = new Form(false);

$form->addGlobal(new Form_Input('act',  '', 'hidden', 'save'));
$form->addGlobal(new Form_Input('uuid', '', 'hidden', $editUuid));

$sectionCommon = new Form_Section(gettext('Instance Settings'));

$sectionCommon->addInput(new Form_Input(
    'name',
    gettext('*Name'),
    'text',
    $pconfig['name'],
    ['placeholder' => 'My VPN', 'maxlength' => '64']
));

$sectionCommon->addInput(new Form_Select(
    'connection_mode',
    gettext('Connection Mode'),
    $pconfig['connection_mode'],
    ['fixed' => gettext('Fixed Connection'), 'rotation' => gettext('Auto Rotation')]
))->setHelp(gettext('Fixed uses one connection. Rotation tests connections in a group and picks the first working one.'));

$form->add($sectionCommon);

$sectionFixed = new Form_Section(gettext('Fixed Connection'));
$sectionFixed->setAttribute('id', 'fixed-section');

if (!empty($connectionOptions)) {
    $sectionFixed->addInput(new Form_Select(
        'connection_uuid',
        gettext('Connection'),
        $pconfig['connection_uuid'],
        $connectionOptions
    ))->setHelp(gettext('Select the connection this instance will use.'));
} else {
    $sectionFixed->addInput(new Form_StaticText(
        gettext('Connection'),
        gettext('No connections available.') . ' <a href="/xray/xray_connections.php">' . gettext('Create one') . '</a>.'
    ));
}

$form->add($sectionFixed);

$sectionRotation = new Form_Section(gettext('Auto Rotation'));
$sectionRotation->setAttribute('id', 'rotation-section');

if (!empty($groupOptions)) {
    $sectionRotation->addInput(new Form_Select(
        'connection_group_uuid',
        gettext('Connection Group'),
        $pconfig['connection_group_uuid'],
        $groupOptions
    ))->setHelp(gettext('Group of connections to rotate through. Each connection is tested before use.'));
} else {
    $sectionRotation->addInput(new Form_StaticText(
        gettext('Connection Group'),
        gettext('No groups available.')
    ));
}

$sectionRotation->addInput(new Form_Input(
    'webhook_url',
    gettext('Failure Webhook'),
    'text',
    $pconfig['webhook_url'],
    ['placeholder' => 'https://hooks.example.com/...']
))->setHelp(gettext('Optional. Called via HTTP POST when no working connection is found in the group.'));

$form->add($sectionRotation);

$sectionNetwork = new Form_Section(gettext('Network Settings'));

$sectionNetwork->addInput(new Form_Input(
    'socks5_listen',
    gettext('SOCKS5 Listen'),
    'text',
    $pconfig['socks5_listen'],
    ['placeholder' => '127.0.0.1']
))->setHelp(gettext('IPv4 address xray-core listens on for SOCKS5. Use 127.0.0.1 to keep the proxy local. Setting a LAN IP exposes the unauthenticated SOCKS5 proxy to that network — ensure firewall rules restrict access.'));

$sectionNetwork->addInput(new Form_StaticText(
    '',
    '<div id="socks5-warning" class="alert alert-warning" style="display:none;margin-bottom:0">' .
    '<i class="fa fa-exclamation-triangle"></i> ' .
    gettext('Warning: SOCKS5 will be exposed on the network without authentication. Make sure pfSense firewall rules block unauthorized access to this port.') .
    '</div>'
));

$sectionNetwork->addInput(new Form_Input(
    'socks5_port',
    gettext('SOCKS5 Port'),
    'number',
    $pconfig['socks5_port'],
    ['min' => '1', 'max' => '65535']
));

$sectionNetwork->addInput(new Form_Input(
    'tun_interface',
    gettext('*TUN Interface'),
    'text',
    $pconfig['tun_interface'],
    ['placeholder' => 'proxytun0', 'maxlength' => '15']
))->setHelp(gettext('Must start with a lowercase letter and end with a digit (e.g. proxytun0).'));

$sectionNetwork->addInput(new Form_Input(
    'mtu',
    gettext('MTU'),
    'number',
    $pconfig['mtu'],
    ['min' => '576', 'max' => '9000']
));

$sectionNetwork->addInput(new Form_Select(
    'loglevel',
    gettext('Log Level'),
    $pconfig['loglevel'],
    ['debug' => 'debug', 'info' => 'info', 'warning' => 'warning', 'error' => 'error', 'none' => 'none']
));

$sectionNetwork->addInput(new Form_Input(
    'bypass_networks',
    gettext('Bypass Networks'),
    'text',
    $pconfig['bypass_networks']
))->setHelp(gettext('Comma-separated CIDRs routed directly, not through Xray (e.g. 10.0.0.0/8,192.168.0.0/16).'));

$form->add($sectionNetwork);

print($form);
?>

<nav class="action-buttons">
    <a href="/xray/xray_instances.php" class="btn btn-sm btn-default">
        <i class="fa fa-times icon-embed-btn"></i><?=gettext('Cancel')?>
    </a>
    <button type="submit" id="saveform" class="btn btn-primary btn-sm">
        <i class="fa fa-save icon-embed-btn"></i><?=gettext('Save')?>
    </button>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    function updateConnectionMode() {
        var mode = $('#connection_mode').val();
        if (mode === 'fixed') {
            $('#fixed-section').show();
            $('#rotation-section').hide();
        } else {
            $('#fixed-section').hide();
            $('#rotation-section').show();
        }
    }

    $('#connection_mode').change(updateConnectionMode);
    updateConnectionMode();

    function updateSocks5Warning() {
        var addr = $('#socks5_listen').val().trim();
        var isLocal = (addr === '' || addr === '127.0.0.1' || /^127\./.test(addr) || addr === '0.0.0.0');
        if (isLocal) {
            $('#socks5-warning').hide();
        } else {
            $('#socks5-warning').show();
        }
    }

    $('#socks5_listen').on('input change', updateSocks5Warning);
    updateSocks5Warning();

    $('#saveform').click(function() {
        $(form).submit();
    });
});
//]]>
</script>

<?php
include('xray/includes/xray_foot.inc');
include('foot.inc');
?>
