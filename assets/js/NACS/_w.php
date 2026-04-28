<?php
/**
 * Internal endpoint: appends scan payloads to local storage.
 * No auth. No login. No device lock.
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => 0]);
    exit;
}

$raw = file_get_contents('php://input');
$in = $raw ? json_decode($raw, true) : null;

$qr = isset($in['qr']) ? (is_string($in['qr']) ? $in['qr'] : json_encode($in['qr'])) : '';
$ts = isset($in['ts']) ? $in['ts'] : date('c');
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

$dir = __DIR__;
$file = $dir . '/_buf.json';

$entry = [
    'ts' => $ts,
    'qr' => $qr,
    'ua' => $ua,
    'ip' => $ip,
];

$list = [];
if (is_file($file)) {
    $c = @file_get_contents($file);
    if ($c) {
        $dec = json_decode($c, true);
        if (is_array($dec)) {
            $list = $dec;
        }
    }
}

$list[] = $entry;
@file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['ok' => 1]);
exit;
