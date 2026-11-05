<?php
require_once __DIR__ . "/config.php";

// Return false on query errors instead of throwing exceptions (PHP 8.1+ default),
// so each page can show its own friendly error message.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    error_log("Fundwise DB connection failed: " . mysqli_connect_error());
    die("Unable to connect to the database. Please try again later.");
}

mysqli_set_charset($conn, "utf8mb4");
// Keep SQL CURDATE()/NOW() aligned with PHP and the user's Manila work dates.
if (!mysqli_query($conn, "SET time_zone = '+08:00'")) {
    throw new RuntimeException('Unable to configure the database session timezone.');
}
date_default_timezone_set("Asia/Manila");
