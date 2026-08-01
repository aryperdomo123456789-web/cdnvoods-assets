#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$minutes = 120;
$limit = 100;
foreach ($argv as $arg) {
    if (preg_match('/^--minutes=(\d+)$/', $arg, $m)) {
        $minutes = max(5, (int) $m[1]);
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
    }
}

$hosts = DirectHostHealth::hosts($minutes, $limit);
$out = [
    'generated_at' => date('c'),
    'window_minutes' => $minutes,
    'summary' => DirectHostHealth::summary($minutes),
    'lb_ok' => [],
    'needs_alt_egress' => [],
    'unknown' => [],
];

foreach ($hosts as $row) {
    $item = [
        'host' => (string) ($row['host'] ?? ''),
        'verdict' => (string) ($row['verdict'] ?? 'unknown'),
        'fail_rate' => (int) ($row['fail_rate'] ?? 0),
        'users' => (int) ($row['users'] ?? 0),
        'streams' => (int) ($row['streams'] ?? 0),
        'hops' => (int) ($row['hops'] ?? 0),
        'explain' => (string) ($row['explain'] ?? ''),
    ];
    if (in_array($item['verdict'], ['ok', 'flaky'], true)) {
        $out['lb_ok'][] = $item;
    } elseif (in_array($item['verdict'], ['blocked', 'unreachable', 'degraded', 'catalog_stale'], true)) {
        $out['needs_alt_egress'][] = $item;
    } else {
        $out['unknown'][] = $item;
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
