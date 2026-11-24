<?php
/*
=========================================================
 PROJECT : ravi2_patient
 FILE    : alerts.php

 PURPOSE :
 1. Display alerts for the logged-in patient.
 2. Read alerts from the existing alerts table.
 3. Show the latest 100 alerts.
 4. Show acknowledged / not acknowledged status.
 5. Protect the page using patient_access.php.

 IMPORTANT :
 - Web dashboard alerts only.
 - No Firebase.
 - No mobile push notifications.
 - No SMS or WhatsApp.
 - No database tables are changed.
=========================================================
*/


// -------------------------------------------------------
// 1. CHECK PATIENT LOGIN AND ACCESS
// -------------------------------------------------------

// This file checks the patient account and device status.
// It also loads config.php and provides the $conn variable.

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. GET LOGGED-IN PATIENT INFORMATION
// -------------------------------------------------------

$patient_id   = $_SESSION["ravi2_patient_id"];
$patient_name = $_SESSION["ravi2_patient_name"];
$device_id    = $_SESSION["ravi2_device_id"];


// -------------------------------------------------------
// 3. INITIALIZE VARIABLES
// -------------------------------------------------------

$alerts = [];

$alert_error = "";


// -------------------------------------------------------
// 4. READ ALERTS FROM DATABASE
// -------------------------------------------------------

/*
 IMPORTANT:

 We search using patient_id.

 This ensures that one patient cannot see another
 patient's alerts.

 We display the latest 100 alerts.
*/

$sql = "
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
    LIMIT 100
";

$stmt = $conn->prepare($sql);


// Check whether SQL preparation was successful.
if (!$stmt) {

    error_log("Alerts page SQL error: " . $conn->error);

    $alert_error = "Unable to load alerts. Please try again later.";

} else {

    // Search only for this logged-in patient.
    $stmt->bind_param("s", $patient_id);

    // Execute the query.
    if (!$stmt->execute()) {

        error_log("Alerts page execution error: " . $stmt->error);

        $alert_error = "Unable to load alerts. Please try again later.";

    } else {

        // Variables for database result.
        $alert_id = 0;
        $parameter_name = "";
        $measured_value = "";
        $alert_message = "";
        $recorded_at = "";
        $alert_device_id = "";
        $acknowledged = 0;
        $acknowledged_at = "";

        // Connect database columns to PHP variables.
        $stmt->bind_result(
            $alert_id,
            $parameter_name,
            $measured_value,
            $alert_message,
            $recorded_at,
            $alert_device_id,
            $acknowledged,
            $acknowledged_at
        );

        // Read each alert.
        while ($stmt->fetch()) {

            // Save the current alert.
            $alerts[] = [
                "id" => $alert_id,
                "parameter_name" => $parameter_name,
                "measured_value" => $measured_value,
                "alert_message" => $alert_message,
                "recorded_at" => $recorded_at,
                "device_id" => $alert_device_id,
                "acknowledged" => $acknowledged,
                "acknowledged_at" => $acknowledged_at
            ];
        }
    }

    // Close the prepared statement.
    $stmt->close();
}


// -------------------------------------------------------
// 5. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

/*
 This function protects the webpage from unsafe HTML.
 All database text is escaped before display.
*/

function alert_escape($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 6. COUNT ALERTS
// -------------------------------------------------------

$total_alerts = count($alerts);

$unacknowledged_count = 0;

foreach ($alerts as $alert) {

    if ((int)$alert["acknowledged"] !== 1) {
        $unacknowledged_count++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Patient Alerts</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .header {
            background: #ffffff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        h2 {
            margin-top: 0;
            color: #1f3b5b;
        }

        .patient-info {
            line-height: 1.8;
        }

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-box {
            background: white;
            padding: 18px;
            border-radius: 8px;
            flex: 1;
            min-width: 150px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            margin-top: 8px;
        }

        .alert-table-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th {
            background: #24476b;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }

        tr:hover {
            background: #f7f9fc;
        }

        .status-new {
            color: #b42318;
            font-weight: bold;
        }

        .status-acknowledged {
            color: #16803c;
            font-weight: bold;
        }

        .no-alerts {
            padding: 25px;
            text-align: center;
            color: #16803c;
            font-size: 17px;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .navigation {
            margin-bottom: 20px;
        }

        .navigation a {
            display: inline-block;
            background: #24476b;
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            margin: 4px 5px 4px 0;
        }

        .navigation a:hover {
            background: #16324f;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
            font-size: 13px;
        }

        @media (max-width: 600px) {

            body {
                padding: 10px;
            }

            .summary-box {
                min-width: 120px;
            }

        }

    </style>

</head>


<body>

<div class="container">


    <!-- ---------------------------------------------
         PATIENT INFORMATION
    ---------------------------------------------- -->

    <div class="header">

        <h2>Patient Alert History</h2>

        <div class="patient-info">

            <strong>Patient Name:</strong>
            <?php echo alert_escape($patient_name); ?>

            <br>

            <strong>Patient ID:</strong>
            <?php echo alert_escape($patient_id); ?>

            <br>

            <strong>Device ID:</strong>
            <?php echo alert_escape($device_id); ?>

            <br>

            <strong>Time Zone:</strong>
            Asia/Kolkata

        </div>

    </div>


    <!-- ---------------------------------------------
         NAVIGATION
    ---------------------------------------------- -->

    <div class="navigation">

        <a href="index.php">Dashboard</a>

        <a href="history.php">Reading History</a>

        <a href="ecg_history.php">ECG History</a>

        <a href="logout.php">Logout</a>

    </div>


    <!-- ---------------------------------------------
         ALERT SUMMARY
    ---------------------------------------------- -->

    <div class="summary">

        <div class="summary-box">

            Total Alerts

            <div class="summary-number">
                <?php echo $total_alerts; ?>
            </div>

        </div>


        <div class="summary-box">

            Unacknowledged Alerts

            <div class="summary-number"
                 style="color:#b42318;">

                <?php echo $unacknowledged_count; ?>

            </div>

        </div>

    </div>


    <!-- ---------------------------------------------
         DISPLAY DATABASE ERROR IF ANY
    ---------------------------------------------- -->

    <?php if ($alert_error !== ""): ?>

        <div class="error">
            <?php echo alert_escape($alert_error); ?>
        </div>

    <?php endif; ?>


    <!-- ---------------------------------------------
         ALERT TABLE
    ---------------------------------------------- -->

    <div class="alert-table-box">

        <h3>Latest Patient Alerts</h3>


        <?php if ($alert_error === "" && $total_alerts === 0): ?>

            <div class="no-alerts">

                No alerts are available for this patient.

            </div>


        <?php elseif ($alert_error === ""): ?>


            <table>

                <thead>

                    <tr>

                        <th>Alert ID</th>

                        <th>Date / Time</th>

                        <th>Parameter</th>

                        <th>Measured Value</th>

                        <th>Alert Message</th>

                        <th>Device ID</th>

                        <th>Status</th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach ($alerts as $alert): ?>

                        <tr>

                            <td>
                                <?php
                                echo alert_escape($alert["id"]);
                                ?>
                            </td>


                            <td>
                                <?php
                                echo alert_escape($alert["recorded_at"]);
                                ?>
                            </td>


                            <td>
                                <strong>
                                    <?php
                                    echo alert_escape(
                                        $alert["parameter_name"]
                                    );
                                    ?>
                                </strong>
                            </td>


                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["measured_value"]
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["alert_message"]
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["device_id"]
                                );
                                ?>
                            </td>


                            <td>

                                <?php if (
                                    (int)$alert["acknowledged"] === 1
                                ): ?>

                                    <span class="status-acknowledged">
                                        Acknowledged
                                    </span>

                                    <?php if (
                                        !empty($alert["acknowledged_at"])
                                    ): ?>

                                        <br>

                                        <small>
                                            <?php
                                            echo alert_escape(
                                                $alert["acknowledged_at"]
                                            );
                                            ?>
                                        </small>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="status-new">
                                        Not Acknowledged
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>


        <?php endif; ?>

    </div>


    <!-- ---------------------------------------------
         FOOTER
    ---------------------------------------------- -->

    <div class="footer">

        Patient Monitoring System

        <br>

        All displayed times use Indian Standard Time.

    </div>


</div>

</body>

</html>
