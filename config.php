<?php
/*
=========================================================
 PROJECT : ravi2_patient.onrender.com
 FILE    : config.php

 PURPOSE :
 1. Connect to existing patient_monitor database.
 2. Use MySQLi.
 3. Set Indian time zone.
 4. Start PHP session safely.
=========================================================
*/

// -------------------------------------------------------
// 1. SET TIME ZONE
// -------------------------------------------------------

date_default_timezone_set("Asia/Kolkata");

// -------------------------------------------------------
// 2. START PHP SESSION
// -------------------------------------------------------

// Configure session only if it has not started.
if (session_status() === PHP_SESSION_NONE) {

    $is_https = (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// -------------------------------------------------------
// 3. DATABASE SETTINGS
// -------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);

$db_host = getenv("DB_HOST");
$db_port = (int)(getenv("DB_PORT") ?: 4000);
$db_name = getenv("DB_NAME") ?: "patient_monitor";
$db_user = getenv("DB_USER");
$db_password = getenv("DB_PASSWORD");

$db_ssl = getenv("DB_SSL") !== "0";
$db_ssl_ca = getenv("DB_SSL_CA");

// Check database settings.
if (!$db_host || !$db_user || $db_password === false) {

    http_response_code(500);
    exit("Database settings are missing.");
}

// -------------------------------------------------------
// 4. CREATE DATABASE CONNECTION
// -------------------------------------------------------

$db = mysqli_init();

$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

$connection_flags = 0;

if ($db_ssl) {

    $connection_flags = MYSQLI_CLIENT_SSL;

    if ($db_ssl_ca) {
        $db->ssl_set(
            null,
            null,
            $db_ssl_ca,
            null,
            null
        );
    }
}

// Connect to TiDB.
$connected = $db->real_connect(
    $db_host,
    $db_user,
    $db_password,
    $db_name,
    $db_port,
    null,
    $connection_flags
);

// Check connection.
if (!$connected) {

    error_log("Database connection failed: " . $db->connect_error);

    http_response_code(500);
    exit("Unable to connect to database.");
}

// -------------------------------------------------------
// 5. SET CHARACTER SET
// -------------------------------------------------------

if (!$db->set_charset("utf8mb4")) {

    error_log("Database character set error.");

    http_response_code(500);
    exit("Database character set error.");
}

// -------------------------------------------------------
// 6. SET DATABASE TIME ZONE
// -------------------------------------------------------

$db->query("SET time_zone = '+05:30'");

// -------------------------------------------------------
// DATABASE CONNECTION READY
// -------------------------------------------------------

// Other PHP files can use $db.

?>
