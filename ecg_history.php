<?php
/*
    ECG HISTORY PAGE
    Project: ravi2_patient

    Features:
    1. Checks patient login and authorization.
    2. Reads ECG records from ecg_readings.
    3. Searches by start and end date/time.
    4. Displays ECG waveform, sample rate and sample count.
    5. Downloads ECG graph as PNG.
    6. Downloads ECG report as PDF.
    7. Downloads original ECG samples as CSV.

    IMPORTANT:
    This page does not change or delete database records.
*/

// Check patient login and authorization first.
require_once "patient_access.php";

date_default_timezone_set("Asia/Kolkata");

// Patient and device details from verified session.
$patientId = $_SESSION["ravi2_patient_id"];
$patientName = $_SESSION["ravi2_patient_name"];
$deviceId = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// DATE AND TIME SEARCH
// --------------------------------------------------

$startInput = trim($_GET["start_datetime"] ?? "");
$endInput   = trim($_GET["end_datetime"] ?? "");

$startDateTime = "1000-01-01 00:00:00";
$endDateTime   = "9999-12-31 23:59:59";

$errorMessage = "";

function convertDateTime($value)
{
    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d\TH:i", $value);

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

if ($startInput !== "") {
    $converted = convertDateTime($startInput);

    if ($converted === false) {
        $errorMessage = "Please enter a valid start date and time.";
    } else {
        $startDateTime = $converted;
    }
}

if ($endInput !== "" && $errorMessage === "") {
    $converted = convertDateTime($endInput);

    if ($converted === false) {
        $errorMessage = "Please enter a valid end date and time.";
    } else {
        $endDateTime = $converted;
    }
}

if ($errorMessage === "" && $startDateTime > $endDateTime) {
    $errorMessage = "Start date and time cannot be later than end date and time.";
}

// --------------------------------------------------
// GET ECG RECORDS
// --------------------------------------------------

$records = [];

if ($errorMessage === "") {

    // Both patient_id and device_id are checked.
    $sql = "
        SELECT
            id,
            samples_json,
            mode,
            recorded_at,
            sample_rate,
            sample_count
        FROM ecg_readings
        WHERE patient_id = ?
          AND device_id = ?
          AND recorded_at >= ?
          AND recorded_at <= ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        $errorMessage = "Unable to prepare the ECG history query.";
    } else {

        mysqli_stmt_bind_param(
            $stmt,
            "ssss",
            $patientId,
            $deviceId,
            $startDateTime,
            $endDateTime
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = "Unable to retrieve ECG records.";
        } else {

            mysqli_stmt_bind_result(
                $stmt,
                $id,
                $samplesJson,
                $mode,
                $recordedAt,
                $sampleRate,
                $sampleCount
            );

            while (mysqli_stmt_fetch($stmt)) {

                $records[] = [
                    "id" => $id,
                    "samples_json" => $samplesJson,
                    "mode" => $mode,
                    "recorded_at" => $recordedAt,
                    "sample_rate" => $sampleRate,
                    "sample_count" => $sampleCount
                ];
            }
        }

        mysqli_stmt_close($stmt);
    }
}

// --------------------------------------------------
// PREPARE ECG SAMPLES
// --------------------------------------------------

function getEcgSamples($json)
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    // Support common ECG JSON formats.
    if (isset($data["ecg_samples"]) && is_array($data["ecg_samples"])) {
        $data = $data["ecg_samples"];
    } elseif (isset($data["samples"]) && is_array($data["samples"])) {
        $data = $data["samples"];
    } elseif (isset($data["data"]) && is_array($data["data"])) {
        $data = $data["data"];
    }

    $samples = [];

    foreach ($data as $value) {
        if (is_numeric($value)) {
            $samples[] = (float)$value;
        }
    }

    return $samples;
}

// --------------------------------------------------
// HTML PAGE
// --------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ECG History</title>

<!-- jsPDF library for creating PDF reports in the browser -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<style>
body {
    font-family: Arial, sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 15px;
    color: #222;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.header, .search-box, .record {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.header h2, .record h3 {
    color: #174a75;
}

.header h2 {
    margin-top: 0;
}

.navigation {
    margin-top: 15px;
}

.navigation a {
    display: inline-block;
    margin: 5px 8px 5px 0;
    padding: 9px 14px;
    background: #174a75;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.navigation a:hover {
    background: #103653;
}

.search-box h3 {
    margin-top: 0;
}

.search-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.search-field {
    flex: 1;
    min-width: 220px;
}

.search-field label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

.search-field input {
    width: 100%;
    padding: 10px;
    border: 1px solid #bbb;
    border-radius: 5px;
    box-sizing: border-box;
}

button {
    padding: 10px 15px;
    border: none;
    color: white;
    border-radius: 5px;
    cursor: pointer;
    margin: 6px 5px 6px 0;
}

button:hover {
    opacity: 0.85;
}

.search-button {
    background: #16804a;
    margin-top: 15px;
}

.clear-button {
    display: inline-block;
    margin-left: 8px;
    padding: 11px 15px;
    background: #666;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.record {
    overflow: hidden;
}

.record h3 {
    margin-top: 0;
}

.record-details {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.detail {
    background: #f1f5f8;
    padding: 10px;
    border-radius: 5px;
}

.waveform {
    width: 100%;
    height: 160px;
    display: block;
    background: #fffafa;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.no-waveform {
    color: #777;
    padding: 15px;
    background: #f7f7f7;
    border-radius: 5px;
}

.download-buttons {
    margin-top: 15px;
    padding-top: 12px;
    border-top: 1px solid #ddd;
}

.png-button {
    background: #1769aa;
}

.pdf-button {
    background: #c62828;
}

.csv-button {
    background: #16804a;
}

.error {
    background: #ffe8e8;
    color: #a00000;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.success {
    background: #e8f5e9;
    color: #17652a;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.empty {
    background: white;
    padding: 25px;
    text-align: center;
    border-radius: 8px;
    color: #666;
}

@media (max-width: 600px) {
    body {
        padding: 8px;
    }

    .record {
        padding: 12px;
    }

    .waveform {
        height: 130px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">

        <h2>ECG History</h2>

        <p>
            <strong>Patient:</strong>
            <?php echo htmlspecialchars($patientName); ?>
        </p>

        <p>
            <strong>Patient ID:</strong>
            <?php echo htmlspecialchars($patientId); ?>
        </p>

        <p>
            <strong>Device ID:</strong>
            <?php echo htmlspecialchars($deviceId); ?>
        </p>

        <div class="navigation">
            <a href="index.php">Dashboard</a>
            <a href="history.php">Vital History</a>
            <a href="alerts.php">Alerts</a>
            <a href="logout.php">Logout</a>
        </div>

    </div>

    <div class="search-box">

        <h3>Search ECG by Date and Time</h3>

        <form method="GET" action="ecg_history.php">

            <div class="search-row">

                <div class="search-field">
                    <label for="start_datetime">Start Date and Time</label>

                    <input
                        type="datetime-local"
                        id="start_datetime"
                        name="start_datetime"
                        value="<?php echo htmlspecialchars($startInput); ?>"
                    >
                </div>

                <div class="search-field">
                    <label for="end_datetime">End Date and Time</label>

                    <input
                        type="datetime-local"
                        id="end_datetime"
                        name="end_datetime"
                        value="<?php echo htmlspecialchars($endInput); ?>"
                    >
                </div>

            </div>

            <button class="search-button" type="submit">Search ECG</button>

            <a class="clear-button" href="ecg_history.php">Show All</a>

        </form>

        <p>
            Leave both fields blank to search all available ECG records.
            The page displays the latest 20 matching records.
        </p>

    </div>

    <?php if ($errorMessage !== ""): ?>

        <div class="error">
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>

    <?php else: ?>

        <div class="success">
            ECG records found: <?php echo count($records); ?>
        </div>

        <?php if (count($records) === 0): ?>

            <div class="empty">
                No ECG records found for this date and time range.
            </div>

        <?php else: ?>

            <?php foreach ($records as $record): ?>

                <?php
                    $samples = getEcgSamples($record["samples_json"]);

                    // Display up to 2000 samples in the graph.
                    $displaySamples = array_slice($samples, 0, 2000);

                    $samplesForJavascript = json_encode(
                        $displaySamples,
                        JSON_HEX_TAG |
                        JSON_HEX_APOS |
                        JSON_HEX_QUOT |
                        JSON_HEX_AMP
                    );

                    if ($samplesForJavascript === false) {
                        $samplesForJavascript = "[]";
                    }

                    $recordId = (string)$record["id"];
                ?>

                <div
                    class="record"
                    data-record-id="<?php echo htmlspecialchars($recordId); ?>"
                    data-patient-id="<?php echo htmlspecialchars($patientId); ?>"
                    data-patient-name="<?php echo htmlspecialchars($patientName); ?>"
                    data-device-id="<?php echo htmlspecialchars($deviceId); ?>"
                    data-recorded-at="<?php echo htmlspecialchars((string)$record["recorded_at"]); ?>"
                    data-sample-rate="<?php echo htmlspecialchars((string)($record["sample_rate"] ?? "N/A")); ?>"
                    data-sample-count="<?php echo htmlspecialchars((string)($record["sample_count"] ?? count($samples))); ?>"
                    data-mode="<?php echo htmlspecialchars((string)($record["mode"] ?? "N/A")); ?>"
                >

                    <h3>ECG Record #<?php echo htmlspecialchars($recordId); ?></h3>

                    <div class="record-details">

                        <div class="detail">
                            <strong>Recorded At:</strong><br>
                            <?php echo htmlspecialchars((string)$record["recorded_at"]); ?>
                        </div>

                        <div class="detail">
                            <strong>Sample Rate:</strong><br>
                            <?php echo htmlspecialchars((string)($record["sample_rate"] ?? "N/A")); ?> Hz
                        </div>

                        <div class="detail">
                            <strong>Sample Count:</strong><br>
                            <?php echo htmlspecialchars((string)($record["sample_count"] ?? count($samples))); ?>
                        </div>

                        <div class="detail">
                            <strong>Mode:</strong><br>
                            <?php echo htmlspecialchars((string)($record["mode"] ?? "N/A")); ?>
                        </div>

                    </div>

                    <strong>ECG Waveform</strong>

                    <?php if (count($displaySamples) > 0): ?>

                        <canvas
                            class="waveform"
                            data-values="<?php
                                echo htmlspecialchars(
                                    $samplesForJavascript,
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                            ?>"
                        ></canvas>

                        <p>
                            <small>
                                Displaying <?php echo count($displaySamples); ?>
                                numeric ECG samples.
                            </small>
                        </p>

                        <!-- Three download buttons for this ECG recording -->
                        <div class="download-buttons">

                            <strong>Download this ECG recording:</strong><br>

                            <button type="button" class="png-button"
                                onclick="downloadPNG(this)">
                                Download PNG
                            </button>

                            <button type="button" class="pdf-button"
                                onclick="downloadPDF(this)">
                                Download PDF
                            </button>

                            <button type="button" class="csv-button"
                                onclick="downloadCSV(this)">
                                Download CSV
                            </button>

                        </div>

                    <?php else: ?>

                        <div class="no-waveform">
                            ECG samples could not be displayed.
                            The stored JSON does not contain a supported
                            numeric sample array.
                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>

// --------------------------------------------------
// DRAW ECG WAVEFORMS
// --------------------------------------------------

function drawWaveforms() {

    document.querySelectorAll(".waveform").forEach(function(canvas) {

        let samples = [];

        try {
            samples = JSON.parse(canvas.dataset.values);
        } catch (error) {
            return;
        }

        if (!Array.isArray(samples) || samples.length < 2) {
            return;
        }

        const context = canvas.getContext("2d");

        const width = canvas.clientWidth;
        const height = canvas.clientHeight;

        if (width <= 0 || height <= 0) {
            return;
        }

        const pixelRatio = window.devicePixelRatio || 1;

        canvas.width = width * pixelRatio;
        canvas.height = height * pixelRatio;

        context.scale(pixelRatio, pixelRatio);

        // Find minimum and maximum values safely.
        let minimum = samples[0];
        let maximum = samples[0];

        samples.forEach(function(value) {
            minimum = Math.min(minimum, value);
            maximum = Math.max(maximum, value);
        });

        if (minimum === maximum) {
            minimum -= 1;
            maximum += 1;
        }

        const padding = 12;
        const usableHeight = height - (padding * 2);
        const usableWidth = width - (padding * 2);

        // Draw ECG background grid.
        context.strokeStyle = "#f0dede";
        context.lineWidth = 0.5;

        for (let x = 0; x < width; x += 20) {
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, height);
            context.stroke();
        }

        for (let y = 0; y < height; y += 20) {
            context.beginPath();
            context.moveTo(0, y);
            context.lineTo(width, y);
            context.stroke();
        }

        // Draw ECG line.
        context.beginPath();
        context.strokeStyle = "#cc2222";
        context.lineWidth = 1.5;

        samples.forEach(function(value, index) {

            const x = padding +
                (index / (samples.length - 1)) * usableWidth;

            const y = padding +
                ((maximum - value) / (maximum - minimum)) * usableHeight;

            if (index === 0) {
                context.moveTo(x, y);
            } else {
                context.lineTo(x, y);
            }
        });

        context.stroke();

    });
}

// Draw the graphs after the page has loaded.
window.addEventListener("load", drawWaveforms);


// --------------------------------------------------
// GET RECORD INFORMATION
// --------------------------------------------------

function getRecordInformation(button) {

    const record = button.closest(".record");

    const canvas = record.querySelector(".waveform");

    let samples = [];

    try {
        samples = JSON.parse(canvas.dataset.values);
    } catch (error) {
        alert("Unable to read ECG sample values.");
        return null;
    }

    return {
        record: record,
        canvas: canvas,
        samples: samples,
        id: record.dataset.recordId,
        patientId: record.dataset.patientId,
        patientName: record.dataset.patientName,
        deviceId: record.dataset.deviceId,
        recordedAt: record.dataset.recordedAt,
        sampleRate: record.dataset.sampleRate,
        sampleCount: record.dataset.sampleCount,
        mode: record.dataset.mode
    };
}


// --------------------------------------------------
// DOWNLOAD 1: ECG GRAPH AS PNG
// --------------------------------------------------

function downloadPNG(button) {

    const info = getRecordInformation(button);

    if (!info) {
        return;
    }

    // Export the actual ECG canvas as a PNG image.
    const image = info.canvas.toDataURL("image/png");

    const link = document.createElement("a");

    link.href = image;

    link.download = "ECG_Record_" + info.id + ".png";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);
}


// --------------------------------------------------
// DOWNLOAD 2: ECG REPORT AS PDF
// --------------------------------------------------

function downloadPDF(button) {

    const info = getRecordInformation(button);

    if (!info) {
        return;
    }

    // Check that the PDF library has loaded.
    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert(
            "PDF library could not be loaded. " +
            "Please check your internet connection and try again."
        );
        return;
    }

    const { jsPDF } = window.jspdf;

    // Create an A4 PDF document.
    const pdf = new jsPDF("p", "mm", "a4");

    // Report heading.
    pdf.setFontSize(18);
    pdf.text("ECG Recording Report", 15, 18);

    pdf.setFontSize(11);

    // Patient details.
    pdf.text("Patient Name: " + info.patientName, 15, 32);
    pdf.text("Patient ID: " + info.patientId, 15, 40);
    pdf.text("Device ID: " + info.deviceId, 15, 48);

    // Recording details.
    pdf.text("ECG Record ID: " + info.id, 15, 60);
    pdf.text("Recorded At: " + info.recordedAt, 15, 68);
    pdf.text("Sample Rate: " + info.sampleRate + " Hz", 15, 76);
    pdf.text("Sample Count: " + info.sampleCount, 15, 84);
    pdf.text("Mode: " + info.mode, 15, 92);

    // Add the ECG graph to the PDF.
    const graphImage = info.canvas.toDataURL("image/png");

    pdf.setFontSize(13);
    pdf.text("ECG Waveform", 15, 108);

    pdf.addImage(
        graphImage,
        "PNG",
        15,
        115,
        180,
        65
    );

    // Add a note about the recording.
    pdf.setFontSize(9);
    pdf.text(
        "This report contains the stored ECG sample waveform.",
        15,
        192
    );

    pdf.text(
        "For clinical interpretation, consult a qualified medical professional.",
        15,
        199
    );

    // Save the PDF file.
    pdf.save("ECG_Record_" + info.id + ".pdf");
}


// --------------------------------------------------
// DOWNLOAD 3: ORIGINAL ECG SAMPLES AS CSV
// --------------------------------------------------

function downloadCSV(button) {

    const info = getRecordInformation(button);

    if (!info) {
        return;
    }

    if (!Array.isArray(info.samples) || info.samples.length === 0) {
        alert("No numeric ECG samples are available for CSV download.");
        return;
    }

    // Prepare CSV content.
    let csv = "";

    // First section: recording details.
    csv += "ECG Recording Details\n";
    csv += "Patient Name," + csvEscape(info.patientName) + "\n";
    csv += "Patient ID," + csvEscape(info.patientId) + "\n";
    csv += "Device ID," + csvEscape(info.deviceId) + "\n";
    csv += "Record ID," + csvEscape(info.id) + "\n";
    csv += "Recorded At," + csvEscape(info.recordedAt) + "\n";
    csv += "Sample Rate," + csvEscape(info.sampleRate) + "\n";
    csv += "Sample Count," + csvEscape(info.sampleCount) + "\n";
    csv += "Mode," + csvEscape(info.mode) + "\n";

    // Blank line before sample values.
    csv += "\n";

    // Second section: original numeric ECG samples.
    csv += "Sample Number,ECG Value\n";

    info.samples.forEach(function(value, index) {

        csv += (index + 1) + "," + value + "\n";

    });

    // Create a downloadable CSV file.
    const blob = new Blob(
        ["\uFEFF" + csv],
        { type: "text/csv;charset=utf-8;" }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;

    link.download = "ECG_Record_" + info.id + ".csv";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}


// Escape text fields for CSV format.
function csvEscape(value) {

    let text = String(value ?? "");

    return '"' + text.replace(/"/g, '""') + '"';
}

</script>

</body>
</html>
