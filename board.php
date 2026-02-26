<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/util-php.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function require_session(mysqli $db, $token) {
    if (!$token) return null;
    $stmt = $db->prepare(
        'SELECT token,operatorId,role,displayName,expiresIso FROM sessions WHERE token=?'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $s = $res->fetch_assoc();
    if (!$s) return null;
    if (!empty($s['expiresIso'])) {
        $expires = strtotime($s['expiresIso']);
        if ($expires !== false && $expires < time()) return null;
    }
    return $s;
}

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($token === '') {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) {
        $token = trim(substr($hdr, 7));
    }
}

$sess = require_session($mysqli, $token);
if (!$sess) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'INVALID_SESSION'], JSON_UNESCAPED_UNICODE);
    exit;
}

$STATUSES = get_statuses();

$res = $mysqli->query('SELECT * FROM operators');
$cards = [];

while ($r = $res->fetch_assoc()) {
    $images = [];
    if (!empty($r['images'])) {
        $decoded = json_decode($r['images'], true);
        if (is_array($decoded)) $images = $decoded;
    }

    $cards[] = [
        'id'          => $r['id'],
        'name'        => $r['name'],
        'role'        => $r['role'] ?? '',
        'status'      => $r['status'] ?: $STATUSES[0],
        'statusSince' => $r['statusSince'] && $r['statusSince'] !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($r['statusSince'])) : '',
        'note'        => $r['note'] ?? '',
        'totals'      => [
            'totalLine'  => (int)($r['totalLine']  ?? 0),
            'totalWant'  => (int)($r['totalWant']  ?? 0),
            'totalBreak' => (int)($r['totalBreak'] ?? 0),
            'totalWait'  => (int)($r['totalWait']  ?? 0),
            'totalLunch' => (int)($r['totalLunch'] ?? 0),
        ],
        'shiftStart'  => $r['shiftStart'] && $r['shiftStart'] !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($r['shiftStart'])) : '',
        'shift'       => $r['shift'] ?? '',
        'workHours'   => $r['workHours'] ?? '',
        'images'      => $images,
    ];
}

echo json_encode([
    'statuses'     => $STATUSES,
    'cards'        => $cards,
    'serverNowIso' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_UNESCAPED_UNICODE);
