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

function msk_date_str() {
    $ms = time() + 3 * 3600;
    return gmdate('Y-m-d', $ms);
}

// загрузка сессии по токену
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
$newStatus  = isset($body['newStatus'])  ? trim((string)$body['newStatus'])  : '';
$token      = isset($body['token'])      ? trim((string)$body['token'])      : '';
$note       = isset($body['note'])       ? (string)$body['note']             : '';

if ($operatorId === '' || $newStatus === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'EMPTY_ID_OR_STATUS'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sess = require_session($mysqli, $token);
if (!$sess) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'INVALID_SESSION'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = (($sess['role'] ?? '') === 'admin');

if (!$isAdmin && trim((string)$sess['operatorId']) !== $operatorId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

// читаем оператора
$stmt = $mysqli->prepare('SELECT * FROM operators WHERE id=?');
$stmt->bind_param('s', $operatorId);
$stmt->execute();
$res = $stmt->get_result();
$op  = $res->fetch_assoc();

if (!$op) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prevStatus = $op['status'] ?: 'Чаты';
$prevSince  = !empty($op['statusSince']) ? strtotime($op['statusSince']) : null;

// соответствие статусов колонкам
$TOTAL_KEYS = [
    'Чаты'               => 'totalLine',
    'Звонки'             => 'totalLine',
    'Обучение'          => 'totalLine',
    'Хочу в перерыв'    => 'totalLine',
    'Перерыв'           => 'totalBreak',
    'Перерыв на звонках'=> 'totalBreak',
    'Ожидание'          => 'totalWait',
    'Ожидание Обед'     => 'totalWait',
    'Обед'              => 'totalLunch',
];

if ($prevSince && isset($TOTAL_KEYS[$prevStatus])) {
    $passedSec = max(0, time() - $prevSince);
    $totalCol  = $TOTAL_KEYS[$prevStatus];
    $newTotal  = (int)($op[$totalCol] ?? 0) + $passedSec;

    $sql = "UPDATE operators SET {$totalCol}=? WHERE id=?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('is', $newTotal, $operatorId);
    $stmt->execute();

    $op[$totalCol] = $newTotal;
}

// текущее время в МСК (UTC+3), ISO с Z
$nowIso = gmdate('Y-m-d\TH:i:s\Z', time() + 3*3600);

// если ставим выходной — переносим накопленные секунды в dailyReports и обнуляем
if ($newStatus === 'Выходной') {
    $dateStr = msk_date_str();

    $stmt = $mysqli->prepare(
        'INSERT INTO dailyReports(date,operatorId,name,totalLine,totalBreak,totalWait,totalLunch)
         VALUES(?,?,?,?,?,?,?)'
    );
    $totalLine  = (int)($op['totalLine']  ?? 0);
    $totalBreak = (int)($op['totalBreak'] ?? 0);
    $totalWait  = (int)($op['totalWait']  ?? 0);
    $totalLunch = (int)($op['totalLunch'] ?? 0);
    $name       = (string)($op['name'] ?? '');

    $stmt->bind_param(
        'sssiiii',
        $dateStr,
        $op['id'],
        $name,
        $totalLine,
        $totalBreak,
        $totalWait,
        $totalLunch
    );
    $stmt->execute();

    $stmt = $mysqli->prepare(
        'UPDATE operators
         SET totalLine=0,totalWant=0,totalBreak=0,totalWait=0,totalLunch=0,shiftStart=NULL
         WHERE id=?'
    );
    $stmt->bind_param('s', $operatorId);
    $stmt->execute();
}

// обновляем статус, статусSince, note
$stmt = $mysqli->prepare(
    'UPDATE operators SET status=?, statusSince=?, note=? WHERE id=?'
);
$stmt->bind_param('ssss', $newStatus, $nowIso, $note, $operatorId);
$stmt->execute();

// shiftStart:
// - если Выходной — очищаем
// - если рабочий статус и раньше не было shiftStart — ставим
if ($newStatus === 'Выходной') {
    $stmt = $mysqli->prepare(
        'UPDATE operators SET shiftStart=NULL WHERE id=?'
    );
    $stmt->bind_param('s', $operatorId);
    $stmt->execute();
} else {
    if (empty($op['shiftStart'])) {
        $stmt = $mysqli->prepare(
            'UPDATE operators SET shiftStart=? WHERE id=?'
        );
        $stmt->bind_param('ss', $nowIso, $operatorId);
        $stmt->execute();
    }
}

// логируем переход (тоже в МСК)
$logTs = $nowIso;
$actorName = $sess['displayName'] ?: $sess['operatorId'];
$stmt = $mysqli->prepare(
    'INSERT INTO logs(ts,actor,operatorId,fromStatus,toStatus,note)
     VALUES(?,?,?,?,?,?)'
);
$stmt->bind_param(
    'ssssss',
    $logTs,
    $actorName,
    $operatorId,
    $prevStatus,
    $newStatus,
    $note
);
$stmt->execute();

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
?>