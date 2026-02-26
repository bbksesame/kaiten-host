<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function toYYYYMMDD($d) {
    if ($d instanceof DateTime) {
        return $d->format('Y-m-d');
    }
    if (is_string($d)) {
        $d = trim($d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        $dt = date_create($d);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

$fromRaw    = $_GET['from']    ?? ($_GET['fromIso'] ?? null);
$toRaw      = $_GET['to']      ?? ($_GET['toIso']   ?? null);
$operatorId = isset($_GET['operatorId']) ? trim($_GET['operatorId']) : '';

$from = toYYYYMMDD($fromRaw);
$to   = toYYYYMMDD($toRaw);

if (!$from || !$to) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'Invalid or missing date range. Expect from/to as YYYY-MM-DD (or fromIso/toIso).',
        'got'   => ['from' => $fromRaw, 'to' => $toRaw],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($from > $to) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => '`from` must be earlier or equal to `to`.',
        'from'  => $from,
        'to'    => $to,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = ['date BETWEEN ? AND ?'];
$params = [$from, $to];
$types  = 'ss';

if ($operatorId !== '') {
    $where[] = 'operatorId = ?';
    $params[] = $operatorId;
    $types   .= 's';
}

$sql = "
SELECT
  operatorId,
  COALESCE(name, operatorId) AS name,
  SUM(COALESCE(totalLine, 0))  AS totalLine,
  SUM(COALESCE(totalBreak, 0)) AS totalBreak,
  SUM(COALESCE(totalWait, 0))  AS totalWait,
  SUM(COALESCE(totalLunch, 0)) AS totalLunch
FROM dailyReports
WHERE " . implode(' AND ', $where) . "
GROUP BY operatorId, name
HAVING
  (SUM(COALESCE(totalLine,0)) +
   SUM(COALESCE(totalBreak,0)) +
   SUM(COALESCE(totalWait,0)) +
   SUM(COALESCE(totalLunch,0))) > 0
ORDER BY name ASC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Prepare failed',
        'msg'   => $mysqli->error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode([
    'ok'     => true,
    'rows'   => $rows,
    'from'   => $from,
    'to'     => $to,
    'filters'=> ['operatorId' => $operatorId],
], JSON_UNESCAPED_UNICODE);