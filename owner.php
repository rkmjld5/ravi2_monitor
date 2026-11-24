<?php
/*
    PROJECT: ravi2_patient
    FILE: owner.php

    PURPOSE:
    Owner / Administrator dashboard.

    DATABASE:
    patient_monitor

    IMPORTANT:
    1. Uses config.php and the $conn MySQLi connection.
    2. Only an authenticated owner can open this page.
    3. Uses the existing patients table.
    4. Uses the existing patient_devices table.
    5. Uses the existing web_users table.
    6. Does not create or modify database tables.
    7. Patient login remains in login.php.
    8. Owner login remains in owner_login.php.
*/

// --------------------------------------------------
// 1. DATABASE CONNECTION AND SESSION
// --------------------------------------------------

require_once __DIR__ . "/config.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available. Please check config.php.");
}

date_default_timezone_set("Asia/Kolkata");

// --------------------------------------------------
// 2. CHECK OWNER LOGIN
// --------------------------------------------------

if (
    empty($_SESSION["owner_logged_in"]) ||
    empty($_SESSION["owner_id"])
) {
    header("Location: owner_login.php");
    exit;
}

// --------------------------------------------------
// 3. HELPER FUNCTIONS
// --------------------------------------------------

function owner_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function owner_date_input($value)
{
    if (empty($value)) {
        return "";
    }

    return date("Y-m-d\TH:i", strtotime($value));
}

function owner_date_display($value)
{
    if (empty($value)) {
        return "Not specified";
    }

    return date("d-m-Y H:i", strtotime($value));
}

function owner_valid_date($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $value = str_replace("T", " ", $value);

    if (strlen($value) === 16) {
        $value .= ":00";
    }

    $date = DateTime::createFromFormat("Y-m-d H:i:s", $value);

    if (!$date || $date->format("Y-m-d H:i:s") !== $value) {
        return false;
    }

    return $value;
}

function owner_redirect_message($message, $type = "success")
{
    $_SESSION["owner_message"] = $message;
    $_SESSION["owner_message_type"] = $type;

    header("Location: owner.php");
    exit;
}

// --------------------------------------------------
// 4. CREATE CSRF SECURITY TOKEN
// --------------------------------------------------

if (empty($_SESSION["owner_csrf"])) {
    $_SESSION["owner_csrf"] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// 5. PROCESS OWNER ACTIONS
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf = $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["owner_csrf"]) ||
        !hash_equals($_SESSION["owner_csrf"], $csrf)
    ) {
        owner_redirect_message(
            "Security verification failed. Please try again.",
            "error"
        );
    }

    $action = $_POST["action"] ?? "";

    // ==============================================
    // ACTION 1: REGISTER NEW PATIENT
    // ==============================================

    if ($action === "register_patient") {

        $patient_id = trim($_POST["patient_id"] ?? "");
        $full_name = trim($_POST["full_name"] ?? "");
        $age = trim($_POST["age"] ?? "");
        $gender = trim($_POST["gender"] ?? "");
        $blood_group = trim($_POST["blood_group"] ?? "");
        $contact = trim($_POST["contact"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($patient_id === "" || $full_name === "") {
            owner_redirect_message(
                "Patient ID and full name are required.",
                "error"
            );
        }

        if ($age !== "" && (!ctype_digit($age) || (int)$age > 120)) {
            owner_redirect_message("Please enter a valid age.", "error");
        }

        // Check whether this Patient ID already exists.
        $check = $conn->prepare(
            "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
        );

        if (!$check) {
            error_log("Patient check error: " . $conn->error);
            owner_redirect_message("Database error while checking patient.", "error");
        }

        $check->bind_param("s", $patient_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            owner_redirect_message(
                "This Patient ID already exists.",
                "error"
            );
        }

        $age_value = ($age === "") ? null : (int)$age;

        $stmt = $conn->prepare("
            INSERT INTO patients
            (
                patient_id,
                full_name,
                age,
                gender,
                blood_group,
                contact,
                email,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Patient registration error: " . $conn->error);
            owner_redirect_message("Unable to register patient.", "error");
        }

        $stmt->bind_param(
            "ssisssss",
            $patient_id,
            $full_name,
            $age_value,
            $gender,
            $blood_group,
            $contact,
            $email,
            $notes
        );

        if ($stmt->execute()) {
            $stmt->close();

            owner_redirect_message(
                "Patient registered successfully. Patient ID: " . $patient_id
            );
        }

        error_log("Patient insert error: " . $stmt->error);
        $stmt->close();

        owner_redirect_message("Patient registration failed.", "error");
    }

    // ==============================================
    // ACTION 2: ASSIGN OR UPDATE ESP32 DEVICE
    // ==============================================

    elseif ($action === "assign_device") {

        $patient_id = trim($_POST["device_patient_id"] ?? "");
        $device_id = trim($_POST["device_id"] ?? "");
        $description = trim($_POST["description"] ?? "");

        $start_date = owner_valid_date($_POST["start_date_time"] ?? "");
        $end_date = owner_valid_date($_POST["end_date_time"] ?? "");

        if ($patient_id === "" || $device_id === "") {
            owner_redirect_message(
                "Patient ID and Device ID are required.",
                "error"
            );
        }

        if ($start_date === false || $end_date === false) {
            owner_redirect_message("Please enter valid device dates.", "error");
        }

        if (
            $start_date !== null &&
            $end_date !== null &&
            strtotime($end_date) < strtotime($start_date)
        ) {
            owner_redirect_message(
                "Device end date cannot be earlier than start date.",
                "error"
            );
        }

        // Confirm that the patient exists.
        $check = $conn->prepare(
            "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
        );

        if (!$check) {
            owner_redirect_message("Database error while checking patient.", "error");
        }

        $check->bind_param("s", $patient_id);
        $check->execute();
        $patient_exists = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$patient_exists) {
            owner_redirect_message("Patient ID does not exist.", "error");
        }

        // Check whether this device already has an assignment.
        $check = $conn->prepare("
            SELECT device_id
            FROM patient_devices
            WHERE device_id = ?
            LIMIT 1
        ");

        if (!$check) {
            owner_redirect_message("Database error while checking device.", "error");
        }

        $check->bind_param("s", $device_id);
        $check->execute();
        $device_exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($device_exists) {

            // Update the existing device assignment.
            // Keep its current enabled/disabled setting unchanged.

            $stmt = $conn->prepare("
                UPDATE patient_devices
                SET
                    patient_id = ?,
                    description = ?,
                    start_date_time = ?,
                    end_date_time = ?
                WHERE device_id = ?
            ");

            if (!$stmt) {
                owner_redirect_message("Unable to prepare device update.", "error");
            }

            $stmt->bind_param(
                "sssss",
                $patient_id,
                $description,
                $start_date,
                $end_date,
                $device_id
            );

            $success = $stmt->execute();
            $stmt->close();

        } else {

            // Create a new device assignment.
            $stmt = $conn->prepare("
                INSERT INTO patient_devices
                (
                    device_id,
                    patient_id,
                    description,
                    enabled,
                    start_date_time,
                    end_date_time
                )
                VALUES (?, ?, ?, 1, ?, ?)
            ");

            if (!$stmt) {
                owner_redirect_message("Unable to prepare device assignment.", "error");
            }

            $stmt->bind_param(
                "sssss",
                $device_id,
                $patient_id,
                $description,
                $start_date,
                $end_date
            );

            $success = $stmt->execute();
            $stmt->close();
        }

        if ($success) {
            owner_redirect_message(
                "Device assignment saved successfully."
            );
        }

        error_log("Device assignment error: " . $conn->error);

        owner_redirect_message(
            "Unable to save device assignment. Please check whether the device is already assigned.",
            "error"
        );
    }

    // ==============================================
    // ACTION 3: ENABLE OR DISABLE DEVICE
    // ==============================================

    elseif ($action === "toggle_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $new_status = (int)($_POST["new_status"] ?? 0);

        if ($device_id === "" || !in_array($new_status, [0, 1], true)) {
            owner_redirect_message("Invalid device request.", "error");
        }

        $stmt = $conn->prepare("
            UPDATE patient_devices
            SET enabled = ?
            WHERE device_id = ?
        ");

        if (!$stmt) {
            owner_redirect_message("Unable to prepare device status update.", "error");
        }

        $stmt->bind_param("is", $new_status, $device_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            owner_redirect_message(
                $new_status === 1
                    ? "Device enabled successfully."
                    : "Device disabled successfully."
            );
        }

        owner_redirect_message("Unable to change device status.", "error");
    }

    // ==============================================
    // ACTION 4: DELETE DEVICE ASSIGNMENT
    // ==============================================

    elseif ($action === "delete_device") {

        $device_id = trim($_POST["device_id"] ?? "");

        if ($device_id === "") {
            owner_redirect_message("Device ID is missing.", "error");
        }

        $stmt = $conn->prepare("
            DELETE FROM patient_devices
            WHERE device_id = ?
        ");

        if (!$stmt) {
            owner_redirect_message("Unable to prepare device deletion.", "error");
        }

        $stmt->bind_param("s", $device_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            owner_redirect_message(
                "Device assignment deleted. Historical readings have not been deleted."
            );
        }

        owner_redirect_message("Unable to delete device assignment.", "error");
    }

    // ==============================================
    // ACTION 5: CREATE OR UPDATE PATIENT LOGIN
    // ==============================================

    elseif ($action === "save_patient_login") {

        $username = trim($_POST["login_username"] ?? "");
        $password = $_POST["login_password"] ?? "";
        $device_id = trim($_POST["login_device_id"] ?? "");

        $valid_from = owner_valid_date($_POST["valid_from"] ?? "");
        $valid_until = owner_valid_date($_POST["valid_until"] ?? "");

        if ($username === "" || $device_id === "") {
            owner_redirect_message(
                "Patient username and assigned device are required.",
                "error"
            );
        }

        if ($valid_from === false || $valid_until === false) {
            owner_redirect_message("Please enter valid login dates.", "error");
        }

        if (
            $valid_from !== null &&
            $valid_until !== null &&
            strtotime($valid_until) < strtotime($valid_from)
        ) {
            owner_redirect_message(
                "Login expiry cannot be earlier than login start.",
                "error"
            );
        }

        // Confirm that the device assignment exists and is enabled.
        $check = $conn->prepare("
            SELECT device_id
            FROM patient_devices
            WHERE device_id = ?
              AND enabled = 1
              AND (start_date_time IS NULL OR start_date_time <= NOW())
              AND (end_date_time IS NULL OR end_date_time >= NOW())
            LIMIT 1
        ");

        if (!$check) {
            owner_redirect_message("Unable to check device assignment.", "error");
        }

        $check->bind_param("s", $device_id);
        $check->execute();
        $assigned_device = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$assigned_device) {
            owner_redirect_message(
                "The device is not enabled or is outside its assignment dates.",
                "error"
            );
        }

        // Check whether this username already exists.
        $check = $conn->prepare("
            SELECT id, role
            FROM web_users
            WHERE username = ?
            LIMIT 1
        ");

        if (!$check) {
            owner_redirect_message("Unable to check patient username.", "error");
        }

        $check->bind_param("s", $username);
        $check->execute();
        $existing_login = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing_login && $existing_login["role"] !== "patient") {
            owner_redirect_message(
                "This username belongs to a non-patient account. Choose another username.",
                "error"
            );
        }

        if ($existing_login) {

            // Update an existing patient login.
            // If password is blank, keep the existing password.

            if ($password !== "") {

                if (strlen($password) < 8) {
                    owner_redirect_message(
                        "A new password must contain at least 8 characters.",
                        "error"
                    );
                }

                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    UPDATE web_users
                    SET
                        password_hash = ?,
                        role = 'patient',
                        device_id = ?,
                        valid_from = ?,
                        valid_until = ?
                    WHERE id = ?
                      AND role = 'patient'
                ");

                if (!$stmt) {
                    owner_redirect_message("Unable to prepare login update.", "error");
                }

                $login_id = (int)$existing_login["id"];

                $stmt->bind_param(
                    "ssssi",
                    $password_hash,
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $login_id
                );

            } else {

                $stmt = $conn->prepare("
                    UPDATE web_users
                    SET
                        role = 'patient',
                        device_id = ?,
                        valid_from = ?,
                        valid_until = ?
                    WHERE id = ?
                      AND role = 'patient'
                ");

                if (!$stmt) {
                    owner_redirect_message("Unable to prepare login update.", "error");
                }

                $login_id = (int)$existing_login["id"];

                $stmt->bind_param(
                    "sssi",
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $login_id
                );
            }

            $success = $stmt->execute();
            $stmt->close();

        } else {

            // New patient login requires a password.
            if ($password === "" || strlen($password) < 8) {
                owner_redirect_message(
                    "Please enter a password containing at least 8 characters.",
                    "error"
                );
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO web_users
                (
                    username,
                    password_hash,
                    role,
                    enabled,
                    device_id,
                    valid_from,
                    valid_until
                )
                VALUES (?, ?, 'patient', 1, ?, ?, ?)
            ");

            if (!$stmt) {
                owner_redirect_message("Unable to prepare patient login creation.", "error");
            }

            $stmt->bind_param(
                "sssss",
                $username,
                $password_hash,
                $device_id,
                $valid_from,
                $valid_until
            );

            $success = $stmt->execute();
            $stmt->close();
        }

        if ($success) {
            owner_redirect_message("Patient login saved successfully.");
        }

        error_log("Patient login save error: " . $conn->error);

        owner_redirect_message(
            "Unable to save patient login. Check whether the username already exists.",
            "error"
        );
    }

    // ==============================================
    // ACTION 6: ENABLE OR DISABLE PATIENT LOGIN
    // ==============================================

    elseif ($action === "toggle_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);
        $new_status = (int)($_POST["new_status"] ?? 0);

        if ($login_id <= 0 || !in_array($new_status, [0, 1], true)) {
            owner_redirect_message("Invalid patient login request.", "error");
        }

        $stmt = $conn->prepare("
            UPDATE web_users
            SET enabled = ?
            WHERE id = ?
              AND role = 'patient'
        ");

        if (!$stmt) {
            owner_redirect_message("Unable to prepare login status update.", "error");
        }

        $stmt->bind_param("ii", $new_status, $login_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            owner_redirect_message(
                $new_status === 1
                    ? "Patient login enabled."
                    : "Patient login disabled."
            );
        }

        owner_redirect_message("Unable to change patient login status.", "error");
    }

    // ==============================================
    // ACTION 7: DELETE PATIENT LOGIN
    // ==============================================

    elseif ($action === "delete_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);

        if ($login_id <= 0) {
            owner_redirect_message("Invalid patient login ID.", "error");
        }

        $stmt = $conn->prepare("
            DELETE FROM web_users
            WHERE id = ?
              AND role = 'patient'
        ");

        if (!$stmt) {
            owner_redirect_message("Unable to prepare patient login deletion.", "error");
        }

        $stmt->bind_param("i", $login_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            owner_redirect_message(
                "Patient login deleted. Patient records and readings have not been deleted."
            );
        }

        owner_redirect_message("Unable to delete patient login.", "error");
    }

    else {
        owner_redirect_message("Unknown request.", "error");
    }
}

// --------------------------------------------------
// 6. READ ONE-TIME MESSAGE
// --------------------------------------------------

$message = $_SESSION["owner_message"] ?? "";
$message_type = $_SESSION["owner_message_type"] ?? "success";

unset($_SESSION["owner_message"]);
unset($_SESSION["owner_message_type"]);

// --------------------------------------------------
// 7. GET PATIENT LIST
// --------------------------------------------------

$patients = [];

$result = $conn->query("
    SELECT
        patient_id,
        full_name,
        age,
        gender,
        blood_group,
        contact,
        email
    FROM patients
    ORDER BY patient_id
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }
    $result->free();
}

// --------------------------------------------------
// 8. GET DEVICE LIST
// --------------------------------------------------

$devices = [];

$result = $conn->query("
    SELECT
        d.device_id,
        d.patient_id,
        d.description,
        d.enabled,
        d.start_date_time,
        d.end_date_time,
        p.full_name
    FROM patient_devices d
    LEFT JOIN patients p
        ON p.patient_id = d.patient_id
    ORDER BY d.device_id
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
    $result->free();
}

// --------------------------------------------------
// 9. GET PATIENT LOGIN LIST
// --------------------------------------------------

$logins = [];

$result = $conn->query("
    SELECT
        w.id,
        w.username,
        w.enabled,
        w.device_id,
        w.valid_from,
        w.valid_until,
        d.patient_id,
        p.full_name
    FROM web_users w
    LEFT JOIN patient_devices d
        ON d.device_id = w.device_id
    LEFT JOIN patients p
        ON p.patient_id = d.patient_id
    WHERE w.role = 'patient'
    ORDER BY w.username
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $logins[] = $row;
    }
    $result->free();
}

$owner_name = $_SESSION["owner_name"]
    ?? $_SESSION["owner_username"]
    ?? "Administrator";

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Owner Dashboard - Patient Monitoring</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #263445;
}

header {
    background: #123b64;
    color: white;
    padding: 18px 24px;
}

.header-content {
    max-width: 1200px;
    margin: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

header h1 {
    margin: 0;
    font-size: 22px;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.header-right a {
    color: white;
    text-decoration: none;
    background: #dc3545;
    padding: 9px 14px;
    border-radius: 5px;
}

.container {
    max-width: 1200px;
    margin: 25px auto;
    padding: 0 15px;
}

.section {
    background: white;
    padding: 22px;
    margin-bottom: 25px;
    border-radius: 9px;
    box-shadow: 0 2px 9px rgba(0,0,0,0.07);
}

h2 {
    margin-top: 0;
    color: #123b64;
    font-size: 20px;
}

h3 {
    color: #123b64;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.form-group {
    margin-bottom: 10px;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
    font-size: 14px;
}

input,
select,
textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    font-size: 14px;
}

textarea {
    min-height: 75px;
    resize: vertical;
}

button,
.button {
    display: inline-block;
    border: none;
    padding: 9px 13px;
    border-radius: 5px;
    cursor: pointer;
    color: white;
    font-size: 13px;
    text-decoration: none;
    margin: 2px;
}

.primary {
    background: #1769aa;
}

.success {
    background: #198754;
}

.warning {
    background: #d97706;
}

.danger {
    background: #dc3545;
}

.secondary {
    background: #64748b;
}

.message {
    padding: 13px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.message.success-message {
    background: #dcfce7;
    color: #166534;
}

.message.error-message {
    background: #fee2e2;
    color: #991b1b;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
    font-size: 13px;
}

th,
td {
    padding: 11px 9px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    vertical-align: top;
}

th {
    background: #edf3f9;
    color: #123b64;
}

.status-enabled {
    color: #15803d;
    font-weight: bold;
}

.status-disabled {
    color: #dc2626;
    font-weight: bold;
}

.status-expired {
    color: #b45309;
    font-weight: bold;
}

.small {
    color: #64748b;
    font-size: 12px;
}

.action-form {
    display: inline-block;
    margin: 2px;
}

hr {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 22px 0;
}

@media (max-width: 650px) {

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }

    header h1 {
        font-size: 18px;
    }

    .section {
        padding: 15px;
    }
}

</style>

</head>

<body>

<header>

    <div class="header-content">

        <h1>Patient Monitoring - Owner Dashboard</h1>

        <div class="header-right">

            <span>
                Administrator: <?php echo owner_escape($owner_name); ?>
            </span>

            <a href="owner_logout.php">Logout</a>

        </div>

    </div>

</header>

<div class="container">

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type === "error" ? "error-message" : "success-message"; ?>">
            <?php echo owner_escape($message); ?>
        </div>

    <?php endif; ?>


    <!-- ==========================================
         SECTION 1: REGISTER NEW PATIENT
    =========================================== -->

    <div class="section">

        <h2>1. Register New Patient</h2>

        <form method="POST" action="owner.php">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

            <input type="hidden" name="action" value="register_patient">

            <div class="form-grid">

                <div class="form-group">
                    <label>Patient ID *</label>
                    <input type="text" name="patient_id" maxlength="30" required>
                </div>

                <div class="form-group">
                    <label>Patient Full Name *</label>
                    <input type="text" name="full_name" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label>Age</label>
                    <input type="number" name="age" min="0" max="120">
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">Select</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Blood Group</label>
                    <input type="text" name="blood_group" maxlength="5">
                </div>

                <div class="form-group">
                    <label>Mobile / Contact</label>
                    <input type="text" name="contact" maxlength="150">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" maxlength="150">
                </div>

                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes"></textarea>
                </div>

            </div>

            <button type="submit" class="primary">
                Register Patient
            </button>

        </form>

    </div>


    <!-- ==========================================
         SECTION 2: ASSIGN OR UPDATE DEVICE
    =========================================== -->

    <div class="section">

        <h2>2. Assign / Update ESP32 Device</h2>

        <p class="small">
            Enter an existing Patient ID and the ESP32 device ID.
            If the device is already assigned, this form updates its assignment and dates.
            Its current enabled/disabled setting is preserved.
        </p>

        <form method="POST" action="owner.php">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

            <input type="hidden" name="action" value="assign_device">

            <div class="form-grid">

                <div class="form-group">
                    <label>Patient ID *</label>
                    <input type="text" name="device_patient_id" maxlength="30" required>
                </div>

                <div class="form-group">
                    <label>ESP32 Device ID *</label>
                    <input type="text" name="device_id" maxlength="32" required>
                </div>

                <div class="form-group full">
                    <label>Device Description</label>
                    <input type="text" name="description" maxlength="150"
                           placeholder="Example: ECG Patient Monitoring ESP32">
                </div>

                <div class="form-group">
                    <label>Assignment Start Date and Time</label>
                    <input type="datetime-local" name="start_date_time">
                </div>

                <div class="form-group">
                    <label>Assignment End Date and Time</label>
                    <input type="datetime-local" name="end_date_time">
                </div>

            </div>

            <button type="submit" class="primary">
                Save Device Assignment
            </button>

        </form>

    </div>


    <!-- ==========================================
         SECTION 3: REGISTERED PATIENTS
    =========================================== -->

    <div class="section">

        <h2>3. Registered Patients</h2>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>Blood Group</th>
                        <th>Mobile / Contact</th>
                        <th>Email</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($patients) === 0): ?>

                    <tr>
                        <td colspan="7">No patients registered.</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($patients as $patient): ?>

                        <tr>
                            <td><?php echo owner_escape($patient["patient_id"]); ?></td>
                            <td><?php echo owner_escape($patient["full_name"]); ?></td>
                            <td><?php echo owner_escape($patient["age"]); ?></td>
                            <td><?php echo owner_escape($patient["gender"]); ?></td>
                            <td><?php echo owner_escape($patient["blood_group"]); ?></td>
                            <td><?php echo owner_escape($patient["contact"]); ?></td>
                            <td><?php echo owner_escape($patient["email"]); ?></td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- ==========================================
         SECTION 4: DEVICE MANAGEMENT
    =========================================== -->

    <div class="section">

        <h2>4. Assigned Devices - Enable / Disable</h2>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Device ID</th>
                        <th>Patient ID</th>
                        <th>Patient Name</th>
                        <th>Description</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Device Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($devices) === 0): ?>

                    <tr>
                        <td colspan="8">No devices assigned.</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($devices as $device): ?>

                        <?php
                        $device_expired = (
                            !empty($device["end_date_time"]) &&
                            strtotime($device["end_date_time"]) < time()
                        );

                        $device_not_started = (
                            !empty($device["start_date_time"]) &&
                            strtotime($device["start_date_time"]) > time()
                        );
                        ?>

                        <tr>

                            <td><?php echo owner_escape($device["device_id"]); ?></td>

                            <td><?php echo owner_escape($device["patient_id"]); ?></td>

                            <td><?php echo owner_escape($device["full_name"]); ?></td>

                            <td><?php echo owner_escape($device["description"]); ?></td>

                            <td><?php echo owner_escape(owner_date_display($device["start_date_time"])); ?></td>

                            <td><?php echo owner_escape(owner_date_display($device["end_date_time"])); ?></td>

                            <td>

                                <?php if ((int)$device["enabled"] !== 1): ?>

                                    <span class="status-disabled">Disabled</span>

                                <?php elseif ($device_expired): ?>

                                    <span class="status-expired">Expired</span>

                                <?php elseif ($device_not_started): ?>

                                    <span class="status-expired">Not Started</span>

                                <?php else: ?>

                                    <span class="status-enabled">Enabled</span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <form method="POST" class="action-form">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

                                    <input type="hidden" name="action" value="toggle_device">

                                    <input type="hidden" name="device_id"
                                           value="<?php echo owner_escape($device["device_id"]); ?>">

                                    <?php if ((int)$device["enabled"] === 1): ?>

                                        <input type="hidden" name="new_status" value="0">

                                        <button type="submit" class="warning">
                                            Disable
                                        </button>

                                    <?php else: ?>

                                        <input type="hidden" name="new_status" value="1">

                                        <button type="submit" class="success">
                                            Enable
                                        </button>

                                    <?php endif; ?>

                                </form>

                                <form method="POST" class="action-form"
                                      onsubmit="return confirm('Delete this device assignment? Historical readings will remain.');">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

                                    <input type="hidden" name="action" value="delete_device">

                                    <input type="hidden" name="device_id"
                                           value="<?php echo owner_escape($device["device_id"]); ?>">

                                    <button type="submit" class="danger">
                                        Delete
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- ==========================================
         SECTION 5: PATIENT LOGIN MANAGEMENT
    =========================================== -->

    <div class="section">

        <h2>5. Create / Update Patient Login</h2>

        <p class="small">
            The patient must have an assigned and currently enabled device.
            Leave the password blank when updating a login if you do not want to change it.
            A new login requires a password of at least 8 characters.
        </p>

        <form method="POST" action="owner.php">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

            <input type="hidden" name="action" value="save_patient_login">

            <div class="form-grid">

                <div class="form-group">
                    <label>Patient Username *</label>
                    <input type="text" name="login_username" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="login_password"
                           minlength="8" autocomplete="new-password">
                </div>

                <div class="form-group full">
                    <label>Assigned ESP32 Device ID *</label>
                    <input type="text" name="login_device_id" maxlength="32" required>
                </div>

                <div class="form-group">
                    <label>Login Valid From</label>
                    <input type="datetime-local" name="valid_from">
                </div>

                <div class="form-group">
                    <label>Login Valid Until</label>
                    <input type="datetime-local" name="valid_until">
                </div>

            </div>

            <button type="submit" class="primary">
                Save Patient Login
            </button>

        </form>

    </div>


    <!-- ==========================================
         SECTION 6: PATIENT LOGIN LIST
    =========================================== -->

    <div class="section">

        <h2>6. Patient Login Accounts - Enable / Disable</h2>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Patient ID</th>
                        <th>Patient Name</th>
                        <th>Device ID</th>
                        <th>Valid From</th>
                        <th>Valid Until</th>
                        <th>Login Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($logins) === 0): ?>

                    <tr>
                        <td colspan="8">No patient login accounts found.</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($logins as $login): ?>

                        <?php
                        $login_expired = (
                            !empty($login["valid_until"]) &&
                            strtotime($login["valid_until"]) < time()
                        );

                        $login_not_started = (
                            !empty($login["valid_from"]) &&
                            strtotime($login["valid_from"]) > time()
                        );
                        ?>

                        <tr>

                            <td><?php echo owner_escape($login["username"]); ?></td>

                            <td><?php echo owner_escape($login["patient_id"]); ?></td>

                            <td><?php echo owner_escape($login["full_name"]); ?></td>

                            <td><?php echo owner_escape($login["device_id"]); ?></td>

                            <td><?php echo owner_escape(owner_date_display($login["valid_from"])); ?></td>

                            <td><?php echo owner_escape(owner_date_display($login["valid_until"])); ?></td>

                            <td>

                                <?php if ((int)$login["enabled"] !== 1): ?>

                                    <span class="status-disabled">Disabled</span>

                                <?php elseif ($login_expired): ?>

                                    <span class="status-expired">Expired</span>

                                <?php elseif ($login_not_started): ?>

                                    <span class="status-expired">Not Started</span>

                                <?php else: ?>

                                    <span class="status-enabled">Enabled</span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <form method="POST" class="action-form">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

                                    <input type="hidden" name="action"
                                           value="toggle_patient_login">

                                    <input type="hidden" name="login_id"
                                           value="<?php echo (int)$login["id"]; ?>">

                                    <?php if ((int)$login["enabled"] === 1): ?>

                                        <input type="hidden" name="new_status" value="0">

                                        <button type="submit" class="warning">
                                            Disable
                                        </button>

                                    <?php else: ?>

                                        <input type="hidden" name="new_status" value="1">

                                        <button type="submit" class="success">
                                            Enable
                                        </button>

                                    <?php endif; ?>

                                </form>

                                <form method="POST" class="action-form"
                                      onsubmit="return confirm('Delete this patient login? Patient records will remain.');">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo owner_escape($_SESSION["owner_csrf"]); ?>">

                                    <input type="hidden" name="action"
                                           value="delete_patient_login">

                                    <input type="hidden" name="login_id"
                                           value="<?php echo (int)$login["id"]; ?>">

                                    <button type="submit" class="danger">
                                        Delete Login
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <div class="section">

        <h2>Important Information</h2>

        <ul>
            <li>Disabling a device prevents patient access through that device.</li>
            <li>Disabling a patient login prevents that username from logging in.</li>
            <li>Enabling an account does not override expired validity dates.</li>
            <li>Device assignment dates and login validity dates are checked separately.</li>
            <li>Deleting a device assignment does not delete historical readings.</li>
            <li>Deleting a patient login does not delete the patient record.</li>
            <li>Patient access is checked again by the protected patient pages.</li>
        </ul>

    </div>

</div>

</body>
</html>
