<?php


$host = getenv('DB_HOST') ?: "localhost";
$user = getenv('DB_USER') ?: "root";
$pass = getenv('DB_PASS') ?: "";
$db = getenv('DB_NAME') ?: "defense_management_system";
$port = (int) (getenv('DB_PORT') ?: 3306);



mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
