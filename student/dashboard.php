
<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$studentId = (int) $_SESSION['student_id'];

// Get student details, including account status.
$stmt = $pdo->prepare("
    SELECT id, first_name, middle_name, last_name, matric_number,
           email, department, programme, level, profile_image, status
    FROM students
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Ensure student exists and account is active.
if (!$student || $student['status'] !== 'active') {

    unset(
        $_SESSION['student_id'],
        $_SESSION['student_name'],
        $_SESSION['matric_number']
    );

    session_regenerate_id(true);

    $_SESSION['student_login_error'] =
        'Your account has been deactivated. Please contact the administrator.';

    header("Location: login.php");
    exit;
}

// Get student's profile image.
$profileImage = !empty($student['profile_image'])
    ? '../uploads/profile/' . rawurlencode($student['profile_image'])
    : null;

$studentName = trim(
    $student['first_name'] . ' ' .
    ($student['middle_name'] ?? '') . ' ' .
    ($student['last_name'] ?? '')
);

$_SESSION['student_name'] = $studentName;


// ============================================
// GET STUDENT'S REGISTERED COURSES
// ============================================

$stmt = $pdo->prepare("
    SELECT c.id, c.course_code, c.course_title, c.department,
           c.level, c.semester
    FROM enrollments e
    INNER JOIN courses c ON c.id = e.course_id
    WHERE e.student_id = ?
    ORDER BY c.course_code ASC
");

$stmt->execute([$studentId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================
// COUNT REGISTERED COURSES
// ============================================

$totalCourses = count($courses);


// ============================================
// GET SELECTED COURSE
// ============================================

$selectedCourseId = filter_input(
    INPUT_GET,
    'course_id',
    FILTER_VALIDATE_INT
);

$selectedCourseId = (
    $selectedCourseId !== false &&
    $selectedCourseId !== null &&
    $selectedCourseId > 0
) ? (int) $selectedCourseId : 0;

$selectedCourse = null;

// Ensure the selected course belongs to this student.
if ($selectedCourseId > 0) {

    foreach ($courses as $course) {

        if ((int) $course['id'] === $selectedCourseId) {
            $selectedCourse = $course;
            break;
        }

    }

    if (!$selectedCourse) {
        $selectedCourseId = 0;
    }
}


// ============================================
// ATTENDANCE STATISTICS
// ============================================

$attendanceSummarySql = "
    SELECT
        COUNT(ar.id) AS total_attendance,
        COALESCE(SUM(
            CASE
                WHEN ar.attendance_status = 'present' THEN 1
                ELSE 0
            END
        ), 0) AS present_count,
        COALESCE(SUM(
            CASE
                WHEN ar.attendance_status = 'late' THEN 1
                ELSE 0
            END
        ), 0) AS late_count
    FROM attendance_records ar
    INNER JOIN attendance_sessions s
        ON s.id = ar.session_id
    WHERE ar.student_id = ?
";

$summaryParams = [$studentId];

if ($selectedCourseId > 0) {
    $attendanceSummarySql .= " AND s.course_id = ?";
    $summaryParams[] = $selectedCourseId;
}

$summaryStmt = $pdo->prepare($attendanceSummarySql);
$summaryStmt->execute($summaryParams);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalAttendance = (int) ($summary['total_attendance'] ?? 0);
$presentCount = (int) ($summary['present_count'] ?? 0);
$lateCount = (int) ($summary['late_count'] ?? 0);


// ============================================
// COUNT CLASSES HELD
// ============================================

$classesSql = "
    SELECT COUNT(*)
    FROM attendance_sessions s
    INNER JOIN enrollments e
        ON e.course_id = s.course_id
    WHERE e.student_id = ?
";

$classesParams = [$studentId];

if ($selectedCourseId > 0) {
    $classesSql .= " AND s.course_id = ?";
    $classesParams[] = $selectedCourseId;
}

$classesStmt = $pdo->prepare($classesSql);
$classesStmt->execute($classesParams);

$totalClasses = (int) $classesStmt->fetchColumn();


// ============================================
// CALCULATE ATTENDANCE RATE
// ============================================

$attendanceRate = $totalClasses > 0
    ? round(($totalAttendance / $totalClasses) * 100)
    : 0;


// ============================================
// GET RECENT ATTENDANCE HISTORY
// ============================================

$attendanceSql = "
    SELECT
        ar.attendance_time AS marked_at,
        ar.attendance_status,
        s.id AS session_id,
        c.course_code,
        c.course_title
    FROM attendance_records ar
    INNER JOIN attendance_sessions s
        ON s.id = ar.session_id
    INNER JOIN courses c
        ON c.id = s.course_id
    WHERE ar.student_id = ?
";

$attendanceParams = [$studentId];

if ($selectedCourseId > 0) {
    $attendanceSql .= " AND s.course_id = ?";
    $attendanceParams[] = $selectedCourseId;
}

$attendanceSql .= "
    ORDER BY ar.attendance_time DESC
    LIMIT 10
";

$attendanceStmt = $pdo->prepare($attendanceSql);
$attendanceStmt->execute($attendanceParams);

$attendanceHistory = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================
// FORMAT ATTENDANCE DATE AND TIME
// ============================================

function dashboardDate($date)
{
    if (empty($date)) {
        return 'N/A';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return 'Invalid date';
    }

    return date('d M Y, h:i A', $timestamp);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Dashboard | SmartAttend</title>
     <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        /* ATTENDANCE COURSE FILTER */

        .attendance-course-filter {
            margin-bottom: 22px;
            padding: 18px;
            background: #f7fbf7;
            border: 1px solid #e3ebe4;
            border-radius: 9px;
        }

        .attendance-course-filter label {
            display: block;
            color: #14532d;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .attendance-course-filter select {
            width: 100%;
            max-width: 450px;
            padding: 12px;
            border: 1px solid #cbd8ce;
            border-radius: 7px;
            background: white;
            color: #26352b;
            font-size: 14px;
            outline: none;
        }

        .attendance-course-filter select:focus {
            border-color: #166534;
            box-shadow: 0 0 0 3px rgba(22, 101, 52, 0.10);
        }

        .attendance-filter-description {
            margin-top: 9px;
            color: #647067;
            font-size: 13px;
        }

    </style>

</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <!-- SIDEBAR -->
    <aside class="student-sidebar" id="studentSidebar">

        <div class="sidebar-brand">

            <div class="brand-symbol">SA</div>

            <h2>SmartAttend</h2>

        </div>

        <!-- STUDENT PROFILE -->
        <div class="sidebar-profile">

            <?php if ($profileImage): ?>

                <img src="<?= e($profileImage) ?>" alt="Profile picture">

            <?php else: ?>

                <div class="profile-placeholder">
                    <?= e(strtoupper(substr($student['first_name'], 0, 1))) ?>
                </div>

            <?php endif; ?>

            <h3><?= e($studentName) ?></h3>

            <p><?= e($student['matric_number']) ?></p>

        </div>

        <!-- NAVIGATION -->
        <nav class="sidebar-navigation">

            <a class="active" href="dashboard.php">
                <span>▦</span> Dashboard
            </a>

            <a href="profile.php">
                <span>♙</span> My Profile
            </a>

            <a href="change_password.php">
                <span>🔒</span> Change Password
            </a>

            <a href="register-courses.php">
                <span>＋</span> Register Courses
            </a>

            <a href="#my-courses">
                <span>▤</span> My Courses
            </a>

            <a href="attendance.php">
                <span>◷</span> Attendance History
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
    <button
        type="button"
        class="student-sidebar-overlay"
        id="studentSidebarOverlay"
        aria-label="Close navigation menu"
        tabindex="-1"
    ></button>

    <!-- MAIN DASHBOARD -->
    <main class="student-main">

        <!-- TOP HEADER -->
        <header class="student-topbar">

            <button
                type="button"
                class="student-menu-toggle"
                id="studentMenuToggle"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="studentSidebar"
            >
                ☰
            </button>

            <div>

                <h1>Student Dashboard</h1>

                <p>Welcome back, <?= e($student['first_name']) ?>.</p>

            </div>

        </header>

        <!-- WELCOME BANNER -->
        <section class="student-welcome">

            <div>

                <p class="welcome-label">
                    SMARTATTEND STUDENT PORTAL
                </p>

                <h2>Hello, <?= e($student['first_name']) ?>!</h2>

                <p>
                    Keep track of your courses and monitor your
                    attendance records from one place.
                </p>

            </div>

            <div class="welcome-icon">✓</div>

        </section>

        <!-- STATISTICS -->
        <section class="student-stat-grid">

            <div class="student-stat-card">

                <div class="stat-icon blue-icon">▤</div>

                <p>Registered Courses</p>

                <h2><?= $totalCourses ?></h2>

            </div>

            <div class="student-stat-card">

                <div class="stat-icon green-icon">✓</div>

                <p>
                    <?= $selectedCourse
                        ? 'Course Attendance Records'
                        : 'Attendance Records' ?>
                </p>

                <h2><?= $totalAttendance ?></h2>

            </div>

            <div class="student-stat-card">

                <div class="stat-icon orange-icon">◷</div>

                <p>
                    <?= $selectedCourse
                        ? 'Course Classes Held'
                        : 'Classes Held' ?>
                </p>

                <h2><?= $totalClasses ?></h2>

            </div>

            <div class="student-stat-card">

                <div class="stat-icon purple-icon">%</div>

                <p>
                    <?= $selectedCourse
                        ? 'Course Attendance Rate'
                        : 'Attendance Rate' ?>
                </p>

                <h2><?= $attendanceRate ?>%</h2>

            </div>

        </section>

        <!-- MY COURSES -->
        <section class="student-content-card" id="my-courses">

            <div class="section-heading">

                <div>

                    <h2>My Courses</h2>

                    <p>Courses registered to your student account.</p>

                </div>

            </div>

            <?php if (count($courses) > 0): ?>

                <div class="student-course-grid">

                    <?php foreach ($courses as $course): ?>

                        <div class="student-course-card">

                            <div class="course-card-icon">▤</div>

                            <h3><?= e($course['course_code']) ?></h3>

                            <p><?= e($course['course_title']) ?></p>

                            <div class="course-details">

                                <span>
                                    Level: <?= e($course['level'] ?? 'N/A') ?>
                                </span>

                                <span>
                                    Semester: <?= e($course['semester'] ?? 'N/A') ?>
                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div>▤</div>

                    <h3>No courses registered yet</h3>

                    <p>Your registered courses will appear here.</p>

                </div>

            <?php endif; ?>

        </section>

        <!-- ATTENDANCE HISTORY -->
        <section class="student-content-card" id="attendance-history">

            <div class="section-heading">

                <div>

                    <h2>Recent Attendance</h2>

                    <p>
                        View attendance records separately for each course.
                    </p>

                </div>

            </div>

            <!-- COURSE SELECTION -->
            <div class="attendance-course-filter">

                <form method="GET" action="dashboard.php">

                    <label for="course_id">
                        Select Course
                    </label>

                    <select
                        name="course_id"
                        id="course_id"
                        onchange="this.form.submit()"
                    >

                        <option value="0"
                            <?= $selectedCourseId === 0 ? 'selected' : '' ?>>

                            All Courses

                        </option>

                        <?php foreach ($courses as $course): ?>

                            <option
                                value="<?= (int) $course['id'] ?>"
                                <?= $selectedCourseId === (int) $course['id'] ? 'selected' : '' ?>
                            >

                                <?= e($course['course_code']) ?>
                                - <?= e($course['course_title']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <p class="attendance-filter-description">

                        <?php if ($selectedCourse): ?>

                            Showing attendance records for
                            <strong><?= e($selectedCourse['course_code']) ?></strong> only.

                        <?php else: ?>

                            Showing attendance records from all your registered courses.

                        <?php endif; ?>

                    </p>

                </form>

            </div>

            <!-- ATTENDANCE TABLE -->
            <?php if (count($attendanceHistory) > 0): ?>

                <div class="student-table-wrapper">

                    <table class="student-table">

                        <thead>

                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Date and Time</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($attendanceHistory as $record): ?>

                                <tr>

                                    <td>
                                        <?= e($record['course_code']) ?>
                                    </td>

                                    <td>
                                        <?= e($record['course_title']) ?>
                                    </td>

                                    <td>
                                        <?= e(dashboardDate($record['marked_at'])) ?>
                                    </td>

                                    <td>

                                        <?php
                                        $status = strtolower(
                                            $record['attendance_status']
                                        );

                                        $statusClass = $status === 'late'
                                            ? 'attendance-late'
                                            : 'attendance-present';
                                        ?>

                                        <span class="<?= e($statusClass) ?>">
                                            <?= e(ucfirst($status)) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div>◷</div>

                    <h3>No attendance records found</h3>

                    <p>

                        <?php if ($selectedCourse): ?>

                            You have no attendance records for
                            <?= e($selectedCourse['course_code']) ?> yet.

                        <?php else: ?>

                            Your attendance history will appear here
                            after attendance is recorded.

                        <?php endif; ?>

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

<!-- MOBILE SIDEBAR SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const toggle = document.getElementById('studentMenuToggle');
    const sidebar = document.getElementById('studentSidebar');
    const overlay = document.getElementById('studentSidebarOverlay');

    if (!toggle || !sidebar || !overlay) {
        return;
    }

    function openMenu() {
        document.body.classList.add('student-sidebar-open');

        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close navigation menu');
        toggle.innerHTML = '✕';
    }

    function closeMenu() {
        document.body.classList.remove('student-sidebar-open');

        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation menu');
        toggle.innerHTML = '☰';
    }

    toggle.addEventListener('click', function () {

        if (document.body.classList.contains('student-sidebar-open')) {
            closeMenu();
        } else {
            openMenu();
        }

    });

    overlay.addEventListener('click', closeMenu);

    sidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeMenu);

    });

    if (window.matchMedia('(max-width: 700px)').matches) {

        if (!sessionStorage.getItem('studentMenuShown')) {

            openMenu();

            sessionStorage.setItem('studentMenuShown', 'true');

        }

    }

});
</script>

</body>
</html>
