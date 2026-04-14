<?php
/*
 * xray_diagnostics.php
 *
 * Copyright (c) 2026 Konstantin A.
 * All rights reserved.
 *
 * Licensed under the BSD 2-Clause License.
 */

##|+PRIV
##|*IDENT=page-vpn-xray-diagnostics
##|*NAME=VPN: Xray: Diagnostics
##|*DESCR=Allow access to the 'VPN: Xray: Diagnostics' page.
##|*MATCH=xray_diagnostics.php*
##|-PRIV

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('xray/includes/xray.inc');

$instances = xray_get_instances();

$selectedUuid = xray_sanitize_uuid($_GET['uuid'] ?? '');

if ($selectedUuid === '' && !empty($instances)) {
    $selectedUuid = $instances[0]['uuid'] ?? '';
}

$pgtitle = [gettext('VPN'), gettext('Xray'), gettext('Diagnostics')];
$pglinks  = ['', '/xray/xray_instances.php', '/xray/xray_instances.php', '@self'];

$tab_array = xray_build_tab_array('diagnostics');

include('head.inc');

display_top_tabs($tab_array);

?>

<?php if (empty($instances)): ?>
<?php print_info_box(gettext('No Xray instances configured.') . ' <a href="/xray/xray_edit.php">' . gettext('Add one') . '</a>.', 'warning', null); ?>
<?php else: ?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext('Interface Statistics') ?></h2>
    </div>
    <div class="panel-body" style="padding: 10px;">
        <div class="form-horizontal">
            <div class="form-group">
                <label class="col-sm-2 control-label"><?= gettext('Instance') ?></label>
                <div class="col-sm-4">
                    <select id="instance-select" class="form-control">
                        <?php foreach ($instances as $inst): ?>
                        <option value="<?= htmlspecialchars($inst['uuid'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            <?= ($inst['uuid'] ?? '') === $selectedUuid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst['name'] ?? $inst['uuid'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars($inst['tun_interface'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <button class="btn btn-primary btn-sm" id="refresh-btn">
                        <i class="fa fa-refresh icon-embed-btn"></i><?= gettext('Refresh') ?>
                    </button>
                    <button class="btn btn-info btn-sm" id="testconnect-btn">
                        <i class="fa fa-plug icon-embed-btn"></i><?= gettext('Test Connection') ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="testconnect-result" class="alert" style="display:none;margin-top:10px"></div>

        <div id="stats-loading" style="display:none;padding:10px 0" class="text-muted">
            <i class="fa fa-spinner fa-spin"></i> <?= gettext('Loading stats...') ?>
        </div>

        <table class="table table-condensed table-bordered" style="max-width:520px;margin-top:10px">
            <tbody id="stats-table">
                <tr>
                    <td colspan="2" class="text-muted text-center">
                        <?= gettext('Select an instance and click Refresh.') ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext('Logs') ?></h2>
    </div>
    <div class="panel-body">
        <ul class="nav nav-tabs" id="logTabs">
            <li class="active">
                <a href="#tab-xraylog" data-toggle="tab">
                    <i class="fa fa-file-text-o"></i> <?= gettext('xray-core Log') ?>
                </a>
            </li>
            <li>
                <a href="#tab-watchdoglog" data-toggle="tab">
                    <i class="fa fa-heartbeat"></i> <?= gettext('Watchdog Log') ?>
                </a>
            </li>
        </ul>
        <style>.xray-log-pre{max-height:400px;overflow-y:auto;font-size:11px;margin-top:5px}</style>
        <div class="tab-content" style="margin-top:10px; padding: 10px;">
            <div id="tab-xraylog" class="tab-pane active">
                <button class="btn btn-primary btn-xs" id="load-xraylog-btn">
                    <i class="fa fa-download icon-embed-btn"></i><?= gettext('Load Log') ?>
                </button>
                <pre id="xraylog-content" class="xray-log-pre"><?= gettext('(click "Load Log" to fetch last 200 lines)') ?></pre>
            </div>
            <div id="tab-watchdoglog" class="tab-pane">
                <button class="btn btn-primary btn-xs" id="load-watchdoglog-btn">
                    <i class="fa fa-download icon-embed-btn"></i><?= gettext('Load Log') ?>
                </button>
                <pre id="watchdoglog-content" class="xray-log-pre"><?= gettext('(click "Load Log" to fetch last 100 lines)') ?></pre>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    var selectedUuid = '<?= htmlspecialchars($selectedUuid, ENT_QUOTES, 'UTF-8') ?>';
    var ajaxUrl = '/xray/xray_ajax.php';

    function ajaxPost(action, uuid, cb) {
        $.ajax({
            url:      ajaxUrl,
            type:     'post',
            data:     { action: action, uuid: uuid },
            dataType: 'json',
            success:  cb,
            error: function() { cb(null); }
        });
    }

    function renderStats(data) {
        if (!data || data.error) {
            $('#stats-table').html(
                '<tr><td colspan="2" class="text-danger"><?= gettext('Failed to load stats') ?>: ' +
                (data && data.error ? data.error : '<?= gettext('unknown error') ?>') +
                '</td></tr>'
            );
            return;
        }

        var statusClass = data.tun_status === 'running' ? 'text-success' : 'text-danger';
        var rows = [
            ['<?= gettext('TUN Interface') ?>',    '<code>' + (data.tun_interface || '&mdash;') + '</code>'],
            ['<?= gettext('TUN Status') ?>',       '<span class="' + statusClass + '">' + (data.tun_status || '&mdash;') + '</span>'],
            ['<?= gettext('TUN IP') ?>',           data.tun_ip || '&mdash;'],
            ['<?= gettext('MTU') ?>',              data.mtu || '&mdash;'],
            ['<?= gettext('Bytes In') ?>',         data.bytes_in_hr || '&mdash;'],
            ['<?= gettext('Bytes Out') ?>',        data.bytes_out_hr || '&mdash;'],
            ['<?= gettext('Packets In') ?>',       data.pkts_in || '&mdash;'],
            ['<?= gettext('Packets Out') ?>',      data.pkts_out || '&mdash;'],
            ['<?= gettext('xray-core Uptime') ?>', data.xray_uptime || '&mdash;'],
            ['<?= gettext('Tunnel Uptime') ?>', data.tun2socks_uptime || '&mdash;'],
            ['<?= gettext('Server') ?>',           data.server_address || '&mdash;'],
        ];

        var html = '';
        $.each(rows, function(i, row) {
            html += '<tr><th style="width:40%">' + row[0] + '</th><td>' + row[1] + '</td></tr>';
        });
        $('#stats-table').html(html);
    }

    function loadStats() {
        var uuid = $('#instance-select').val() || selectedUuid;
        $('#stats-loading').show();
        ajaxPost('ifstats', uuid, function(data) {
            $('#stats-loading').hide();
            renderStats(data);
        });
    }

    $('#instance-select').on('change', function() {
        selectedUuid = $(this).val();
    });

    $('#refresh-btn').on('click', loadStats);

    $('#testconnect-btn').on('click', function() {
        var uuid = $('#instance-select').val() || selectedUuid;
        var resultEl = $('#testconnect-result')
            .show()
            .removeClass('alert-success alert-danger alert-info')
            .addClass('alert-info')
            .text('<?= gettext('Testing connection\u2026') ?>');

        ajaxPost('testconnect', uuid, function(data) {
            if (!data) {
                resultEl.removeClass('alert-info').addClass('alert-danger').text('<?= gettext('Request failed.') ?>');
                return;
            }
            if (data.result === 'ok') {
                resultEl.removeClass('alert-info').addClass('alert-success')
                    .text('<?= gettext('Connection OK') ?> (HTTP ' + data.http_code + ')');
            } else {
                resultEl.removeClass('alert-info').addClass('alert-danger')
                    .text('<?= gettext('Connection failed') ?> (HTTP ' + (data.http_code || 0) + ')');
            }
        });
    });

    $('#load-xraylog-btn').on('click', function() {
        ajaxPost('log', '', function(data) {
            $('#xraylog-content').text(data && data.log ? data.log : '<?= gettext('(empty)') ?>');
        });
    });

    $('#load-watchdoglog-btn').on('click', function() {
        ajaxPost('watchdoglog', '', function(data) {
            $('#watchdoglog-content').text(data && data.log ? data.log : '<?= gettext('(empty)') ?>');
        });
    });

    if (selectedUuid) {
        loadStats();
    }
});
//]]>
</script>

<?php
include('xray/includes/xray_foot.inc');
include('foot.inc');
?>
