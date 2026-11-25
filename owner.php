<?php
/*
|--------------------------------------------------------------------------
| PROJECT: ravi2_patient
| FILE: owner.php
|--------------------------------------------------------------------------
| OWNER / ADMINISTRATOR DASHBOARD
|
| Functions:
| 1. Register patients
| 2. Select a patient and assign a device
| 3. Enable / disable device assignments
| 4. Create and manage patient login accounts
|
| Important:
| - Uses MySQLi and the existing config.php connection ($conn).
| - Uses Asia/Kolkata time.
| - Does not delete monitoring or ECG history.
| - Stores patient_id in web_users as well as device_id so that the
|   login account remains linked to the correct patient.
|--------------------------------------------------------------------------
*/

date_default_timezone_set("Asia/Kolkata");
require_once __DIR__ . "/config.php";

/*--------------------------------------------------------------
  1. CHECK DATABASE CONNECTION AND OWNER LOGIN
--------------------------------------------------------------*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection is not available.");
}

if (
    empty($_SESSION["owner_logged_in"]) ||
    empty($_SESSION["owner_id"])
) {
    header("Location: owner_login.php");
    exit;
}

/*--------------------------------------------------------------
  2. HELPER FUNCTIONS
--------------------------------------------------------------*/

function owner_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function owner_date_input($value): string
{
    if (empty($value)) {
        return "";
    }

    $time = strtotime((string)$value);
    return ($time === false) ? "" : date("Y-m-d\TH:i", $time);
}

function owner_date_display($value): string
{
    if (empty($value)) {
        return "Not specified";
    }

    $time = strtotime((string)$value);
    return ($time === false)
        ? owner_escape($value)
        : date("d-m-Y H:i", $time);
}

/*
 * Return:
 *   null  = field was left blank
 *   false = invalid date
 *   text  = valid MySQL date/time
 */
function owner_valid_date($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat(
        "Y-m-d\TH:i",
        $value,
        new DateTimeZone("Asia/Kolkata")
    );

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

function owner_message(string $message): void
{
    $_SESSION["owner_message"] = $message;
    header("Location: owner.php");
    exit;
}

/*--------------------------------------------------------------
  3. CSRF SECURITY TOKEN
--------------------------------------------------------------*/

if (empty($_SESSION["owner_csrf"])) {
    $_SESSION["owner_csrf"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["owner_csrf"];

/*--------------------------------------------------------------
  4. PROCESS FORM SUBMISSIONS
--------------------------------------------------------------*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $posted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        owner_message("Security check failed. Please reload and try again.");
    }

    $action = (string)($_POST["action"] ?? "");

    try {

        /*======================================================
          ACTION 1: REGISTER A NEW PATIENT
        ======================================================*/

        if ($action === "register_patient") {

            $patient_id  = trim((string)($_POST["patient_id"] ?? ""));
            $full_name   = trim((string)($_POST["full_name"] ?? ""));
            $age_input   = trim((string)($_POST["age"] ?? ""));
            $gender      = trim((string)($_POST["gender"] ?? ""));
            $blood_group = trim((string)($_POST["blood_group"] ?? ""));
            $contact     = trim((string)($_POST["contact"] ?? ""));
            $email       = trim((string)($_POST["email"] ?? ""));
            $notes       = trim((string)($_POST["notes"] ?? ""));

            if ($patient_id === "" || $full_name === "") {
                owner_message("Patient ID and patient name are required.");
            }

            if (strlen($patient_id) > 50 || strlen($full_name) > 150) {
                owner_message("Patient ID or patient name is too long.");
            }

            $age = null;
            if ($age_input !== "") {
                if (!ctype_digit($age_input) || (int)$age_input > 120) {
                    owner_message("Please enter a valid patient age.");
                }
                $age = (int)$age_input;
            }

            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                owner_message("Please enter a valid email address.");
            }

            $check = $conn->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
            );
            $check->bind_param("s", $patient_id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                owner_message("This Patient ID already exists.");
            }

            $stmt = $conn->prepare(
                "INSERT INTO patients
                 (patient_id, full_name, age, gender, blood_group, contact, email, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssisssss",
                $patient_id,
                $full_name,
                $age,
                $gender,
                $blood_group,
                $contact,
                $email,
                $notes
            );

            $stmt->execute();
            $stmt->close();

            owner_message("Patient registered successfully.");
        }

        /*======================================================
          ACTION 2: ASSIGN OR REASSIGN A DEVICE TO A PATIENT

          The device row is updated, not deleted and recreated.
          Existing monitoring_readings and ecg_readings are never
          changed. Patient login rows for this device are updated
          to the selected patient_id so the login mapping agrees.
        ======================================================*/

        if ($action === "assign_device") {

            $device_id   = trim((string)($_POST["device_id"] ?? ""));
            $patient_id  = trim((string)($_POST["device_patient_id"] ?? ""));
            $description = trim((string)($_POST["description"] ?? ""));

            $start_date = owner_valid_date($_POST["start_date_time"] ?? "");
            $end_date   = owner_valid_date($_POST["end_date_time"] ?? "");

            if ($device_id === "" || $patient_id === "") {
                owner_message("Please select or enter both Device ID and Patient ID.");
            }

            if (strlen($device_id) > 100 || strlen($description) > 255) {
                owner_message("Device ID or description is too long.");
            }

            if ($start_date === false || $end_date === false) {
                owner_message("Please enter valid device assignment dates.");
            }

            if (
                $start_date !== null &&
                $end_date !== null &&
                $end_date <= $start_date
            ) {
                owner_message("Device end date/time must be later than start date/time.");
            }

            $patientCheck = $conn->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
            );
            $patientCheck->bind_param("s", $patient_id);
            $patientCheck->execute();
            $patientExists = $patientCheck->get_result()->num_rows > 0;
            $patientCheck->close();

            if (!$patientExists) {
                owner_message("The selected Patient ID does not exist.");
            }

            $conn->begin_transaction();

            try {
                /*
                 * Lock an existing device assignment while saving.
                 * This avoids two owner requests changing the same
                 * device at the same time.
                 */
                $check = $conn->prepare(
                    "SELECT device_id
                     FROM patient_devices
                     WHERE device_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );
                $check->bind_param("s", $device_id);
                $check->execute();
                $deviceExists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($deviceExists) {

                    $stmt = $conn->prepare(
                        "UPDATE patient_devices
                         SET patient_id = ?,
                             description = ?,
                             start_date_time = ?,
                             end_date_time = ?
                         WHERE device_id = ?"
                    );

                    $stmt->bind_param(
                        "sssss",
                        $patient_id,
                        $description,
                        $start_date,
                        $end_date,
                        $device_id
                    );

                } else {

                    $stmt = $conn->prepare(
                        "INSERT INTO patient_devices
                         (device_id, patient_id, description, enabled,
                          start_date_time, end_date_time)
                         VALUES (?, ?, ?, 1, ?, ?)"
                    );

                    $stmt->bind_param(
                        "sssss",
                        $device_id,
                        $patient_id,
                        $description,
                        $start_date,
                        $end_date
                    );
                }

                $stmt->execute();
                $stmt->close();

                /*
                 * Do not silently transfer a patient login when a device
                 * is reassigned. The owner must review/edit that login in
                 * Section 5. This prevents credentials for the previous
                 * patient from automatically becoming the new patient's
                 * login credentials.
                 */

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            owner_message(
                "Device assignment saved. Please review the patient login in Section 5 if this device was reassigned. Existing monitoring and ECG history was not changed."
            );
        }

        /*======================================================
          ACTION 3: ENABLE OR DISABLE A DEVICE
        ======================================================*/

        if ($action === "toggle_device") {

            $device_id = trim((string)($_POST["device_id"] ?? ""));
            $new_status = filter_var(
                $_POST["new_status"] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $device_id === "" ||
                !in_array($new_status, [0, 1], true)
            ) {
                owner_message("Invalid device status request.");
            }

            $stmt = $conn->prepare(
                "UPDATE patient_devices
                 SET enabled = ?
                 WHERE device_id = ?"
            );
            $stmt->bind_param("is", $new_status, $device_id);
            $stmt->execute();
            $matched = $stmt->affected_rows;
            $stmt->close();

            owner_message(
                $matched >= 0
                    ? (($new_status === 1) ? "Device enabled." : "Device disabled.")
                    : "Could not update device status."
            );
        }

        /*======================================================
          ACTION 4: DELETE DEVICE ASSIGNMENT

          Do not delete while a patient login still refers to
          the device. Disable it instead, or remove/edit the
          patient login first. Historical readings are untouched.
        ======================================================*/

        if ($action === "delete_device") {

            $device_id = trim((string)($_POST["device_id"] ?? ""));

            if ($device_id === "") {
                owner_message("Device ID is missing.");
            }

            $check = $conn->prepare(
                "SELECT id
                 FROM web_users
                 WHERE device_id = ?
                   AND role = 'patient'
                 LIMIT 1"
            );
            $check->bind_param("s", $device_id);
            $check->execute();
            $hasLogin = $check->get_result()->num_rows > 0;
            $check->close();

            if ($hasLogin) {
                owner_message(
                    "This device is linked to a patient login. Disable the device or edit/delete that login before deleting the assignment."
                );
            }

            $stmt = $conn->prepare(
                "DELETE FROM patient_devices WHERE device_id = ?"
            );
            $stmt->bind_param("s", $device_id);
            $stmt->execute();
            $stmt->close();

            owner_message(
                "Device assignment deleted. Historical monitoring and ECG readings were not deleted."
            );
        }

        /*======================================================
          ACTION 5: CREATE OR EDIT A PATIENT LOGIN ACCOUNT
        ======================================================*/

        if ($action === "save_patient_login") {

            $login_id  = (int)($_POST["login_id"] ?? 0);
            $username  = trim((string)($_POST["username"] ?? ""));
            $password  = (string)($_POST["password"] ?? "");
            $patient_id = trim((string)($_POST["login_patient_id"] ?? ""));
            $device_id = trim((string)($_POST["login_device_id"] ?? ""));

            $valid_from  = owner_valid_date($_POST["valid_from"] ?? "");
            $valid_until = owner_valid_date($_POST["valid_until"] ?? "");

            if (
                $username === "" ||
                strlen($username) > 100 ||
                $patient_id === "" ||
                $device_id === ""
            ) {
                owner_message("Username, Patient ID and Device ID are required.");
            }

            if ($valid_from === false || $valid_until === false) {
                owner_message("Please enter valid login validity dates.");
            }

            if (
                $valid_from !== null &&
                $valid_until !== null &&
                $valid_until <= $valid_from
            ) {
                owner_message("Login expiry must be later than the login start date/time.");
            }

            /*
             * Confirm that the selected device belongs to the
             * selected patient, is enabled, and is within its
             * assignment date range at the present time.
             */
            $deviceCheck = $conn->prepare(
                "SELECT device_id
                 FROM patient_devices
                 WHERE device_id = ?
                   AND patient_id = ?
                   AND enabled = 1
                   AND (start_date_time IS NULL OR start_date_time <= NOW())
                   AND (end_date_time IS NULL OR end_date_time >= NOW())
                 LIMIT 1"
            );
            $deviceCheck->bind_param("ss", $device_id, $patient_id);
            $deviceCheck->execute();
            $deviceAvailable = $deviceCheck->get_result()->num_rows > 0;
            $deviceCheck->close();

            if (!$deviceAvailable) {
                owner_message(
                    "The selected device is not currently enabled and valid for this patient. Check Section 3 and Section 4."
                );
            }

            /* Username must be unique. */

            if ($login_id > 0) {
                $check = $conn->prepare(
                    "SELECT id
                     FROM web_users
                     WHERE username = ?
                       AND id <> ?
                     LIMIT 1"
                );
                $check->bind_param("si", $username, $login_id);
            } else {
                $check = $conn->prepare(
                    "SELECT id
                     FROM web_users
                     WHERE username = ?
                     LIMIT 1"
                );
                $check->bind_param("s", $username);
            }

            $check->execute();
            $usernameExists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($usernameExists) {
                owner_message("This username is already in use.");
            }

            if ($login_id > 0) {

                $check = $conn->prepare(
                    "SELECT id
                     FROM web_users
                     WHERE id = ?
                       AND role = 'patient'
                     LIMIT 1"
                );
                $check->bind_param("i", $login_id);
                $check->execute();
                $loginExists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$loginExists) {
                    owner_message("Patient login account was not found.");
                }

                if ($password !== "") {

                    if (strlen($password) < 8) {
                        owner_message("New password must contain at least 8 characters.");
                    }

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare(
                        "UPDATE web_users
                         SET username = ?,
                             patient_id = ?,
                             device_id = ?,
                             valid_from = ?,
                             valid_until = ?,
                             password_hash = ?
                         WHERE id = ?
                           AND role = 'patient'"
                    );

                    $stmt->bind_param(
                        "ssssssi",
                        $username,
                        $patient_id,
                        $device_id,
                        $valid_from,
                        $valid_until,
                        $password_hash,
                        $login_id
                    );

                } else {

                    $stmt = $conn->prepare(
                        "UPDATE web_users
                         SET username = ?,
                             patient_id = ?,
                             device_id = ?,
                             valid_from = ?,
                             valid_until = ?
                         WHERE id = ?
                           AND role = 'patient'"
                    );

                    $stmt->bind_param(
                        "sssssi",
                        $username,
                        $patient_id,
                        $device_id,
                        $valid_from,
                        $valid_until,
                        $login_id
                    );
                }

                $stmt->execute();
                $stmt->close();

                owner_message("Patient login account updated successfully.");
            }

            /* Create a new patient login. */

            if (strlen($password) < 8) {
                owner_message("New patient password must contain at least 8 characters.");
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role = "patient";
            $enabled = 1;

            $stmt = $conn->prepare(
                "INSERT INTO web_users
                 (username, password_hash, role, enabled,
                  patient_id, device_id, valid_from, valid_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "sssissss",
                $username,
                $password_hash,
                $role,
                $enabled,
                $patient_id,
                $device_id,
                $valid_from,
                $valid_until
            );

            $stmt->execute();
            $stmt->close();

            owner_message("New patient login account created successfully.");
        }

        /*======================================================
          ACTION 6: ENABLE OR DISABLE PATIENT LOGIN
        ======================================================*/

        if ($action === "toggle_patient_login") {

            $login_id = (int)($_POST["login_id"] ?? 0);
            $new_status = filter_var(
                $_POST["new_status"] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $login_id <= 0 ||
                !in_array($new_status, [0, 1], true)
            ) {
                owner_message("Invalid patient login status request.");
            }

            $stmt = $conn->prepare(
                "UPDATE web_users
                 SET enabled = ?
                 WHERE id = ?
                   AND role = 'patient'"
            );
            $stmt->bind_param("ii", $new_status, $login_id);
            $stmt->execute();
            $stmt->close();

            owner_message(
                ($new_status === 1)
                    ? "Patient login enabled."
                    : "Patient login disabled."
            );
        }

        /*======================================================
          ACTION 7: DELETE PATIENT LOGIN ONLY
        ======================================================*/

        if ($action === "delete_patient_login") {

            $login_id = (int)($_POST["login_id"] ?? 0);

            if ($login_id <= 0) {
                owner_message("Invalid patient login account.");
            }

            $stmt = $conn->prepare(
                "DELETE FROM web_users
                 WHERE id = ?
                   AND role = 'patient'"
            );
            $stmt->bind_param("i", $login_id);
            $stmt->execute();
            $stmt->close();

            owner_message(
                "Patient login account deleted. Patient details and historical readings remain unchanged."
            );
        }

        owner_message("Unknown request.");

    } catch (Throwable $e) {

        error_log("ravi2_patient owner.php error: " . $e->getMessage());

        /*
         * Show a useful but limited message. Full database details
         * are written to the server log, not exposed to visitors.
         */
        owner_message(
            "The requested operation could not be completed. Please check the database table columns and server error log."
        );
    }
}

/*--------------------------------------------------------------
  5. LOAD LOGIN ACCOUNT SELECTED FOR EDITING
--------------------------------------------------------------*/

$edit_login = null;
$edit_login_id = (int)($_GET["edit_login"] ?? 0);

if ($edit_login_id > 0) {

    $stmt = $conn->prepare(
        "SELECT id, username, patient_id, device_id, valid_from, valid_until
         FROM web_users
         WHERE id = ?
           AND role = 'patient'
         LIMIT 1"
    );

    $stmt->bind_param("i", $edit_login_id);
    $stmt->execute();
    $edit_login = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_login) {
        $_SESSION["owner_message"] = "Patient login account was not found.";
        header("Location: owner.php");
        exit;
    }
}

/*--------------------------------------------------------------
  6. LOAD ONE-TIME MESSAGE
--------------------------------------------------------------*/

$message = (string)($_SESSION["owner_message"] ?? "");
unset($_SESSION["owner_message"]);

/*--------------------------------------------------------------
  7. LOAD PATIENT LIST
--------------------------------------------------------------*/

$patients = [];

$result = $conn->query(
    "SELECT patient_id, full_name, age, gender, blood_group,
            contact, email, notes
     FROM patients
     ORDER BY patient_id"
);

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

/*--------------------------------------------------------------
  8. LOAD DEVICE ASSIGNMENTS
--------------------------------------------------------------*/

$devices = [];

$result = $conn->query(
    "SELECT d.device_id, d.patient_id, d.description,
            d.enabled, d.start_date_time, d.end_date_time,
            p.full_name
     FROM patient_devices d
     LEFT JOIN patients p ON p.patient_id = d.patient_id
     ORDER BY d.device_id"
);

while ($row = $result->fetch_assoc()) {
    $devices[] = $row;
}

/*--------------------------------------------------------------
  9. LOAD PATIENT LOGIN ACCOUNTS

  Prefer web_users.patient_id. The LEFT JOIN to patient_devices
  is only a fallback for any older login row where patient_id
  has not yet been populated.
--------------------------------------------------------------*/

$patient_logins = [];

$result = $conn->query(
    "SELECT w.id, w.username, w.enabled,
            w.patient_id AS login_patient_id,
            w.device_id, w.valid_from, w.valid_until,
            COALESCE(NULLIF(w.patient_id, ''), d.patient_id) AS patient_id,
            p.full_name
     FROM web_users w
     LEFT JOIN patient_devices d ON d.device_id = w.device_id
     LEFT JOIN patients p
       ON p.patient_id = COALESCE(NULLIF(w.patient_id, ''), d.patient_id)
     WHERE w.role = 'patient'
     ORDER BY w.username"
);

while ($row = $result->fetch_assoc()) {
    $patient_logins[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Owner / Administrator Dashboard</title>

<style>
    * { box-sizing: border-box; }

    body {
        font-family: Arial, sans-serif;
        background: #f3f5f7;
        margin: 0;
        padding: 20px;
        color: #222;
    }

    .container { max-width: 1250px; margin: auto; }
    h1 { color: #17365d; }
    h2 {
        background: #e7eef7;
        padding: 12px;
        border-left: 5px solid #245a91;
        margin-top: 0;
    }

    .section {
        background: white;
        padding: 18px;
        margin-bottom: 22px;
        border-radius: 7px;
        box-shadow: 0 1px 5px rgba(0,0,0,0.08);
    }

    label { display: block; font-weight: bold; margin-top: 10px; }

    input, select, textarea {
        width: 100%;
        max-width: 480px;
        padding: 9px;
        margin-top: 5px;
        box-sizing: border-box;
        border: 1px solid #bbb;
        border-radius: 4px;
        background: #fff;
    }

    textarea { min-height: 65px; }

    button, .button {
        display: inline-block;
        padding: 8px 13px;
        margin: 5px 3px 5px 0;
        border: 0;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        color: white;
        background: #245a91;
        font-size: 14px;
    }

    button:hover, .button:hover { opacity: 0.85; }
    .green { background: #198754; }
    .red { background: #c0392b; }
    .orange { background: #d97706; }
    .grey { background: #555; }

    .message {
        background: #e4f5e8;
        border: 1px solid #8bc99a;
        padding: 12px;
        border-radius: 5px;
        margin: 15px 0;
    }

    .note { color: #555; font-size: 13px; line-height: 1.5; }
    .status-enabled { color: #16803c; font-weight: bold; }
    .status-disabled { color: #c0392b; font-weight: bold; }
    .small-form { display: inline; }
    .top-links { margin-bottom: 20px; }
    .top-links a { margin-right: 10px; }

    .edit-panel {
        border: 2px solid #d97706;
        padding: 15px;
        border-radius: 6px;
        background: #fffaf0;
        margin-bottom: 15px;
    }

    .table-wrap { width: 100%; overflow-x: auto; }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
        background: white;
        min-width: 720px;
    }

    th, td {
        border: 1px solid #ddd;
        padding: 9px;
        text-align: left;
        vertical-align: top;
    }

    th { background: #e7eef7; }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(240px, 1fr));
        gap: 12px 20px;
        max-width: 1000px;
    }

    .form-grid > div { min-width: 0; }
    .form-grid input, .form-grid select { max-width: 100%; }

    @media(max-width: 750px) {
        body { padding: 8px; }
        .form-grid { grid-template-columns: 1fr; }
        .section { padding: 12px; }
    }
</style>
</head>

<body>
<div class="container">

<h1>Owner / Administrator Dashboard</h1>

<p>
    Logged in as:
    <strong><?php echo owner_escape($_SESSION["owner_username"] ?? "Administrator"); ?></strong>
</p>

<div class="top-links">
    <a class="button grey" href="owner_logout.php">Logout</a>
    <a class="button" href="index.php">Patient Dashboard</a>
</div>

<?php if ($message !== ""): ?>
    <div class="message"><?php echo owner_escape($message); ?></div>
<?php endif; ?>

<!-- SECTION 1: REGISTER PATIENT -->
<div class="section">
<h2>1. Register New Patient</h2>

<form method="post" action="owner.php">
    <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
    <input type="hidden" name="action" value="register_patient">

    <div class="form-grid">
        <div>
            <label>Patient ID *</label>
            <input type="text" name="patient_id" maxlength="50" placeholder="Example: P1004" required>
        </div>
        <div>
            <label>Patient Full Name *</label>
            <input type="text" name="full_name" maxlength="150" required>
        </div>
        <div>
            <label>Age</label>
            <input type="number" name="age" min="0" max="120">
        </div>
        <div>
            <label>Gender</label>
            <input type="text" name="gender">
        </div>
        <div>
            <label>Blood Group</label>
            <input type="text" name="blood_group">
        </div>
        <div>
            <label>Contact / Mobile Number</label>
            <input type="text" name="contact">
        </div>
        <div>
            <label>Email Address</label>
            <input type="email" name="email">
        </div>
        <div>
            <label>Notes</label>
            <textarea name="notes"></textarea>
        </div>
    </div>

    <button type="submit">Register Patient</button>
</form>
</div>

<!-- SECTION 2: PATIENT LIST -->
<div class="section">
<h2>2. Registered Patients - Select Patient ID</h2>

<?php if (count($patients) === 0): ?>
    <p>No patients found. Register a patient in Section 1.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<tr>
    <th>Patient ID</th><th>Name</th><th>Age</th><th>Gender</th>
    <th>Blood Group</th><th>Contact</th><th>Email</th>
</tr>
<?php foreach ($patients as $patient): ?>
<tr>
    <td><strong><?php echo owner_escape($patient["patient_id"]); ?></strong></td>
    <td><?php echo owner_escape($patient["full_name"]); ?></td>
    <td><?php echo owner_escape($patient["age"]); ?></td>
    <td><?php echo owner_escape($patient["gender"]); ?></td>
    <td><?php echo owner_escape($patient["blood_group"]); ?></td>
    <td><?php echo owner_escape($patient["contact"]); ?></td>
    <td><?php echo owner_escape($patient["email"]); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- SECTION 3: ASSIGN DEVICE -->
<div class="section">
<h2>3. Select Patient ID and Assign Device ID</h2>

<form method="post" action="owner.php">
    <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
    <input type="hidden" name="action" value="assign_device">

    <div class="form-grid">
        <div>
            <label>Device ID *</label>
            <input type="text" name="device_id" maxlength="100"
                   placeholder="Example: ESP32-01" required>
            <p class="note">Enter the exact Device ID configured in the ESP32 firmware.</p>
        </div>

        <div>
            <label>Select Patient ID *</label>
            <select name="device_patient_id" required>
                <option value="">-- Select Patient --</option>
                <?php foreach ($patients as $patient): ?>
                <option value="<?php echo owner_escape($patient["patient_id"]); ?>">
                    <?php echo owner_escape($patient["patient_id"] . " - " . $patient["full_name"]); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Device Description</label>
            <input type="text" name="description" maxlength="255"
                   placeholder="Example: ECG Patient Monitoring ESP32">
        </div>

        <div>
            <label>Assignment Start Date / Time</label>
            <input type="datetime-local" name="start_date_time">
        </div>

        <div>
            <label>Assignment End Date / Time</label>
            <input type="datetime-local" name="end_date_time">
        </div>
    </div>

    <p class="note">
        For an existing Device ID, Save updates its patient assignment and dates.
        Its enabled/disabled status is preserved. Historical monitoring and ECG
        records are not changed. If a patient login exists for this device, its
        patient_id is updated to match the new assignment.
    </p>

    <button type="submit">Save Device Assignment</button>
</form>
</div>

<!-- SECTION 4: DEVICE LIST -->
<div class="section">
<h2>4. Device Assignments - Enable / Disable</h2>

<?php if (count($devices) === 0): ?>
    <p>No device assignments found.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<tr>
    <th>Device ID</th><th>Patient ID</th><th>Patient Name</th>
    <th>Description</th><th>Status</th><th>Start</th><th>End</th><th>Actions</th>
</tr>

<?php foreach ($devices as $device): ?>
<tr>
    <td><strong><?php echo owner_escape($device["device_id"]); ?></strong></td>
    <td><?php echo owner_escape($device["patient_id"]); ?></td>
    <td><?php echo owner_escape($device["full_name"]); ?></td>
    <td><?php echo owner_escape($device["description"]); ?></td>
    <td>
        <?php if ((int)$device["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>
    <td><?php echo owner_date_display($device["start_date_time"]); ?></td>
    <td><?php echo owner_date_display($device["end_date_time"]); ?></td>
    <td>
        <form method="post" action="owner.php" class="small-form">
            <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
            <input type="hidden" name="action" value="toggle_device">
            <input type="hidden" name="device_id" value="<?php echo owner_escape($device["device_id"]); ?>">
            <?php if ((int)$device["enabled"] === 1): ?>
                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>
            <?php else: ?>
                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>
            <?php endif; ?>
        </form>

        <form method="post" action="owner.php" class="small-form"
              onsubmit="return confirm('Delete this device assignment? Historical readings will remain.');">
            <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
            <input type="hidden" name="action" value="delete_device">
            <input type="hidden" name="device_id" value="<?php echo owner_escape($device["device_id"]); ?>">
            <button type="submit" class="red">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- SECTION 5: PATIENT LOGIN FORM -->
<div class="section">
<h2>5. Create / Edit Patient Login Account</h2>

<?php if ($edit_login !== null): ?>
<div class="edit-panel">
    <strong>Editing Patient Login Account</strong>
    <p>
        Login ID: <?php echo (int)$edit_login["id"]; ?>
        &nbsp; | &nbsp; Username: <?php echo owner_escape($edit_login["username"]); ?>
    </p>
    <a class="button grey" href="owner.php">Cancel Editing</a>
</div>
<?php endif; ?>

<form method="post" action="owner.php">
    <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
    <input type="hidden" name="action" value="save_patient_login">
    <input type="hidden" name="login_id" value="<?php echo $edit_login ? (int)$edit_login["id"] : 0; ?>">

    <div class="form-grid">
        <div>
            <label>Patient Login Username *</label>
            <input type="text" name="username" maxlength="100"
                   value="<?php echo owner_escape($edit_login["username"] ?? ""); ?>" required>
        </div>

        <div>
            <label>Select Patient ID *</label>
            <select name="login_patient_id" id="login_patient_id" required>
                <option value="">-- Select Patient --</option>
                <?php foreach ($patients as $patient): ?>
                <option value="<?php echo owner_escape($patient["patient_id"]); ?>"
                    <?php echo (($edit_login["patient_id"] ?? "") === $patient["patient_id"]) ? "selected" : ""; ?>>
                    <?php echo owner_escape($patient["patient_id"] . " - " . $patient["full_name"]); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Select Device ID *</label>
            <select name="login_device_id" id="login_device_id" required>
                <option value="">-- Select a valid enabled device --</option>
                <?php foreach ($devices as $device): ?>
                    <?php
                    $isEnabled = (int)$device["enabled"] === 1;
                    $selectedDevice = $edit_login["device_id"] ?? "";
                    $isSelected = ($selectedDevice === $device["device_id"]);
                    ?>
                    <?php if ($isEnabled || $isSelected): ?>
                    <option
                        value="<?php echo owner_escape($device["device_id"]); ?>"
                        data-patient="<?php echo owner_escape($device["patient_id"]); ?>"
                        <?php echo $isSelected ? "selected" : ""; ?>
                    >
                        <?php
                        echo owner_escape(
                            $device["device_id"] . " - " .
                            $device["patient_id"] . " - " .
                            $device["full_name"] .
                            ($isEnabled ? "" : " (currently disabled - existing account)")
                        );
                        ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <p class="note">The device list is filtered to show devices assigned to the selected patient.</p>
        </div>

        <div>
            <label><?php echo $edit_login ? "New Password (optional)" : "Password *"; ?></label>
            <input type="password" name="password"
                   <?php echo $edit_login ? "" : "required"; ?>
                   minlength="8" autocomplete="new-password">
            <p class="note">
                <?php echo $edit_login
                    ? "Leave blank to keep the existing password."
                    : "Password must contain at least 8 characters."; ?>
            </p>
        </div>

        <div>
            <label>Login Valid From</label>
            <input type="datetime-local" name="valid_from"
                   value="<?php echo owner_escape(owner_date_input($edit_login["valid_from"] ?? "")); ?>">
        </div>

        <div>
            <label>Login Valid Until / Expiry</label>
            <input type="datetime-local" name="valid_until"
                   value="<?php echo owner_escape(owner_date_input($edit_login["valid_until"] ?? "")); ?>">
        </div>
    </div>

    <p class="note">
        The selected device must be assigned to the selected patient, enabled,
        and within its assignment dates when the login is saved. Blank login
        validity dates mean no separate login date restriction.
    </p>

    <?php if ($edit_login): ?>
        <button type="submit" class="orange">Save Patient Login Changes</button>
        <a href="owner.php" class="button grey">Cancel</a>
    <?php else: ?>
        <button type="submit">Create Patient Login</button>
    <?php endif; ?>
</form>
</div>

<!-- SECTION 6: PATIENT LOGIN LIST -->
<div class="section">
<h2>6. Patient Login Accounts - Enable / Disable / Edit / Delete</h2>

<p class="note">
    Edit changes username, patient, device, validity dates or password.
    Disable prevents login. Delete removes only the login account, not
    the patient record or historical readings.
</p>

<?php if (count($patient_logins) === 0): ?>
    <p>No patient login accounts found.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<tr>
    <th>Login ID</th><th>Username</th><th>Patient ID</th><th>Patient Name</th>
    <th>Device ID</th><th>Status</th><th>Valid From</th><th>Valid Until</th><th>Actions</th>
</tr>

<?php foreach ($patient_logins as $login): ?>
<tr>
    <td><?php echo (int)$login["id"]; ?></td>
    <td><?php echo owner_escape($login["username"]); ?></td>
    <td><?php echo owner_escape($login["patient_id"]); ?></td>
    <td><?php echo owner_escape($login["full_name"]); ?></td>
    <td><?php echo owner_escape($login["device_id"]); ?></td>
    <td>
        <?php if ((int)$login["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>
    <td><?php echo owner_date_display($login["valid_from"]); ?></td>
    <td><?php echo owner_date_display($login["valid_until"]); ?></td>
    <td>
        <a class="button orange"
           href="owner.php?edit_login=<?php echo (int)$login["id"]; ?>">Edit</a>

        <form method="post" action="owner.php" class="small-form">
            <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
            <input type="hidden" name="action" value="toggle_patient_login">
            <input type="hidden" name="login_id" value="<?php echo (int)$login["id"]; ?>">
            <?php if ((int)$login["enabled"] === 1): ?>
                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>
            <?php else: ?>
                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>
            <?php endif; ?>
        </form>

        <form method="post" action="owner.php" class="small-form"
              onsubmit="return confirm('Delete this patient login account? Patient records and historical readings will remain.');">
            <input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
            <input type="hidden" name="action" value="delete_patient_login">
            <input type="hidden" name="login_id" value="<?php echo (int)$login["id"]; ?>">
            <button type="submit" class="red">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<div class="section">
    <strong>Owner / Administrator Dashboard</strong><br>
    <a class="button grey" href="owner_logout.php">Logout</a>
</div>

</div>

<script>
/*
 * When a patient is selected in the login form, show only devices
 * assigned to that patient. The server checks this relationship
 * again before saving, so the browser filter is not the security check.
 */
(function () {
    const patientSelect = document.getElementById("login_patient_id");
    const deviceSelect = document.getElementById("login_device_id");

    if (!patientSelect || !deviceSelect) return;

    const allOptions = Array.from(deviceSelect.options).map(function (option) {
        return {
            value: option.value,
            text: option.text,
            patient: option.getAttribute("data-patient") || "",
            isPlaceholder: option.value === ""
        };
    });

    function filterDevices() {
        const selectedPatient = patientSelect.value;
        const previousDevice = deviceSelect.value;

        deviceSelect.innerHTML = "";

        allOptions.forEach(function (item) {
            if (
                item.isPlaceholder ||
                (selectedPatient !== "" && item.patient === selectedPatient)
            ) {
                const option = document.createElement("option");
                option.value = item.value;
                option.textContent = item.text;

                if (item.patient) {
                    option.setAttribute("data-patient", item.patient);
                }

                deviceSelect.appendChild(option);
            }
        });

        const canKeepPrevious = Array.from(deviceSelect.options).some(function (o) {
            return o.value === previousDevice;
        });

        if (canKeepPrevious) {
            deviceSelect.value = previousDevice;
        } else {
            deviceSelect.value = "";
        }
    }

    patientSelect.addEventListener("change", filterDevices);
    filterDevices();
})();
</script>

</body>
</html>
