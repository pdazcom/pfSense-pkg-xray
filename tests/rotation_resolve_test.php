#!/usr/local/bin/php
<?php

/**
 * Unit tests for xray_resolve_connection_for_instance() and rotation filter logic.
 *
 * Run: php tests/rotation_resolve_test.php
 *
 * No pfSense, no xray-core, no network — pure logic only.
 * The test file provides stub implementations of xray_get_connection_by_uuid()
 * and xray_get_connections_by_group() so the function under test can be loaded
 * without any pfSense includes.
 */

// ─── Stubs ─────────────────────────────────────────────────────────────────────

$STUB_CONNECTIONS = [];

function xray_get_connection_by_uuid(string $uuid): ?array
{
    global $STUB_CONNECTIONS;
    foreach ($STUB_CONNECTIONS as $c) {
        if (($c['uuid'] ?? '') === $uuid) {
            return $c;
        }
    }
    return null;
}

function xray_get_connections_by_group(string $group_uuid): array
{
    global $STUB_CONNECTIONS;
    return array_values(array_filter(
        $STUB_CONNECTIONS,
        fn($c) => ($c['group_uuid'] ?? '') === $group_uuid
    ));
}

// ─── Function under test ───────────────────────────────────────────────────────

function xray_resolve_connection_for_instance(array $inst): ?array
{
    $mode = $inst['connection_mode'] ?? 'fixed';

    if ($mode === 'fixed') {
        $connUuid = $inst['connection_uuid'] ?? '';
        if ($connUuid === '') {
            return null;
        }
        return xray_get_connection_by_uuid($connUuid);
    }

    $activeUuid = $inst['active_connection_uuid'] ?? '';
    if ($activeUuid !== '') {
        $conn = xray_get_connection_by_uuid($activeUuid);
        if ($conn !== null && ($conn['enabled'] ?? 'on') === 'on') {
            return $conn;
        }
    }

    $groupUuid = $inst['connection_group_uuid'] ?? '';
    if ($groupUuid === '') {
        return null;
    }
    $groupConns = array_values(array_filter(
        xray_get_connections_by_group($groupUuid),
        fn($c) => ($c['enabled'] ?? 'on') === 'on'
    ));
    return $groupConns[0] ?? null;
}

// ─── Rotation filter + priority sort (mirrors xray-rotation.php logic) ─────────

function rotation_filter_and_sort(array $allConns, string $groupUuid): array
{
    $enabled = array_values(array_filter(
        array_filter($allConns, fn($c) => ($c['group_uuid'] ?? '') === $groupUuid),
        fn($c) => ($c['enabled'] ?? 'on') === 'on'
    ));
    usort($enabled, fn($a, $b) => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));
    return $enabled;
}

// ─── Test runner ───────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_equals(mixed $expected, mixed $actual, string $label): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        $exp = json_encode($expected);
        $act = json_encode($actual);
        echo "  FAIL  {$label}\n        expected: {$exp}\n        got:      {$act}\n";
        $failed++;
    }
}

function assert_null(mixed $actual, string $label): void
{
    assert_equals(null, $actual, $label);
}

function assert_not_null(mixed $actual, string $label): void
{
    global $passed, $failed;
    if ($actual !== null) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        echo "  FAIL  {$label}\n        expected non-null, got null\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n{$title}\n" . str_repeat('─', strlen($title)) . "\n";
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

function conn(string $uuid, string $groupUuid, bool $enabled = true, int $priority = 0): array
{
    return [
        'uuid'       => $uuid,
        'group_uuid' => $groupUuid,
        'name'       => "conn-{$uuid}",
        'enabled'    => $enabled ? 'on' : '',
        'priority'   => (string)$priority,
    ];
}

function inst_rotation(string $groupUuid, string $activeUuid = ''): array
{
    return [
        'connection_mode'        => 'rotation',
        'connection_group_uuid'  => $groupUuid,
        'active_connection_uuid' => $activeUuid,
    ];
}

function inst_fixed(string $connUuid): array
{
    return [
        'connection_mode' => 'fixed',
        'connection_uuid' => $connUuid,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: xray_resolve_connection_for_instance
// ═══════════════════════════════════════════════════════════════════════════════

section('Fixed mode');

$STUB_CONNECTIONS = [conn('aaa', 'g1')];

$result = xray_resolve_connection_for_instance(inst_fixed('aaa'));
assert_equals('aaa', $result['uuid'] ?? null, 'returns the fixed connection');

$result = xray_resolve_connection_for_instance(inst_fixed('unknown'));
assert_null($result, 'returns null for unknown uuid');

$result = xray_resolve_connection_for_instance(inst_fixed(''));
assert_null($result, 'returns null when connection_uuid is empty');

// ─── Rotation: active_connection_uuid is enabled ────────────────────────────

section('Rotation — active connection is enabled');

$STUB_CONNECTIONS = [
    conn('c1', 'g1', enabled: true,  priority: 10),
    conn('c2', 'g1', enabled: true,  priority: 20),
];

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: 'c1'));
assert_equals('c1', $result['uuid'] ?? null, 'returns active connection when enabled');

// ─── Rotation: active_connection_uuid is DISABLED ───────────────────────────

section('Rotation — active connection is disabled (main bug scenario)');

$STUB_CONNECTIONS = [
    conn('c1', 'g1', enabled: false, priority: 5),
    conn('c2', 'g1', enabled: true,  priority: 10),
];

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: 'c1'));
assert_not_null($result, 'does not return null when active is disabled but others exist');
assert_equals('c2', $result['uuid'] ?? null, 'skips disabled active, returns first enabled from group');

// ─── Rotation: active_connection_uuid unknown (deleted) ─────────────────────

section('Rotation — active connection was deleted');

$STUB_CONNECTIONS = [
    conn('c2', 'g1', enabled: true, priority: 5),
];

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: 'deleted-uuid'));
assert_equals('c2', $result['uuid'] ?? null, 'falls back to group when active uuid not found');

// ─── Rotation: no active uuid set yet ───────────────────────────────────────

section('Rotation — no active connection set yet');

$STUB_CONNECTIONS = [
    conn('c1', 'g1', enabled: true, priority: 0),
    conn('c2', 'g1', enabled: true, priority: 0),
];

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: ''));
assert_equals('c1', $result['uuid'] ?? null, 'returns first enabled connection when no active set');

// ─── Rotation: ALL connections disabled ─────────────────────────────────────

section('Rotation — all connections disabled');

$STUB_CONNECTIONS = [
    conn('c1', 'g1', enabled: false, priority: 10),
    conn('c2', 'g1', enabled: false, priority: 5),
];

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: 'c1'));
assert_null($result, 'returns null when all connections are disabled');

$result = xray_resolve_connection_for_instance(inst_rotation('g1', activeUuid: ''));
assert_null($result, 'returns null when no active and all disabled');

// ─── Rotation: no group configured ──────────────────────────────────────────

section('Rotation — no group configured');

$result = xray_resolve_connection_for_instance([
    'connection_mode'        => 'rotation',
    'connection_group_uuid'  => '',
    'active_connection_uuid' => '',
]);
assert_null($result, 'returns null when group uuid is empty');

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: rotation_filter_and_sort (mirrors xray-rotation.php)
// ═══════════════════════════════════════════════════════════════════════════════

section('Rotation filter: enabled-only + priority sort');

$conns = [
    conn('c1', 'g1', enabled: true,  priority: 5),
    conn('c2', 'g1', enabled: false, priority: 20),
    conn('c3', 'g1', enabled: true,  priority: 15),
    conn('c4', 'g2', enabled: true,  priority: 99),
];

$result = rotation_filter_and_sort($conns, 'g1');
assert_equals(2, count($result), 'filters out disabled and other-group connections');
assert_equals('c3', $result[0]['uuid'], 'highest-priority enabled connection is first');
assert_equals('c1', $result[1]['uuid'], 'lower-priority connection is second');

section('Rotation filter: all disabled');

$conns = [
    conn('c1', 'g1', enabled: false, priority: 10),
    conn('c2', 'g1', enabled: false, priority: 5),
];
$result = rotation_filter_and_sort($conns, 'g1');
assert_equals(0, count($result), 'returns empty list when all disabled');

section('Rotation filter: priority tie — stable order preserved');

$conns = [
    conn('c1', 'g1', enabled: true, priority: 10),
    conn('c2', 'g1', enabled: true, priority: 10),
];
$result = rotation_filter_and_sort($conns, 'g1');
assert_equals(2, count($result), 'both connections present on priority tie');
assert_equals('c1', $result[0]['uuid'], 'original order preserved on priority tie');

section('Rotation filter: priority defaults to 0 when not set');

$conns = [
    ['uuid' => 'x1', 'group_uuid' => 'g1', 'enabled' => 'on'],
    ['uuid' => 'x2', 'group_uuid' => 'g1', 'enabled' => 'on', 'priority' => '5'],
];
$result = rotation_filter_and_sort($conns, 'g1');
assert_equals('x2', $result[0]['uuid'], 'connection with explicit priority 5 beats missing-priority (0)');

// ─── Summary ──────────────────────────────────────────────────────────────────

$total = $passed + $failed;
echo "\n" . str_repeat('═', 50) . "\n";
echo "Result: {$passed}/{$total} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo "\n";
exit($failed > 0 ? 1 : 0);
