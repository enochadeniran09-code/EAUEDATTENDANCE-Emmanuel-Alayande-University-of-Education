
<?php
require_once __DIR__ . '/../config/db.php';

// Make sure the student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$studentId = (int) $_SESSION['student_id'];

$message = '';
$messageType = '';

// Generate a CSRF token for this page
if (empty($_SESSION['change_password_csrf'])) {
    $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
}

// Confirm that the student account is active
$studentQuery = $pdo->prepare("
    SELECT id, first_name, status, password
    FROM students
    WHERE id = ?
    LIMIT 1
");

$studentQuery->execute([$studentId]);
$student = $studentQuery->fetch(PDO::FETCH_ASSOC);

if (!$student || $student['status'] !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['change_password_csrf'], $submittedToken)
    ) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'error';

    } else {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $message = 'Please fill in all password fields.';
            $messageType = 'error';

        } elseif (!password_verify($currentPassword, $student['password'])) {
            $message = 'Your current password is incorrect.';
            $messageType = 'error';

        } elseif (strlen($newPassword) < 8) {
            $message = 'Your new password must contain at least 8 characters.';
            $messageType = 'error';

        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Your new passwords do not match.';
            $messageType = 'error';

        } elseif (password_verify($newPassword, $student['password'])) {
            $message = 'Your new password must be different from your current password.';
            $messageType = 'error';

        } else {

            try {
                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $updatePassword = $pdo->prepare("
                    UPDATE students
                    SET password = ?
                    WHERE id = ?
                ");

                $updatePassword->execute([
                    $hashedPassword,
                    $studentId
                ]);

                // Update the locally loaded password
                $student['password'] = $hashedPassword;

                // Generate a fresh CSRF token
                $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));

                $message = 'Your password has been changed successfully.';
                $messageType = 'success';

            } catch (PDOException $e) {
                error_log('Student password change error: ' . $e->getMessage());

                $message = 'Unable to change your password. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

$studentName = $student['first_name'] ?? 'Student';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <title>Change Password | SmartAttend</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --green: #145c39;
            --dark-green: #103c29;
            --main-green: #197447;
            --light-green: #e7f3eb;
            --gold: #c9a227;
            --white: #ffffff;
            --background: #f4f7f5;
            --text: #26352c;
            --muted: #718078;
            --border: #dfe8e1;
        }

        body {
            font-family: Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .topbar {
            background: var(--dark-green);
            color: white;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            font-size: 21px;
            font-weight: bold;
        }

        .brand span {
            color: var(--gold);
        }

        .student-name {
            font-size: 14px;
            color: #e4eee7;
        }

        .container {
            max-width: 650px;
            margin: 45px auto;
            padding: 0 20px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--green);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 18px rgba(16, 60, 41, 0.05);
        }

        .card h1 {
            color: var(--dark-green);
            font-size: 25px;
            margin-bottom: 10px;
        }

        .description {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: 600;
            color: #39483e;
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper input {
            width: 100%;
            padding: 13px 48px 13px 13px;
            border: 1px solid var(--border);
            border-radius: 7px;
            font-size: 14px;
            outline: none;
        }

        .password-wrapper input:focus {
            border-color: var(--main-green);
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.09);
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 5px;
            background: transparent;
            color: var(--green);
            cursor: pointer;
            padding: 4px;
        }

        .toggle-password:hover {
            background: var(--light-green);
        }

        .toggle-password svg {
            width: 21px;
            height: 21px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .password-hint {
            font-size: 12px;
            color: var(--muted);
            margin-top: 7px;
        }

        .message {
            padding: 13px 15px;
            border-radius: 7px;
            margin-bottom: 22px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.success {
            color: #176638;
            background: #e8f6ed;
            border: 1px solid #b9dfc5;
        }

        .message.error {
            color: #b42318;
            background: #fff0ef;
            border: 1px solid #f2c5c1;
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 7px;
            background: var(--green);
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .submit-btn:hover {
            background: var(--dark-green);
        }

        @media (max-width: 550px) {
            .card {
                padding: 23px 18px;
            }

            .topbar {
                padding: 16px 5%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">Smart<span>Attend</span></div>

    <div class="student-name">
        Welcome, <?php echo e($studentName); ?>
    </div>
</header>

<main class="container">

    <a href="dashboard.php" class="back-link">
        &larr; Back to Dashboard
    </a>

    <div class="card">

        <h1>Change Password</h1>

        <p class="description">
            Update your SmartAttend login password.
            Enter your current password and choose a new one.
        </p>

        <?php if ($message !== ''): ?>
            <div class="message <?php echo e($messageType); ?>">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($_SESSION['change_password_csrf']); ?>"
            >

            <div class="form-group">
                <label for="current_password">Current Password</label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        onclick="togglePassword('current_password', this)"
                        aria-label="Show current password"
                        aria-pressed="false"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="8"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        onclick="togglePassword('new_password', this)"
                        aria-label="Show new password"
                        aria-pressed="false"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>

                <p class="password-hint">
                    Your new password must contain at least 8 characters.
                </p>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        onclick="togglePassword('confirm_password', this)"
                        aria-label="Show confirm password"
                        aria-pressed="false"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                Update Password
            </button>

        </form>

    </div>

</main>

<script>
    function togglePassword(inputId, button) {
        const input = document.getElementById(inputId);

        if (!input) return;

        const isPassword = input.type === 'password';

        if (isPassword) {
            input.type = 'text';

            button.innerHTML = `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 3l18 18"/>
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                    <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8"/>
                    <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.1 0 2.1-.2 3-.5"/>
                </svg>
            `;

            button.setAttribute('aria-label', 'Hide password');
            button.setAttribute('aria-pressed', 'true');

        } else {
            input.type = 'password';

            button.innerHTML = `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            `;

            button.setAttribute('aria-label', 'Show password');
            button.setAttribute('aria-pressed', 'false');
        }
    }
</script>

</body>
</html>
