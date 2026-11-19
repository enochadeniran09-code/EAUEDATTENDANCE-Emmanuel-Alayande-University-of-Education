
<?php
require_once '../config/db.php';

// Preserve the QR token
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Only accept a valid QR token
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $token = '';
}

$error = "";

// Display any account deactivation message
if (isset($_SESSION['student_login_error'])) {
    $error = $_SESSION['student_login_error'];
    unset($_SESSION['student_login_error']);
}

// If student is already logged in
if (isset($_SESSION['student_id'])) {

    // Check current account status
    $statusStmt = $pdo->prepare(
        "SELECT status FROM students WHERE id = ? LIMIT 1"
    );

    $statusStmt->execute([$_SESSION['student_id']]);
    $currentStudent = $statusStmt->fetch();

    if (!$currentStudent || $currentStudent['status'] !== 'active') {

        unset(
            $_SESSION['student_id'],
            $_SESSION['student_name'],
            $_SESSION['matric_number']
        );

        session_regenerate_id(true);

        $error = "Your account has been deactivated. Please contact the administrator.";

    } else {

        if ($token !== '') {
            header("Location: scan.php?token=" . urlencode($token));
        } else {
            header("Location: dashboard.php");
        }

        exit;
    }
}

$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $matric = strtoupper(trim($_POST['matric_number'] ?? ''));
    $pass = $_POST['password'] ?? '';

    if ($matric === '' || $pass === '') {

        $error = "Please enter your matric number and password.";

    }
    elseif (!preg_match('/^EAUED[0-9]{4,}$/', $matric)) {

        $error = "Invalid matric number. Use the format EAUED0001 or above.";

    }
    else {

        $number = (int) substr($matric, 5);

        if ($number < 1) {

            $error = "Invalid matric number.";

        } else {

            $stmt = $pdo->prepare(
                "SELECT * FROM students
                 WHERE matric_number = ?
                 LIMIT 1"
            );

            $stmt->execute([$matric]);

            $student = $stmt->fetch();

            if (
                $student &&
                password_verify($pass, $student['password'])
            ) {

                // Block inactive student accounts
                if ($student['status'] !== 'active') {

                    $error = "Your account has been deactivated. Please contact the administrator.";

                } else {

                    session_regenerate_id(true);

                    $_SESSION['student_id'] = $student['id'];
                    $_SESSION['student_name'] = $student['first_name'];
                    $_SESSION['matric_number'] = $student['matric_number'];

                    // Preserve QR attendance redirect
                    if ($token !== '') {

                        header(
                            "Location: scan.php?token=" . urlencode($token)
                        );

                    } else {

                        header("Location: dashboard.php");

                    }

                    exit;
                }

            } else {

                $error = "Incorrect matric number or password.";

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

    <title>Student Login | SmartAttend</title>

    <!-- Correct favicon path -->
    <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">

    <link rel="stylesheet"
          href="../assets/css/student.css">

</head>

<body>

<div class="auth-container">

    <div class="auth-card login-card">

        <div class="brand">

            <div class="brand-icon">SA</div>

            <h1>SmartAttend</h1>

            <p>Student Attendance Management System</p>

        </div>

        <h2>Welcome Back!</h2>

        <p class="subtitle">
            Login to access your student account.
        </p>

        <?php if ($flash): ?>

            <div class="alert <?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="alert error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <!-- Preserve QR token during login -->
            <input type="hidden"
                   name="token"
                   value="<?= e($token) ?>">

            <div class="form-group">

                <label>Matric Number</label>

                <input type="text"
                       name="matric_number"
                       placeholder="Enter your matric number"
                       value="<?= e($_POST['matric_number'] ?? '') ?>"
                       required>

            </div>

            <div class="form-group">

                <label>Password</label>

                <div class="password-box">

                    <input type="password"
                           name="password"
                           id="login_password"
                           placeholder="Enter your password"
                           required>

                    <button type="button"
                            class="toggle-password"
                            data-toggle-password="login_password">
                        Show
                    </button>

                </div>

            </div>

            <button type="submit" class="primary-btn">
                Login
            </button>

        </form>

        <div class="auth-footer">

            <p>
                Don't have an account?
                <a href="register.php">Register here</a>
            </p>

            <p>
                <a href="../index.php">
                    Back to main page
                </a>
            </p>

        </div>

    </div>

</div>

<script src="../assets/js/auth.js"></script>

</body>
</html>
