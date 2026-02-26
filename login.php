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

$body = read_json_body();
$code = isset($body['code']) ? trim((string)$body['code']) : '';

if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad code'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT code,displayName,operatorId,role FROM accessCodes WHERE code=?'
);
$stmt->bind_param('s', $code);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

if (!$row) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bad code'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = bin2hex(random_bytes(16));
$exp   = gmdate('Y-m-d\TH:i:s\Z', time() + 24*3600);

$stmt = $mysqli->prepare(
    'INSERT INTO sessions(token,operatorId,role,displayName,expiresIso) VALUES(?,?,?,?,?)'
);
$role = (string)($row['role'] ?? '');
$displayName = (string)($row['displayName'] ?? '');
$operatorId  = (string)$row['operatorId'];

$stmt->bind_param('sssss', $token, $operatorId, $role, $displayName, $exp);
$stmt->execute();

echo json_encode([
    'ok'         => true,
    'token'      => $token,
    'operatorId' => $operatorId,
    'role'       => $role,
    'displayName'=> $displayName,
], JSON_UNESCAPED_UNICODE);