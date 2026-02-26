<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "START<br>\n";

$host   = '127.0.0.1';
$user   = 'abolobkovy';
$pass   = 'Hahyjabaj1';
$db     = 'abolobkovy';
$port   = 3308;
$socket = '/var/run/mysql8-container/mysqld.sock';

echo "Before mysqli<br>\n";

$mysqli = @new mysqli($host, $user, $pass, $db, $port, $socket);

if ($mysqli->connect_errno) {
    echo "ERROR: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
} else {
    echo "OK: connected to MySQL and DB selected.";
}

echo "<br>\nDONE";