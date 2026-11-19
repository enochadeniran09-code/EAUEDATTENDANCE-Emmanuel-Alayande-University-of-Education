
<?php

require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// Administrator information
$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

// Count lecturers
$lecturerCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'lecturer'
")->fetchColumn();

// Count students
$studentCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM students
")->fetchColumn();

// Count courses
$courseCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM courses
")->fetchColumn();

// Count attendance records
$attendanceCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_records
")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administrator Dashboard | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        /* ADMIN DASHBOARD */

        .admin-welcome {
            background: linear-gradient(120deg, #145c39, #24784d);
        }

        .admin-welcome .welcome-icon {
            background: rgba(255, 255, 255, 0.13);
        }

        .admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .admin-stat-card {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .admin-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(20, 92, 57, 0.08);
        }

        .admin-stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .admin-stat-label {
            color: #718078;
            font-size: 12px;
            font-weight: 600;
        }

        .admin-stat-icon {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #e8f4ec;
            color: #145c39;
            font-size: 20px;
        }

        .admin-stat-card h2 {
            color: #183d29;
            font-size: 27px;
            margin: 0;
            font-weight: 800;
        }

        .admin-stat-description {
            color: #89958d;
            font-size: 11px;
            margin-top: 7px;
        }

        .admin-management-card {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
        }

        .admin-management-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .admin-management-heading h2 {
            color: #244b35;
            font-size: 17px;
            margin: 0;
        }

        .admin-management-card > p {
            color: #718078;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .admin-management-icon {
            width: 40px;
            height: 40px;
            border-radius: 9px;
            background: #e8f4ec;
            color: #145c39;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .admin-dashboard-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .admin-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            background: #145c39;
            color: #fff;
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: background 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .admin-action-btn:hover {
            background: #103c29;
        }

        .admin-action-secondary {
            background: #e8f4ec;
            color: #145c39;
            border: 1px solid #cce4d4;
        }

        .admin-action-secondary:hover {
            background: #145c39;
            color: #fff;
        }

        /* MOBILE MENU DEFAULTS */

        .admin-menu-toggle {
            display: none;
        }

        .admin-sidebar-overlay {
            display: none;
        }

        @media (max-width: 1100px) {

            .admin-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        /* =====================================
           ADMIN MOBILE SIDEBAR
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

            body.admin-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            /* Dark overlay */

            .admin-sidebar-overlay {
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

            body.admin-sidebar-open .admin-sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            /* Menu button */

            .admin-menu-toggle {
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

            .admin-menu-toggle:focus-visible {
                outline: 3px solid #a7d7b8;
                outline-offset: 3px;
            }

            /* Header positioning */

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

            /* Prevent background scrolling */

            body.admin-sidebar-open {
                overflow: hidden;
            }

            /* Statistics */

            .admin-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 13px;
            }

            .admin-stat-card {
                padding: 16px;
            }

            .admin-stat-card h2 {
                font-size: 23px;
            }

            /* Management cards */

            .admin-management-card {
                padding: 18px;
            }

            .admin-dashboard-actions {
                flex-direction: column;
            }

            .admin-action-btn {
                width: 100%;
                box-sizing: border-box;
            }

        }

        /* EXTRA SMALL PHONES */

        @media (max-width: 380px) {

            .student-topbar {
                gap: 9px;
                padding-left: 10px;
                padding-right: 10px;
            }

            .admin-menu-toggle {
                width: 40px;
                min-width: 40px;
                max-width: 40px;
                height: 40px;
                flex-basis: 40px;
            }

            .student-topbar h1 {
                font-size: 20px;
            }

            .admin-stats-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <!-- SIDEBAR -->
    <aside class="student-sidebar" id="adminSidebar">

        <div class="sidebar-brand">
            <div class="brand-symbol">SA</div>
            <h2>SmartAttend</h2>
        </div>

        <!-- ADMIN PROFILE -->
        <div class="sidebar-profile">

            <div class="profile-placeholder">
                <?= e($adminInitial) ?>
            </div>

            <h3><?= e($adminName) ?></h3>

            <p>Administrator Account</p>

        </div>

        <!-- NAVIGATION -->
        <nav class="sidebar-navigation">

            <a class="active" href="dashboard.php">
                <span>▦</span> Dashboard
            </a>

            <a href="create_lecturer.php">
                <span>＋</span> Create Lecturer
            </a>

            <a href="lecturers.php">
                <span>♙</span> Manage Lecturers
            </a>

            <a href="students.php">
                <span>♙</span> Manage Students
            </a>

            <a href="manage_courses.php">
                <span>▤</span> Course Management
            </a>

            <a href="attendance_sessions.php">
                <span>◷</span> Attendance Sessions
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
        class="admin-sidebar-overlay"
        id="adminSidebarOverlay"
    ></div>

    <!-- MAIN CONTENT -->

    <main class="student-main">

        <!-- TOP HEADER -->

        <header class="student-topbar">

            <button
                type="button"
                class="admin-menu-toggle"
                id="adminMenuToggle"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="adminSidebar"
            >
                ☰
            </button>

            <div>

                <h1>Administrator Dashboard</h1>

                <p>
                    Manage and monitor the SmartAttend system.
                </p>

            </div>

        </header>

        <!-- WELCOME BANNER -->

        <section class="student-welcome admin-welcome">

            <div>

                <p class="welcome-label">
                    SMARTATTEND ADMINISTRATOR PORTAL
                </p>

                <h2>
                    Welcome, <?= e($adminName) ?>!
                </h2>

                <p>
                    Manage lecturers, monitor students, and oversee
                    attendance activities across the institution.
                </p>

            </div>

            <div class="welcome-icon">
                ♙
            </div>

        </section>

        <!-- STATISTICS -->

        <section class="admin-stats-grid">

            <div class="admin-stat-card">

                <div class="admin-stat-top">
                    <span class="admin-stat-label">Total Students</span>
                    <div class="admin-stat-icon">♙</div>
                </div>

                <h2><?= number_format($studentCount) ?></h2>

                <p class="admin-stat-description">
                    Registered student accounts
                </p>

            </div>

            <div class="admin-stat-card">

                <div class="admin-stat-top">
                    <span class="admin-stat-label">Total Lecturers</span>
                    <div class="admin-stat-icon">♙</div>
                </div>

                <h2><?= number_format($lecturerCount) ?></h2>

                <p class="admin-stat-description">
                    Registered lecturer accounts
                </p>

            </div>

            <div class="admin-stat-card">

                <div class="admin-stat-top">
                    <span class="admin-stat-label">Total Courses</span>
                    <div class="admin-stat-icon">▤</div>
                </div>

                <h2><?= number_format($courseCount) ?></h2>

                <p class="admin-stat-description">
                    Courses available in the system
                </p>

            </div>

            <div class="admin-stat-card">

                <div class="admin-stat-top">
                    <span class="admin-stat-label">Attendance Records</span>
                    <div class="admin-stat-icon">◷</div>
                </div>

                <h2><?= number_format($attendanceCount) ?></h2>

                <p class="admin-stat-description">
                    Total recorded attendance entries
                </p>

            </div>

        </section>

        <!-- LECTURER MANAGEMENT -->

        <section class="admin-management-card">

            <div class="admin-management-heading">

                <h2>Lecturer Management</h2>

                <div class="admin-management-icon">♙</div>

            </div>

            <p>
                Create new lecturer accounts and manage existing
                lecturers within the SmartAttend system.
            </p>

            <div class="admin-dashboard-actions">

                <a
                    href="create_lecturer.php"
                    class="admin-action-btn"
                >
                    + Create Lecturer Account
                </a>

                <a
                    href="lecturers.php"
                    class="admin-action-btn admin-action-secondary"
                >
                    View All Lecturers
                </a>

            </div>

        </section>

        <!-- SYSTEM MANAGEMENT -->

        <section class="admin-management-card">

            <div class="admin-management-heading">

                <h2>System Management</h2>

                <div class="admin-management-icon">⚙</div>

            </div>

            <p>
                Manage students, courses, attendance sessions,
                and attendance reports from one central location.
            </p>

            <div class="admin-dashboard-actions">

                <a
                    href="students.php"
                    class="admin-action-btn admin-action-secondary"
                >
                    Student Management
                </a>

                <a
                    href="manage_courses.php"
                    class="admin-action-btn admin-action-secondary"
                >
                    Course Management
                </a>

                <a
                    href="attendance_sessions.php"
                    class="admin-action-btn admin-action-secondary"
                >
                    Attendance Session Management
                </a>

                <a
                    href="attendance_reports.php"
                    class="admin-action-btn admin-action-secondary"
                >
                    Attendance Reports
                </a>

            </div>

        </section>

        <!-- FOOTER -->

        <footer class="student-footer">

            <p>
                © <?= date('Y') ?> SmartAttend. All rights reserved.
            </p>

            <p>
                Knowledge, Truth and Service
            </p>

        </footer>

    </main>

</div>

<!-- ADMIN MOBILE MENU JAVASCRIPT -->

<script>

(function () {

    const menuButton = document.getElementById('adminMenuToggle');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminSidebarOverlay');

    if (!menuButton || !sidebar || !overlay) {
        return;
    }

    function openAdminMenu() {

        document.body.classList.add('admin-sidebar-open');

        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Close navigation menu');

        menuButton.textContent = '✕';

    }

    function closeAdminMenu() {

        document.body.classList.remove('admin-sidebar-open');

        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Open navigation menu');

        menuButton.textContent = '☰';

    }

    // Toggle the menu
    menuButton.addEventListener('click', function () {

        if (document.body.classList.contains('admin-sidebar-open')) {

            closeAdminMenu();

        } else {

            openAdminMenu();

        }

    });

    // Close when overlay is clicked
    overlay.addEventListener('click', closeAdminMenu);

    // Close after selecting a navigation link
    sidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeAdminMenu);

    });

    // Close when Escape is pressed
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeAdminMenu();
        }

    });

    // Close menu when switching to desktop screen size
    const desktopMedia = window.matchMedia('(min-width: 701px)');

    function handleScreenChange(event) {

        if (event.matches) {
            closeAdminMenu();
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
