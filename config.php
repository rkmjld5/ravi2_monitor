
<?php
/*
=========================================================
 PROJECT : ravi2_patient.onrender.com
 FILE    : config.php

 PURPOSE :
 1. Connect to the existing patient_monitor database.
 2. Use MySQLi (not PDO).
 3. Set Indian time zone.
 4. Start a secure PHP session.

 IMPORTANT:
 Database passwords are stored in Render Environment
 Variables, NOT inside this PHP program.
=========================================================
*/

// Stop if any serious PHP error occurs.
mysqli_report(MYSQLI_REPORT_OFF);

// Indian time zone.
date_default_timezone_set("Asia/Kolkata");

// -------------------------------------------------------
// 1. START PHP SESSION
// -------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {

    // Secure session cookie settings.
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
// 2. DATABASE SETTINGS
// -------------------------------------------------------

// These values will be entered in Render Environment.

$db_host = getenv("DB_HOST");
$db_port = (int)(getenv("DB_PORT") ?: 4000);
$db_name = getenv("DB_NAME") ?: "patient_monitor";
$db_user = getenv("DB_USER");
$db_password = getenv("DB_PASSWORD");

// TiDB connection normally uses SSL.
$db_ssl = getenv("DB_SSL") !== "0";
$db_ssl_ca = getenv("DB_SSL_CA");

// Check that essential settings are available.
if (!$db_host || !$db_user || $db_password === false) {

    http_response_code(500);

    exit(
        "Database settings are missing. " .
        "Please check Render Environment Variables."
    );
}

// -------------------------------------------------------
// 3. CONNECT TO DATABASE
// -------------------------------------------------------

$db = mysqli_init();

// Connection timeout: 15 seconds.
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

// Enable SSL when connecting to TiDB.
$connection_flags = 0;

if ($db_ssl) {

    $connection_flags = MYSQLI_CLIENT_SSL;

    // Use a CA certificate if its path is provided.
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

// Establish database connection.
$connected = $db->real_connect(
    $db_host,
    $db_user,
    $db_password,
    $db_name,
    $db_port,
    null,
    $connection_flags
);

// Check connection result.
if (!$connected) {

    // Keep technical details in server logs.
    error_log("Database connection failed: " . $db->connect_error);

    http_response_code(500);

    exit("Unable to connect to the database. Please contact administrator.");
}

// -------------------------------------------------------
// 4. SET CHARACTER SET
// -------------------------------------------------------

if (!$db->set_charset("utf8mb4")) {

    error_log("Unable to set database character set.");

    http_response_code(500);

    exit("Database character set error.");
}

// -------------------------------------------------------
// 5. SET DATABASE TIME ZONE
// -------------------------------------------------------

// All application pages use Indian Standard Time.
$db->query("SET time_zone = '+05:30'");

// -------------------------------------------------------
// DATABASE CONNECTION COMPLETED
// -------------------------------------------------------

// Other PHP programs can now use the $db variable.

?>
