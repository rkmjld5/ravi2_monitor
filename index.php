
<?php
/*
    PROJECT: ravi2_patient
    FILE: index.php

    PURPOSE:
    Main patient monitoring dashboard.

    FEATURES:
    1. Displays the latest monitoring readings.
    2. Displays the latest 10 patient alerts.
    3. Allows the patient to acknowledge individual alerts.
    4. Records acknowledgement date and time.
    5. Refreshes automatically every 5 seconds.
    6. Checks patient and device authorization.
    7. Provides navigation to other patient pages.

    IMPORTANT:
    - Only the logged-in patient can acknowledge their own alerts.
    - Existing alert records are never deleted.
    - P1001 records and historical alerts are not modified.
    - Uses mysqli.
*/

// --------------------------------------------------
// 1. CHECK PATIENT LOGIN AND ACCESS PERMISSION
// --------------------------------------------------

require_once __DIR__ . "/patient_access.php";

// patient_access.php loads config.php and starts
// the authorized patient session.

// --------------------------------------------------
// 2. GET PATIENT INFORMATION FROM SESSION
// --------------------------------------------------

$patient_id   = $_SESSION["ravi2_patient_id"];
$patient_name = $_SESSION["ravi2_patient_name"];
$device_id    = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// 3. CREATE CSRF TOKEN
// --------------------------------------------------

/*
   This token protects the acknowledgement form.

   It prevents another website from submitting an
   acknowledgement request using the patient's login.
*/

if (empty($_SESSION["dashboard_csrf_token"])) {

    $_SESSION["dashboard_csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrf_token = $_SESSION["dashboard_csrf_token"];

// --------------------------------------------------
// 4. PROCESS ACKNOWLEDGEMENT REQUEST
// --------------------------------------------------

$acknowledge_error = "";
$acknowledge_success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Check the CSRF token first.

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $acknowledge_error =
            "Security check failed. Please refresh the page and try again.";

    } else {

        // Read the alert ID submitted by the button.

        $posted_alert_id = $_POST["alert_id"] ?? "";

        if (
            !is_scalar($posted_alert_id) ||
            !ctype_digit((string)$posted_alert_id) ||
            (int)$posted_alert_id < 1
        ) {

            $acknowledge_error = "Invalid alert selected.";

        } else {

            $posted_alert_id = (int)$posted_alert_id;

            /*
               IMPORTANT SECURITY CHECK:

               The UPDATE includes patient_id.

               Therefore, a patient cannot acknowledge
               another patient's alert by changing the ID.
            */

            $ack_sql = "
                UPDATE alerts
                SET
                    acknowledged = 1,
                    acknowledged_at = NOW()
                WHERE
                    id = ?
                    AND patient_id = ?
                    AND acknowledged = 0
            ";

            $ack_stmt = $conn->prepare($ack_sql);

            if (!$ack_stmt) {

                error_log(
                    "Alert acknowledgement prepare error: " .
                    $conn->error
                );

                $acknowledge_error =
                    "Unable to acknowledge the alert. Please try again.";

            } else {

                $ack_stmt->bind_param(
                    "is",
                    $posted_alert_id,
                    $patient_id
                );

                if (!$ack_stmt->execute()) {

                    error_log(
                        "Alert acknowledgement execution error: " .
                        $ack_stmt->error
                    );

                    $acknowledge_error =
                        "Unable to acknowledge the alert. Please try again.";

                } elseif ($ack_stmt->affected_rows === 1) {

                    // The selected alert was acknowledged.

                    $acknowledge_success =
                        "Alert acknowledged successfully.";

                } else {

                    /*
                       No row was changed.

                       The alert may already be acknowledged,
                       or it may not belong to this patient.
                    */

                    $acknowledge_error =
                        "This alert is already acknowledged or is not available.";
                }

                $ack_stmt->close();
            }
        }
    }

    /*
       Redirect after processing the POST request.

       This prevents the browser from submitting the
       same acknowledgement again when the page refreshes.

       The message is stored temporarily in the session.
    */

    if ($acknowledge_success !== "") {

        $_SESSION["dashboard_ack_message"] =
            $acknowledge_success;

    } elseif ($acknowledge_error !== "") {

        $_SESSION["dashboard_ack_error"] =
            $acknowledge_error;
    }

    header("Location: index.php");
    exit;
}

// --------------------------------------------------
// 5. GET ACKNOWLEDGEMENT MESSAGE AFTER REDIRECT
// --------------------------------------------------

if (isset($_SESSION["dashboard_ack_message"])) {

    $acknowledge_success =
        $_SESSION["dashboard_ack_message"];

    unset($_SESSION["dashboard_ack_message"]);
}

if (isset($_SESSION["dashboard_ack_error"])) {

    $acknowledge_error =
        $_SESSION["dashboard_ack_error"];

    unset($_SESSION["dashboard_ack_error"]);
}

// --------------------------------------------------
// 6. PREPARE VARIABLES FOR DISPLAY
// --------------------------------------------------

$reading = null;
$alerts = [];

$reading_error = "";
$alert_error = "";

// --------------------------------------------------
// 7. GET THE LATEST MONITORING READING
// --------------------------------------------------

$reading_sql = "
    SELECT
        id,
        room_temperature,
        humidity,
        bpm,
        spo2,
        body_temperature,
        mode,
        recorded_at
    FROM monitoring_readings
    WHERE
        patient_id = ?
        AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
";

$reading_stmt = $conn->prepare($reading_sql);

if (!$reading_stmt) {

    error_log("Dashboard reading query error: " . $conn->error);

    $reading_error = "Unable to load monitoring readings.";

} else {

    $reading_stmt->bind_param("ss", $patient_id, $device_id);

    if (!$reading_stmt->execute()) {

        error_log(
            "Dashboard reading execution error: " .
            $reading_stmt->error
        );

        $reading_error = "Unable to load monitoring readings.";

    } else {

        $reading_stmt->bind_result(
            $reading_id,
            $room_temperature,
            $humidity,
            $bpm,
            $spo2,
            $body_temperature,
            $mode,
            $recorded_at
        );

        if ($reading_stmt->fetch()) {

            $reading = [
                "id" => $reading_id,
                "room_temperature" => $room_temperature,
                "humidity" => $humidity,
                "bpm" => $bpm,
                "spo2" => $spo2,
                "body_temperature" => $body_temperature,
                "mode" => $mode,
                "recorded_at" => $recorded_at
            ];
        }
    }

    $reading_stmt->close();
}

// --------------------------------------------------
// 8. GET THE LATEST 10 PATIENT ALERTS
// --------------------------------------------------

$alert_sql = "
    SELECT
        id,
        parameter_name,
        measured_value,
        alert_message,
        recorded_at,
        device_id,
        acknowledged,
        acknowledged_at
    FROM alerts
    WHERE patient_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 10
";

$alert_stmt = $conn->prepare($alert_sql);

if (!$alert_stmt) {

    error_log("Dashboard alert query error: " . $conn->error);

    $alert_error = "Unable to load alerts.";

} else {

    $alert_stmt->bind_param("s", $patient_id);

    if (!$alert_stmt->execute()) {

        error_log(
            "Dashboard alert execution error: " .
            $alert_stmt->error
        );

        $alert_error = "Unable to load alerts.";

    } else {

        $alert_stmt->bind_result(
            $alert_id,
            $parameter_name,
            $measured_value,
            $alert_message,
            $alert_recorded_at,
            $alert_device_id,
            $acknowledged,
            $acknowledged_at
        );

        while ($alert_stmt->fetch()) {

            $alerts[] = [
                "id" => $alert_id,
                "parameter_name" => $parameter_name,
                "measured_value" => $measured_value,
                "alert_message" => $alert_message,
                "recorded_at" => $alert_recorded_at,
                "device_id" => $alert_device_id,
                "acknowledged" => $acknowledged,
                "acknowledged_at" => $acknowledged_at
            ];
        }
    }

    $alert_stmt->close();
}

// --------------------------------------------------
// 9. SAFE OUTPUT FUNCTION
// --------------------------------------------------

function dashboard_escape($value) {

    if ($value === null || $value === "") {
        return "--";
    }

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// --------------------------------------------------
// 10. DISPLAY A READING VALUE
// --------------------------------------------------

function show_reading($value, $unit = "") {

    if ($value === null || $value === "") {
        return "--";
    }

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    ) . $unit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta http-equiv="refresh" content="5">

    <title>Patient Monitoring Dashboard</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f1f4f8;
            margin: 0;
            color: #263238;
        }

        .header {
            background: #1769aa;
            color: white;
            padding: 18px 20px;
        }

        .header h1 {
            margin: 0;
            font-size: 23px;
        }

        .header p {
            margin: 6px 0 0;
            font-size: 14px;
        }

        .container {
            width: 94%;
            max-width: 1150px;
            margin: 22px auto;
        }

        .patient-info {
            background: white;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07);
        }

        .patient-info h2 {
            margin: 0 0 12px;
            color: #1769aa;
        }

        .patient-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 14px;
        }

        .section-title {
            margin: 25px 0 14px;
            font-size: 20px;
            color: #263b55;
        }

        .reading-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .reading-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07);
            border-left: 4px solid #1769aa;
        }

        .reading-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .reading-value {
            font-size: 28px;
            font-weight: bold;
            color: #1769aa;
        }

        .reading-unit {
            font-size: 14px;
            color: #777;
        }

        .reading-time {
            margin-top: 18px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
        }

        .message {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .success-message {
            background: #d9f2e3;
            color: #146c43;
            border-left: 4px solid #198754;
        }

        .error-message {
            background: #fce8e8;
            color: #a12626;
            border-left: 4px solid #c0392b;
        }

        .alert-card {
            background: white;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 4px solid #d9534f;
            box-shadow: 0 2px 5px rgba(0,0,0,0.06);
        }

        .alert-card.acknowledged {
            border-left-color: #198754;
        }

        .alert-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 7px;
        }

        .alert-message {
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .alert-details {
            color: #777;
            font-size: 12px;
            line-height: 1.7;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-pending {
            background: #fff0d5;
            color: #8a5700;
        }

        .status-done {
            background: #d9f2e3;
            color: #146c43;
        }

        .acknowledge-form {
            margin-top: 14px;
        }

        .acknowledge-button {
            background: #198754;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 17px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }

        .acknowledge-button:hover {
            background: #146c43;
        }

        .navigation {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 22px 0;
        }

        .navigation a {
            display: inline-block;
            background: #1769aa;
            color: white;
            text-decoration: none;
            padding: 11px 16px;
            border-radius: 5px;
            font-size: 14px;
        }

        .navigation a:hover {
            background: #10558c;
        }

        .navigation a.logout {
            background: #b52b35;
        }

        .navigation a.logout:hover {
            background: #8f1f28;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 12px;
        }

        @media (max-width: 700px) {

            .reading-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .header h1 {
                font-size: 19px;
            }
        }

        @media (max-width: 420px) {

            .reading-grid {
                grid-template-columns: 1fr;
            }

            .patient-details {
                gap: 10px;
            }
        }

    </style>

</head>

<body>

    <div class="header">

        <h1>Patient Monitoring System</h1>

        <p>Patient Dashboard</p>

    </div>


    <div class="container">

        <!-- PATIENT INFORMATION -->

        <div class="patient-info">

            <h2>
                Welcome, <?php echo dashboard_escape($patient_name); ?>
            </h2>

            <div class="patient-details">

                <div>
                    <strong>Patient ID:</strong>
                    <?php echo dashboard_escape($patient_id); ?>
                </div>

                <div>
                    <strong>Device ID:</strong>
                    <?php echo dashboard_escape($device_id); ?>
                </div>

                <div>
                    <strong>Account:</strong>
                    Active
                </div>

            </div>

        </div>


        <!-- LATEST MONITORING READINGS -->

        <h2 class="section-title">
            Latest Monitoring Readings
        </h2>

        <?php if ($reading_error !== ""): ?>

            <div class="message">
                <?php echo dashboard_escape($reading_error); ?>
            </div>

        <?php elseif ($reading === null): ?>

            <div class="message">
                No monitoring readings are available yet.
                Please check that your ESP32 device is connected.
            </div>

        <?php else: ?>

            <div class="reading-grid">

                <div class="reading-card">

                    <div class="reading-label">
                        Heart Rate
                    </div>

                    <div class="reading-value">
                        <?php echo show_reading($reading["bpm"]); ?>
                    </div>

                    <div class="reading-unit">
                        BPM
                    </div>

                </div>


                <div class="reading-card">

                    <div class="reading-label">
                        Blood Oxygen (SpO2)
                    </div>

                    <div class="reading-value">
                        <?php echo show_reading($reading["spo2"]); ?>
                    </div>

                    <div class="reading-unit">
                        %
                    </div>

                </div>


                <div class="reading-card">

                    <div class="reading-label">
                        Body Temperature
                    </div>

                    <div class="reading-value">
                        <?php echo show_reading($reading["body_temperature"]); ?>
                    </div>

                    <div class="reading-unit">
                        °C
                    </div>

                </div>


                <div class="reading-card">

                    <div class="reading-label">
                        Room Temperature
                    </div>

                    <div class="reading-value">
                        <?php echo show_reading($reading["room_temperature"]); ?>
                    </div>

                    <div class="reading-unit">
                        °C
                    </div>

                </div>


                <div class="reading-card">

                    <div class="reading-label">
                        Humidity
                    </div>

                    <div class="reading-value">
                        <?php echo show_reading($reading["humidity"]); ?>
                    </div>

                    <div class="reading-unit">
                        %
                    </div>

                </div>


                <div class="reading-card">

                    <div class="reading-label">
                        Device Mode
                    </div>

                    <div class="reading-value" style="font-size:22px;">
                        <?php echo dashboard_escape($reading["mode"]); ?>
                    </div>

                    <div class="reading-unit">
                        ESP32 monitoring mode
                    </div>

                </div>

            </div>


            <div class="reading-time">

                <strong>Last Reading:</strong>

                <?php echo dashboard_escape($reading["recorded_at"]); ?>

            </div>

        <?php endif; ?>


        <!-- PATIENT WEB ALERTS -->

        <h2 class="section-title">
            Latest Web Alerts
        </h2>

        <?php if ($acknowledge_success !== ""): ?>

            <div class="message success-message">
                <?php echo dashboard_escape($acknowledge_success); ?>
            </div>

        <?php endif; ?>


        <?php if ($acknowledge_error !== ""): ?>

            <div class="message error-message">
                <?php echo dashboard_escape($acknowledge_error); ?>
            </div>

        <?php endif; ?>


        <?php if ($alert_error !== ""): ?>

            <div class="message">
                <?php echo dashboard_escape($alert_error); ?>
            </div>

        <?php elseif (count($alerts) === 0): ?>

            <div class="message">
                No alerts are available.
            </div>

        <?php else: ?>

            <?php foreach ($alerts as $alert): ?>

                <div class="alert-card <?php
                    echo ((int)$alert["acknowledged"] === 1)
                        ? "acknowledged"
                        : "";
                ?>">

                    <div class="alert-title">

                        <?php
                            echo dashboard_escape(
                                $alert["parameter_name"]
                            );
                        ?>

                        &nbsp;

                        <?php if ((int)$alert["acknowledged"] === 1): ?>

                            <span class="status status-done">
                                Acknowledged
                            </span>

                        <?php else: ?>

                            <span class="status status-pending">
                                Not Acknowledged
                            </span>

                        <?php endif; ?>

                    </div>


                    <div class="alert-message">

                        <?php
                            echo dashboard_escape(
                                $alert["alert_message"]
                            );
                        ?>

                    </div>


                    <div class="alert-details">

                        <strong>Measured Value:</strong>

                        <?php
                            echo dashboard_escape(
                                $alert["measured_value"]
                            );
                        ?>

                        <br>

                        <strong>Device:</strong>

                        <?php
                            echo dashboard_escape(
                                $alert["device_id"]
                            );
                        ?>

                        <br>

                        <strong>Alert Time:</strong>

                        <?php
                            echo dashboard_escape(
                                $alert["recorded_at"]
                            );
                        ?>


                        <?php if ((int)$alert["acknowledged"] === 1): ?>

                            <br>

                            <strong>Acknowledged At:</strong>

                            <?php
                                echo dashboard_escape(
                                    $alert["acknowledged_at"]
                                );
                            ?>

                        <?php endif; ?>

                    </div>


                    <!-- SHOW BUTTON ONLY FOR PENDING ALERTS -->

                    <?php if ((int)$alert["acknowledged"] !== 1): ?>

                        <form
                            method="POST"
                            action="index.php"
                            class="acknowledge-form"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?php
                                    echo dashboard_escape($csrf_token);
                                ?>"
                            >

                            <input
                                type="hidden"
                                name="alert_id"
                                value="<?php
                                    echo (int)$alert["id"];
                                ?>"
                            >

                            <button
                                type="submit"
                                class="acknowledge-button"
                            >
                                Acknowledge Alert
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>


        <!-- NAVIGATION LINKS -->

        <div class="navigation">

            <a href="history.php">
                Reading History
            </a>

            <a href="ecg_history.php">
                ECG History
            </a>

            <a href="alerts.php">
                All Alerts
            </a>

            <a href="logout.php" class="logout">
                Logout
            </a>

        </div>

    </div>


    <div class="footer">

        Ravi2 Patient Monitoring System

        <br>

        Dashboard refreshes automatically every 5 seconds.

    </div>

</body>
</html>
