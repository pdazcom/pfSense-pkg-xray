<?php

/*
 * xray_connections.php — Connection groups and connections management.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-connections
##|*NAME=VPN: Xray: Connections
##|*DESCR=Allow access to the 'VPN: Xray: Connections' page.
##|*MATCH=xray_connections.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');

xray_bootstrap_default_group();
xray_migrate_instances_to_connections();

$activeGroupUuid = xray_sanitize_uuid($_GET['group_uuid'] ?? '');

$groups = xray_get_groups();

if ($activeGroupUuid === '' && !empty($groups)) {
    $activeGroupUuid = $groups[0]['uuid'];
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Connections')];
$pglinks  = ['', '/xray/xray_instances.php', '@self'];

$tab_array = xray_build_tab_array('connections');

include('head.inc');

display_top_tabs($tab_array);

function xray_render_test_result(string $json): string
{
    if ($json === '') {
        return '<span class="text-muted">&mdash;</span>';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '<span class="text-muted">&mdash;</span>';
    }
    if (($data['status'] ?? '') === 'ok') {
        $ms = (int)($data['ping_ms'] ?? 0);
        return '<span class="text-success"><i class="fa fa-check-circle"></i> ' . $ms . ' ms</span>';
    }
    return '<span class="text-danger"><i class="fa fa-times-circle"></i> ' . gettext('Unavailable') . '</span>';
}

?>
<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?=gettext('Connection Groups')?></h2>
    </div>
    <div class="panel-body">

<?php if (empty($groups)): ?>
        <?php print_info_box(gettext('No groups configured. Click "Add Group" to create one.'), 'warning', null); ?>
<?php else: ?>

        <ul class="nav nav-tabs" id="connGroupTabs">
<?php foreach ($groups as $group):
    $gUuid   = htmlspecialchars($group['uuid'], ENT_QUOTES, 'UTF-8');
    $gName   = htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8');
    $isActive = ($group['uuid'] === $activeGroupUuid);
    $isSub    = ($group['type'] ?? 'manual') === 'subscription';
?>
            <li<?=$isActive ? ' class="active"' : ''?>>
                <a href="?group_uuid=<?=$gUuid?>">
                    <?=$gName?>
                    <?php if ($isSub): ?>
                    <small class="text-muted">(sub)</small>
                    <?php endif; ?>
                </a>
            </li>
<?php endforeach; ?>
        </ul>

<?php foreach ($groups as $group):
    $gUuid    = htmlspecialchars($group['uuid'], ENT_QUOTES, 'UTF-8');
    $gName    = htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8');
    $isActive = ($group['uuid'] === $activeGroupUuid);
    $isSub    = ($group['type'] ?? 'manual') === 'subscription';
    $isDefault = ($group['uuid'] === XRAY_DEFAULT_GROUP_UUID);
    $connections = xray_get_connections_by_group($group['uuid']);
    if (!$isActive) { continue; }
?>
        <div class="tab-content" style="padding-top:15px">

            <div class="row" style="margin-bottom:10px; padding-left: 10px; padding-right: 10px;">
                <div class="col-sm-12">
                    <a href="/xray/xray_connection_edit.php?group_uuid=<?=$gUuid?>"
                       class="btn btn-success btn-sm">
                        <i class="fa fa-plus icon-embed-btn"></i><?=gettext('Add Connection')?>
                    </a>
                    <a href="/xray/xray_group_edit.php?uuid=<?=$gUuid?>"
                       class="btn btn-primary btn-sm" role="button">
                        <i class="fa fa-pencil icon-embed-btn"></i><?=gettext('Edit Group')?>
                    </a>
                    <?php if ($isSub): ?>
                    <button type="button" class="btn btn-info btn-sm xray-btn-update-sub"
                            data-group-uuid="<?=$gUuid?>">
                        <i class="fa fa-refresh icon-embed-btn"></i><?=gettext('Update')?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-warning btn-sm xray-btn-urltest-group"
                            data-group-uuid="<?=$gUuid?>">
                        <i class="fa fa-bolt icon-embed-btn"></i><?=gettext('URL Test All')?>
                    </button>
                    <button type="button" class="btn btn-danger btn-sm xray-btn-urltest-stop"
                            data-group-uuid="<?=$gUuid?>" style="display:none">
                        <i class="fa fa-stop icon-embed-btn"></i><?=gettext('Stop')?>
                    </button>
                    <?php if (!$isDefault): ?>
                    <button type="button" class="btn btn-danger btn-sm xray-btn-delete-group"
                            data-group-uuid="<?=$gUuid?>"
                            data-group-name="<?=$gName?>">
                        <i class="fa fa-trash icon-embed-btn"></i><?=gettext('Delete Group')?>
                    </button>
                    <?php endif; ?>
                    <span class="xray-group-action-status text-muted" style="margin-left:10px"></span>
                </div>
            </div>

            <table class="table table-hover table-striped table-condensed">
                <thead>
                    <tr>
                        <th><?=gettext('Name')?></th>
                        <th><?=gettext('Server')?></th>
                        <th><?=gettext('Mode')?></th>
                        <th><?=gettext('Test Result')?></th>
                        <th><?=gettext('Actions')?></th>
                    </tr>
                </thead>
                <tbody>
<?php if (!empty($connections)): ?>
<?php foreach ($connections as $conn):
    $connUuid    = htmlspecialchars($conn['uuid'], ENT_QUOTES, 'UTF-8');
    $connName    = htmlspecialchars($conn['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $serverLabel = htmlspecialchars(xray_connection_server_label($conn), ENT_QUOTES, 'UTF-8');
    $mode        = xray_connection_mode_label($conn);
    $testResult  = $conn['test_result'] ?? '';
?>
                    <tr>
                        <td><?=$connName?></td>
                        <td><?=$serverLabel !== '' ? '<code>' . $serverLabel . '</code>' : '<span class="text-muted">&mdash;</span>'?></td>
                        <td><?=htmlspecialchars($mode, ENT_QUOTES, 'UTF-8')?></td>
                        <td class="xray-test-result" data-conn-uuid="<?=$connUuid?>">
                            <?=xray_render_test_result($testResult)?>
                        </td>
                        <td>
                            <a class="fa fa-pencil" title="<?=gettext('Edit')?>"
                               href="/xray/xray_connection_edit.php?uuid=<?=$connUuid?>"></a>
                            <a class="fa fa-bolt text-info xray-btn-urltest" title="<?=gettext('URL Test')?>"
                               href="#" data-conn-uuid="<?=$connUuid?>"></a>
                            <a class="fa fa-trash text-danger xray-btn-delete-conn" title="<?=gettext('Delete')?>"
                               href="#" data-conn-uuid="<?=$connUuid?>"
                               data-conn-name="<?=$connName?>"></a>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                        <td colspan="5">
                            <?php print_info_box(gettext('No connections in this group.'), 'info', null); ?>
                        </td>
                    </tr>
<?php endif; ?>
                </tbody>
            </table>
        </div>
<?php endforeach; ?>

<?php endif; ?>

    </div>
</div>

<nav class="action-buttons">
    <a href="/xray/xray_group_edit.php" class="btn btn-success btn-sm">
        <i class="fa fa-plus icon-embed-btn"></i>
        <?=gettext('Add Group')?>
    </a>
</nav>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    var ajaxUrl = '/xray/xray_ajax.php';

    function showGroupStatus(msg, cls) {
        $('.xray-group-action-status').text(msg).removeClass().addClass('xray-group-action-status ' + cls);
    }

    function renderTestResult(data) {
        if (!data) {
            return '<span class="text-muted">&mdash;</span>';
        }
        if (data.status === 'ok') {
            return '<span class="text-success"><i class="fa fa-check-circle"></i> ' + data.ping_ms + ' ms</span>';
        }
        return '<span class="text-danger"><i class="fa fa-times-circle"></i> <?=gettext('Unavailable')?></span>';
    }

    $('.xray-btn-urltest').on('click', function(e) {
        e.preventDefault();
        var connUuid = $(this).data('conn-uuid');
        var $cell    = $('.xray-test-result[data-conn-uuid="' + connUuid + '"]');
        $cell.html('<i class="fa fa-spinner fa-spin text-muted"></i>');

        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'urltest', connection_uuid: connUuid },
            dataType: 'json',
            success:  function(data) { $cell.html(renderTestResult(data)); },
            error:    function()     { $cell.html('<span class="text-danger"><?=gettext('Error')?></span>'); }
        });
    });

    var urltestGroupTimer = null;

    function urltestGroupFinish(statusMsg, statusCls) {
        clearInterval(urltestGroupTimer);
        urltestGroupTimer = null;
        $('.xray-btn-urltest-group').prop('disabled', false);
        $('.xray-btn-urltest-stop').hide();
        showGroupStatus(statusMsg, statusCls);
        $('.xray-test-result').each(function() {
            if ($(this).find('.fa-spinner').length) {
                $(this).html('<span class="text-muted">&mdash;</span>');
            }
        });
    }

    function urltestGroupPoll(groupUuid) {
        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'urltest_group_status', group_uuid: groupUuid },
            dataType: 'json',
            success: function(data) {
                if (!data) { return; }
                $.each(data.results || {}, function(connUuid, result) {
                    if (result !== null) {
                        var $cell = $('.xray-test-result[data-conn-uuid="' + connUuid + '"]');
                        $cell.html(renderTestResult(result));
                    }
                });
                if (data.done) {
                    urltestGroupFinish('<?=gettext('Done.')?>', 'text-success xray-group-action-status');
                }
            },
            error: function() {
                urltestGroupFinish('<?=gettext('Error during test.')?>', 'text-danger xray-group-action-status');
            }
        });
    }

    $('.xray-btn-urltest-group').on('click', function() {
        var groupUuid = $(this).data('group-uuid');
        var $btn      = $(this);
        $btn.prop('disabled', true);
        $('.xray-btn-urltest-stop[data-group-uuid="' + groupUuid + '"]').show();
        showGroupStatus('<?=gettext('Testing...')?>', 'text-muted xray-group-action-status');

        $('.xray-test-result').each(function() {
            $(this).html('<i class="fa fa-spinner fa-spin text-muted"></i>');
        });

        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'urltest_group_start', group_uuid: groupUuid },
            dataType: 'json',
            success: function(data) {
                if (data && data.error) {
                    urltestGroupFinish(data.error, 'text-danger xray-group-action-status');
                    return;
                }
                if (urltestGroupTimer) {
                    clearInterval(urltestGroupTimer);
                }
                urltestGroupTimer = setInterval(function() {
                    urltestGroupPoll(groupUuid);
                }, 2000);
            },
            error: function() {
                urltestGroupFinish('<?=gettext('Error during test.')?>', 'text-danger xray-group-action-status');
            }
        });
    });

    $('.xray-btn-urltest-stop').on('click', function() {
        var groupUuid = $(this).data('group-uuid');
        showGroupStatus('<?=gettext('Stopping...')?>', 'text-muted xray-group-action-status');
        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'urltest_group_stop', group_uuid: groupUuid },
            dataType: 'json',
            complete: function() {
                // finish state will come from the next poll when the script writes done:true
            }
        });
    });

    $('.xray-btn-update-sub').on('click', function() {
        var groupUuid = $(this).data('group-uuid');
        var $btn      = $(this);
        $btn.prop('disabled', true);
        showGroupStatus('<?=gettext('Updating subscription...')?>', 'text-muted xray-group-action-status');

        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'update_subscription', group_uuid: groupUuid },
            dataType: 'json',
            complete: function(xhr) {
                $btn.prop('disabled', false);
                var data = xhr.responseJSON;
                if (data && data.error) {
                    showGroupStatus('<?=gettext('Error:')?> ' + data.error, 'text-danger xray-group-action-status');
                } else if (data) {
                    showGroupStatus(
                        '<?=gettext('Done.')?> +' + (data.added||0) + ' ~' + (data.updated||0) + ' -' + (data.removed||0),
                        'text-success xray-group-action-status'
                    );
                    setTimeout(function() { location.reload(); }, 1500);
                }
            }
        });
    });

    $('.xray-btn-delete-conn').on('click', function(e) {
        e.preventDefault();
        var connUuid = $(this).data('conn-uuid');
        var connName = $(this).data('conn-name');
        if (!confirm('<?=gettext('Delete connection')?> "' + connName + '"?')) { return; }

        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'delete_connection', uuid: connUuid },
            dataType: 'json',
            success:  function(data) {
                if (data && data.error) {
                    alert(data.error);
                } else {
                    location.reload();
                }
            }
        });
    });

    $('.xray-btn-delete-group').on('click', function() {
        var groupUuid = $(this).data('group-uuid');
        var groupName = $(this).data('group-name');
        if (!confirm('<?=gettext('Delete group')?> "' + groupName + '"? <?=gettext('All connections in this group will be deleted.')?>')) { return; }

        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: 'delete_group', uuid: groupUuid },
            dataType: 'json',
            success:  function(data) {
                if (data && data.error) {
                    alert(data.error);
                } else {
                    location.href = '/xray/xray_connections.php';
                }
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
