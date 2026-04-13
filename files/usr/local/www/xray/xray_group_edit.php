<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');
require_once('xray/includes/xray_validate.inc');

$editUuid = preg_replace('/[^0-9a-fA-F\-]/', '', $_GET['uuid'] ?? $_POST['uuid'] ?? '');
if (strlen($editUuid) < 36) {
    $editUuid = '';
}
$isNew = ($editUuid === '');

$defaults = [
    'name'       => '',
    'type'       => 'manual',
    'sub_url'    => '',
    'autoupdate' => '',
];

if (!$isNew) {
    $existing = xray_get_group_by_uuid($editUuid);
    $pconfig  = $existing !== null ? array_merge($defaults, $existing) : $defaults;
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

        $group = [
            'uuid'       => $newUuid,
            'name'       => trim($_POST['name'] ?? ''),
            'type'       => $type,
            'sub_url'    => $type === 'subscription' ? trim($_POST['sub_url'] ?? '') : '',
            'autoupdate' => ($type === 'subscription' && !empty($_POST['autoupdate'])) ? 'on' : '',
        ];

        xray_save_group($group);

        header('Location: /xray/xray_connections.php?group_uuid=' . urlencode($newUuid));
        exit;
    }

    $pconfig = array_merge($defaults, $_POST);
    $pconfig['uuid'] = $editUuid;
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Connections'),
            $isNew ? gettext('Add Group') : gettext('Edit Group')];
$pglinks = ['', '/xray/xray_instances.php', '/xray/xray_connections.php', '@self'];

$tab_array   = [];
$tab_array[] = [gettext('Connections'), true,  '/xray/xray_connections.php'];
$tab_array[] = [gettext('Instances'),   false, '/xray/xray_instances.php'];
$tab_array[] = [gettext('Settings'),    false, '/xray/xray_settings.php'];
$tab_array[] = [gettext('Diagnostics'), false, '/xray/xray_diagnostics.php'];

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

$sectionSub->addInput(new Form_Input(
    'sub_url',
    gettext('Subscription URL'),
    'text',
    $pconfig['sub_url'],
    ['placeholder' => 'https://example.com/sub/...']
))->setHelp(gettext('Remote URL returning a list of vless:// links (plain text or base64-encoded).'));

$sectionSub->addInput(new Form_Checkbox(
    'autoupdate',
    gettext('Auto-update'),
    gettext('Update subscription automatically every 30 minutes'),
    ($pconfig['autoupdate'] ?? '') === 'on'
))->setHelp(gettext('When enabled, the subscription is refreshed via cron every 30 minutes.'));

$form->add($sectionSub);

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
        }
    }

    $('#type').change(updateType);
    updateType();

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
