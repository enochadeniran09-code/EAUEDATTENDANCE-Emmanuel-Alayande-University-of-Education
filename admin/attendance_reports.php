
<?php

require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// ============================================
// AUTOMATICALLY CLOSE ALL EXPIRED SESSIONS
// ============================================

$pdo->exec("
    UPDATE attendance_sessions
    SET status = 'closed'
    WHERE status = 'active'
      AND end_time <= NOW()
");

// Administrator information
$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

// ============================================
// FILTER VALUES
// ============================================

$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

$courseId = ($courseId && $courseId > 0) ? $courseId : '';
$sessionId = ($sessionId && $sessionId > 0) ? $sessionId : '';

$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if ($fromDate !== '' && !preg_match($datePattern, $fromDate)) {
    $fromDate = '';
}

if ($toDate !== '' && !preg_match($datePattern, $toDate)) {
    $toDate = '';
}

// ============================================
// GET COURSES FOR FILTER
// ============================================

$courseStmt = $pdo->query("
    SELECT id, course_code, course_title
    FROM courses
    ORDER BY course_code ASC
");

$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// GET ATTENDANCE SESSIONS FOR FILTER
// ============================================

$sessionStmt = $pdo->query("
    SELECT
        s.id,
        s.session_title,
        s.start_time,
        c.course_code
    FROM attendance_sessions s
    INNER JOIN courses c ON c.id = s.course_id
    ORDER BY s.start_time DESC
");

$sessions = $sessionStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUILD ATTENDANCE REPORT QUERY
// ============================================

$sql = "
    SELECT
        ar.id AS attendance_id,
        ar.attendance_time,
        ar.attendance_status,
        ar.attendance_method,

        st.matric_number,
        st.first_name,
        st.middle_name,
        st.last_name,

        c.course_code,
        c.course_title,

        s.id AS session_id,
        s.session_title,
        s.start_time,

        u.full_name AS lecturer_name

    FROM attendance_records ar

    INNER JOIN attendance_sessions s
        ON s.id = ar.session_id

    INNER JOIN courses c
        ON c.id = s.course_id

    INNER JOIN students st
        ON st.id = ar.student_id

    LEFT JOIN users u
        ON u.id = s.lecturer_id

    WHERE 1 = 1
";

$params = [];

// Filter by course
if ($courseId !== '') {
    $sql .= " AND c.id = :course_id";
    $params[':course_id'] = $courseId;
}

// Filter by attendance session
if ($sessionId !== '') {
    $sql .= " AND s.id = :session_id";
    $params[':session_id'] = $sessionId;
}

// Filter by starting date
if ($fromDate !== '') {
    $sql .= " AND DATE(ar.attendance_time) >= :from_date";
    $params[':from_date'] = $fromDate;
}

// Filter by ending date
if ($toDate !== '') {
    $sql .= " AND DATE(ar.attendance_time) <= :to_date";
    $params[':to_date'] = $toDate;
}

$sql .= " ORDER BY ar.attendance_time DESC";

$reportStmt = $pdo->prepare($sql);
$reportStmt->execute($params);

$records = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULATE REPORT STATISTICS
// ============================================

$totalAttendance = count($records);
$presentCount = 0;
$lateCount = 0;

foreach ($records as $record) {

    if ($record['attendance_status'] === 'present') {
        $presentCount++;
    }

    if ($record['attendance_status'] === 'late') {
        $lateCount++;
    }
}

// ============================================
// EXPORT REPORT AS CSV
// ============================================

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="smartattend-attendance-report.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Matric Number',
        'Student Name',
        'Course Code',
        'Course Title',
        'Attendance Session',
        'Lecturer',
        'Attendance Status',
        'Attendance Method',
        'Attendance Date and Time'
    ]);

    foreach ($records as $record) {

        $studentName = trim(
            $record['first_name'] . ' ' .
            ($record['middle_name'] ?? '') . ' ' .
            $record['last_name']
        );

        fputcsv($output, [
            $record['matric_number'],
            $studentName,
            $record['course_code'],
            $record['course_title'],
            $record['session_title'],
            $record['lecturer_name'] ?? 'N/A',
            ucfirst($record['attendance_status']),
            ucfirst($record['attendance_method']),
            $record['attendance_time']
        ]);
    }

    fclose($output);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendance Reports | SmartAttend</title>
     <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        /* =====================================
           HAMBURGER BUTTON
        ===================================== */

        .admin-mobile-menu {
            display: none;
            width: 42px;
            height: 42px;
            min-width: 42px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: #ffffff;
            font-size: 26px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
            padding: 0;
            position: relative;
            z-index: 1002;
        }

        .admin-mobile-overlay {
            display: none;
        }

        /* =====================================
           ATTENDANCE REPORTS
        ===================================== */

        .report-welcome {
            background: linear-gradient(120deg, #145c39, #24784d);
        }

        .report-welcome .welcome-icon {
            background: rgba(255, 255, 255, 0.13);
        }

        .report-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .report-stat-card {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
        }

        .report-stat-card p {
            color: #718078;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .report-stat-card h2 {
            color: #145c39;
            font-size: 28px;
            margin: 0;
        }

        .report-stat-card.present h2 {
            color: #198754;
        }

        .report-stat-card.late h2 {
            color: #c58a16;
        }

        .report-card {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
        }

        .report-card h2 {
            color: #244b35;
            font-size: 17px;
            margin-bottom: 18px;
        }

        .report-filter-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .report-filter-group label {
            display: block;
            color: #52665a;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .report-filter-group input,
        .report-filter-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce7df;
            border-radius: 7px;
            background: #fff;
            color: #244b35;
            font-size: 13px;
            box-sizing: border-box;
            outline: none;
        }

        .report-filter-group input:focus,
        .report-filter-group select:focus {
            border-color: #197447;
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.08);
        }

        .report-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        .report-btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .report-btn:hover {
            background: #103c29;
            color: #fff;
        }

        .report-btn-secondary {
            background: #e8f4ec;
            color: #145c39;
            border: 1px solid #cce4d4;
        }

        .report-btn-secondary:hover {
            background: #145c39;
            color: #fff;
        }

        .report-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        .report-table th {
            background: #f1f7f3;
            color: #315640;
            font-size: 11px;
            text-align: left;
            padding: 14px 12px;
            white-space: nowrap;
        }

        .report-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf1ee;
            color: #53665a;
            font-size: 12px;
            vertical-align: middle;
        }

        .report-table tr:hover td {
            background: #fbfdfb;
        }

        .student-name {
            color: #244b35;
            font-weight: 700;
        }

        .student-matric {
            display: block;
            color: #89958d;
            font-size: 10px;
            margin-top: 4px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-present {
            background: #e5f5eb;
            color: #198754;
        }

        .status-late {
            background: #fff4dc;
            color: #a36b08;
        }

        .empty-report {
            text-align: center;
            padding: 40px 15px;
            color: #89958d;
        }

        .empty-report strong {
            display: block;
            color: #315640;
            font-size: 15px;
            margin-bottom: 7px;
        }

        .report-results-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .report-results-heading h2 {
            margin: 0;
        }

        .report-count {
            color: #718078;
            font-size: 12px;
        }

        /* =====================================
           PRINT STYLES
        ===================================== */

        @media print {

            .student-sidebar,
            .student-topbar,
            .report-welcome,
            .report-filter-card,
            .report-actions,
            .student-footer,
            .admin-mobile-menu,
            .admin-mobile-overlay {
                display: none !important;
            }

            .student-layout {
                display: block !important;
            }

            .student-main {
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .report-card,
            .report-stat-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }

            .report-table {
                min-width: 100%;
            }

            .report-table th,
            .report-table td {
                font-size: 9px;
                padding: 7px;
            }

            body {
                background: #fff !important;
            }
        }

        /* =====================================
           MOBILE AND TABLET MENU
        ===================================== */

        @media (max-width: 768px) {

            .admin-mobile-menu {
                display: flex !important;
            }

            /* Sidebar closed by default */
            .student-sidebar {
                display: flex !important;
                flex-direction: column;
                position: fixed !important;
                top: 0 !important;
                bottom: 0 !important;
                left: 0 !important;
                width: 260px !important;
                max-width: 85vw;
                height: 100vh !important;
                height: 100dvh !important;
                z-index: 1001 !important;
                overflow-y: auto !important;
                transform: translateX(-110%);
                transition: transform 0.3s ease !important;
                visibility: hidden;
                pointer-events: none;
            }

            /* Sidebar open */
            .student-sidebar.active {
                transform: translateX(0) !important;
                visibility: visible !important;
                pointer-events: auto !important;
            }

            /* Dark background */
            .admin-mobile-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 1000;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            .admin-mobile-overlay.active {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            /* Main dashboard */
            .student-main {
                width: 100% !important;
                margin-left: 0 !important;
                box-sizing: border-box;
            }

            /* Hamburger alignment */
            .student-topbar {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                gap: 12px;
                width: 100%;
                box-sizing: border-box;
                padding-left: 0;
                margin-left: 0;
            }

            .student-topbar > div {
                min-width: 0;
            }

            .student-topbar h1 {
                font-size: 20px;
            }

            .report-stats-grid {
                grid-template-columns: 1fr;
            }

            .report-filter-grid {
                grid-template-columns: 1fr;
            }

            .report-card {
                padding: 17px;
            }

            .report-actions {
                flex-direction: column;
            }

            .report-btn {
                width: 100%;
                box-sizing: border-box;
            }
        }

    </style>

</head>

<body class="student-dashboard-body">

<!-- MOBILE OVERLAY -->
<div class="admin-mobile-overlay" id="adminMobileOverlay"></div>

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

            <p>Administrator Account</p>

        </div>

        <!-- NAVIGATION -->
        <nav class="sidebar-navigation">

            <a href="dashboard.php">
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

            <a class="active" href="attendance_reports.php">
                <span>◷</span> Attendance Reports
            </a>

        </nav>

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

    <!-- MAIN CONTENT -->
    <main class="student-main">

        <!-- HEADER -->
        <header class="student-topbar">

            <button
                type="button"
                class="admin-mobile-menu"
                id="adminMobileMenu"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="adminSidebar"
            >
                ☰
            </button>

            <div>

                <h1>Attendance Reports</h1>

                <p>
                    View and monitor attendance records across the institution.
                </p>

            </div>

        </header>

        <!-- WELCOME -->
        <section class="student-welcome report-welcome">

            <div>

                <p class="welcome-label">
                    SMARTATTEND ADMINISTRATOR PORTAL
                </p>

                <h2>Attendance Reports</h2>

                <p>
                    Monitor student attendance by course, attendance session
                    and date.
                </p>

            </div>

            <div class="welcome-icon">
                ◷
            </div>

        </section>

        <!-- STATISTICS -->
        <section class="report-stats-grid">

            <div class="report-stat-card">

                <p>Total Attendance Records</p>

                <h2><?= number_format($totalAttendance) ?></h2>

            </div>

            <div class="report-stat-card present">

                <p>Present</p>

                <h2><?= number_format($presentCount) ?></h2>

            </div>

            <div class="report-stat-card late">

                <p>Late</p>

                <h2><?= number_format($lateCount) ?></h2>

            </div>

        </section>

        <!-- FILTERS -->
        <section class="report-card report-filter-card">

            <h2>Filter Attendance Records</h2>

            <form method="GET" action="attendance_reports.php">

                <div class="report-filter-grid">

                    <div class="report-filter-group">

                        <label for="course_id">Select Course</label>

                        <select name="course_id" id="course_id">

                            <option value="">All Courses</option>

                            <?php foreach ($courses as $course): ?>

                                <option
                                    value="<?= (int) $course['id'] ?>"
                                    <?= (string) $courseId === (string) $course['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($course['course_code']) ?>
                                    - <?= e($course['course_title']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="report-filter-group">

                        <label for="session_id">Attendance Session</label>

                        <select name="session_id" id="session_id">

                            <option value="">All Sessions</option>

                            <?php foreach ($sessions as $session): ?>

                                <option
                                    value="<?= (int) $session['id'] ?>"
                                    <?= (string) $sessionId === (string) $session['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($session['course_code']) ?>
                                    - <?= e($session['session_title']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="report-filter-group">

                        <label for="from_date">From Date</label>

                        <input
                            type="date"
                            name="from_date"
                            id="from_date"
                            value="<?= e($fromDate) ?>"
                        >

                    </div>

                    <div class="report-filter-group">

                        <label for="to_date">To Date</label>

                        <input
                            type="date"
                            name="to_date"
                            id="to_date"
                            value="<?= e($toDate) ?>"
                        >

                    </div>

                </div>

                <div class="report-actions">

                    <button type="submit" class="report-btn">
                        Apply Filters
                    </button>

                    <a
                        href="attendance_reports.php"
                        class="report-btn report-btn-secondary"
                    >
                        Reset Filters
                    </a>

                    <button
                        type="button"
                        onclick="window.print()"
                        class="report-btn report-btn-secondary"
                    >
                        Print Report
                    </button>

                    <button
                        type="submit"
                        name="export"
                        value="csv"
                        class="report-btn report-btn-secondary"
                    >
                        Export CSV
                    </button>

                </div>

            </form>

        </section>

        <!-- ATTENDANCE TABLE -->
        <section class="report-card">

            <div class="report-results-heading">

                <h2>Attendance Records</h2>

                <span class="report-count">
                    <?= number_format($totalAttendance) ?> record(s) found
                </span>

            </div>

            <?php if (!empty($records)): ?>

                <div class="report-table-wrapper">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>S/N</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Attendance Session</th>
                                <th>Lecturer</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Date & Time</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php $number = 1; ?>

                            <?php foreach ($records as $record): ?>

                                <?php

                                $studentName = trim(
                                    $record['first_name'] . ' ' .
                                    ($record['middle_name'] ?? '') . ' ' .
                                    $record['last_name']
                                );

                                $statusClass =
                                    $record['attendance_status'] === 'late'
                                    ? 'status-late'
                                    : 'status-present';

                                ?>

                                <tr>

                                    <td><?= $number++ ?></td>

                                    <td>

                                        <span class="student-name">
                                            <?= e($studentName) ?>
                                        </span>

                                        <span class="student-matric">
                                            <?= e($record['matric_number']) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <strong>
                                            <?= e($record['course_code']) ?>
                                        </strong>

                                        <br>

                                        <?= e($record['course_title']) ?>

                                    </td>

                                    <td>
                                        <?= e($record['session_title']) ?>
                                    </td>

                                    <td>
                                        <?= e($record['lecturer_name'] ?? 'N/A') ?>
                                    </td>

                                    <td>

                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= e($record['attendance_status']) ?>
                                        </span>

                                    </td>

                                    <td>
                                        <?= e($record['attendance_method']) ?>
                                    </td>

                                    <td>
                                        <?= date(
                                            'd M Y, h:i A',
                                            strtotime($record['attendance_time'])
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-report">

                    <strong>No Attendance Records Found</strong>

                    <p>
                        There are no attendance records matching your selected
                        filters.
                    </p>

                </div>

            <?php endif; ?>

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

<!-- =====================================
     HAMBURGER MENU JAVASCRIPT
===================================== -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const menuButton = document.getElementById('adminMobileMenu');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminMobileOverlay');

    if (!menuButton || !sidebar || !overlay) {
        console.error('Mobile menu elements were not found.');
        return;
    }

    function openMenu() {

        sidebar.classList.add('active');
        overlay.classList.add('active');

        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Close navigation menu');

        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {

        sidebar.classList.remove('active');
        overlay.classList.remove('active');

        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Open navigation menu');

        document.body.style.overflow = '';
    }

    menuButton.addEventListener('click', function (event) {

        event.preventDefault();

        if (sidebar.classList.contains('active')) {
            closeMenu();
        } else {
            openMenu();
        }

    });

    overlay.addEventListener('click', closeMenu);

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeMenu();
        }

    });

    sidebar.querySelectorAll('.sidebar-navigation a').forEach(function (link) {

        link.addEventListener('click', function () {

            if (window.innerWidth <= 768) {
                closeMenu();
            }

        });

    });

    window.addEventListener('resize', function () {

        if (window.innerWidth > 768) {
            closeMenu();
        }

    });

});

</script>

</body>
</html>
