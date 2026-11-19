
<?php
require_once __DIR__ . '/../config/auth.php';

redirect_user_dashboard();

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (
        authenticate_user(
            $pdo,
            $email,
            $password,
            'admin'
        )
    ) {

        header("Location: dashboard.php");
        exit;

    } else {

        $error = "Invalid administrator email or password.";

    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administrator Login | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f2f7f3;
            font-family: Arial, Helvetica, sans-serif;
        }

        .admin-login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 16px;
            background:
                radial-gradient(circle at top left, rgba(35, 131, 78, 0.10), transparent 35%),
                #f2f7f3;
        }

        .admin-login-card {
            width: 100%;
            max-width: 440px;
            padding: 38px 35px;
            background: #ffffff;
            border: 1px solid #e2ebe4;
            border-radius: 15px;
            box-shadow: 0 12px 35px rgba(20, 70, 43, 0.09);
        }

        .admin-login-brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .admin-login-logo {
            width: 62px;
            height: 62px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #176b40;
            color: #ffffff;
            border-radius: 13px;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: 1px;
            box-shadow: 0 5px 13px rgba(23, 107, 64, 0.20);
        }

        .admin-login-brand h1 {
            margin: 0;
            color: #173d2c;
            font-size: 25px;
            font-weight: 800;
        }

        .admin-login-brand p {
            margin: 7px 0 0;
            color: #718078;
            font-size: 13px;
        }

        .admin-login-heading {
            text-align: center;
            margin-bottom: 25px;
        }

        .admin-login-heading h2 {
            margin: 0 0 9px;
            color: #203d2d;
            font-size: 21px;
        }

        .admin-login-heading p {
            margin: 0;
            color: #78877e;
            font-size: 13px;
            line-height: 1.6;
        }

        .admin-login-error {
            padding: 13px 15px;
            margin-bottom: 20px;
            background: #fff1f0;
            border: 1px solid #f0c7c4;
            border-left: 4px solid #d94343;
            border-radius: 7px;
            color: #a32c2c;
            font-size: 13px;
            line-height: 1.5;
        }

        .admin-login-form .form-group {
            margin-bottom: 20px;
        }

        .admin-login-form label {
            display: block;
            margin-bottom: 8px;
            color: #294637;
            font-size: 13px;
            font-weight: 700;
        }

        .admin-login-form input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #dce7df;
            border-radius: 7px;
            background: #fbfdfb;
            color: #263b30;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .admin-login-form input:focus {
            border-color: #23834e;
            box-shadow: 0 0 0 3px rgba(35, 131, 78, 0.10);
        }

        .admin-login-password {
            position: relative;
        }

        .admin-login-password input {
            padding-right: 75px;
        }

        .admin-login-password .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #187345;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            padding: 5px;
        }

        .admin-login-button {
            width: 100%;
            padding: 14px;
            margin-top: 5px;
            border: none;
            border-radius: 7px;
            background: #176b40;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .admin-login-button:hover {
            background: #105331;
            transform: translateY(-1px);
        }

        .admin-login-motto {
            margin-top: 23px;
            text-align: center;
            color: #829087;
            font-size: 11px;
            letter-spacing: 0.3px;
        }

        @media (max-width: 480px) {

            .admin-login-card {
                padding: 30px 22px;
            }

            .admin-login-brand h1 {
                font-size: 23px;
            }

            .admin-login-heading h2 {
                font-size: 19px;
            }

        }

    </style>

</head>

<body>

<div class="admin-login-wrapper">

    <div class="admin-login-card">

        <div class="admin-login-brand">

            <div class="admin-login-logo">
                SA
            </div>

            <h1>SmartAttend</h1>

            <p>Administrator Portal</p>

        </div>

        <div class="admin-login-heading">

            <h2>Administrator Login</h2>

            <p>
                Sign in to manage the attendance system.
            </p>

        </div>

        <?php if ($error): ?>

            <div class="admin-login-error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST"
              action=""
              class="admin-login-form">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <div class="form-group">

                <label for="admin_email">
                    Email Address
                </label>

                <input type="email"
                       id="admin_email"
                       name="email"
                       placeholder="Enter administrator email"
                       autocomplete="username"
                       required>

            </div>

            <div class="form-group">

                <label for="admin_password">
                    Password
                </label>

                <div class="admin-login-password">

                    <input type="password"
                           id="admin_password"
                           name="password"
                           placeholder="Enter password"
                           autocomplete="current-password"
                           required>

                    <button type="button"
                            class="toggle-password"
                            data-toggle-password="admin_password">
                        Show
                    </button>

                </div>

            </div>

            <button type="submit"
                    class="admin-login-button">
                Login as Administrator
            </button>

        </form>

        <p class="auth-footer">
            <a href="../index.php">Back to main page</a>
        </p>

        <div class="admin-login-motto">
            Knowledge, Truth and Service
        </div>

    </div>

</div>

<script src="../assets/js/auth.js"></script>

</body>
</html>
