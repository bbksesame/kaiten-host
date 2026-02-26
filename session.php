<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token === '') {
    echo json_encode(null, JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT token,operatorId,role,displayName,expiresIso FROM sessions WHERE token=?'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$sess = $res->fetch_assoc();

if (!$sess) {
    echo json_encode(null, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($sess['expiresIso'])) {
    $expires = strtotime($sess['expiresIso']);
    if ($expires !== false && $expires < time()) {
        echo json_encode(null, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode($sess, JSON_UNESCAPED_UNICODE);