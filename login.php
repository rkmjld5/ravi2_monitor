<?php
/*
=========================================================
 PROJECT : ravi2_patient.onrender.com
 FILE    : login.php

 PURPOSE :
 1. Patient enters username and password.
 2. Verify existing patient account.
 3. Check patient and device enabled status.
 4. Check account and device validity dates.
 5. Start patient session.
 6. Open patient dashboard.

 DATABASE TABLES:
 web_users
 patients
 patient_devices

 No database records are modified.
=========================================================
*/

require_once "config.php";

// -------------------------------------------------------
// 1. INITIAL SETTINGS
// -------------------------------------------------------

$error = "";

// Create a security token for the login form.
if (empty($_SESSION['ravi2_login_token'])) {
    $_SESSION['ravi2_login_token'] = bin2hex(random_bytes(32));
}

// -------------------------------------------------------
// 2. CHECK DATE VALIDITY
// -------------------------------------------------------

// NULL start date means no start-date restriction.
// NULL end date means no expiry restriction.

function dateIsValid($start, $end)
{
    $now = time();

    if ($start !== null && $start !== "") {
        if (strtotime($start) > $now) {
            return false;
        }
    }

    if ($end !== null && $end !== "") {
        if (strtotime($end) < $now) {
            return false;
        }
    }

    return true;
}

// -------------------------------------------------------
// 3. PROCESS LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Check security token.
    if (
        !isset($_POST['login_token']) ||
        !hash_equals(
            $_SESSION['ravi2_login_token'],
            $_POST['login_token']
        )
    ) {
        $error = "Invalid request. Please try again.";
    }

    else {

        // Read entered username and password.
        $username = trim($_POST['username'] ?? "");
        $password = $_POST['password'] ?? "";

        if ($username === "" || $password === "") {

            $error = "Please enter username and password.";

        } else {

            // ------------------------------------------------
            // 4. FIND PATIENT LOGIN ACCOUNT
            // ------------------------------------------------

            $sql = "SELECT
                        id,
                        username,
                        patient_id,
                        password_hash,
                        enabled,
                        device_id,
                        valid_from,
                        valid_until
                    FROM web_users
                    WHERE username = ?
                    LIMIT 1";

            $stmt = $db->prepare($sql);

            if (!$stmt) {
                error_log("Patient login query preparation failed.");
                $error = "Unable to process login. Please try again.";
            }

            else {

                $stmt->bind_param("s", $username);
                $stmt->execute();

                $stmt->bind_result(
                    $user_id,
                    $db_username,
                    $patient_id,
                    $password_hash,
                    $enabled,
                    $device_id,
                    $valid_from,
                    $valid_until
                );

                $account_found = $stmt->fetch();

                $stmt->close();

                // --------------------------------------------
                // 5. VERIFY USERNAME AND PASSWORD
                // --------------------------------------------

                if (
                    !$account_found ||
                    !password_verify($password, $password_hash)
                ) {

                    $error = "Incorrect username or password.";

                }

                // --------------------------------------------
                // 6. CHECK ACCOUNT ENABLED STATUS
                // --------------------------------------------

                elseif ((int)$enabled !== 1) {

                    $error = "Your account is disabled. Contact administrator.";

                }

                // --------------------------------------------
                // 7. CHECK ACCOUNT VALIDITY DATES
                // --------------------------------------------

                elseif (!dateIsValid($valid_from, $valid_until)) {

                    $error = "Your account is not currently valid.";

                }

                // --------------------------------------------
                // 8. CHECK PATIENT INFORMATION
                // --------------------------------------------

                elseif (empty($patient_id) || empty($device_id)) {

                    $error = "No patient or device assignment found.";

                }

                else {

                    $patient_name = "";

                    $sql = "SELECT full_name
                            FROM patients
                            WHERE patient_id = ?
                            LIMIT 1";

                    $stmt = $db->prepare($sql);

                    if (!$stmt) {

                        error_log("Patient information query failed.");
                        $error = "Unable to verify patient information.";

                    } else {

                        $stmt->bind_param("s", $patient_id);
                        $stmt->execute();

                        $stmt->bind_result($patient_name);

                        $patient_found = $stmt->fetch();

                        $stmt->close();

                        if (!$patient_found) {

                            $error = "Patient record not found.";

                        }
                    }

                    // ----------------------------------------
                    // 9. CHECK ASSIGNED ESP32 DEVICE
                    // ----------------------------------------

                    if ($error === "") {

                        $device_enabled = 0;
                        $device_start = null;
                        $device_end = null;

                        $sql = "SELECT
                                    enabled,
                                    start_date_time,
                                    end_date_time
                                FROM patient_devices
                                WHERE device_id = ?
                                AND patient_id = ?
                                LIMIT 1";

                        $stmt = $db->prepare($sql);

                        if (!$stmt) {

                            error_log("Device verification query failed.");
                            $error = "Unable to verify device.";

                        } else {

                            $stmt->bind_param(
                                "ss",
                                $device_id,
                                $patient_id
                            );

                            $stmt->execute();

                            $stmt->bind_result(
                                $device_enabled,
                                $device_start,
                                $device_end
                            );

                            $device_found = $stmt->fetch();

                            $stmt->close();

                            if (!$device_found) {

                                $error = "Assigned device not found.";

                            }

                            elseif ((int)$device_enabled !== 1) {

                                $error = "Your monitoring device is disabled.";

                            }

                            elseif (
                                !dateIsValid($device_start, $device_end)
                            ) {

                                $error = "Your device assignment is not currently valid.";

                            }
                        }
                    }

                    // ----------------------------------------
                    // 10. LOGIN SUCCESSFUL
                    // ----------------------------------------

                    if ($error === "") {

                        // Change session ID after successful login.
                        session_regenerate_id(true);

                        // Store patient login information.
                        $_SESSION['ravi2_patient_logged_in'] = true;

                        $_SESSION['ravi2_user_id'] = $user_id;

                        $_SESSION['ravi2_username'] = $db_username;

                        $_SESSION['ravi2_patient_id'] = $patient_id;

                        $_SESSION['ravi2_patient_name'] = $patient_name;

                        $_SESSION['ravi2_device_id'] = $device_id;

                        // Remove login security token.
                        unset($_SESSION['ravi2_login_token']);

                        // Open patient dashboard.
                        header("Location: index.php");
                        exit;
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Patient Login</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f2f5f9;
            margin: 0;
            padding: 0;
        }

        .login-box {
            max-width: 380px;
            margin: 80px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.10);
        }

        h2 {
            text-align: center;
            color: #23466b;
        }

        label {
            display: block;
            margin-top: 18px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-top: 7px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            width: 100%;
            padding: 12px;
            margin-top: 25px;
            background: #1769aa;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        button:hover {
            background: #104d80;
        }

        .error {
            color: #b00020;
            background: #ffe9e9;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
        }

        .footer {
            text-align: center;
            color: #777;
            font-size: 12px;
            margin-top: 20px;
        }

    </style>

</head>

<body>

<div class="login-box">

    <h2>Patient Monitoring System</h2>

    <p style="text-align:center;">
        Patient Login
    </p>

    <?php if ($error !== ""): ?>

        <div class="error">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="login.php">

        <input
            type="hidden"
            name="login_token"
            value="<?php echo htmlspecialchars(
                $_SESSION['ravi2_login_token']
            ); ?>"
        >

        <label>Username</label>

        <input
            type="text"
            name="username"
            maxlength="50"
            required
            autocomplete="username"
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">
            Login
        </button>

    </form>

    <div class="footer">
        Patient Monitoring System
    </div>

</div>

</body>
</html>
