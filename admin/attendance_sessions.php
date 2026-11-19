
<?php

require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// Administrator information
$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

$message = '';
$messageType = 'success';
$error = '';

// Handle closing an attendance session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';
    $sessionId = (int) ($_POST['session_id'] ?? 0);

    if ($action === 'close_session' && $sessionId > 0) {

        $closeSession = $pdo->prepare("
            UPDATE attendance_sessions
            SET status = 'closed',
                end_time = COALESCE(end_time, NOW())
            WHERE id = ?
              AND status = 'active'
        ");

        $closeSession->execute([$sessionId]);

        if ($closeSession->rowCount() > 0) {
            header("Location: attendance_sessions.php?session_id={$sessionId}&closed=1");
            exit;
        }

        header("Location: attendance_sessions.php?session_id={$sessionId}&closed=0");
        exit;
    }
}

// Display action messages
if (isset($_GET['closed'])) {

    if ($_GET['closed'] === '1') {
        $message = 'Attendance session closed successfully.';
        $messageType = 'success';

    } elseif ($_GET['closed'] === '0') {
        $message = 'This session is already closed or could not be updated.';
        $messageType = 'error';
    }
}

// Search and filter values
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'active', 'closed'], true)) {
    $statusFilter = 'all';
}

// Selected session
$selectedSessionId = filter_input(
    INPUT_GET,
    'session_id',
    FILTER_VALIDATE_INT
);

if (!$selectedSessionId || $selectedSessionId < 1) {
    $selectedSessionId = null;
}

// Get attendance session details
$selectedSession = null;
$sessionRecords = [];
$sessionAttendanceCount = 0;

if ($selectedSessionId) {

    $detailQuery = $pdo->prepare("
        SELECT
            a.id,
            a.session_title,
            a.status,
            a.start_time,
            a.end_time,
            a.created_at,
            a.allowed_radius,
            a.latitude,
            a.longitude,
            c.course_code,
            c.course_title,
            u.full_name AS lecturer_name,

            (
                SELECT COUNT(*)
                FROM attendance_records ar
                WHERE ar.session_id = a.id
            ) AS attendance_count

        FROM attendance_sessions a

        LEFT JOIN courses c
            ON c.id = a.course_id

        LEFT JOIN users u
            ON u.id = a.lecturer_id

        WHERE a.id = ?

        LIMIT 1
    ");

    $detailQuery->execute([$selectedSessionId]);

    $selectedSession = $detailQuery->fetch(PDO::FETCH_ASSOC);

    if ($selectedSession) {

        $sessionAttendanceCount = (int) $selectedSession['attendance_count'];

        // Get students who attended this particular session
        $recordsQuery = $pdo->prepare("
            SELECT
                ar.id,
                ar.attendance_status,
                ar.attendance_method,
                ar.attendance_time,
                s.matric_number,
                s.first_name,
                s.middle_name,
                s.last_name

            FROM attendance_records ar

            LEFT JOIN students s
                ON s.id = ar.student_id

            WHERE ar.session_id = ?

            ORDER BY ar.attendance_time DESC
        ");

        $recordsQuery->execute([$selectedSessionId]);

        $sessionRecords = $recordsQuery->fetchAll(PDO::FETCH_ASSOC);

    } else {

        $error = 'The selected attendance session could not be found.';
    }
}

// Count active sessions
$activeSessionCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_sessions
    WHERE status = 'active'
")->fetchColumn();

// Count closed sessions
$closedSessionCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_sessions
    WHERE status = 'closed'
")->fetchColumn();

// Count all sessions
$totalSessionCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_sessions
")->fetchColumn();

// Build attendance session list
$sql = "
    SELECT
        a.id,
        a.session_title,
        a.status,
        a.start_time,
        a.end_time,
        a.created_at,
        c.course_code,
        c.course_title,
        u.full_name AS lecturer_name,

        (
            SELECT COUNT(*)
            FROM attendance_records ar
            WHERE ar.session_id = a.id
        ) AS attendance_count

    FROM attendance_sessions a

    LEFT JOIN courses c
        ON c.id = a.course_id

    LEFT JOIN users u
        ON u.id = a.lecturer_id

    WHERE 1 = 1
";

$params = [];

// Search filter
if ($search !== '') {

    $sql .= "
        AND (
            a.session_title LIKE ?
            OR c.course_code LIKE ?
            OR c.course_title LIKE ?
            OR u.full_name LIKE ?
        )
    ";

    $searchTerm = "%{$search}%";

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Status filter
if ($statusFilter !== 'all') {

    $sql .= " AND a.status = ? ";

    $params[] = $statusFilter;
}

$sql .= " ORDER BY a.id DESC ";

$sessionsQuery = $pdo->prepare($sql);
$sessionsQuery->execute($params);

$sessions = $sessionsQuery->fetchAll(PDO::FETCH_ASSOC);

// Helper for displaying dates
function sessionDate($date)
{
    if (empty($date)) {
        return 'Not available';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return 'Not available';
    }

    return date('d M Y, h:i A', $timestamp);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendance Sessions | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        /* MOBILE HAMBURGER MENU */

        .admin-menu-toggle {
            display: none;
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            border: none;
            border-radius: 8px;
            background: #145c39;
            color: #fff;
            font-size: 25px;
            line-height: 1;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        .admin-sidebar-overlay {
            display: none;
        }

        .session-welcome {
            background: linear-gradient(120deg, #145c39, #24784d);
        }

        .session-welcome .welcome-icon {
            background: rgba(255, 255, 255, 0.13);
        }

        .session-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .session-stat-card {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 21px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
        }

        .session-stat-card p {
            color: #718078;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .session-stat-card h2 {
            color: #145c39;
            font-size: 27px;
            font-weight: 800;
            margin: 0;
        }

        .session-panel {
            background: #fff;
            border: 1px solid #e7eee9;
            border-radius: 12px;
            padding: 23px;
            margin-bottom: 23px;
            box-shadow: 0 4px 15px rgba(20, 92, 57, 0.04);
        }

        .session-panel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .session-panel-heading h2 {
            color: #244b35;
            font-size: 17px;
            margin: 0;
        }

        .session-panel-heading p {
            color: #829087;
            font-size: 12px;
            margin-top: 6px;
        }

        .session-filter {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .session-filter input,
        .session-filter select {
            min-height: 43px;
            padding: 10px 13px;
            border: 1px solid #dce7df;
            border-radius: 7px;
            font-size: 12px;
            outline: none;
            background: #fff;
            color: #244b35;
        }

        .session-filter input {
            flex: 1;
            min-width: 180px;
        }

        .session-filter input:focus,
        .session-filter select:focus {
            border-color: #145c39;
        }

        .session-filter button {
            padding: 11px 19px;
            background: #145c39;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .session-filter button:hover {
            background: #103c29;
        }

        .session-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .session-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .session-table th {
            background: #f3f8f4;
            color: #557061;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            padding: 14px 12px;
            white-space: nowrap;
        }

        .session-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #edf1ee;
            color: #46584d;
            font-size: 12px;
            vertical-align: middle;
        }

        .session-table tr:hover td {
            background: #fbfdfb;
        }

        .session-title {
            color: #244b35;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .session-subtitle {
            color: #89958d;
            font-size: 11px;
        }

        .session-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .session-status.active {
            background: #e2f5e9;
            color: #197447;
        }

        .session-status.closed {
            background: #f0f1f1;
            color: #65716a;
        }

        .session-view-btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            background: #e8f4ec;
            color: #145c39;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .session-view-btn:hover {
            background: #145c39;
            color: #fff;
        }

        .session-close-form {
            margin-top: 7px;
        }

        .session-close-btn {
            padding: 7px 11px;
            background: #fff0ed;
            color: #b33b2e;
            border: 1px solid #f1d2cd;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
        }

        .session-close-btn:hover {
            background: #b33b2e;
            color: #fff;
        }

        .session-alert {
            padding: 14px 17px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .session-alert.success {
            background: #e7f5eb;
            color: #17623b;
            border: 1px solid #c9e8d2;
        }

        .session-alert.error {
            background: #fff0ed;
            color: #a83225;
            border: 1px solid #f0d0ca;
        }

        .session-empty {
            text-align: center;
            padding: 45px 15px;
            color: #829087;
        }

        .session-empty strong {
            display: block;
            color: #244b35;
            font-size: 15px;
            margin-bottom: 8px;
        }

        .session-details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .session-detail-item {
            padding: 15px;
            border: 1px solid #e7eee9;
            background: #f9fcfa;
            border-radius: 8px;
        }

        .session-detail-item span {
            display: block;
            color: #829087;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .session-detail-item strong {
            display: block;
            color: #244b35;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .session-back-btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 15px;
            border-radius: 7px;
            background: #e8f4ec;
            color: #145c39;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .session-back-btn:hover {
            background: #145c39;
            color: #fff;
        }

        .session-record-count {
            display: inline-block;
            padding: 5px 9px;
            background: #e8f4ec;
            color: #145c39;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .attendance-present {
            color: #197447;
            font-weight: 700;
        }

        .attendance-late {
            color: #b7791f;
            font-weight: 700;
        }

        .session-action-area {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        @media (max-width: 850px) {

            .session-details-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }


@media (max-width: 700px) {

    html,
    body {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }

    body.admin-sidebar-open {
        overflow: hidden;
    }

    .student-layout {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .admin-menu-toggle {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 43px;
        height: 43px;
        margin: 0;
    }

    .student-sidebar {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        bottom: 0 !important;
        width: 270px !important;
        max-width: 85vw !important;
        height: 100vh !important;
        height: 100dvh !important;
        margin: 0 !important;
        overflow-y: auto;
        z-index: 1001;
        transform: translateX(-105%);
        transition: transform 0.3s ease;
    }

    body.admin-sidebar-open .student-sidebar {
        transform: translateX(0);
    }

    .admin-sidebar-overlay {
        display: block !important;
        position: fixed;
        inset: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.45);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    body.admin-sidebar-open .admin-sidebar-overlay {
        opacity: 1;
        visibility: visible;
    }

    .student-main {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 15px !important;
        box-sizing: border-box !important;
    }

    .student-topbar {
        display: flex !important;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        width: 100%;
        margin: 0 0 20px;
        padding: 10px 0;
        box-sizing: border-box;
    }

    .student-topbar > div {
        flex: 1;
        min-width: 0;
    }

    .student-topbar h1 {
        font-size: 18px;
        line-height: 1.35;
        margin: 0;
    }

    .student-topbar p {
        font-size: 11px;
        line-height: 1.5;
        margin-top: 5px;
    }

    .student-welcome,
    .session-stats,
    .session-panel,
    .student-footer {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .session-stats {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .session-details-grid {
        grid-template-columns: 1fr;
    }

    .session-panel {
        padding: 15px;
    }

    .session-filter {
        flex-direction: column;
    }

    .session-filter input,
    .session-filter select,
    .session-filter button {
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }

    .session-table-wrapper {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
    }

}


        @media (max-width: 650px) {

            .session-stats {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .session-details-grid {
                grid-template-columns: 1fr;
            }

            .session-panel {
                padding: 16px;
            }

            .session-filter {
                flex-direction: column;
            }

            .session-filter input,
            .session-filter select,
            .session-filter button {
                width: 100%;
                box-sizing: border-box;
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

            <a class="active" href="attendance_sessions.php">
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
        aria-hidden="true"
    ></div>

    <!-- MAIN CONTENT -->
    <main class="student-main">

        <!-- HEADER -->
        <header class="student-topbar">

            <!-- MOBILE HAMBURGER BUTTON -->
            <button
                type="button"
                class="admin-menu-toggle"
                id="adminMenuToggle"
                aria-label="Open navigation menu"
                aria-controls="adminSidebar"
                aria-expanded="false"
            >
                ☰
            </button>

            <div>

                <h1>Attendance Session Management</h1>

                <p>
                    View and manage attendance sessions across the institution.
                </p>

            </div>

        </header>

        <!-- WELCOME BANNER -->
        <section class="student-welcome session-welcome">

            <div>

                <p class="welcome-label">
                    SMARTATTEND ADMINISTRATOR PORTAL
                </p>

                <h2>Attendance Sessions</h2>

                <p>
                    Monitor lecturer-created sessions and inspect
                    attendance records for each individual class.
                </p>

            </div>

            <div class="welcome-icon">
                ◷
            </div>

        </section>

        <!-- ALERTS -->
        <?php if ($message): ?>

            <div class="session-alert <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="session-alert error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <!-- SESSION STATISTICS -->
        <section class="session-stats">

            <div class="session-stat-card">

                <p>Total Attendance Sessions</p>

                <h2><?= number_format($totalSessionCount) ?></h2>

            </div>

            <div class="session-stat-card">

                <p>Active Sessions</p>

                <h2><?= number_format($activeSessionCount) ?></h2>

            </div>

            <div class="session-stat-card">

                <p>Closed Sessions</p>

                <h2><?= number_format($closedSessionCount) ?></h2>

            </div>

        </section>

        <?php if ($selectedSession): ?>

            <!-- SESSION DETAILS -->
            <section class="session-panel">

                <a href="attendance_sessions.php" class="session-back-btn">
                    ← Back to All Sessions
                </a>

                <div class="session-panel-heading">

                    <div>

                        <h2>
                            <?= e($selectedSession['session_title'] ?: 'Attendance Session') ?>
                        </h2>

                        <p>
                            Attendance records for this particular class session.
                        </p>

                    </div>

                    <span class="session-status <?= e($selectedSession['status']) ?>">
                        <?= e($selectedSession['status']) ?>
                    </span>

                </div>

                <div class="session-details-grid">

                    <div class="session-detail-item">
                        <span>Course Code</span>
                        <strong>
                            <?= e($selectedSession['course_code'] ?? 'Not available') ?>
                        </strong>
                    </div>

                    <div class="session-detail-item">
                        <span>Course Title</span>
                        <strong>
                            <?= e($selectedSession['course_title'] ?? 'Not available') ?>
                        </strong>
                    </div>

                    <div class="session-detail-item">
                        <span>Lecturer</span>
                        <strong>
                            <?= e($selectedSession['lecturer_name'] ?? 'Not available') ?>
                        </strong>
                    </div>

                    <div class="session-detail-item">
                        <span>Session Start</span>
                        <strong>
                            <?= e(sessionDate($selectedSession['start_time'])) ?>
                        </strong>
                    </div>

                    <div class="session-detail-item">
                        <span>Session End</span>
                        <strong>
                            <?= e(sessionDate($selectedSession['end_time'])) ?>
                        </strong>
                    </div>

                    <div class="session-detail-item">
                        <span>Attendance Count</span>
                        <strong>
                            <?= number_format($sessionAttendanceCount) ?> Students
                        </strong>
                    </div>

                </div>

                <?php if ($selectedSession['status'] === 'active'): ?>

                    <form
                        action="attendance_sessions.php"
                        method="POST"
                        onsubmit="return confirm('Are you sure you want to close this attendance session? Students will no longer be able to record attendance for it.');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="close_session"
                        >

                        <input
                            type="hidden"
                            name="session_id"
                            value="<?= (int) $selectedSession['id'] ?>"
                        >

                        <button type="submit" class="session-close-btn">
                            Close Attendance Session
                        </button>

                    </form>

                <?php endif; ?>

            </section>

            <!-- ATTENDANCE RECORDS FOR SELECTED SESSION -->
            <section class="session-panel">

                <div class="session-panel-heading">

                    <div>

                        <h2>Student Attendance Records</h2>

                        <p>
                            Only attendance records belonging to this session are displayed.
                        </p>

                    </div>

                    <span class="session-record-count">
                        <?= number_format($sessionAttendanceCount) ?> Records
                    </span>

                </div>

                <?php if (empty($sessionRecords)): ?>

                    <div class="session-empty">

                        <strong>No Attendance Records Yet</strong>

                        No student has recorded attendance for this session.

                    </div>

                <?php else: ?>

                    <div class="session-table-wrapper">

                        <table class="session-table">

                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student Name</th>
                                    <th>Matric Number</th>
                                    <th>Attendance Status</th>
                                    <th>Method</th>
                                    <th>Attendance Time</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($sessionRecords as $index => $record): ?>

                                    <?php
                                    $studentName = trim(
                                        ($record['first_name'] ?? '') . ' ' .
                                        ($record['middle_name'] ?? '') . ' ' .
                                        ($record['last_name'] ?? '')
                                    );

                                    if ($studentName === '') {
                                        $studentName = 'Unknown Student';
                                    }

                                    $attendanceStatus = strtolower(
                                        $record['attendance_status'] ?? ''
                                    );
                                    ?>

                                    <tr>

                                        <td><?= $index + 1 ?></td>

                                        <td>
                                            <strong>
                                                <?= e($studentName) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($record['matric_number'] ?? 'N/A') ?>
                                        </td>

                                        <td>
                                            <span class="<?= $attendanceStatus === 'late' ? 'attendance-late' : 'attendance-present' ?>">
                                                <?= e(ucfirst($record['attendance_status'] ?? 'Unknown')) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(strtoupper($record['attendance_method'] ?? 'N/A')) ?>
                                        </td>

                                        <td>
                                            <?= e(sessionDate($record['attendance_time'])) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        <?php else: ?>

            <!-- ALL ATTENDANCE SESSIONS -->
            <section class="session-panel">

                <div class="session-panel-heading">

                    <div>

                        <h2>All Attendance Sessions</h2>

                        <p>
                            View and manage all attendance sessions created by lecturers.
                        </p>

                    </div>

                </div>

                <!-- SEARCH AND FILTER -->
                <form method="GET" action="attendance_sessions.php" class="session-filter">

                    <input
                        type="text"
                        name="search"
                        placeholder="Search course, lecturer or session..."
                        value="<?= e($search) ?>"
                    >

                    <select name="status">

                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                            All Sessions
                        </option>

                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                            Active Sessions
                        </option>

                        <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>
                            Closed Sessions
                        </option>

                    </select>

                    <button type="submit">
                        Search Sessions
                    </button>

                </form>

                <?php if (empty($sessions)): ?>

                    <div class="session-empty">

                        <strong>No Attendance Sessions Found</strong>

                        There are currently no sessions matching your search.

                    </div>

                <?php else: ?>

                    <div class="session-table-wrapper">

                        <table class="session-table">

                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Session</th>
                                    <th>Course</th>
                                    <th>Lecturer</th>
                                    <th>Attendance</th>
                                    <th>Start Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($sessions as $index => $session): ?>

                                    <tr>

                                        <td><?= $index + 1 ?></td>

                                        <td>

                                            <div class="session-title">
                                                <?= e($session['session_title'] ?: 'Attendance Session') ?>
                                            </div>

                                            <div class="session-subtitle">
                                                Session #<?= (int) $session['id'] ?>
                                            </div>

                                        </td>

                                        <td>

                                            <div class="session-title">
                                                <?= e($session['course_code'] ?? 'N/A') ?>
                                            </div>

                                            <div class="session-subtitle">
                                                <?= e($session['course_title'] ?? 'Unknown Course') ?>
                                            </div>

                                        </td>

                                        <td>
                                            <?= e($session['lecturer_name'] ?? 'Unknown Lecturer') ?>
                                        </td>

                                        <td>
                                            <span class="session-record-count">
                                                <?= number_format((int) $session['attendance_count']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(sessionDate($session['start_time'])) ?>
                                        </td>

                                        <td>
                                            <span class="session-status <?= e($session['status']) ?>">
                                                <?= e($session['status']) ?>
                                            </span>
                                        </td>

                                        <td>

                                            <div class="session-action-area">

                                                <a
                                                    href="attendance_sessions.php?session_id=<?= (int) $session['id'] ?>"
                                                    class="session-view-btn"
                                                >
                                                    View Records
                                                </a>

                                                <?php if ($session['status'] === 'active'): ?>

                                                    <form
                                                        action="attendance_sessions.php"
                                                        method="POST"
                                                        class="session-close-form"
                                                        onsubmit="return confirm('Are you sure you want to close this attendance session?');"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(csrf_token()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="close_session"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="session_id"
                                                            value="<?= (int) $session['id'] ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="session-close-btn"
                                                        >
                                                            Close Session
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

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

<script>
document.addEventListener('DOMContentLoaded', function () {

    const menuButton = document.getElementById('adminMenuToggle');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminSidebarOverlay');

    if (!menuButton || !sidebar || !overlay) {
        return;
    }

    function openMenu() {
        document.body.classList.add('admin-sidebar-open');

        menuButton.textContent = '✕';
        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Close navigation menu');

        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeMenu() {
        document.body.classList.remove('admin-sidebar-open');

        menuButton.textContent = '☰';
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Open navigation menu');

        overlay.setAttribute('aria-hidden', 'true');
    }

    menuButton.addEventListener('click', function () {

        if (document.body.classList.contains('admin-sidebar-open')) {
            closeMenu();
        } else {
            openMenu();
        }

    });

    overlay.addEventListener('click', closeMenu);

    // Close menu after selecting a navigation link
    sidebar.querySelectorAll('.sidebar-navigation a').forEach(function (link) {

        link.addEventListener('click', closeMenu);

    });

    // Close menu with Escape key
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeMenu();
        }

    });

    // Close menu when returning to desktop screen size
    window.addEventListener('resize', function () {

        if (window.innerWidth > 700) {
            closeMenu();
        }

    });

});
</script>

</body>
</html>
