
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];

// Automatically close expired attendance sessions
$stmt = $pdo->prepare("
    UPDATE attendance_sessions
    SET status = 'closed'
    WHERE lecturer_id = ?
      AND status = 'active'
      AND end_time <= NOW()
");
$stmt->execute([$lecturerId]);

$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';
$lecturerEmail = $_SESSION['user_email'] ?? 'N/A';

$lecturerInitial = strtoupper(substr(trim($lecturerName), 0, 1));

// Count assigned courses
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM courses
    WHERE lecturer_id = ?
");
$stmt->execute([$lecturerId]);
$courseCount = (int) $stmt->fetchColumn();

// Count attendance sessions
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_sessions
    WHERE lecturer_id = ?
");
$stmt->execute([$lecturerId]);
$sessionCount = (int) $stmt->fetchColumn();

// Count attendance records
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_records ar
    INNER JOIN attendance_sessions s
        ON ar.session_id = s.id
    WHERE s.lecturer_id = ?
");
$stmt->execute([$lecturerId]);
$attendanceCount = (int) $stmt->fetchColumn();

// Count active attendance sessions
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_sessions
    WHERE lecturer_id = ?
      AND status = 'active'
");
$stmt->execute([$lecturerId]);
$activeSessionCount = (int) $stmt->fetchColumn();

// Get lecturer's assigned courses
$stmt = $pdo->prepare("
    SELECT id, course_code, course_title,
           department, level, semester
    FROM courses
    WHERE lecturer_id = ?
    ORDER BY course_code ASC
");
$stmt->execute([$lecturerId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lecturer's recent attendance sessions
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.session_title,
        s.status,
        s.start_time,
        s.end_time,
        s.qr_token,
        c.course_code,
        c.course_title,
        COUNT(ar.id) AS attendance_total
    FROM attendance_sessions s
    LEFT JOIN courses c
        ON c.id = s.course_id
    LEFT JOIN attendance_records ar
        ON ar.session_id = s.id
    WHERE s.lecturer_id = ?
    GROUP BY
        s.id,
        s.session_title,
        s.status,
        s.start_time,
        s.end_time,
        s.qr_token,
        c.course_code,
        c.course_title
    ORDER BY s.id DESC
    LIMIT 5
");
$stmt->execute([$lecturerId]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lecturer Dashboard | SmartAttend</title>
     <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>
        .lecturer-status-closed {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #fff0ee;
            color: #b42318;
            font-size: 11px;
            font-weight: 800;
        }

        .qr-button {
            padding: 9px 13px;
            border: none;
            border-radius: 6px;
            background: #145c39;
            color: white;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .qr-button:hover {
            background: #103c29;
        }

        .manage-attendance-button {
            display: inline-block;
            padding: 9px 12px;
            border-radius: 6px;
            background: #e8f4ec;
            color: #145c39;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            border: 1px solid #cce4d4;
        }

        .manage-attendance-button:hover {
            background: #145c39;
            color: white;
        }

        .qr-modal {
            display: none;
            position: fixed;
            z-index: 1200;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            justify-content: center;
            align-items: center;
            padding: 15px;
        }

        .qr-modal-content {
            position: relative;
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 12px;
            background: white;
            text-align: center;
        }

        .qr-modal-content h2 {
            color: #145c39;
            margin-bottom: 10px;
        }

        .qr-modal-content p {
            color: #718078;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        #qrcode {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 220px;
            margin: 15px auto;
        }

        #qrcode img,
        #qrcode canvas {
            max-width: 100%;
        }

        .qr-close {
            position: absolute;
            top: 8px;
            right: 13px;
            border: none;
            background: transparent;
            color: #555;
            font-size: 28px;
            cursor: pointer;
        }

        .qr-print-button {
            padding: 11px 20px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: white;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .qr-print-button:hover {
            background: #103c29;
        }

        /* Mobile menu is hidden on desktop */
        .lecturer-menu-toggle {
            display: none;
        }

        .lecturer-sidebar-overlay {
            display: none;
        }

        /* QR code printing */
        @media print {
            body * {
                visibility: hidden;
            }

            #qrModal,
            #qrModal * {
                visibility: visible;
            }

            #qrModal {
                display: flex !important;
                position: absolute;
                inset: 0;
                background: white;
            }

            .qr-close,
            .qr-print-button {
                display: none;
            }
        }

        /* =====================================
           MOBILE RESPONSIVE LECTURER DASHBOARD
        ===================================== */

        @media (max-width: 700px) {

            .student-layout {
                display: flex !important;
                flex-direction: row !important;
                min-height: 100vh;
                width: 100%;
                overflow-x: hidden;
            }

            /* Sidebar drawer */
            .student-sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;

                width: min(85vw, 300px) !important;
                max-width: 300px;
                height: 100vh;
                height: 100dvh;

                z-index: 1102;
                overflow-y: auto;

                transform: translateX(-105%);
                transition: transform 0.3s ease;
            }

            body.lecturer-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            /* Sidebar background overlay */
            .lecturer-sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;

                background: rgba(0, 0, 0, 0.5);

                z-index: 1100;

                opacity: 0;
                visibility: hidden;

                transition:
                    opacity 0.3s ease,
                    visibility 0.3s ease;
            }

            body.lecturer-sidebar-open .lecturer-sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            /* FIXED MOBILE MENU BUTTON */
            .lecturer-menu-toggle {
                display: flex !important;

                align-items: center;
                justify-content: center;

                width: 44px;
                min-width: 44px;
                max-width: 44px;
                height: 44px;

                padding: 0;
                margin: 0;

                flex: 0 0 44px;
                flex-shrink: 0;

                border: none;
                border-radius: 8px;

                background: #145c39;
                color: #ffffff;

                font-size: 23px;
                line-height: 1;

                cursor: pointer;
                position: relative;
                z-index: 5;
            }

            .lecturer-menu-toggle:focus-visible {
                outline: 3px solid #a7d7b8;
                outline-offset: 3px;
            }

            /* FIXED MOBILE HEADER POSITION */
            .student-topbar {
                display: flex !important;

                flex-direction: row !important;
                align-items: center !important;
                justify-content: flex-start !important;

                gap: 12px;

                width: 100%;
                max-width: 100%;

                box-sizing: border-box;

                padding: 12px 15px;
                margin: 0;
            }

            .student-topbar > div {
                flex: 1;
                min-width: 0;
            }

            .student-topbar h1 {
                margin-top: 0;
                overflow-wrap: break-word;
            }

            /* Main content */
            .student-main {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                margin-left: 0 !important;
                box-sizing: border-box;
            }

            /* Prevent background scrolling when menu is open */
            body.lecturer-sidebar-open {
                overflow: hidden;
            }

            /* QR modal on smaller screens */
            .qr-modal-content {
                padding: 25px 18px;
            }
        }

        /* Smaller mobile screens */
        @media (max-width: 380px) {

            .student-topbar {
                gap: 9px;
                padding-left: 10px;
                padding-right: 10px;
            }

            .lecturer-menu-toggle {
                width: 40px;
                min-width: 40px;
                max-width: 40px;
                height: 40px;
                flex-basis: 40px;
            }

            .student-topbar h1 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <!-- SIDEBAR -->
    <aside class="student-sidebar" id="lecturerSidebar">

        <div class="sidebar-brand">
            <div class="brand-symbol">SA</div>
            <h2>SmartAttend</h2>
        </div>

        <!-- LECTURER PROFILE -->
        <div class="sidebar-profile">

            <div class="profile-placeholder">
                <?= e($lecturerInitial) ?>
            </div>

            <h3><?= e($lecturerName) ?></h3>
            <p>Lecturer Account</p>

        </div>

        <!-- NAVIGATION -->
        <nav class="sidebar-navigation">

            <a class="active" href="dashboard.php">
                <span>▦</span> Dashboard
            </a>

            <a href="#lecturer-information">
                <span>♙</span> My Information
            </a>

            <a href="session.php">
                <span>＋</span> Create Session
            </a>

            <a href="#my-courses">
                <span>▤</span> My Courses
            </a>

            <a href="attendance-history.php">
                <span>◷</span> Attendance History
            </a>

            <a href="change_password.php">
                <span>🔒</span> Change Password
            </a>

        </nav>

        <!-- LOGOUT -->
        <div class="sidebar-bottom">

            <form action="logout.php" method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <button type="submit" class="sidebar-logout">
                    ⇥ Logout
                </button>

            </form>

        </div>

    </aside>

    <!-- MOBILE SIDEBAR OVERLAY -->
    <div
        class="lecturer-sidebar-overlay"
        id="lecturerSidebarOverlay"
    ></div>

    <!-- MAIN DASHBOARD -->
    <main class="student-main">

        <!-- TOP HEADER -->
        <header class="student-topbar">

            <button
                type="button"
                class="lecturer-menu-toggle"
                id="lecturerMenuToggle"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="lecturerSidebar"
            >
                ☰
            </button>

            <div>
                <h1>Lecturer Dashboard</h1>

                <p>
                    Welcome back, <?= e($lecturerName) ?>.
                </p>
            </div>

        </header>

        <!-- WELCOME BANNER -->
        <section class="student-welcome">

            <div>

                <p class="welcome-label">
                    SMARTATTEND LECTURER PORTAL
                </p>

                <h2>
                    Welcome, <?= e($lecturerName) ?>!
                </h2>

                <p>
                    Manage your assigned courses, create attendance
                    sessions and monitor your students' attendance
                    records from one place.
                </p>

            </div>

            <div class="welcome-icon">✓</div>

        </section>

        <!-- STATISTICS -->
        <section class="student-stat-grid">

            <div class="student-stat-card">
                <div class="stat-icon blue-icon">▤</div>
                <p>Assigned Courses</p>
                <h2><?= $courseCount ?></h2>
            </div>

            <div class="student-stat-card">
                <div class="stat-icon green-icon">◷</div>
                <p>Attendance Sessions</p>
                <h2><?= $sessionCount ?></h2>
            </div>

            <div class="student-stat-card">
                <div class="stat-icon orange-icon">✓</div>
                <p>Attendance Records</p>
                <h2><?= $attendanceCount ?></h2>
            </div>

            <div class="student-stat-card">
                <div class="stat-icon purple-icon">●</div>
                <p>Active Sessions</p>
                <h2><?= $activeSessionCount ?></h2>
            </div>

        </section>

        <!-- LECTURER INFORMATION -->
        <section
            class="student-content-card"
            id="lecturer-information"
        >

            <div class="section-heading">
                <div>
                    <h2>Lecturer Information</h2>
                    <p>Your lecturer account details.</p>
                </div>
            </div>

            <div class="student-course-grid">

                <div class="student-course-card">
                    <div class="course-card-icon">♙</div>
                    <h3>Full Name</h3>
                    <p><?= e($lecturerName) ?></p>
                </div>

                <div class="student-course-card">
                    <div class="course-card-icon">✉</div>
                    <h3>Email Address</h3>
                    <p><?= e($lecturerEmail) ?></p>
                </div>

                <div class="student-course-card">
                    <div class="course-card-icon">✓</div>
                    <h3>Account Type</h3>
                    <p>Lecturer</p>
                </div>

            </div>

        </section>

        <!-- ATTENDANCE MANAGEMENT -->
        <section class="student-content-card">

            <div class="section-heading">
                <div>
                    <h2>Attendance Management</h2>
                    <p>
                        Manage your courses and student attendance.
                    </p>
                </div>
            </div>

            <div class="student-course-grid">

                <div class="student-course-card">
                    <div class="course-card-icon">▤</div>
                    <h3>My Courses</h3>

                    <p>
                        View all courses assigned to your lecturer account.
                    </p>

                    <a href="#my-courses" class="profile-back-link">
                        View My Courses
                    </a>
                </div>

                <div class="student-course-card">
                    <div class="course-card-icon">＋</div>
                    <h3>Create Attendance Session</h3>

                    <p>
                        Create a new attendance session for your students.
                    </p>

                    <a href="session.php" class="profile-back-link">
                        Create Session
                    </a>
                </div>

                <div class="student-course-card">
                    <div class="course-card-icon">◷</div>
                    <h3>Attendance History</h3>

                    <p>
                        View your previous attendance sessions and
                        their current status.
                    </p>

                    <a
                        href="attendance-history.php"
                        class="profile-back-link"
                    >
                        View My Sessions
                    </a>
                </div>

            </div>

        </section>

        <!-- ASSIGNED COURSES -->
        <section
            class="student-content-card"
            id="my-courses"
        >

            <div class="section-heading">
                <div>
                    <h2>My Assigned Courses</h2>
                    <p>
                        Courses assigned to your lecturer account.
                    </p>
                </div>
            </div>

            <?php if (!empty($courses)): ?>

                <div class="student-table-wrapper">

                    <table class="student-table">

                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Department</th>
                                <th>Level</th>
                                <th>Semester</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($courses as $course): ?>

                                <tr>

                                    <td>
                                        <?= e($course['course_code']) ?>
                                    </td>

                                    <td>
                                        <?= e($course['course_title']) ?>
                                    </td>

                                    <td>
                                        <?= e($course['department']) ?>
                                    </td>

                                    <td>
                                        <?= e($course['level']) ?>
                                    </td>

                                    <td>
                                        <?= e($course['semester']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div>▤</div>

                    <h3>No courses assigned yet</h3>

                    <p>
                        No courses have been assigned to your account.
                        Please contact the administrator.
                    </p>

                </div>

            <?php endif; ?>

        </section>

        <!-- ATTENDANCE SESSIONS -->
        <section
            class="student-content-card"
            id="attendance-sessions"
        >

            <div class="section-heading">
                <div>
                    <h2>My Attendance Sessions</h2>

                    <p>
                        Your five most recent attendance sessions
                        with their individual attendance totals.
                    </p>
                </div>
            </div>

            <?php if (!empty($sessions)): ?>

                <div class="student-table-wrapper">

                    <table class="student-table">

                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Session Title</th>
                                <th>Total Attendance</th>
                                <th>Status</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>QR Code</th>
                                <th>Manage Attendance</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($sessions as $session): ?>

                                <tr>

                                    <td>
                                        <?= e($session['course_code'] ?? 'N/A') ?>
                                    </td>

                                    <td>
                                        <?= e($session['course_title'] ?? 'N/A') ?>
                                    </td>

                                    <td>
                                        <?= e($session['session_title']) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= (int) $session['attendance_total'] ?>
                                        </strong>

                                        <?= (int) $session['attendance_total'] === 1
                                            ? 'student'
                                            : 'students' ?>
                                    </td>

                                    <td>

                                        <?php if (
                                            strtolower($session['status']) === 'active'
                                        ): ?>

                                            <span class="attendance-present">
                                                Active
                                            </span>

                                        <?php else: ?>

                                            <span class="lecturer-status-closed">
                                                <?= e(ucfirst($session['status'])) ?>
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= e($session['start_time']) ?>
                                    </td>

                                    <td>
                                        <?= e($session['end_time']) ?>
                                    </td>

                                    <!-- QR CODE -->
                                    <td>

                                        <?php if (
                                            strtolower($session['status']) === 'active'
                                            && !empty($session['qr_token'])
                                        ): ?>

                                            <button
                                                type="button"
                                                class="qr-button"
                                                data-qr-url="../student/scan.php?token=<?= rawurlencode($session['qr_token']) ?>"
                                                data-session-title="<?= e($session['session_title']) ?>"
                                                onclick="showQRCode(this)"
                                            >
                                                View QR Code
                                            </button>

                                        <?php else: ?>

                                            <span>Unavailable</span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- MANAGE ATTENDANCE -->
                                    <td>

                                        <a
                                            href="session-attendance.php?session_id=<?= (int) $session['id'] ?>"
                                            class="manage-attendance-button"
                                        >
                                            View Students / Mark Attendance
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div>◷</div>

                    <h3>No attendance sessions yet</h3>

                    <p>
                        You have not created any attendance sessions.
                        Your sessions will appear here after creation.
                    </p>

                </div>

            <?php endif; ?>

        </section>

        <!-- FOOTER -->
        <footer class="student-footer">

            <p>
                © <?= date('Y') ?> SmartAttend. All rights reserved.
            </p>

            <p>Knowledge, Truth and Service</p>

        </footer>

    </main>

</div>

<!-- QR CODE MODAL -->
<div class="qr-modal" id="qrModal">

    <div class="qr-modal-content">

        <button
            type="button"
            class="qr-close"
            onclick="closeQRCode()"
            aria-label="Close QR code"
        >
            &times;
        </button>

        <h2>Attendance QR Code</h2>

        <p id="qrSessionTitle"></p>

        <div id="qrcode"></div>

        <p>
            Students will scan this code to access the attendance page.
        </p>

        <button
            type="button"
            class="qr-print-button"
            onclick="window.print()"
        >
            Print QR Code
        </button>

    </div>

</div>

<!-- QR CODE JAVASCRIPT LIBRARY -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>

/* =====================================
   QR CODE FUNCTIONS
===================================== */

function showQRCode(button) {

    const qrUrl = button.dataset.qrUrl;
    const sessionTitle = button.dataset.sessionTitle;

    const qrContainer = document.getElementById('qrcode');

    document.getElementById('qrSessionTitle').textContent =
        sessionTitle;

    qrContainer.innerHTML = '';

    if (typeof QRCode === 'undefined') {

        alert(
            "QR Code library could not load. Please check your internet connection."
        );

        return;
    }

    document.getElementById('qrModal').style.display = 'flex';

    new QRCode(qrContainer, {

        text: new URL(qrUrl, window.location.href).href,

        width: 220,

        height: 220,

        colorDark: "#000000",

        colorLight: "#ffffff",

        correctLevel: QRCode.CorrectLevel.H

    });

}

function closeQRCode() {

    document.getElementById('qrModal').style.display = 'none';

}

window.addEventListener('click', function(event) {

    const modal = document.getElementById('qrModal');

    if (event.target === modal) {
        closeQRCode();
    }

});


/* =====================================
   LECTURER MOBILE SIDEBAR CONTROLS
===================================== */

(function () {

    const menuButton = document.getElementById('lecturerMenuToggle');
    const sidebar = document.getElementById('lecturerSidebar');
    const overlay = document.getElementById('lecturerSidebarOverlay');

    if (!menuButton || !sidebar || !overlay) {
        return;
    }

    function openLecturerMenu() {

        document.body.classList.add('lecturer-sidebar-open');

        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Close navigation menu');

        menuButton.textContent = '✕';

    }

    function closeLecturerMenu() {

        document.body.classList.remove('lecturer-sidebar-open');

        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Open navigation menu');

        menuButton.textContent = '☰';

    }

    // Toggle menu button
    menuButton.addEventListener('click', function () {

        if (document.body.classList.contains('lecturer-sidebar-open')) {

            closeLecturerMenu();

        } else {

            openLecturerMenu();

        }

    });

    // Close when overlay is clicked
    overlay.addEventListener('click', closeLecturerMenu);

    // Close after selecting any sidebar navigation link
    sidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeLecturerMenu);

    });

    // Close when Escape is pressed
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeLecturerMenu();
        }

    });

    // Close menu when returning to desktop screen size
    const desktopMedia = window.matchMedia('(min-width: 701px)');

    function handleScreenChange(event) {

        if (event.matches) {
            closeLecturerMenu();
        }

    }

    if (desktopMedia.addEventListener) {

        desktopMedia.addEventListener('change', handleScreenChange);

    } else {

        desktopMedia.addListener(handleScreenChange);

    }

})();

</script>

</body>
</html>
