
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];
$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';

$message = '';
$messageType = '';

// Get lecturer account
$stmt = $pdo->prepare("
    SELECT id, full_name, password
    FROM users
    WHERE id = ? AND role = 'lecturer'
    LIMIT 1
");

$stmt->execute([$lecturerId]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

// Ensure lecturer account exists
if (!$lecturer) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        verify_csrf();

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

        } elseif (!password_verify($currentPassword, $lecturer['password'])) {
            $message = 'Your current password is incorrect.';
            $messageType = 'error';

        } elseif (strlen($newPassword) < 8) {
            $message = 'Your new password must contain at least 8 characters.';
            $messageType = 'error';

        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Your new passwords do not match.';
            $messageType = 'error';

        } elseif (password_verify($newPassword, $lecturer['password'])) {
            $message = 'Your new password must be different from your current password.';
            $messageType = 'error';

        } else {

            $hashedPassword = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $updatePassword = $pdo->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ? AND role = 'lecturer'
            ");

            $updatePassword->execute([
                $hashedPassword,
                $lecturerId
            ]);

            $lecturer['password'] = $hashedPassword;

            $message = 'Your password has been changed successfully.';
            $messageType = 'success';
        }

    } catch (PDOException $e) {
        error_log('Lecturer password change error: ' . $e->getMessage());

        $message = 'Unable to change your password. Please try again.';
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Password | SmartAttend Lecturer Portal</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        .password-container {
            max-width: 650px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .password-card {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #dfe8e1;
            box-shadow: 0 4px 18px rgba(16, 60, 41, 0.05);
        }

        .password-card h2 {
            color: #103c29;
            margin-bottom: 10px;
        }

        .password-description {
            color: #718078;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .password-group {
            margin-bottom: 20px;
        }

        .password-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 9px;
            color: #39483e;
        }

        /* PASSWORD INPUT AND EYE ICON */
        .password-input-wrapper {
            position: relative;
            width: 100%;
        }

        .password-input-wrapper input {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 13px 48px 13px 13px;
            border: 1px solid #dfe8e1;
            border-radius: 7px;
            outline: none;
            background: #ffffff;
        }

        .password-input-wrapper input:focus {
            border-color: #197447;
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.09);
        }

        /* EYE ICON BUTTON */
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
            padding: 4px;
            border: none;
            border-radius: 5px;
            background: transparent;
            color: #145c39;
            cursor: pointer;
            z-index: 2;
        }

        .toggle-password:hover {
            background: #e7f3eb;
            color: #103c29;
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
            color: #718078;
            margin-top: 7px;
        }

        .password-message {
            padding: 13px 15px;
            border-radius: 7px;
            margin-bottom: 22px;
            font-size: 14px;
            line-height: 1.5;
        }

        .password-message.success {
            color: #176638;
            background: #e8f6ed;
            border: 1px solid #b9dfc5;
        }

        .password-message.error {
            color: #b42318;
            background: #fff0ef;
            border: 1px solid #f2c5c1;
        }

        .password-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .password-submit:hover {
            background: #103c29;
        }

        .password-back {
            display: inline-block;
            margin-bottom: 20px;
            color: #145c39;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .password-back:hover {
            text-decoration: underline;
        }

        @media (max-width: 550px) {

            .password-card {
                padding: 22px 18px;
            }

        }

    </style>

</head>

<body>

<div class="password-container">

    <a href="dashboard.php" class="password-back">
        &larr; Back to Lecturer Dashboard
    </a>

    <div class="password-card">

        <h2>Change Password</h2>

        <p class="password-description">
            Update your SmartAttend lecturer account password.
            Enter your current password and choose a new one.
        </p>

        <?php if ($message !== ''): ?>

            <div class="password-message <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <!-- CURRENT PASSWORD -->
            <div class="password-group">

                <label for="current_password">
                    Current Password
                </label>

                <div class="password-input-wrapper">

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

            <!-- NEW PASSWORD -->
            <div class="password-group">

                <label for="new_password">
                    New Password
                </label>

                <div class="password-input-wrapper">

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

            <!-- CONFIRM PASSWORD -->
            <div class="password-group">

                <label for="confirm_password">
                    Confirm New Password
                </label>

                <div class="password-input-wrapper">

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

            <button type="submit" class="password-submit">
                Update Password
            </button>

        </form>

    </div>

</div>

<script>

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (!input) return;

    if (input.type === 'password') {

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
