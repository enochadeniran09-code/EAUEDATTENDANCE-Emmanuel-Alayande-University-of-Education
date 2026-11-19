
<?php

// Load authentication and database connection
require_once __DIR__ . '/../config/auth.php';

// Redirect already logged-in users
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
            'lecturer'
        )
    ) {

        header("Location: dashboard.php");
        exit;

    } else {

        $error = "Invalid lecturer email or password.";

    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lecturer Login | SmartAttend</title>
     <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">

    <!-- Reuse the established SmartAttend styling -->
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="icon" href="assets/eaued logo.png" type="image/png" sizes="16x16">
</head>

<body>

<div class="auth-container">

    <div class="auth-card login-card">

        <div class="brand">
            <div class="brand-icon">SA</div>

            <h1>SmartAttend</h1>

            <p>Lecturer Portal</p>
        </div>

        <h2>Lecturer Login</h2>

        <p class="subtitle">
            Sign in to manage your student attendance.
        </p>

        <?php if ($error): ?>
            <div class="alert error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="form-group">

                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter lecturer email"
                    autocomplete="username"
                    required
                >

            </div>

            <div class="form-group">

                <label for="lecturer_password">Password</label>

                <div class="password-box">

                    <input
                        type="password"
                        id="lecturer_password"
                        name="password"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        data-toggle-password="lecturer_password"
                    >
                        Show
                    </button>

                </div>

            </div>

            <button type="submit" class="primary-btn">
                Login as Lecturer
            </button>

        </form>

        <p class="auth-footer">
            <a href="../index.php">Back to main page</a>
        </p>

    </div>

</div>

<script src="../assets/js/auth.js"></script>

</body>
</html>
