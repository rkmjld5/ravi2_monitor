<?php
/*
|--------------------------------------------------------------------------
| PROJECT: ravi2_patient
| FILE: owner.php
|--------------------------------------------------------------------------
| OWNER / ADMINISTRATOR DASHBOARD
|
| Sections 4 and 6 deliberately start from the patients table.
| Therefore EVERY registered patient is displayed, even when that
| patient has no device assignment or no patient login account.
|
| Uses MySQLi ($conn), config.php, and Asia/Kolkata time.
| Patient history is never deleted by these actions.
|--------------------------------------------------------------------------
*/

date_default_timezone_set("Asia/Kolkata");
require_once __DIR__ . "/config.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection is not available.");
}

if (empty($_SESSION["owner_logged_in"]) || empty($_SESSION["owner_id"])) {
    header("Location: owner_login.php");
    exit;
}

/*------------------------- Helper functions -------------------------*/

function owner_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function owner_date_input($value): string
{
    if (empty($value)) return "";
    $time = strtotime((string)$value);
    return $time === false ? "" : date("Y-m-d\TH:i", $time);
}

function owner_date_display($value): string
{
    if (empty($value)) return "Not specified";
    $time = strtotime((string)$value);
    return $time === false ? owner_escape($value) : date("d-m-Y H:i", $time);
}

function owner_valid_date($value)
{
    $value = trim((string)$value);
    if ($value === "") return null;

    $date = DateTime::createFromFormat(
        "Y-m-d\TH:i",
        $value,
        new DateTimeZone("Asia/Kolkata")
    );

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) return false;
    return $date->format("Y-m-d H:i:s");
}

function owner_message(string $message): void
{
    $_SESSION["owner_message"] = $message;
    header("Location: owner.php");
    exit;
}

/*------------------------- CSRF token -------------------------*/

if (empty($_SESSION["owner_csrf"])) {
    $_SESSION["owner_csrf"] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION["owner_csrf"];

/*------------------------- Process submitted forms -------------------------*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $posted_token = $_POST["csrf_token"] ?? "";
    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        owner_message("Security check failed. Please reload and try again.");
    }

    $action = (string)($_POST["action"] ?? "");

    try {

        /* Register a new patient. */
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

            $check = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
            $check->bind_param("s", $patient_id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
            if ($exists) owner_message("This Patient ID already exists.");

            $stmt = $conn->prepare(
                "INSERT INTO patients
                 (patient_id, full_name, age, gender, blood_group, contact, email, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssisssss",
                $patient_id, $full_name, $age, $gender,
                $blood_group, $contact, $email, $notes
            );
            $stmt->execute();
            $stmt->close();

            owner_message("Patient registered successfully. The patient will now appear in Sections 2, 4 and 6.");
        }

        /* Assign or update one device. This does not transfer login credentials. */
        if ($action === "assign_device") {
            $device_id   = trim((string)($_POST["device_id"] ?? ""));
            $patient_id  = trim((string)($_POST["device_patient_id"] ?? ""));
            $description = trim((string)($_POST["description"] ?? ""));
            $start_date  = owner_valid_date($_POST["start_date_time"] ?? "");
            $end_date    = owner_valid_date($_POST["end_date_time"] ?? "");

            if ($device_id === "" || $patient_id === "") {
                owner_message("Please enter/select both Device ID and Patient ID.");
            }
            if (strlen($device_id) > 100 || strlen($description) > 255) {
                owner_message("Device ID or description is too long.");
            }
            if ($start_date === false || $end_date === false) {
                owner_message("Please enter valid device assignment dates.");
            }
            if ($start_date !== null && $end_date !== null && $end_date <= $start_date) {
                owner_message("Device end date/time must be later than start date/time.");
            }

            $patientCheck = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
            $patientCheck->bind_param("s", $patient_id);
            $patientCheck->execute();
            $patientExists = $patientCheck->get_result()->num_rows > 0;
            $patientCheck->close();
            if (!$patientExists) owner_message("The selected Patient ID does not exist.");

            $conn->begin_transaction();
            try {
                $check = $conn->prepare(
                    "SELECT device_id FROM patient_devices WHERE device_id = ? LIMIT 1 FOR UPDATE"
                );
                $check->bind_param("s", $device_id);
                $check->execute();
                $deviceExists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($deviceExists) {
                    $stmt = $conn->prepare(
                        "UPDATE patient_devices
                         SET patient_id = ?, description = ?,
                             start_date_time = ?, end_date_time = ?
                         WHERE device_id = ?"
                    );
                    $stmt->bind_param("sssss", $patient_id, $description, $start_date, $end_date, $device_id);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO patient_devices
                         (device_id, patient_id, description, enabled, start_date_time, end_date_time)
                         VALUES (?, ?, ?, 1, ?, ?)"
                    );
                    $stmt->bind_param("sssss", $device_id, $patient_id, $description, $start_date, $end_date);
                }
                $stmt->execute();
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            owner_message(
                "Device assignment saved. Existing patient login accounts were not transferred automatically. Review Section 6 if the device was reassigned. Historical readings were not changed."
            );
        }

        /* Enable or disable a device assignment. */
        if ($action === "toggle_device") {
            $device_id = trim((string)($_POST["device_id"] ?? ""));
            $new_status = filter_var($_POST["new_status"] ?? null, FILTER_VALIDATE_INT);
            if ($device_id === "" || !in_array($new_status, [0, 1], true)) {
                owner_message("Invalid device status request.");
            }

            $stmt = $conn->prepare("UPDATE patient_devices SET enabled = ? WHERE device_id = ?");
            $stmt->bind_param("is", $new_status, $device_id);
            $stmt->execute();
            $stmt->close();
            owner_message($new_status === 1 ? "Device enabled." : "Device disabled.");
        }

        /* Delete assignment only; refuse if a login still refers to it. */
        if ($action === "delete_device") {
            $device_id = trim((string)($_POST["device_id"] ?? ""));
            if ($device_id === "") owner_message("Device ID is missing.");

            $check = $conn->prepare(
                "SELECT id FROM web_users WHERE device_id = ? AND role = 'patient' LIMIT 1"
            );
            $check->bind_param("s", $device_id);
            $check->execute();
            $hasLogin = $check->get_result()->num_rows > 0;
            $check->close();
            if ($hasLogin) {
                owner_message("This device is linked to a patient login. Disable the device or edit/delete that login before deleting the assignment.");
            }

            $stmt = $conn->prepare("DELETE FROM patient_devices WHERE device_id = ?");
            $stmt->bind_param("s", $device_id);
            $stmt->execute();
            $stmt->close();
            owner_message("Device assignment deleted. Historical readings were not deleted.");
        }

        /* Create or edit a patient login. A login still requires an enabled,
           currently valid device assignment, as in the existing application. */
        if ($action === "save_patient_login") {
            $login_id   = (int)($_POST["login_id"] ?? 0);
            $username   = trim((string)($_POST["username"] ?? ""));
            $password   = (string)($_POST["password"] ?? "");
            $patient_id = trim((string)($_POST["login_patient_id"] ?? ""));
            $device_id  = trim((string)($_POST["login_device_id"] ?? ""));
            $valid_from = owner_valid_date($_POST["valid_from"] ?? "");
            $valid_until = owner_valid_date($_POST["valid_until"] ?? "");

            if ($username === "" || strlen($username) > 100 || $patient_id === "" || $device_id === "") {
                owner_message("Username, Patient ID and Device ID are required.");
            }
            if ($valid_from === false || $valid_until === false) {
                owner_message("Please enter valid login validity dates.");
            }
            if ($valid_from !== null && $valid_until !== null && $valid_until <= $valid_from) {
                owner_message("Login expiry must be later than the login start date/time.");
            }

            $patientCheck = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
            $patientCheck->bind_param("s", $patient_id);
            $patientCheck->execute();
            $patientExists = $patientCheck->get_result()->num_rows > 0;
            $patientCheck->close();
            if (!$patientExists) owner_message("Selected patient does not exist.");

            $deviceCheck = $conn->prepare(
                "SELECT device_id FROM patient_devices
                 WHERE device_id = ? AND patient_id = ? AND enabled = 1
                   AND (start_date_time IS NULL OR start_date_time <= NOW())
                   AND (end_date_time IS NULL OR end_date_time >= NOW())
                 LIMIT 1"
            );
            $deviceCheck->bind_param("ss", $device_id, $patient_id);
            $deviceCheck->execute();
            $deviceAvailable = $deviceCheck->get_result()->num_rows > 0;
            $deviceCheck->close();
            if (!$deviceAvailable) {
                owner_message("The selected device is not currently enabled and valid for this patient. Check Sections 3 and 4.");
            }

            if ($login_id > 0) {
                $check = $conn->prepare(
                    "SELECT id FROM web_users WHERE username = ? AND id <> ? LIMIT 1"
                );
                $check->bind_param("si", $username, $login_id);
            } else {
                $check = $conn->prepare("SELECT id FROM web_users WHERE username = ? LIMIT 1");
                $check->bind_param("s", $username);
            }
            $check->execute();
            $usernameExists = $check->get_result()->num_rows > 0;
            $check->close();
            if ($usernameExists) owner_message("This username is already in use.");

            if ($login_id > 0) {
                $check = $conn->prepare(
                    "SELECT id FROM web_users WHERE id = ? AND role = 'patient' LIMIT 1"
                );
                $check->bind_param("i", $login_id);
                $check->execute();
                $loginExists = $check->get_result()->num_rows > 0;
                $check->close();
                if (!$loginExists) owner_message("Patient login account was not found.");

                if ($password !== "") {
                    if (strlen($password) < 8) owner_message("New password must contain at least 8 characters.");
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        "UPDATE web_users
                         SET username = ?, patient_id = ?, device_id = ?,
                             valid_from = ?, valid_until = ?, password_hash = ?
                         WHERE id = ? AND role = 'patient'"
                    );
                    $stmt->bind_param(
                        "ssssssi",
                        $username, $patient_id, $device_id,
                        $valid_from, $valid_until, $password_hash, $login_id
                    );
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE web_users
                         SET username = ?, patient_id = ?, device_id = ?,
                             valid_from = ?, valid_until = ?
                         WHERE id = ? AND role = 'patient'"
                    );
                    $stmt->bind_param(
                        "sssssi",
                        $username, $patient_id, $device_id,
                        $valid_from, $valid_until, $login_id
                    );
                }
                $stmt->execute();
                $stmt->close();
                owner_message("Patient login account updated successfully.");
            }

            if (strlen($password) < 8) owner_message("New patient password must contain at least 8 characters.");
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role = "patient";
            $enabled = 1;
            $stmt = $conn->prepare(
                "INSERT INTO web_users
                 (username, password_hash, role, enabled, patient_id, device_id, valid_from, valid_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "sssissss",
                $username, $password_hash, $role, $enabled,
                $patient_id, $device_id, $valid_from, $valid_until
            );
            $stmt->execute();
            $stmt->close();
            owner_message("New patient login account created successfully.");
        }

        /* Enable or disable a patient login. */
        if ($action === "toggle_patient_login") {
            $login_id = (int)($_POST["login_id"] ?? 0);
            $new_status = filter_var($_POST["new_status"] ?? null, FILTER_VALIDATE_INT);
            if ($login_id <= 0 || !in_array($new_status, [0, 1], true)) {
                owner_message("Invalid patient login status request.");
            }
            $stmt = $conn->prepare(
                "UPDATE web_users SET enabled = ? WHERE id = ? AND role = 'patient'"
            );
            $stmt->bind_param("ii", $new_status, $login_id);
            $stmt->execute();
            $stmt->close();
            owner_message($new_status === 1 ? "Patient login enabled." : "Patient login disabled.");
        }

        /* Delete only the login row, never the patient or history. */
        if ($action === "delete_patient_login") {
            $login_id = (int)($_POST["login_id"] ?? 0);
            if ($login_id <= 0) owner_message("Invalid patient login account.");

            $stmt = $conn->prepare("DELETE FROM web_users WHERE id = ? AND role = 'patient'");
            $stmt->bind_param("i", $login_id);
            $stmt->execute();
            $stmt->close();
            owner_message("Patient login account deleted. Patient details and historical readings remain unchanged.");
        }

        owner_message("Unknown request.");

    } catch (Throwable $e) {
        error_log("ravi2_patient owner.php error: " . $e->getMessage());
        owner_message("The requested operation could not be completed. Please check the database table columns and server error log.");
    }
}

/*------------------------- Load login being edited -------------------------*/

$edit_login = null;
$edit_login_id = (int)($_GET["edit_login"] ?? 0);
$new_login_patient_id = trim((string)($_GET["new_login_for"] ?? ""));

if ($edit_login_id > 0) {
    $stmt = $conn->prepare(
        "SELECT id, username, patient_id, device_id, valid_from, valid_until
         FROM web_users WHERE id = ? AND role = 'patient' LIMIT 1"
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

/*------------------------- Load message and all registered patients -------------------------*/

$message = (string)($_SESSION["owner_message"] ?? "");
unset($_SESSION["owner_message"]);

$patients = [];
$result = $conn->query(
    "SELECT patient_id, full_name, age, gender, blood_group, contact, email, notes
     FROM patients ORDER BY patient_id"
);
while ($row = $result->fetch_assoc()) $patients[] = $row;

/*
 * Section 4: patients are the LEFT-side/base table.
 * A patient without a device therefore still gets a row.
 * A patient with multiple devices gets one row per device.
 */
$devices = [];
$result = $conn->query(
    "SELECT p.patient_id, p.full_name, d.device_id, d.description,
            d.enabled, d.start_date_time, d.end_date_time
     FROM patients p
     LEFT JOIN patient_devices d ON d.patient_id = p.patient_id
     ORDER BY p.patient_id, d.device_id"
);
while ($row = $result->fetch_assoc()) $devices[] = $row;

/*
 * Section 6: again, patients are the LEFT-side/base table.
 * Patients without a login get a placeholder row.
 * Old login rows with an empty patient_id are matched to the
 * patient currently assigned to their device as a compatibility fallback.
 */
$patient_logins = [];
$result = $conn->query(
    "SELECT p.patient_id, p.full_name,
            w.id, w.username, w.enabled, w.device_id,
            w.valid_from, w.valid_until
     FROM patients p
     LEFT JOIN web_users w
       ON w.role = 'patient'
      AND (
           w.patient_id = p.patient_id
           OR (
                (w.patient_id IS NULL OR w.patient_id = '')
                AND EXISTS (
                    SELECT 1 FROM patient_devices dx
                    WHERE dx.device_id = w.device_id
                      AND dx.patient_id = p.patient_id
                )
           )
      )
     ORDER BY p.patient_id, w.username"
);
while ($row = $result->fetch_assoc()) $patient_logins[] = $row;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Owner / Administrator Dashboard</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; background:#f3f5f7; margin:0; padding:20px; color:#222; }
.container { max-width:1250px; margin:auto; }
h1 { color:#17365d; }
h2 { background:#e7eef7; padding:12px; border-left:5px solid #245a91; margin-top:0; }
.section { background:#fff; padding:18px; margin-bottom:22px; border-radius:7px; box-shadow:0 1px 5px rgba(0,0,0,.08); }
label { display:block; font-weight:bold; margin-top:10px; }
input, select, textarea { width:100%; max-width:480px; padding:9px; margin-top:5px; border:1px solid #bbb; border-radius:4px; background:#fff; }
textarea { min-height:65px; }
button, .button { display:inline-block; padding:8px 13px; margin:5px 3px 5px 0; border:0; border-radius:4px; cursor:pointer; text-decoration:none; color:#fff; background:#245a91; font-size:14px; }
button:hover, .button:hover { opacity:.85; }
.green { background:#198754; } .red { background:#c0392b; } .orange { background:#d97706; } .grey { background:#555; }
.message { background:#e4f5e8; border:1px solid #8bc99a; padding:12px; border-radius:5px; margin:15px 0; }
.note { color:#555; font-size:13px; line-height:1.5; }
.status-enabled { color:#16803c; font-weight:bold; }
.status-disabled { color:#c0392b; font-weight:bold; }
.status-none { color:#777; font-style:italic; }
.small-form { display:inline; }
.top-links { margin-bottom:20px; }
.edit-panel { border:2px solid #d97706; padding:15px; border-radius:6px; background:#fffaf0; margin-bottom:15px; }
.table-wrap { width:100%; overflow-x:auto; }
table { width:100%; border-collapse:collapse; margin-top:12px; background:#fff; min-width:760px; }
th, td { border:1px solid #ddd; padding:9px; text-align:left; vertical-align:top; }
th { background:#e7eef7; }
.form-grid { display:grid; grid-template-columns:repeat(2,minmax(240px,1fr)); gap:12px 20px; max-width:1000px; }
.form-grid > div { min-width:0; }
.form-grid input, .form-grid select { max-width:100%; }
@media(max-width:750px) { body { padding:8px; } .form-grid { grid-template-columns:1fr; } .section { padding:12px; } }
</style>
</head>
<body>
<div class="container">

<h1>Owner / Administrator Dashboard</h1>
<p>Logged in as: <strong><?php echo owner_escape($_SESSION["owner_username"] ?? "Administrator"); ?></strong></p>
<div class="top-links">
    <a class="button grey" href="owner_logout.php">Logout</a>
    <a class="button" href="index.php">Patient Dashboard</a>
</div>

<?php if ($message !== ""): ?>
<div class="message"><?php echo owner_escape($message); ?></div>
<?php endif; ?>

<!-- SECTION 1 -->
<div class="section">
<h2>1. Register New Patient</h2>
<form method="post" action="owner.php">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="register_patient">
<div class="form-grid">
<div><label>Patient ID *</label><input type="text" name="patient_id" maxlength="50" placeholder="Example: P1004" required></div>
<div><label>Patient Full Name *</label><input type="text" name="full_name" maxlength="150" required></div>
<div><label>Age</label><input type="number" name="age" min="0" max="120"></div>
<div><label>Gender</label><input type="text" name="gender"></div>
<div><label>Blood Group</label><input type="text" name="blood_group"></div>
<div><label>Contact / Mobile Number</label><input type="text" name="contact"></div>
<div><label>Email Address</label><input type="email" name="email"></div>
<div><label>Notes</label><textarea name="notes"></textarea></div>
</div>
<button type="submit">Register Patient</button>
</form>
</div>

<!-- SECTION 2 -->
<div class="section">
<h2>2. Registered Patients - Select Patient ID</h2>
<?php if (!$patients): ?>
<p>No patients found. Register a patient in Section 1.</p>
<?php else: ?>
<div class="table-wrap"><table>
<tr><th>Patient ID</th><th>Name</th><th>Age</th><th>Gender</th><th>Blood Group</th><th>Contact</th><th>Email</th></tr>
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
</table></div>
<?php endif; ?>
</div>

<!-- SECTION 3 -->
<div class="section" id="section3">
<h2>3. Select Patient ID and Assign Device ID</h2>
<form method="post" action="owner.php">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="assign_device">
<div class="form-grid">
<div>
<label>Device ID *</label>
<input type="text" name="device_id" maxlength="100" placeholder="Example: ESP32-01" required>
<p class="note">Enter the exact Device ID configured in the ESP32 firmware.</p>
</div>
<div>
<label>Select Patient ID *</label>
<select name="device_patient_id" required>
<option value="">-- Select Patient --</option>
<?php foreach ($patients as $patient): ?>
<option value="<?php echo owner_escape($patient["patient_id"]); ?>"><?php echo owner_escape($patient["patient_id"] . " - " . $patient["full_name"]); ?></option>
<?php endforeach; ?>
</select>
</div>
<div><label>Device Description</label><input type="text" name="description" maxlength="255" placeholder="Example: ECG Patient Monitoring ESP32"></div>
<div><label>Assignment Start Date / Time</label><input type="datetime-local" name="start_date_time"></div>
<div><label>Assignment End Date / Time</label><input type="datetime-local" name="end_date_time"></div>
</div>
<p class="note">
For an existing Device ID, Save updates its patient assignment and dates. Its enabled/disabled status is preserved.
A reassignment does not automatically transfer patient login credentials. Historical monitoring and ECG records are not changed.
</p>
<button type="submit">Save Device Assignment</button>
</form>
</div>

<!-- SECTION 4: ALL PATIENTS, INCLUDING THOSE WITHOUT DEVICES -->
<div class="section">
<h2>4. Device Assignments - Enable / Disable</h2>
<p class="note">Every registered patient is listed. Patients without a device show “Not assigned”. If a patient has more than one device, each device is shown on its own row.</p>
<?php if (!$devices): ?>
<p>No registered patients found. Register patients in Section 1.</p>
<?php else: ?>
<div class="table-wrap"><table>
<tr><th>Patient ID</th><th>Patient Name</th><th>Device ID</th><th>Description</th><th>Status</th><th>Start</th><th>End</th><th>Actions</th></tr>
<?php foreach ($devices as $device): ?>
<tr>
<td><strong><?php echo owner_escape($device["patient_id"]); ?></strong></td>
<td><?php echo owner_escape($device["full_name"]); ?></td>
<td>
<?php if (!empty($device["device_id"])): ?>
<strong><?php echo owner_escape($device["device_id"]); ?></strong>
<?php else: ?><span class="status-none">Not assigned</span><?php endif; ?>
</td>
<td><?php echo owner_escape($device["description"] ?? ""); ?></td>
<td>
<?php if (empty($device["device_id"])): ?>
<span class="status-none">No device</span>
<?php elseif ((int)$device["enabled"] === 1): ?>
<span class="status-enabled">Enabled</span>
<?php else: ?>
<span class="status-disabled">Disabled</span>
<?php endif; ?>
</td>
<td><?php echo !empty($device["device_id"]) ? owner_date_display($device["start_date_time"]) : "—"; ?></td>
<td><?php echo !empty($device["device_id"]) ? owner_date_display($device["end_date_time"]) : "—"; ?></td>
<td>
<?php if (!empty($device["device_id"])): ?>
<form method="post" action="owner.php" class="small-form">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="toggle_device">
<input type="hidden" name="device_id" value="<?php echo owner_escape($device["device_id"]); ?>">
<?php if ((int)$device["enabled"] === 1): ?>
<input type="hidden" name="new_status" value="0"><button type="submit" class="orange">Disable</button>
<?php else: ?>
<input type="hidden" name="new_status" value="1"><button type="submit" class="green">Enable</button>
<?php endif; ?>
</form>
<form method="post" action="owner.php" class="small-form" onsubmit="return confirm('Delete this device assignment? Historical readings will remain.');">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="delete_device">
<input type="hidden" name="device_id" value="<?php echo owner_escape($device["device_id"]); ?>">
<button type="submit" class="red">Delete</button>
</form>
<?php else: ?>
<a class="button" href="#section3">Assign Device</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>

<!-- SECTION 5 -->
<div class="section" id="section5">
<h2>5. Create / Edit Patient Login Account</h2>
<?php if ($edit_login !== null): ?>
<div class="edit-panel">
<strong>Editing Patient Login Account</strong>
<p>Login ID: <?php echo (int)$edit_login["id"]; ?> &nbsp; | &nbsp; Username: <?php echo owner_escape($edit_login["username"]); ?></p>
<a class="button grey" href="owner.php">Cancel Editing</a>
</div>
<?php elseif ($new_login_patient_id !== ""): ?>
<div class="edit-panel">
<strong>Create a login for <?php echo owner_escape($new_login_patient_id); ?></strong>
<p>Choose an enabled device assigned to this patient. A patient without a device must first receive a device assignment.</p>
</div>
<?php endif; ?>

<form method="post" action="owner.php">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="save_patient_login">
<input type="hidden" name="login_id" value="<?php echo $edit_login ? (int)$edit_login["id"] : 0; ?>">
<div class="form-grid">
<div>
<label>Patient Login Username *</label>
<input type="text" name="username" maxlength="100" value="<?php echo owner_escape($edit_login["username"] ?? ""); ?>" required>
</div>
<div>
<label>Select Patient ID *</label>
<select name="login_patient_id" id="login_patient_id" required>
<option value="">-- Select Patient --</option>
<?php foreach ($patients as $patient): ?>
<?php $selectedPatient = $edit_login["patient_id"] ?? $new_login_patient_id; ?>
<option value="<?php echo owner_escape($patient["patient_id"]); ?>" <?php echo ($selectedPatient === $patient["patient_id"]) ? "selected" : ""; ?>>
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
$isEnabled = !empty($device["device_id"]) && (int)$device["enabled"] === 1;
$selectedDevice = $edit_login["device_id"] ?? "";
$isSelected = ($selectedDevice !== "" && $selectedDevice === $device["device_id"]);
?>
<?php if (!empty($device["device_id"]) && ($isEnabled || $isSelected)): ?>
<option value="<?php echo owner_escape($device["device_id"]); ?>"
data-patient="<?php echo owner_escape($device["patient_id"]); ?>"
<?php echo $isSelected ? "selected" : ""; ?>>
<?php echo owner_escape($device["device_id"] . " - " . $device["patient_id"] . " - " . $device["full_name"] . ($isEnabled ? "" : " (disabled - existing account)")); ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<p class="note">Only devices assigned to the selected patient will be shown.</p>
</div>
<div>
<label><?php echo $edit_login ? "New Password (optional)" : "Password *"; ?></label>
<input type="password" name="password" <?php echo $edit_login ? "" : "required"; ?> minlength="8" autocomplete="new-password">
<p class="note"><?php echo $edit_login ? "Leave blank to keep the existing password." : "Password must contain at least 8 characters."; ?></p>
</div>
<div><label>Login Valid From</label><input type="datetime-local" name="valid_from" value="<?php echo owner_escape(owner_date_input($edit_login["valid_from"] ?? "")); ?>"></div>
<div><label>Login Valid Until / Expiry</label><input type="datetime-local" name="valid_until" value="<?php echo owner_escape(owner_date_input($edit_login["valid_until"] ?? "")); ?>"></div>
</div>
<p class="note">A login account requires an enabled device assigned to that patient. Blank login validity dates mean no separate login date restriction.</p>
<?php if ($edit_login): ?>
<button type="submit" class="orange">Save Patient Login Changes</button><a href="owner.php" class="button grey">Cancel</a>
<?php else: ?>
<button type="submit">Create Patient Login</button>
<?php endif; ?>
</form>
</div>

<!-- SECTION 6: ALL PATIENTS, INCLUDING THOSE WITHOUT LOGINS -->
<div class="section">
<h2>6. Patient Login Accounts - Enable / Disable / Edit / Delete</h2>
<p class="note">Every registered patient is listed. Patients without an account show “No login created”. A patient may have more than one login account; each account is shown separately.</p>
<?php if (!$patient_logins): ?>
<p>No registered patients found. Register patients in Section 1.</p>
<?php else: ?>
<div class="table-wrap"><table>
<tr><th>Patient ID</th><th>Patient Name</th><th>Login ID</th><th>Username</th><th>Device ID</th><th>Status</th><th>Valid From</th><th>Valid Until</th><th>Actions</th></tr>
<?php foreach ($patient_logins as $login): ?>
<tr>
<td><strong><?php echo owner_escape($login["patient_id"]); ?></strong></td>
<td><?php echo owner_escape($login["full_name"]); ?></td>
<?php if (empty($login["id"])): ?>
<td>—</td>
<td><span class="status-none">No login created</span></td>
<td>—</td><td>—</td><td>—</td><td>—</td>
<td>
<?php
$patientHasDevice = false;
foreach ($devices as $d) {
    if ($d["patient_id"] === $login["patient_id"] && !empty($d["device_id"])) {
        $patientHasDevice = true;
        break;
    }
}
?>
<?php if ($patientHasDevice): ?>
<a class="button green" href="owner.php?new_login_for=<?php echo rawurlencode($login["patient_id"]); ?>#section5">Create Login</a>
<?php else: ?>
<span class="status-none">Assign device first</span>
<?php endif; ?>
</td>
<?php else: ?>
<td><?php echo (int)$login["id"]; ?></td>
<td><?php echo owner_escape($login["username"]); ?></td>
<td><?php echo owner_escape($login["device_id"]); ?></td>
<td>
<?php if ((int)$login["enabled"] === 1): ?><span class="status-enabled">Enabled</span>
<?php else: ?><span class="status-disabled">Disabled</span><?php endif; ?>
</td>
<td><?php echo owner_date_display($login["valid_from"]); ?></td>
<td><?php echo owner_date_display($login["valid_until"]); ?></td>
<td>
<a class="button orange" href="owner.php?edit_login=<?php echo (int)$login["id"]; ?>#section5">Edit</a>
<form method="post" action="owner.php" class="small-form">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="toggle_patient_login">
<input type="hidden" name="login_id" value="<?php echo (int)$login["id"]; ?>">
<?php if ((int)$login["enabled"] === 1): ?>
<input type="hidden" name="new_status" value="0"><button type="submit" class="orange">Disable</button>
<?php else: ?>
<input type="hidden" name="new_status" value="1"><button type="submit" class="green">Enable</button>
<?php endif; ?>
</form>
<form method="post" action="owner.php" class="small-form" onsubmit="return confirm('Delete this patient login account? Patient records and historical readings will remain.');">
<input type="hidden" name="csrf_token" value="<?php echo owner_escape($csrf_token); ?>">
<input type="hidden" name="action" value="delete_patient_login">
<input type="hidden" name="login_id" value="<?php echo (int)$login["id"]; ?>">
<button type="submit" class="red">Delete</button>
</form>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>

<div class="section">
<strong>Owner / Administrator Dashboard</strong><br>
<a class="button grey" href="owner_logout.php">Logout</a>
</div>

</div>

<script>
/*
 * Filter the device dropdown according to the selected patient.
 * The PHP server validates the patient/device relationship again
 * when the form is submitted.
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
            placeholder: option.value === ""
        };
    });

    function filterDevices() {
        const selectedPatient = patientSelect.value;
        const previousDevice = deviceSelect.value;
        deviceSelect.innerHTML = "";

        allOptions.forEach(function (item) {
            if (item.placeholder || (selectedPatient !== "" && item.patient === selectedPatient)) {
                const option = document.createElement("option");
                option.value = item.value;
                option.textContent = item.text;
                if (item.patient) option.setAttribute("data-patient", item.patient);
                deviceSelect.appendChild(option);
            }
        });

        const canKeep = Array.from(deviceSelect.options).some(function (option) {
            return option.value === previousDevice;
        });
        deviceSelect.value = canKeep ? previousDevice : "";
    }

    patientSelect.addEventListener("change", filterDevices);
    filterDevices();
})();
</script>
</body>
</html>
