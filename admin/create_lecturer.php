
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

$errors = [];

$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Please enter the lecturer full name.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (empty($errors)) {
        $checkEmail = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $checkEmail->execute([$email]);

        if ($checkEmail->fetch()) {
            $errors[] = 'An account with this email address already exists.';
        }
    }

    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password, role)
                VALUES (?, ?, ?, 'lecturer')
            ");

            $stmt->execute([
                $fullName,
                $email,
                $hashedPassword
            ]);

            set_flash('success', 'Lecturer account created successfully.');

            header('Location: lecturers.php');
            exit;

        } catch (PDOException $e) {
            error_log('Create lecturer error: ' . $e->getMessage());
            $errors[] = 'Unable to create lecturer account. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Lecturer | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <style>
        .admin-form-card {
            max-width: 850px;
        }

        .admin-form-card .primary-btn {
            max-width: 250px;
        }

        .admin-form-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 25px;
            padding-top: 22px;
            border-top: 1px solid var(--border);
        }

        .admin-cancel-link {
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            padding: 12px 16px;
        }

        .admin-cancel-link:hover {
            color: var(--green);
        }

        .admin-form-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
            margin-top: 7px;
        }

        .admin-page-label {
            color: var(--gold);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }

        /* PASSWORD EYE ICON */
        .password-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-box input {
            width: 100%;
            padding-right: 48px;
        }

        .toggle-password {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--green);
            cursor: pointer;
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

        /* Mobile menu button */
        .admin-menu-toggle {
            display: none;
            width: 44px;
            height: 44px;
            min-width: 44px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: white;
            font-size: 25px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        .admin-sidebar-overlay {
            display: none;
        }

        @media (max-width: 700px) {

            .student-layout {
                width: 100%;
                overflow-x: hidden;
            }

            .student-sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 270px;
                max-width: 85%;
                height: 100vh;
                z-index: 1001;
                transform: translateX(-105%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            body.admin-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            .admin-sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 1000;
            }

            body.admin-sidebar-open .admin-sidebar-overlay {
                display: block;
            }

            .student-main {
                width: 100%;
                min-width: 0;
                margin-left: 0;
            }

            .student-topbar {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 15px;
                min-height: 70px;
            }

            .admin-menu-toggle {
                display: inline-flex;
                flex: 0 0 44px;
                position: relative;
                z-index: 999;
            }

            .student-topbar > div {
                flex: 1;
                min-width: 0;
            }

            .student-topbar h1 {
                font-size: 20px;
            }

            .student-topbar p {
                font-size: 11px;
            }

            .admin-form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .admin-form-card .primary-btn {
                max-width: none;
            }

            .admin-cancel-link {
                text-align: center;
            }

            body.admin-sidebar-open {
                overflow: hidden;
            }
        }
    </style>
</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <!-- SIDEBAR -->
    <aside class="student-sidebar" id="adminSidebar">

        <div class="sidebar-brand">
            <div class="brand-symbol">SA</div>
            <h2>SmartAttend</h2>
        </div>

        <div class="sidebar-profile">
            <div class="profile-placeholder">
                <?= e($adminInitial) ?>
            </div>

            <h3><?= e($adminName) ?></h3>
            <p>System Administrator</p>
        </div>

        <nav class="sidebar-navigation">

            <a href="dashboard.php">
                <span>⌂</span>
                Dashboard
            </a>

            <a href="create_lecturer.php" class="active">
                <span>＋</span>
                Create Lecturer
            </a>

            <a href="lecturers.php">
                <span>♧</span>
                Manage Lecturers
            </a>

        </nav>

        <div class="sidebar-bottom">
            <form action="../logout.php" method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= e(csrf_token()) ?>">

                <button type="submit" class="sidebar-logout">
                    ↪ Logout
                </button>
            </form>
        </div>

    </aside>

    <!-- MOBILE SIDEBAR OVERLAY -->
    <div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

    <!-- MAIN CONTENT -->
    <main class="student-main">

        <div class="student-topbar">

            <button
                type="button"
                class="admin-menu-toggle"
                id="adminMenuToggle"
                aria-label="Open navigation menu"
                aria-expanded="false"
            >
                ☰
            </button>

            <div>
                <h1>Create Lecturer</h1>
                <p>Administrator Portal / Create Lecturer</p>
            </div>

        </div>

        <!-- WELCOME BANNER -->
        <section class="student-welcome">

            <div>
                <p class="welcome-label">LECTURER MANAGEMENT</p>

                <h2>Create a New Lecturer Account</h2>

                <p>
                    Register a lecturer on SmartAttend by providing
                    their full name, email address and login password.
                </p>
            </div>

            <div class="welcome-icon">＋</div>

        </section>

        <!-- FORM CARD -->
        <section class="student-content-card admin-form-card">

            <div class="section-heading">
                <div>
                    <h2>Lecturer Information</h2>
                    <p>Enter the lecturer's details below.</p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= e($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">

                <input type="hidden" name="csrf_token"
                       value="<?= e(csrf_token()) ?>">

                <div class="form-row">

                    <div class="form-group">
                        <label for="full_name">Lecturer Full Name</label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter lecturer full name"
                            value="<?= e($fullName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter lecturer email"
                            value="<?= e($email) ?>"
                            required
                        >
                    </div>

                </div>

                <div class="form-row">

                    <div class="form-group">
                        <label for="password">Password</label>

                        <div class="password-box">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Create a password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="toggle-password"
                                data-password-target="password"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>

                        <p class="admin-form-note">
                            Password must contain at least 8 characters.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>

                        <div class="password-box">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Confirm password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="toggle-password"
                                data-password-target="confirm_password"
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

                </div>

                <div class="admin-form-actions">

                    <button type="submit" class="primary-btn">
                        Create Lecturer Account
                    </button>

                    <a href="lecturers.php" class="admin-cancel-link">
                        Cancel
                    </a>

                </div>

            </form>

        </section>

        <footer class="student-footer">
            © <?= date('Y') ?> SmartAttend |
            Emmanuel Alayande University of Education, Oyo
            <br>
            <strong>Knowledge, Truth and Service</strong>
        </footer>

    </main>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Mobile sidebar functionality
    const menuButton = document.getElementById('adminMenuToggle');
    const overlay = document.getElementById('adminSidebarOverlay');
    const sidebar = document.getElementById('adminSidebar');

    if (menuButton && overlay && sidebar) {

        function openSidebar() {
            document.body.classList.add('admin-sidebar-open');
            menuButton.setAttribute('aria-expanded', 'true');
            menuButton.setAttribute('aria-label', 'Close navigation menu');
            menuButton.textContent = '✕';
        }

        function closeSidebar() {
            document.body.classList.remove('admin-sidebar-open');
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Open navigation menu');
            menuButton.textContent = '☰';
        }

        menuButton.addEventListener('click', function () {
            if (document.body.classList.contains('admin-sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        overlay.addEventListener('click', closeSidebar);

        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeSidebar);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 700) {
                closeSidebar();
            }
        });
    }

    // Eye and crossed-eye password controls
    document.querySelectorAll('[data-password-target]').forEach(button => {

        button.addEventListener('click', function () {

            const inputId = this.getAttribute('data-password-target');
            const passwordInput = document.getElementById(inputId);

            if (!passwordInput) {
                return;
            }

            const isHidden = passwordInput.type === 'password';

            if (isHidden) {

                passwordInput.type = 'text';

                this.setAttribute('aria-label', 'Hide password');
                this.setAttribute('aria-pressed', 'true');

                // Crossed-eye icon
                this.innerHTML = `
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 3l18 18"/>
                        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                        <path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7s-1.2 2.4-3.1 3.9"/>
                        <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"/>
                    </svg>
                `;

            } else {

                passwordInput.type = 'password';

                this.setAttribute('aria-label', 'Show password');
                this.setAttribute('aria-pressed', 'false');

                // Normal eye icon
                this.innerHTML = `
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                `;
            }

        });

    });

});
</script>

</body>
</html>
