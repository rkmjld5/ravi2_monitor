<?php
/*
=========================================================
 PROJECT : ravi2_patient
 FILE    : owner_login.php

 PURPOSE :
 1. Separate Administrator / Owner login.
 2. Verify username and password from owner_users.
 3. Start a secure owner session.
 4. Open the owner dashboard after successful login.

 IMPORTANT :
 - Uses existing owner_users table.
 - Does not create or change owner accounts.
 - Patient login remains separate.
=========================================================
*/

require_once __DIR__ . "/config.php";

// If owner is already logged in, open owner dashboard.
if (!empty($_SESSION["owner_logged_in"])) {
    header("Location: owner.php");
    exit;
}

// Create security token.
if (empty($_SESSION["owner_login_token"])) {
    $_SESSION["owner_login_token"] = bin2hex(random_bytes(32));
}

$error_message = "";

// -------------------------------------------------------
// HANDLE LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["owner_login_token"], $submitted_token)
    ) {

        $error_message = "Invalid request. Please refresh the page.";

    } else {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($username === "" || $password === "") {

            $error_message = "Please enter username and password.";

        } else {

            // Find the owner account.
            $sql = "
                SELECT
                    id,
                    username,
                    password_hash,
                    full_name
                FROM owner_users
                WHERE username = ?
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {

                error_log("Owner login SQL error: " . $conn->error);
                $error_message = "Unable to process login. Please try again.";

            } else {

                $stmt->bind_param("s", $username);
                $stmt->execute();

                $stmt->bind_result(
                    $owner_id,
                    $owner_username,
                    $password_hash,
                    $owner_full_name
                );

                $account_found = $stmt->fetch();

                $stmt->close();

                // Verify the password.
                if (
                    $account_found &&
                    password_verify($password, $password_hash)
                ) {

                    // Prevent session fixation.
                    session_regenerate_id(true);

                    // Save owner login information.
                    $_SESSION["owner_logged_in"] = true;
                    $_SESSION["owner_id"] = $owner_id;
                    $_SESSION["owner_username"] = $owner_username;
                    $_SESSION["owner_full_name"] = $owner_full_name;

                    // Remove old login token.
                    unset($_SESSION["owner_login_token"]);

                    // Open Administrator dashboard.
                    header("Location: owner.php");
                    exit;

                } else {

                    $error_message = "Incorrect owner username or password.";
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
          content="width=device-width, initial-scale=1.0">

    <title>Administrator Login</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f1f4f8;
            margin: 0;
            padding: 20px;
        }

        .login-box {
            max-width: 420px;
            margin: 70px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.12);
        }

        h2 {
            text-align: center;
            color: #24476b;
            margin-top: 0;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 7px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        button {
            width: 100%;
            padding: 12px;
            margin-top: 22px;
            background: #24476b;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #16324f;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .footer {
            text-align: center;
            color: #777;
            font-size: 13px;
            margin-top: 20px;
        }

    </style>

</head>

<body>

<div class="login-box">

    <h2>Administrator Login</h2>

    <div class="subtitle">
        Patient Monitoring System
    </div>

    <?php if ($error_message !== ""): ?>

        <div class="error">
            <?php
            echo htmlspecialchars(
                $error_message,
                ENT_QUOTES,
                "UTF-8"
            );
            ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="owner_login.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php
            echo htmlspecialchars(
                $_SESSION["owner_login_token"],
                ENT_QUOTES,
                "UTF-8"
            );
            ?>"
        >

        <label for="username">Owner Username</label>

        <input
            type="text"
            id="username"
            name="username"
            required
            autocomplete="username"
        >

        <label for="password">Owner Password</label>

        <input
            type="password"
            id="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">
            Owner Login
        </button>

    </form>

    <div class="footer">
        Administrator / Owner Access Only
    </div>

</div>

</body>
</html>
