<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

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

$body = read_json_body();
$operatorId = isset($body['operatorId']) ? trim((string)$body['operatorId']) : '';
$token      = isset($body['token'])      ? trim((string)$body['token'])      : '';
$dataUrl    = isset($body['dataUrl'])    ? (string)$body['dataUrl']          : '';

$sess = require_session($mysqli, $token);
if (!$sess) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'INVALID_SESSION'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($operatorId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'EMPTY_ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $dataUrl)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'BAD_IMAGE'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $mysqli->prepare('SELECT images FROM operators WHERE id=?');
$stmt->bind_param('s', $operatorId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

$trimmed = json_encode([ (string)$dataUrl ], JSON_UNESCAPED_UNICODE);

$stmt = $mysqli->prepare('UPDATE operators SET images=? WHERE id=?');
$stmt->bind_param('ss', $trimmed, $operatorId);
$stmt->execute();

$nowIso = gmdate('Y-m-d\TH:i:s\Z');
$actor  = $sess['displayName'] ?: $sess['operatorId'];

$stmt = $mysqli->prepare(
    'INSERT INTO logs(ts,actor,operatorId,fromStatus,toStatus,note)
     VALUES(?,?,?,?,?,?)'
);
$fromStatus = '-';
$toStatus   = 'SetImage';
$note       = 'avatar replaced';

$stmt->bind_param(
    'ssssss',
    $nowIso,
    $actor,
    $operatorId,
    $fromStatus,
    $toStatus,
    $note
);
$stmt->execute();

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);