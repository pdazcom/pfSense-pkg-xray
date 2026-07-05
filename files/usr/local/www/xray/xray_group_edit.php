<?php
/*
 * xray_group_edit.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-group-edit
##|*NAME=VPN: Xray: Edit Group
##|*DESCR=Allow access to the 'VPN: Xray: Edit Group' page.
##|*MATCH=xray_group_edit.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_validate.inc');

$editUuid = xray_sanitize_uuid($_GET['uuid'] ?? $_POST['uuid'] ?? '');
$isNew = ($editUuid === '');

$defaults = [
    'name'           => '',
    'type'           => 'manual',
    'sub_urls'       => '',
    'autoupdate'     => '',
    'happ_enabled'   => '',
    'hwid'           => '',
    'happ_ua'        => 'Happ/3.13.0',
    'happ_device_os' => 'Android',
    'happ_model'     => '',
    'happ_locale'    => 'ru',
    'happ_os_ver'    => '15',
];

if (!$isNew) {
    $existing = xray_get_group_by_uuid($editUuid);
    if ($existing !== null) {
        $pconfig = array_merge($defaults, $existing);
        $pconfig['sub_urls'] = implode("\n", xray_group_sub_urls($existing));
    } else {
        $pconfig = $defaults;
    }
} else {
    $pconfig = $defaults;
}

$input_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'save') {
    xray_validate_group($_POST, $isNew ? null : $editUuid);

    if (empty($input_errors)) {
        $newUuid = $isNew ? xray_generate_uuid() : $editUuid;

        $type = in_array($_POST['type'] ?? '', ['manual', 'subscription'], true)
            ? $_POST['type'] : 'manual';

        $subUrls = '';
        if ($type === 'subscription') {
            $rawLines = preg_split('/[\r\n]+/', $_POST['sub_urls'] ?? '');
            $subUrls  = implode("\n", array_values(array_filter(array_map('trim', $rawLines))));
        }

        $group = [
            'uuid'           => $newUuid,
            'name'           => trim($_POST['name'] ?? ''),
            'type'           => $type,
            'sub_urls'       => $subUrls,
            'autoupdate'     => ($type === 'subscription' && !empty($_POST['autoupdate'])) ? 'on' : '',
            'happ_enabled'   => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? 'on' : '',
            'hwid'           => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['hwid'] ?? '') : '',
            'happ_ua'        => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['happ_ua'] ?? '') : '',
            'happ_device_os' => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['happ_device_os'] ?? '') : '',
            'happ_model'     => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['happ_model'] ?? '') : '',
            'happ_locale'    => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['happ_locale'] ?? '') : '',
            'happ_os_ver'    => ($type === 'subscription' && !empty($_POST['happ_enabled'])) ? trim($_POST['happ_os_ver'] ?? '') : '',
        ];

        xray_save_group($group);

        header('Location: /xray/xray_connections.php?group_uuid=' . urlencode($newUuid));
        exit;
    }

    $pconfig         = array_merge($defaults, $_POST);
    $pconfig['uuid'] = $editUuid;
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Connections'),
            $isNew ? gettext('Add Group') : gettext('Edit Group')];
$pglinks = ['', '/xray/xray_instances.php', '/xray/xray_connections.php', '@self'];

$tab_array = xray_build_tab_array('connections');

include('head.inc');

if (!empty($input_errors)) {
    print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$form = new Form(false);

$form->addGlobal(new Form_Input('act',  '', 'hidden', 'save'));
$form->addGlobal(new Form_Input('uuid', '', 'hidden', $editUuid));

$section = new Form_Section(gettext('Group Settings'));

$section->addInput(new Form_Input(
    'name',
    gettext('*Name'),
    'text',
    $pconfig['name'],
    ['placeholder' => gettext('My Group'), 'maxlength' => '64']
));

$section->addInput(new Form_Select(
    'type',
    gettext('Type'),
    $pconfig['type'],
    ['manual' => gettext('Manual'), 'subscription' => gettext('Subscription')]
))->setHelp(gettext('Manual groups are managed manually. Subscription groups are populated from a remote URL.'));

$form->add($section);

$sectionSub = new Form_Section(gettext('Subscription Settings'));
$sectionSub->setAttribute('id', 'subscription-section');

$sectionSub->addInput(new Form_Textarea(
    'sub_urls',
    gettext('Subscription URLs'),
    $pconfig['sub_urls'],
    ['placeholder' => "https://provider1.example.com/sub/token1\nhttps://provider2.example.com/sub/token2", 'rows' => '4']
))->setHelp(gettext('One URL per line. All URLs will be fetched and merged into a single pool of connections.'));

$sectionSub->addInput(new Form_Checkbox(
    'autoupdate',
    gettext('Auto-update'),
    gettext('Update subscription automatically every 30 minutes'),
    ($pconfig['autoupdate'] ?? '') === 'on'
))->setHelp(gettext('When enabled, the subscription is refreshed via cron every 30 minutes.'));

$sectionSub->addInput(new Form_Checkbox(
    'happ_enabled',
    gettext('Happ Headers'),
    gettext('Send Happ app headers with subscription requests (X-Hwid, User-Agent, etc.)'),
    ($pconfig['happ_enabled'] ?? '') === 'on'
))->setHelp(gettext('Enable if your subscription provider uses Happ device authentication.'));

$form->add($sectionSub);

$sectionHapp = new Form_Section(gettext('Happ Subscription Headers'));
$sectionHapp->setAttribute('id', 'happ-section');

$hwid = htmlspecialchars($pconfig['hwid'] ?? '', ENT_QUOTES, 'UTF-8');
$sectionHapp->addInput(new Form_StaticText(
    gettext('HWID'),
    '<div class="input-group">'
    . '<input type="text" name="hwid" id="hwid" class="form-control" maxlength="128"'
    . ' placeholder="' . gettext('e.g. 74jf74nf8f4jr5je') . '"'
    . ' value="' . $hwid . '">'
    . '<span class="input-group-btn">'
    . '<button type="button" id="btn-gen-hwid" class="btn btn-default btn-sm">'
    . '<i class="fa fa-random icon-embed-btn"></i>' . gettext('Generate') . '</button>'
    . '</span>'
    . '</div>'
    . '<span class="help-block">' . gettext('X-Hwid header sent with subscription requests. Uniquely identifies this device to the server.') . '</span>'
));

$sectionHapp->addInput(new Form_Input(
    'happ_ua',
    gettext('User-Agent'),
    'text',
    $pconfig['happ_ua'] ?: 'Happ/3.13.0',
    ['maxlength' => '128', 'placeholder' => 'Happ/3.13.0']
))->setHelp(gettext('User-Agent header. Default: Happ/3.13.0'));

$sectionHapp->addInput(new Form_Input(
    'happ_device_os',
    gettext('Device OS'),
    'text',
    $pconfig['happ_device_os'] ?: 'Android',
    ['maxlength' => '64', 'placeholder' => 'Android']
))->setHelp(gettext('X-Device-Os header.'));

$sectionHapp->addInput(new Form_Input(
    'happ_model',
    gettext('Device Model'),
    'text',
    $pconfig['happ_model'] ?? '',
    ['maxlength' => '64', 'placeholder' => 'ELP-NX1']
))->setHelp(gettext('X-Device-Model header. Optional.'));

$sectionHapp->addInput(new Form_Input(
    'happ_locale',
    gettext('Locale'),
    'text',
    $pconfig['happ_locale'] ?: 'ru',
    ['maxlength' => '64', 'placeholder' => 'ru']
))->setHelp(gettext('X-Device-Locale header.'));

$sectionHapp->addInput(new Form_Input(
    'happ_os_ver',
    gettext('OS Version'),
    'text',
    $pconfig['happ_os_ver'] ?: '15',
    ['maxlength' => '64', 'placeholder' => '15']
))->setHelp(gettext('X-Ver-Os header.'));

$form->add($sectionHapp);

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
    function updateType() {
        var type = $('#type').val();
        if (type === 'subscription') {
            $('#subscription-section').show();
        } else {
            $('#subscription-section').hide();
            $('#happ-section').hide();
        }
        updateHapp();
    }

    function updateHapp() {
        var type = $('#type').val();
        if (type === 'subscription' && $('#happ_enabled').is(':checked')) {
            $('#happ-section').show();
        } else {
            $('#happ-section').hide();
        }
    }

    $('#type').change(updateType);
    $('#happ_enabled').change(updateHapp);
    updateType();

    $('#btn-gen-hwid').click(function() {
        var hwid = '';
        for (var i = 0; i < 16; i++) {
            hwid += Math.floor(Math.random() * 16).toString(16);
        }
        $('#hwid').val(hwid);
    });

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
