<?php


$host = getenv('DB_HOST') ?: 'yamanote.proxy.rlwy.net';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'phydVwNEnaBxuXuqlLTYjFCccPipzWdK';
$db = getenv('DB_NAME') ?: 'railway';
$port_value = getenv('DB_PORT') ?: 37444;



mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
