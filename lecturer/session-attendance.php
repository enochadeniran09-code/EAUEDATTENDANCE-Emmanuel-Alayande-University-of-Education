
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];
$sessionId = (int) ($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

$error = '';
$success = '';
$session = null;
$attendanceRecords = [];

// ============================================
// AUTOMATICALLY CLOSE THIS SESSION IF EXPIRED
// ============================================

$expireStmt = $pdo->prepare("
    UPDATE attendance_sessions
    SET status = 'closed'
    WHERE id = ?
      AND lecturer_id = ?
      AND status = 'active'
      AND end_time <= NOW()
");

$expireStmt->execute([$sessionId, $lecturerId]);

// Get this session, ensuring it belongs to the logged-in lecturer.
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.course_id,
        s.session_title,
        s.status,
        s.start_time,
        s.end_time,
        c.course_code,
        c.course_title
    FROM attendance_sessions s
    INNER JOIN courses c ON c.id = s.course_id
    WHERE s.id = ?
      AND s.lecturer_id = ?
    LIMIT 1
");

$stmt->execute([$sessionId, $lecturerId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(404);
    exit('Attendance session not found or you do not have permission to access it.');
}

// Check whether this session is currently accepting attendance.
$sessionIsOpen = (
    strtolower($session['status']) === 'active' &&
    strtotime($session['start_time']) <= time() &&
    strtotime($session['end_time']) > time()
);

// ============================================
// PROCESS LECTURER-RECORDED ATTENDANCE
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $matricNumber = trim($_POST['matric_number'] ?? '');

    if (!$sessionIsOpen) {

        $error = 'This attendance session is not currently open.';

    } elseif ($matricNumber === '') {

        $error = 'Please enter the student matric number.';

    } else {

        // Find the student using their matric number.
        $studentCheck = $pdo->prepare("
            SELECT id, first_name, middle_name, last_name, matric_number
            FROM students
            WHERE UPPER(matric_number) = UPPER(?)
            LIMIT 1
        ");

        $studentCheck->execute([$matricNumber]);
        $student = $studentCheck->fetch(PDO::FETCH_ASSOC);

        if (!$student) {

            $error = 'No student was found with that matric number.';

        } else {

            // Confirm that the student is enrolled in this course.
            $enrollmentCheck = $pdo->prepare("
                SELECT id
                FROM enrollments
                WHERE student_id = ?
                  AND course_id = ?
                LIMIT 1
            ");

            $enrollmentCheck->execute([
                $student['id'],
                $session['course_id']
            ]);

            if (!$enrollmentCheck->fetch()) {

                $error = 'This student is not registered for this course.';

            } else {

                // Prevent the same student being recorded twice.
                $duplicateCheck = $pdo->prepare("
                    SELECT id
                    FROM attendance_records
                    WHERE session_id = ?
                      AND student_id = ?
                    LIMIT 1
                ");

                $duplicateCheck->execute([
                    $sessionId,
                    $student['id']
                ]);

                if ($duplicateCheck->fetch()) {

                    $error = 'This student already has an attendance record for this session.';

                } else {

                    try {

                        // Only insert attendance if the session is
                        // still active and has not reached its deadline.
                        $insert = $pdo->prepare("
                            INSERT INTO attendance_records (
                                session_id,
                                student_id,
                                selfie_path,
                                student_latitude,
                                student_longitude,
                                location_accuracy,
                                attendance_method,
                                attendance_status,
                                attendance_time
                            )
                            SELECT
                                ?, ?, NULL, NULL, NULL, NULL,
                                'lecturer', 'present', NOW()
                            FROM attendance_sessions
                            WHERE id = ?
                              AND lecturer_id = ?
                              AND status = 'active'
                              AND start_time <= NOW()
                              AND end_time > NOW()
                        ");

                        $insert->execute([
                            $sessionId,
                            $student['id'],
                            $sessionId,
                            $lecturerId
                        ]);

                        if ($insert->rowCount() === 1) {

                            $success = 'Attendance recorded successfully for '
                                . trim(
                                    $student['first_name'] . ' ' .
                                    ($student['middle_name'] ?? '') . ' ' .
                                    ($student['last_name'] ?? '')
                                )
                                . ' (' . $student['matric_number'] . ').';

                        } else {

                            // The session may have expired while
                            // the attendance request was being processed.
                            $expireStmt->execute([
                                $sessionId,
                                $lecturerId
                            ]);

                            $error = 'This attendance session has expired or has been closed. No attendance was recorded.';
                        }

                    } catch (PDOException $e) {

                        if (
                            isset($e->errorInfo[1]) &&
                            (int) $e->errorInfo[1] === 1062
                        ) {
                            $error = 'This student already has attendance for this session.';
                        } else {
                            $error = 'Unable to record attendance. Please try again.';
                        }
                    }
                }
            }
        }
    }
}

// ============================================
// REFRESH SESSION STATUS AFTER POST
// ============================================

$expireStmt->execute([$sessionId, $lecturerId]);

$stmt = $pdo->prepare("
    SELECT status, start_time, end_time
    FROM attendance_sessions
    WHERE id = ?
      AND lecturer_id = ?
    LIMIT 1
");

$stmt->execute([$sessionId, $lecturerId]);
$updatedSession = $stmt->fetch(PDO::FETCH_ASSOC);

if ($updatedSession) {
    $session['status'] = $updatedSession['status'];
    $session['start_time'] = $updatedSession['start_time'];
    $session['end_time'] = $updatedSession['end_time'];
}

$sessionIsOpen = (
    strtolower($session['status']) === 'active' &&
    strtotime($session['start_time']) <= time() &&
    strtotime($session['end_time']) > time()
);

// Get all attendance records for this particular session.
$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.attendance_time,
        ar.attendance_status,
        ar.attendance_method,
        st.first_name,
        st.middle_name,
        st.last_name,
        st.matric_number
    FROM attendance_records ar
    INNER JOIN students st ON st.id = ar.student_id
    WHERE ar.session_id = ?
    ORDER BY ar.attendance_time DESC
");

$stmt->execute([$sessionId]);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalAttendance = count($attendanceRecords);

// Format attendance date and time.
function sessionAttendanceDate($date)
{
    if (empty($date)) {
        return 'N/A';
    }

    $timestamp = strtotime($date);

    return $timestamp === false
        ? 'Invalid date'
        : date('d M Y, h:i A', $timestamp);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Session Attendance | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/student.css">

    <style>
        .session-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .summary-item {
            background: #f2f7f4;
            border: 1px solid #dcebe1;
            border-radius: 9px;
            padding: 16px;
        }

        .summary-item p {
            margin: 0 0 8px;
            color: #64746a;
            font-size: 13px;
        }

        .summary-item h3 {
            margin: 0;
            color: #145c39;
            font-size: 21px;
        }

        .manual-attendance-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: end;
            margin-top: 15px;
        }

        .manual-attendance-form .form-field {
            flex: 1;
            min-width: 220px;
        }

        .manual-attendance-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            font-size: 13px;
        }

        .manual-attendance-form input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5ded8;
            border-radius: 7px;
            font-size: 14px;
        }

        .manual-attendance-form button {
            padding: 12px 18px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: white;
            font-weight: 700;
            cursor: pointer;
        }

        .manual-attendance-form button:hover {
            background: #103c29;
        }

        .message {
            padding: 13px 15px;
            margin-bottom: 17px;
            border-radius: 7px;
            line-height: 1.5;
        }

        .message.error {
            background: #ffe8e6;
            color: #a32318;
        }

        .message.success {
            background: #e4f6e9;
            color: #176334;
        }

        .method-label {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 15px;
            background: #edf4ef;
            color: #145c39;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .session-back-link {
            display: inline-block;
            margin-bottom: 18px;
            color: #145c39;
            text-decoration: none;
            font-weight: 700;
        }

        .session-back-link:hover {
            text-decoration: underline;
        }

        .session-closed-note {
            padding: 12px;
            margin-top: 15px;
            border-radius: 7px;
            background: #fff3df;
            color: #80520b;
            line-height: 1.5;
        }
    </style>
</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <aside class="student-sidebar">

        <div class="sidebar-brand">
            <div class="brand-symbol">SA</div>
            <h2>SmartAttend</h2>
        </div>

        <div class="sidebar-profile">
            <div class="profile-placeholder">
                <?= e(strtoupper(substr($_SESSION['user_name'] ?? 'L', 0, 1))) ?>
            </div>

            <h3><?= e($_SESSION['user_name'] ?? 'Lecturer') ?></h3>
            <p>Lecturer Account</p>
        </div>

        <nav class="sidebar-navigation">
            <a href="dashboard.php">
                <span>▦</span> Dashboard
            </a>

            <a class="active" href="#">
                <span>◷</span> Session Attendance
            </a>

            <a href="session.php">
                <span>＋</span> Create Session
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

    <main class="student-main">

        <header class="student-topbar">
            <div>
                <h1>Session Attendance</h1>
                <p>View and manage attendance for this class.</p>
            </div>
        </header>

        <a href="dashboard.php#attendance-sessions" class="session-back-link">
            ← Back to Attendance Sessions
        </a>

        <section class="student-welcome">
            <div>
                <p class="welcome-label">ATTENDANCE SESSION</p>

                <h2><?= e($session['session_title']) ?></h2>

                <p>
                    <?= e($session['course_code']) ?> -
                    <?= e($session['course_title']) ?>
                </p>
            </div>

            <div class="welcome-icon">✓</div>
        </section>

        <section class="session-summary">

            <div class="summary-item">
                <p>Total Students Recorded</p>
                <h3><?= $totalAttendance ?></h3>
            </div>

            <div class="summary-item">
                <p>Session Status</p>
                <h3><?= e(ucfirst($session['status'])) ?></h3>
            </div>

            <div class="summary-item">
                <p>Session Start</p>
                <h3 style="font-size:15px;">
                    <?= e(sessionAttendanceDate($session['start_time'])) ?>
                </h3>
            </div>

        </section>

        <!-- MANUAL ATTENDANCE -->
        <section class="student-content-card">

            <div class="section-heading">
                <div>
                    <h2>Record Attendance Manually</h2>
                    <p>
                        Enter the matric number of a student who cannot
                        scan the QR code.
                    </p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="message error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="message success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($sessionIsOpen): ?>

                <form method="POST" class="manual-attendance-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int) $session['id'] ?>"
                    >

                    <div class="form-field">
                        <label for="matric_number">
                            Student Matric Number
                        </label>

                        <input
                            type="text"
                            name="matric_number"
                            id="matric_number"
                            placeholder="Enter student's matric number"
                            required
                        >
                    </div>

                    <button type="submit">
                        Record Attendance
                    </button>

                </form>

                <p style="font-size:12px; color:#718078; margin-top:12px;">
                    The student must be registered for this course.
                    The record will be saved as Lecturer Recorded.
                </p>

            <?php else: ?>

                <div class="session-closed-note">
                    This session is not currently open for attendance.
                    You can still view the attendance records, but you
                    cannot add new records.
                </div>

            <?php endif; ?>

        </section>

        <!-- STUDENTS WHO ATTENDED -->
        <section class="student-content-card">

            <div class="section-heading">
                <div>
                    <h2>Students Who Attended</h2>
                    <p>
                        Attendance records for this session only.
                    </p>
                </div>
            </div>

            <?php if (!empty($attendanceRecords)): ?>

                <div class="student-table-wrapper">

                    <table class="student-table">

                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Matric Number</th>
                                <th>Student Name</th>
                                <th>Attendance Time</th>
                                <th>Status</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($attendanceRecords as $index => $record): ?>

                                <?php
                                $fullName = trim(
                                    $record['first_name'] . ' ' .
                                    ($record['middle_name'] ?? '') . ' ' .
                                    ($record['last_name'] ?? '')
                                );

                                $method = strtolower($record['attendance_method'] ?? 'qr');
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <?= e($record['matric_number']) ?>
                                    </td>

                                    <td>
                                        <?= e($fullName) ?>
                                    </td>

                                    <td>
                                        <?= e(sessionAttendanceDate($record['attendance_time'])) ?>
                                    </td>

                                    <td>
                                        <span class="attendance-present">
                                            <?= e(ucfirst($record['attendance_status'])) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="method-label">
                                            <?= $method === 'lecturer'
                                                ? 'Lecturer Recorded'
                                                : e(strtoupper($method)) ?>
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

                    <h3>No attendance records yet</h3>

                    <p>
                        Students who mark attendance or are recorded
                        manually will appear here.
                    </p>
                </div>

            <?php endif; ?>

        </section>

        <footer class="student-footer">
            <p>
                © <?= date('Y') ?> SmartAttend. All rights reserved.
            </p>

            <p>Knowledge, Truth and Service</p>
        </footer>

    </main>

</div>

</body>
</html>
