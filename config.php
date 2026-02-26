<?php
// Настройки подключения к MySQL

$DB_HOST   = '127.0.0.1';
$DB_USER   = 'abolobkovy';
$DB_PASS   = 'Hahyjabaj1';
$DB_NAME   = 'abolobkovy';
$DB_PORT   = 3308;
$DB_SOCKET = '/var/run/mysql8-container/mysqld.sock';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT, $DB_SOCKET);

if ($mysqli->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'DB connect error',
        'code'  => $mysqli->connect_errno,
        'msg'   => $mysqli->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli->set_charset('utf8mb4');