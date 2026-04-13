<?php
/*
 * xray_settings.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-settings
##|*NAME=VPN: Xray: Settings
##|*DESCR=Allow access to the 'VPN: Xray: Settings' page.
##|*MATCH=xray_settings.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');

$pconfig = xray_get_global_config();
$save_success = false;

if ($_POST && isset($_POST['act']) && $_POST['act'] === 'save') {
	$testUrl = trim($_POST['test_url'] ?? '');
	if ($testUrl === '' || !filter_var($testUrl, FILTER_VALIDATE_URL)) {
		$testUrl = 'https://www.google.com';
	}
	$notifHook = trim($_POST['notification_webhook'] ?? '');
	if ($notifHook !== '' && !filter_var($notifHook, FILTER_VALIDATE_URL)) {
		$notifHook = '';
	}
	$pconfig = [
		'enabled'               => !empty($_POST['enabled'])          ? 'on' : '',
		'watchdog_enabled'      => !empty($_POST['watchdog_enabled']) ? 'on' : '',
		'test_url'              => $testUrl,
		'notification_webhook'  => $notifHook,
	];
	xray_save_global_config($pconfig);
	xray_resync();
	$save_success = true;
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Settings')];
$pglinks  = ['', '/xray/xray_instances.php', '@self'];

$tab_array   = [];
$tab_array[] = [gettext('Connections'), false, '/xray/xray_connections.php'];
$tab_array[] = [gettext('Instances'),   false, '/xray/xray_instances.php'];
$tab_array[] = [gettext('Settings'),    true,  '/xray/xray_settings.php'];
$tab_array[] = [gettext('Diagnostics'), false, '/xray/xray_diagnostics.php'];

include('head.inc');

if ($save_success) {
	print_info_box(gettext('Settings saved and services reconfigured.'), 'success');
}

display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section(gettext('General Settings'));

$section->addInput(new Form_Checkbox(
	'enabled',
	gettext('Enable'),
	gettext('Enable Xray'),
	($pconfig['enabled'] ?? '') === 'on'
))->setHelp(gettext('Start all configured instances on boot and allow manual start/stop.'));

$section->addInput(new Form_Checkbox(
	'watchdog_enabled',
	gettext('Watchdog'),
	gettext('Enable crash watchdog'),
	($pconfig['watchdog_enabled'] ?? '') === 'on'
))->setHelp(gettext('Automatically restart crashed xray-core or tun2socks processes (runs every minute via cron).'));

$section->addInput(new Form_Input(
	'test_url',
	gettext('URL Test Target'),
	'text',
	$pconfig['test_url'] ?? 'https://www.google.com',
	['placeholder' => 'https://www.google.com']
))->setHelp(gettext('URL used when testing connections. Must return HTTP 2xx-3xx for a connection to be considered working.'));

$section->addInput(new Form_Input(
	'notification_webhook',
	gettext('Notification Webhook'),
	'text',
	$pconfig['notification_webhook'] ?? '',
	['placeholder' => 'https://hooks.example.com/...']
))->setHelp(gettext('Optional. HTTP POST called when rotation finds no working connection. Leave empty to disable.'));

$form->add($section);

$form->addGlobal(new Form_Input('act', '', 'hidden', 'save'));

print($form);

?>
<nav class="action-buttons">
	<button type="submit" id="saveform" class="btn btn-primary btn-sm">
		<i class="fa fa-save icon-embed-btn"></i>
		<?=gettext('Save')?>
	</button>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
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
