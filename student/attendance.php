
<?php
require_once __DIR__ . '/../config/db.php';

// Make sure the student is logged in.
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$student_id = (int) $_SESSION['student_id'];

// ============================================
// CHECK WHETHER THE STUDENT ACCOUNT IS ACTIVE
// ============================================

$statusStmt = $pdo->prepare("
    SELECT status
    FROM students
    WHERE id = ?
    LIMIT 1
");

$statusStmt->execute([$student_id]);
$studentStatus = $statusStmt->fetchColumn();

if ($studentStatus !== 'active') {

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

$student_name = $_SESSION['student_name'] ?? 'Student';

// ============================================
// GET THE STUDENT'S REGISTERED COURSES
// ============================================

$coursesStmt = $pdo->prepare("
    SELECT DISTINCT
        c.id,
        c.course_code,
        c.course_title
    FROM enrollments e
    INNER JOIN courses c
        ON e.course_id = c.id
    WHERE e.student_id = ?
    ORDER BY c.course_code ASC
");

$coursesStmt->execute([$student_id]);
$registeredCourses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Confirm that the selected course belongs to this student.
$selectedCourse = null;

if ($selectedCourseId > 0) {

    foreach ($registeredCourses as $course) {

        if ((int) $course['id'] === $selectedCourseId) {
            $selectedCourse = $course;
            break;
        }

    }

    // Reject a course that the student is not registered for.
    if (!$selectedCourse) {
        $selectedCourseId = 0;
    }
}

// ============================================
// GET ATTENDANCE SUMMARY
// ============================================

$summarySql = "
    SELECT
        COUNT(*) AS total_attendance,
        SUM(
            CASE
                WHEN ar.attendance_status = 'present' THEN 1
                ELSE 0
            END
        ) AS present_count,
        SUM(
            CASE
                WHEN ar.attendance_status = 'late' THEN 1
                ELSE 0
            END
        ) AS late_count
    FROM attendance_records ar
    INNER JOIN attendance_sessions s
        ON ar.session_id = s.id
    WHERE ar.student_id = ?
";

$summaryParams = [$student_id];

if ($selectedCourseId > 0) {
    $summarySql .= " AND s.course_id = ?";
    $summaryParams[] = $selectedCourseId;
}

$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($summaryParams);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// GET ATTENDANCE HISTORY
// ============================================

$attendanceSql = "
    SELECT
        ar.id,
        ar.attendance_time,
        ar.attendance_status,
        ar.attendance_method,
        s.session_title,
        c.course_code,
        c.course_title
    FROM attendance_records ar
    INNER JOIN attendance_sessions s
        ON ar.session_id = s.id
    INNER JOIN courses c
        ON s.course_id = c.id
    WHERE ar.student_id = ?
";

$attendanceParams = [$student_id];

if ($selectedCourseId > 0) {
    $attendanceSql .= " AND s.course_id = ?";
    $attendanceParams[] = $selectedCourseId;
}

$attendanceSql .= "
    ORDER BY ar.attendance_time DESC
";

$attendanceStmt = $pdo->prepare($attendanceSql);
$attendanceStmt->execute($attendanceParams);

$attendanceRecords = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ATTENDANCE STATISTICS
// ============================================

$totalAttendance = (int) ($summary['total_attendance'] ?? 0);
$presentCount = (int) ($summary['present_count'] ?? 0);
$lateCount = (int) ($summary['late_count'] ?? 0);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Attendance History | SmartAttend</title>

    <link rel="stylesheet"
          href="../assets/css/student.css">

    <style>

        .attendance-history-page {
            min-height: 100vh;
            background: #f4f7f4;
            padding: 35px 20px;
            color: #26352b;
        }

        .attendance-history-container {
            max-width: 1150px;
            margin: auto;
        }

        .attendance-history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }

        .attendance-history-header h1 {
            color: #14532d;
            margin-bottom: 8px;
        }

        .attendance-history-header p {
            color: #647067;
        }

        .attendance-back-button {
            display: inline-block;
            background: #166534;
            color: white;
            text-decoration: none;
            padding: 11px 20px;
            border-radius: 7px;
            font-weight: 600;
        }

        .attendance-back-button:hover {
            background: #14532d;
        }

        /* COURSE FILTER */

        .attendance-course-filter {
            background: white;
            border: 1px solid #e3ebe4;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
        }

        .attendance-course-filter label {
            display: block;
            color: #14532d;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .attendance-course-filter select {
            width: 100%;
            max-width: 500px;
            padding: 13px;
            border: 1px solid #cbd8ce;
            border-radius: 7px;
            font-size: 14px;
            background: white;
            color: #26352b;
            outline: none;
        }

        .attendance-course-filter select:focus {
            border-color: #166534;
            box-shadow: 0 0 0 3px rgba(22,101,52,0.10);
        }

        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .attendance-summary-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #e3ebe4;
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
        }

        .attendance-summary-card h3 {
            font-size: 14px;
            color: #68756c;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .attendance-summary-card .number {
            font-size: 30px;
            font-weight: 700;
            color: #166534;
        }

        .attendance-table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #e3ebe4;
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
        }

        .attendance-table-card h2 {
            color: #14532d;
            margin-bottom: 20px;
        }

        .attendance-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .attendance-history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .attendance-history-table th {
            background: #edf5ee;
            color: #14532d;
            padding: 15px;
            text-align: left;
            font-size: 13px;
        }

        .attendance-history-table td {
            padding: 15px;
            border-bottom: 1px solid #edf0ed;
            font-size: 14px;
        }

        .attendance-history-table tr:hover {
            background: #f8fbf8;
        }

        .status-badge,
        .method-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-present {
            background: #dcfce7;
            color: #166534;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .method-badge {
            background: #eef2ff;
            color: #3730a3;
        }

        .no-attendance {
            text-align: center;
            padding: 45px 15px;
            color: #68756c;
        }

        .no-attendance h3 {
            color: #14532d;
            margin-bottom: 10px;
        }

        .attendance-history-footer {
            text-align: center;
            margin-top: 30px;
            color: #7b867e;
            font-size: 13px;
        }

        @media (max-width: 700px) {

            .attendance-summary {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .attendance-history-page {
                padding: 20px 12px;
            }

            .attendance-table-card {
                padding: 15px;
            }

            .attendance-history-header {
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

<div class="attendance-history-page">

    <div class="attendance-history-container">

        <!-- PAGE HEADER -->

        <div class="attendance-history-header">

            <div>

                <h1>Attendance History</h1>

                <p>
                    Welcome, <?= e($student_name) ?>.
                    View your attendance records for each course.
                </p>

            </div>

            <a href="dashboard.php"
               class="attendance-back-button">

                &larr; Back to Dashboard

            </a>

        </div>

        <!-- COURSE FILTER -->

        <div class="attendance-course-filter">

            <form method="GET">

                <label for="course_id">
                    Select Course to View Attendance
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

                    <?php foreach ($registeredCourses as $course): ?>

                        <option
                            value="<?= (int) $course['id'] ?>"
                            <?= $selectedCourseId === (int) $course['id'] ? 'selected' : '' ?>
                        >

                            <?= e($course['course_code']) ?>
                            - <?= e($course['course_title']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </form>

        </div>

        <!-- ATTENDANCE SUMMARY -->

        <div class="attendance-summary">

            <div class="attendance-summary-card">

                <h3>Total Attendance Records</h3>

                <div class="number">
                    <?= $totalAttendance ?>
                </div>

            </div>

            <div class="attendance-summary-card">

                <h3>Classes Present</h3>

                <div class="number">
                    <?= $presentCount ?>
                </div>

            </div>

            <div class="attendance-summary-card">

                <h3>Late Attendance</h3>

                <div class="number">
                    <?= $lateCount ?>
                </div>

            </div>

        </div>

        <!-- ATTENDANCE RECORDS -->

        <div class="attendance-table-card">

            <h2>

                <?php if ($selectedCourse): ?>

                    <?= e($selectedCourse['course_code']) ?>
                    - Attendance Records

                <?php else: ?>

                    My Attendance Records - All Courses

                <?php endif; ?>

            </h2>

            <?php if (count($attendanceRecords) > 0): ?>

                <div class="attendance-table-wrapper">

                    <table class="attendance-history-table">

                        <thead>

                            <tr>
                                <th>S/N</th>
                                <th>Course</th>
                                <th>Class Session</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Method</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php $sn = 1; ?>

                            <?php foreach ($attendanceRecords as $record): ?>

                                <?php
                                $status = strtolower(
                                    $record['attendance_status']
                                );

                                $statusClass = (
                                    $status === 'late'
                                ) ? 'status-late' : 'status-present';
                                ?>

                                <tr>

                                    <td>
                                        <?= $sn++ ?>
                                    </td>

                                    <td>

                                        <strong>
                                            <?= e($record['course_code']) ?>
                                        </strong>

                                        <br>

                                        <?= e($record['course_title']) ?>

                                    </td>

                                    <td>
                                        <?= e($record['session_title'] ?? 'Class Session') ?>
                                    </td>

                                    <td>

                                        <?= date(
                                            'd M Y',
                                            strtotime($record['attendance_time'])
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= date(
                                            'h:i A',
                                            strtotime($record['attendance_time'])
                                        ) ?>

                                    </td>

                                    <td>

                                        <span class="method-badge">
                                            <?= e($record['attendance_method']) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= e(ucfirst($status)) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="no-attendance">

                    <h3>
                        No Attendance Records Found
                    </h3>

                    <p>

                        <?php if ($selectedCourse): ?>

                            You do not have any attendance records
                            for this course yet.

                        <?php else: ?>

                            You have not marked attendance for any class yet.
                            Your attendance records will appear here after
                            you successfully mark your attendance.

                        <?php endif; ?>

                    </p>

                </div>

            <?php endif; ?>

        </div>

        <!-- FOOTER -->

        <div class="attendance-history-footer">

            Emmanuel Alayande University of Education, Oyo

            <br>

            Knowledge, Truth and Service

            <br><br>

            &copy; <?= date('Y') ?> SmartAttend.
            All rights reserved.

        </div>

    </div>

</div>

</body>
</html>
