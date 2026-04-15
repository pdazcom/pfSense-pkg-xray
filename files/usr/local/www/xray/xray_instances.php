<?php
/*
 * xray_instances.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray
##|*NAME=VPN: Xray
##|*DESCR=Allow access to the 'VPN: Xray' page.
##|*MATCH=xray_instances.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');

if ($_POST && isset($_POST['act']) && $_POST['act'] === 'delete') {
	$delUuid = xray_sanitize_uuid($_POST['uuid'] ?? '');
	if ($delUuid !== '') {
		mwexec('/usr/local/bin/php /usr/local/scripts/xray/xray-service-control.php stop ' . escapeshellarg($delUuid));
		xray_delete_instance($delUuid);
	}
	header('Location: /xray/xray_instances.php');
	exit;
}

$instances = xray_get_instances();
$allConns  = xray_get_connections();
$allGroups = xray_get_groups();

function xray_instance_connection_label(array $inst, array $allConns, array $allGroups): string
{
	$mode = $inst['connection_mode'] ?? 'fixed';

	if ($mode === 'rotation') {
		$groupUuid = $inst['connection_group_uuid'] ?? '';
		$groupName = '';
		foreach ($allGroups as $g) {
			if ($g['uuid'] === $groupUuid) {
				$groupName = $g['name'];
				break;
			}
		}
		return htmlspecialchars($groupName !== '' ? $groupName : gettext('(unknown group)'), ENT_QUOTES, 'UTF-8')
			. ' <span class="label label-info">' . gettext('rotation') . '</span>';
	}

	$connUuid = $inst['connection_uuid'] ?? '';
	foreach ($allConns as $conn) {
		if ($conn['uuid'] === $connUuid) {
			$name        = htmlspecialchars($conn['name'] ?? '', ENT_QUOTES, 'UTF-8');
			$serverLabel = xray_connection_server_label($conn);
			$serverHtml  = $serverLabel !== ''
				? '<br><small class="text-muted"><code>' . htmlspecialchars($serverLabel, ENT_QUOTES, 'UTF-8') . '</code></small>'
				: '';
			return $name . $serverHtml;
		}
	}
	return '<span class="text-muted">' . gettext('(none)') . '</span>';
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Instances')];
$pglinks  = ['', '@self', '@self'];

$tab_array = xray_build_tab_array('instances');

include('head.inc');

display_top_tabs($tab_array);

?>
<form name="mainform" method="post">
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Xray Instances')?></h2></div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Connection')?></th>
					<th><?=gettext('TUN Interface')?></th>
					<th><?=gettext('SOCKS5')?></th>
					<th><?=gettext('Status')?></th>
					<th><?=gettext('Actions')?></th>
				</tr>
			</thead>
			<tbody>
<?php
if (!empty($instances)):
	foreach ($instances as $inst):
		$uuid = htmlspecialchars($inst['uuid'] ?? '', ENT_QUOTES, 'UTF-8');
?>
				<tr>
					<td><?=htmlspecialchars($inst['name'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
					<td><?=xray_instance_connection_label($inst, $allConns, $allGroups)?></td>
					<td><code><?=htmlspecialchars($inst['tun_interface'] ?? '', ENT_QUOTES, 'UTF-8')?></code></td>
					<td><?=htmlspecialchars($inst['socks5_listen'] ?? '', ENT_QUOTES, 'UTF-8')?>:<?=(int)($inst['socks5_port'] ?? 10808)?></td>
					<td>
						<span class="xray-status" data-uuid="<?=$uuid?>">
							<i class="fa fa-spinner fa-spin text-muted"></i>
						</span>
					</td>
					<td>
						<a class="fa fa-pencil" title="<?=gettext('Edit')?>" href="/xray/xray_edit.php?uuid=<?=urlencode($inst['uuid'] ?? '')?>"></a>
						<a class="fa fa-play text-success xray-btn-start" title="<?=gettext('Start')?>" href="#" data-uuid="<?=$uuid?>"></a>
						<a class="fa fa-stop text-warning xray-btn-stop" title="<?=gettext('Stop')?>" href="#" data-uuid="<?=$uuid?>"></a>
						<a class="fa fa-bar-chart text-info" title="<?=gettext('Diagnostics')?>" href="/xray/xray_diagnostics.php?uuid=<?=urlencode($inst['uuid'] ?? '')?>"></a>
						<a class="fa fa-trash text-danger" title="<?=gettext('Delete')?>" href="?act=delete&amp;uuid=<?=$uuid?>" usepost></a>
					</td>
				</tr>
<?php
	endforeach;
else:
?>
				<tr>
					<td colspan="6">
						<?php print_info_box(gettext('No Xray instances configured. Click "Add Instance" below to create one.'), 'warning', null); ?>
					</td>
				</tr>
<?php
endif;
?>
			</tbody>
		</table>
	</div>
</div>
</form>

<nav class="action-buttons">
	<a href="/xray/xray_edit.php" class="btn btn-success btn-sm">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext('Add Instance')?>
	</a>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function renderStatus(s) {
		if (!s) {
			return '<span class="text-muted"><i class="fa fa-question-circle"></i> <?=gettext('Unknown')?></span>';
		}
		if (s.status === 'ok') {
			return '<span class="text-success"><i class="fa fa-check-circle"></i> <?=gettext('Running')?></span>';
		}
		return '<span class="text-danger"><i class="fa fa-times-circle"></i> <?=gettext('Stopped')?></span>';
	}

	function refreshStatus() {
		$.ajax({
			url: '/xray/xray_ajax.php',
			type: 'post',
			data: { action: 'statusall' },
			dataType: 'json',
			success: function(data) {
				$('.xray-status[data-uuid]').each(function() {
					var uuid = $(this).data('uuid');
					$(this).html(renderStatus(data[uuid]));
				});
			},
			error: function() {
				$('.xray-status').html('<span class="text-muted"><i class="fa fa-exclamation-triangle"></i></span>');
			}
		});
	}

	function instanceAction(action, uuid) {
		$.ajax({
			url: '/xray/xray_ajax.php',
			type: 'post',
			data: { action: action, uuid: uuid },
			dataType: 'json',
			success: function(data) {
				if (data.result === 'failed' && data.output) {
					alert(data.output);
				}
			},
			complete: function() {
				setTimeout(refreshStatus, 1500);
			}
		});
	}

	$('.xray-btn-start').on('click', function(e) {
		e.preventDefault();
		instanceAction('start', $(this).data('uuid'));
	});

	$('.xray-btn-stop').on('click', function(e) {
		e.preventDefault();
		instanceAction('stop', $(this).data('uuid'));
	});

	refreshStatus();
	setInterval(refreshStatus, 10000);
});
//]]>
</script>

<?php
include('xray/includes/xray_foot.inc');
include('foot.inc');
?>
