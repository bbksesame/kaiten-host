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

function msk_now_hm() {
    return gmdate('H:i', time() + 3 * 3600);
}

function msk_now_iso() {
    return gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 3600);
}

function msk_date_str() {
    return gmdate('Y-m-d', time() + 3 * 3600);
}

$body  = read_json_body();
$force = !empty($body['force']) || (isset($_GET['force']) && $_GET['force'] === '1');
$actorName = isset($body['actor']) ? trim((string)$body['actor']) : '';
if ($actorName === '') $actorName = 'auto-out';
if (strlen($actorName) > 64) $actorName = substr($actorName, 0, 64);

$allowedTimes = ['17:02','18:02','19:02','20:02','21:02','22:02','23:02','23:59'];
$nowHm = msk_now_hm();
if (!$force && !in_array($nowHm, $allowedTimes, true)) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'NOT_SCHEDULED_TIME',
        'now' => $nowHm
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Select operators with a shift end time and not already in "Выходной"
$res = $mysqli->query("SELECT * FROM operators WHERE shiftEnd IS NOT NULL AND shiftEnd <> '00:00:00' AND status <> 'Выходной'");
if (!$res) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_ERROR', 'msg' => $mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}

$TOTAL_KEYS = [
    'Чаты'               => 'totalLine',
    'Звонки'             => 'totalLine',
    'Обучение'           => 'totalLine',
    'Хочу в перерыв'     => 'totalLine',
    'Перерыв'            => 'totalBreak',
    'Перерыв на звонках' => 'totalBreak',
    'Ожидание'           => 'totalWait',
    'Ожидание Обед'      => 'totalWait',
    'Обед'               => 'totalLunch',
];

$nowIso = msk_now_iso();
$today  = msk_date_str();
$moved = 0;
$checked = 0;

while ($op = $res->fetch_assoc()) {
    $checked++;
    $shiftEnd = trim((string)($op['shiftEnd'] ?? ''));
    if (strlen($shiftEnd) >= 5) $shiftEnd = substr($shiftEnd, 0, 5);
    if ($shiftEnd === '') continue;
    if ($shiftEnd > $nowHm) continue;

    $operatorId = (string)$op['id'];
    $prevStatus = $op['status'] ?: 'Чаты';
    $prevSince  = !empty($op['statusSince']) ? strtotime($op['statusSince']) : null;

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

    // Save daily totals and reset for "Выходной"
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
        $today,
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

    // Update status, statusSince, note
    $newStatus = 'Выходной';
    $note = '';
    $stmt = $mysqli->prepare(
        'UPDATE operators SET status=?, statusSince=?, note=? WHERE id=?'
    );
    $stmt->bind_param('ssss', $newStatus, $nowIso, $note, $operatorId);
    $stmt->execute();

    // Log transition
    $stmt = $mysqli->prepare(
        'INSERT INTO logs(ts,actor,operatorId,fromStatus,toStatus,note)
         VALUES(?,?,?,?,?,?)'
    );
    $stmt->bind_param(
        'ssssss',
        $nowIso,
        $actorName,
        $operatorId,
        $prevStatus,
        $newStatus,
        $note
    );
    $stmt->execute();

    $moved++;
}

echo json_encode([
    'ok' => true,
    'moved' => $moved,
    'checked' => $checked,
    'now' => $nowHm,
    'force' => $force
], JSON_UNESCAPED_UNICODE);
