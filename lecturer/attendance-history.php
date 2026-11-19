
<?php

require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];
$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';
$lecturerInitial = strtoupper(substr(trim($lecturerName), 0, 1));

// Automatically close expired sessions
$stmt = $pdo->prepare("
    UPDATE attendance_sessions
    SET status = 'closed'
    WHERE lecturer_id = ?
      AND status = 'active'
      AND end_time <= NOW()
");
$stmt->execute([$lecturerId]);

// Search and date filter inputs
$search = trim($_GET['search'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$dateError = '';

// Validate date format and actual calendar date
function validHistoryDate($date)
{
    if ($date === '') {
        return true;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);

    return checkdate(
        (int) $parts[1],
        (int) $parts[2],
        (int) $parts[0]
    );
}

// Validate supplied dates
if (!validHistoryDate($startDate) || !validHistoryDate($endDate)) {

    $dateError = 'Please enter valid dates.';

    $startDate = '';
    $endDate = '';

} elseif (
    $startDate !== '' &&
    $endDate !== '' &&
    $startDate > $endDate
) {

    $dateError = 'The starting date cannot be later than the ending date.';

    $startDate = '';
    $endDate = '';
}

// Build attendance history query
$sql = "
    SELECT
        s.id,
        s.session_title,
        s.status,
        s.start_time,
        s.end_time,
        c.course_code,
        c.course_title,
        COUNT(ar.id) AS attendance_total
    FROM attendance_sessions s

    INNER JOIN courses c
        ON c.id = s.course_id

    LEFT JOIN attendance_records ar
        ON ar.session_id = s.id

    WHERE s.lecturer_id = ?
";

$params = [$lecturerId];

// Search by course code or session title
if ($search !== '') {

    $sql .= "
        AND (
            c.course_code LIKE ?
            OR s.session_title LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Filter by starting date
if ($startDate !== '') {

    $sql .= " AND s.start_time >= ?";

    $params[] = $startDate . ' 00:00:00';
}

// Filter by ending date
if ($endDate !== '') {

    $sql .= " AND s.start_time < DATE_ADD(?, INTERVAL 1 DAY)";

    $params[] = $endDate;
}

$sql .= "
    GROUP BY
        s.id,
        s.session_title,
        s.status,
        s.start_time,
        s.end_time,
        c.course_code,
        c.course_title

    ORDER BY s.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count displayed sessions
$historyCount = count($sessions);

// Prepare CSV download URL while preserving filters
$exportUrl = 'attendance-history.php?' . http_build_query([
    'search' => $search,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'export' => 'csv'
]);

// Export attendance history as CSV
if (
    isset($_GET['export']) &&
    $_GET['export'] === 'csv' &&
    $dateError === ''
) {

    $filename = 'SmartAttend_Attendance_History_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="' . $filename . '"'
    );

    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM helps Excel display characters correctly
    fwrite($output, "\xEF\xBB\xBF");

    // Protect CSV files against spreadsheet formula injection
    $safeCsvValue = function ($value) {

        $value = (string) ($value ?? '');

        if (preg_match('/^[\t\r ]*[=+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    };

    // CSV headings
    fputcsv($output, [
        'Course Code',
        'Course Title',
        'Session Title',
        'Total Attendance',
        'Status',
        'Start Time',
        'End Time'
    ]);

    // CSV data
    foreach ($sessions as $session) {

        fputcsv($output, [
            $safeCsvValue($session['course_code']),
            $safeCsvValue($session['course_title']),
            $safeCsvValue($session['session_title']),
            (int) $session['attendance_total'],
            $safeCsvValue(ucfirst($session['status'])),
            $safeCsvValue($session['start_time']),
            $safeCsvValue($session['end_time'])
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

    <title>Attendance History | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <style>

        .history-search-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            width: 100%;
            margin-bottom: 15px;
        }

        .history-search-form input {
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid #dce5df;
            border-radius: 7px;
            font-family: inherit;
            font-size: 13px;
            outline: none;
            background: #fff;
        }

        .history-search-form input:focus {
            border-color: #145c39;
            box-shadow: 0 0 0 3px rgba(20, 92, 57, 0.08);
        }

        .history-search-input {
            flex: 1 1 240px;
        }

        .history-date-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1 1 150px;
        }

        .history-date-field label {
            color: #52645a;
            font-size: 11px;
            font-weight: 700;
        }

        .history-date-field input {
            width: 100%;
            box-sizing: border-box;
        }

        .history-search-button {
            padding: 12px 18px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: white;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .history-search-button:hover {
            background: #103c29;
        }

        .history-clear-link {
            display: inline-block;
            color: #145c39;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            padding: 10px 5px;
        }

        .history-clear-link:hover {
            text-decoration: underline;
        }

        .history-date-error {
            padding: 11px 14px;
            margin-bottom: 15px;
            border-radius: 7px;
            background: #fff0ee;
            color: #b42318;
            font-size: 12px;
        }

        .history-export-area {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 15px;
            margin: 18px 0;
            background: #f5faf6;
            border: 1px solid #dcebe0;
            border-radius: 8px;
        }

        .history-export-text h3 {
            color: #244b35;
            font-size: 13px;
            margin: 0 0 5px;
        }

        .history-export-text p {
            color: #718078;
            font-size: 11px;
            margin: 0;
        }

        .history-export-button {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 7px;
            background: #145c39;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .history-export-button:hover {
            background: #103c29;
        }

        .history-count {
            color: #718078;
            font-size: 12px;
            margin-bottom: 15px;
        }

        .history-status-active {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #e8f4ec;
            color: #145c39;
            font-size: 11px;
            font-weight: 800;
        }

        .history-status-closed {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #fff0ee;
            color: #b42318;
            font-size: 11px;
            font-weight: 800;
        }

        .history-view-button {
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

        .history-view-button:hover {
            background: #145c39;
            color: white;
        }

        .history-empty {
            padding: 35px 20px;
            text-align: center;
            background: #f8fbf9;
            border-radius: 9px;
        }

        .history-empty h3 {
            color: #34483d;
            margin-bottom: 8px;
        }

        .history-empty p {
            color: #718078;
            font-size: 13px;
            line-height: 1.6;
        }

        .history-back-link {
            display: inline-block;
            margin-top: 15px;
            color: #145c39;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 600px) {

            .history-search-form {
                align-items: stretch;
            }

            .history-search-input,
            .history-date-field {
                flex-basis: 100%;
            }

            .history-search-button {
                flex: 1;
            }

            .history-export-area {
                align-items: flex-start;
                flex-direction: column;
            }

        }

        .history-action-buttons {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 7px;
}

.history-download-button {
    display: inline-block;
    padding: 9px 12px;
    border-radius: 6px;
    background: #145c39;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    border: 1px solid #145c39;
}

.history-download-button:hover {
    background: #103c29;
    border-color: #103c29;
}

    </style>

</head>

<body class="student-dashboard-body">

<div class="student-layout">

    <!-- SIDEBAR -->
    <aside class="student-sidebar">

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

            <a href="dashboard.php">
                <span>▦</span> Dashboard
            </a>

            <a href="dashboard.php#lecturer-information">
                <span>♙</span> My Information
            </a>

            <a href="session.php">
                <span>＋</span> Create Session
            </a>

            <a href="dashboard.php#my-courses">
                <span>▤</span> My Courses
            </a>

            <a class="active" href="attendance-history.php">
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

    <!-- MAIN CONTENT -->
    <main class="student-main">

        <!-- TOP HEADER -->
        <header class="student-topbar">

            <div>

                <h1>Attendance History</h1>

                <p>
                    View and manage all your previous attendance sessions.
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
                    Your Attendance History
                </h2>

                <p>
                    Review all the attendance sessions you have created
                    and check the students who attended each class.
                </p>

            </div>

            <div class="welcome-icon">◷</div>

        </section>

        <!-- ATTENDANCE HISTORY -->
        <section class="student-content-card">

            <div class="section-heading">

                <div>

                    <h2>All Attendance Sessions</h2>

                    <p>
                        Search and view your complete attendance history.
                    </p>

                </div>

            </div>

            <!-- SEARCH AND DATE FILTERS -->
            <form
                method="GET"
                action="attendance-history.php"
                class="history-search-form"
            >

                <input
                    type="search"
                    name="search"
                    class="history-search-input"
                    placeholder="Search course code or session title..."
                    value="<?= e($search) ?>"
                >

                <div class="history-date-field">

                    <label for="start_date">From Date</label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?= e($startDate) ?>"
                    >

                </div>

                <div class="history-date-field">

                    <label for="end_date">To Date</label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?= e($endDate) ?>"
                    >

                </div>

                <button
                    type="submit"
                    class="history-search-button"
                >
                    Apply Filters
                </button>

                <a
                    href="attendance-history.php"
                    class="history-clear-link"
                >
                    Clear Filters
                </a>

            </form>

            <?php if ($dateError !== ''): ?>

                <div class="history-date-error">
                    <?= e($dateError) ?>
                </div>

            <?php endif; ?>

            <!-- CSV DOWNLOAD -->
            <div class="history-export-area">

                <div class="history-export-text">

                    <h3>Download Attendance Report</h3>

                    <p>
                        Download the sessions currently displayed,
                        including their attendance totals and dates.
                    </p>

                </div>

                <a
                    href="<?= e($exportUrl) ?>"
                    class="history-export-button"
                >
                    ↓ Download CSV Report
                </a>

            </div>

            <!-- RESULT COUNT -->
            <p class="history-count">

                Showing <?= $historyCount ?>
                <?= $historyCount === 1 ? 'session' : 'sessions' ?>

                <?php if ($search !== '' || $startDate !== '' || $endDate !== ''): ?>

                    matching your filters.

                <?php else: ?>

                    in your attendance history.

                <?php endif; ?>

            </p>

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
                                <th>Action</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($sessions as $session): ?>

                                <tr>

                                    <td>
                                        <?= e($session['course_code']) ?>
                                    </td>

                                    <td>
                                        <?= e($session['course_title']) ?>
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

                                            <span class="history-status-active">
                                                Active
                                            </span>

                                        <?php else: ?>

                                            <span class="history-status-closed">
                                                Closed
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= e(date(
                                            'd M Y, h:i A',
                                            strtotime($session['start_time'])
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= e(date(
                                            'd M Y, h:i A',
                                            strtotime($session['end_time'])
                                        )) ?>
                                    </td>

                                    <td>

    <div class="history-action-buttons">

        <a
            href="session-attendance.php?session_id=<?= (int) $session['id'] ?>"
            class="history-view-button"
        >
            View Students
        </a>

        <a
            href="export-session-attendance.php?session_id=<?= (int) $session['id'] ?>"
            class="history-download-button"
        >
            ↓ Download Report
        </a>

    </div>

</td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="history-empty">

                    <h3>
                        <?= ($search !== '' || $startDate !== '' || $endDate !== '')
                            ? 'No matching sessions found'
                            : 'No attendance history yet' ?>
                    </h3>

                    <p>

                        <?php if (
                            $search !== '' ||
                            $startDate !== '' ||
                            $endDate !== ''
                        ): ?>

                            No attendance sessions match your selected
                            search or date filters. Try changing them.

                        <?php else: ?>

                            Your attendance sessions will appear here
                            after you create them.

                        <?php endif; ?>

                    </p>

                    <?php if (
                        $search === '' &&
                        $startDate === '' &&
                        $endDate === ''
                    ): ?>

                        <a
                            href="session.php"
                            class="history-back-link"
                        >
                            Create Attendance Session
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <a href="dashboard.php" class="history-back-link">
                ← Back to Dashboard
            </a>

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

</body>
</html>
