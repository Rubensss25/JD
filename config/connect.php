<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'jd_water_station';

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass);
    $conn->set_charset('utf8mb4');
    $conn->query(
        "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $conn->select_db($dbName);
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);
    exit('Database connection failed: ' . $exception->getMessage());
}
