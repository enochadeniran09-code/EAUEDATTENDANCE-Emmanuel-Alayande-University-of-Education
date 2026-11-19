
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

$message = '';
$messageType = 'success';

// Preserve search value
$search = trim($_GET['search'] ?? '');

// Handle administrator actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

    if (!$studentId || $studentId < 1) {
        $message = 'Invalid student record.';
        $messageType = 'error';

    } else {

        // Activate or deactivate a student
        if ($action === 'change_status') {

            $newStatus = $_POST['new_status'] ?? '';

            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                $message = 'Invalid account status.';
                $messageType = 'error';

            } else {
                $stmt = $pdo->prepare(
                    "UPDATE students SET status = ? WHERE id = ?"
                );

                $stmt->execute([$newStatus, $studentId]);

                $message = $newStatus === 'active'
                    ? 'Student account activated successfully.'
                    : 'Student account deactivated successfully.';
            }
        }

        // Delete student only when no academic records are linked
        elseif ($action === 'delete_student') {

            try {
                $pdo->beginTransaction();

                $check = $pdo->prepare(
                    "SELECT id FROM students WHERE id = ? FOR UPDATE"
                );
                $check->execute([$studentId]);

                if (!$check->fetch()) {
                    throw new Exception('Student record not found.');
                }

                $enrollmentCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM enrollments WHERE student_id = ?"
                );
                $enrollmentCheck->execute([$studentId]);
                $enrollmentCount = (int) $enrollmentCheck->fetchColumn();

                $attendanceCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM attendance_records WHERE student_id = ?"
                );
                $attendanceCheck->execute([$studentId]);
                $attendanceCount = (int) $attendanceCheck->fetchColumn();

                if ($enrollmentCount > 0 || $attendanceCount > 0) {
                    throw new Exception(
                        'This student cannot be deleted because course registrations or attendance records are linked to the account. Deactivate the account instead.'
                    );
                }

                $delete = $pdo->prepare(
                    "DELETE FROM students WHERE id = ?"
                );
                $delete->execute([$studentId]);

                $pdo->commit();

                $message = 'Student record deleted successfully.';

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $e->getMessage();
                $messageType = 'error';
            }
        }

        else {
            $message = 'Invalid action.';
            $messageType = 'error';
        }
    }
}

// Get student records
$sql = "
    SELECT
        s.id,
        s.matric_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email,
        s.department,
        s.programme,
        s.level,
        s.status,
        s.created_at,

        (
            SELECT COUNT(*)
            FROM enrollments e
            WHERE e.student_id = s.id
        ) AS enrollment_count,

        (
            SELECT COUNT(*)
            FROM attendance_records ar
            WHERE ar.student_id = s.id
        ) AS attendance_count

    FROM students s
";

$params = [];

if ($search !== '') {
    $sql .= "
        WHERE
            s.first_name LIKE ?
            OR s.middle_name LIKE ?
            OR s.last_name LIKE ?
            OR s.matric_number LIKE ?
            OR s.email LIKE ?
            OR s.department LIKE ?
            OR s.programme LIKE ?
    ";

    $searchTerm = "%{$search}%";

    $params = array_fill(0, 7, $searchTerm);
}

$sql .= " ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$students = $stmt->fetchAll();

// Dashboard counts
$totalStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM students"
)->fetchColumn();

$activeStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM students WHERE status = 'active'"
)->fetchColumn();

$inactiveStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM students WHERE status = 'inactive'"
)->fetchColumn();

// View individual student
$viewStudent = null;

$viewId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);

if ($viewId && $viewId > 0) {

    $viewStmt = $pdo->prepare("
        SELECT
            id,
            matric_number,
            first_name,
            middle_name,
            last_name,
            email,
            department,
            programme,
            level,
            status,
            created_at,
            profile_image
        FROM students
        WHERE id = ?
        LIMIT 1
    ");

    $viewStmt->execute([$viewId]);
    $viewStudent = $viewStmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Management | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>
        .admin-message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .admin-message.success {
            background: #e7f3eb;
            color: #145c39;
        }

        .admin-message.error {
            background: #fdeaea;
            color: #a52828;
        }

        .admin-action {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 6px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            margin: 2px;
        }

        .view-action {
            background: #e7f3eb;
            color: #145c39;
        }

        .edit-action {
            background: #f7f0df;
            color: #80601e;
        }

        .activate-action {
            background: #e7f3eb;
            color: #145c39;
        }

        .deactivate-action {
            background: #fff1df;
            color: #a45d12;
        }

        .delete-action {
            background: #fdeaea;
            color: #a52828;
        }

        .admin-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .admin-status.active {
            background: #e7f3eb;
            color: #145c39;
        }

        .admin-status.inactive {
            background: #fdeaea;
            color: #a52828;
        }

        .student-search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 18px 0;
        }

        .student-search-form input {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 1px solid #dce5de;
            border-radius: 7px;
            outline: none;
        }

        .student-search-form button {
            padding: 12px 20px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: white;
            cursor: pointer;
            font-weight: 600;
        }

        .student-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .student-detail-item {
            padding: 12px;
            background: #f5f8f6;
            border-radius: 7px;
        }

        .student-detail-item small {
            display: block;
            color: #718078;
            margin-bottom: 5px;
        }

        .student-detail-item strong {
            color: #145c39;
            overflow-wrap: anywhere;
        }

        .admin-action-form {
            display: inline;
        }

        .admin-action-form button {
            font-family: inherit;
        }

        /* Mobile hamburger button */
        .admin-menu-toggle {
            display: none;
            width: 44px;
            height: 44px;
            min-width: 44px;
            border: none;
            border-radius: 8px;
            background: #145c39;
            color: #fff;
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

            /* Mobile sidebar drawer */
            .student-sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 270px;
                max-width: 85%;
                height: 100vh;
                height: 100dvh;
                z-index: 2001;
                transform: translateX(-105%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            body.admin-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            /* Dark background overlay */
            .admin-sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 2000;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            body.admin-sidebar-open .admin-sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            /* Main content */
            .student-main {
                width: 100%;
                min-width: 0;
                margin-left: 0;
            }

            /* Top header */
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
            }

            .student-topbar > div {
                flex: 1;
                min-width: 0;
            }

            body.admin-sidebar-open {
                overflow: hidden;
            }

            /* Student details */
            .student-details-grid {
                grid-template-columns: 1fr;
            }

            /* Search form */
            .student-search-form {
                width: 100%;
            }

            .student-search-form input {
                min-width: 0;
                width: 100%;
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

            <div>
                <h2>SmartAttend</h2>
                <small>Administrator Portal</small>
            </div>

        </div>

        <div class="sidebar-profile">

            <div class="profile-placeholder">
                <?= htmlspecialchars($adminInitial) ?>
            </div>

            <div>
                <strong><?= htmlspecialchars($adminName) ?></strong>
                <small>Administrator</small>
            </div>

        </div>

        <nav class="sidebar-navigation">

            <a href="dashboard.php">
                <span>▦</span>
                Dashboard
            </a>

            <a href="create_lecturer.php">
                <span>＋</span>
                Create Lecturer
            </a>

            <a href="lecturers.php">
                <span>♙</span>
                Manage Lecturers
            </a>

            <a href="manage_courses.php">
                <span>▤</span>
                Course Management
            </a>

            <a href="students.php" class="active">
                <span>♧</span>
                Student Management
            </a>

            <a href="add_student.php">
                <span>＋</span>
                Add Student
            </a>

        </nav>

        <div class="sidebar-bottom">

            <form action="logout.php" method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(csrf_token()) ?>"
                >

                <button type="submit" class="sidebar-logout">
                    Logout
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

        <header class="student-topbar">

            <!-- MOBILE HAMBURGER BUTTON -->
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
                <strong>Student Management</strong>
                <small>Manage registered students</small>
            </div>

        </header>


        <section class="student-welcome">

            <div>

                <span class="welcome-label">ADMINISTRATION</span>

                <h1>Student Management</h1>

                <p>
                    View, search and manage student accounts
                    in the SmartAttend system.
                </p>

            </div>

            <div class="welcome-icon">♧</div>

        </section>


        <?php if ($message): ?>

            <div class="admin-message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>


        <!-- STATISTICS -->
        <section class="student-stat-grid">

            <div class="student-stat-card">

                <div class="stat-icon green-icon">♧</div>

                <div>
                    <small>Total Students</small>
                    <h2><?= number_format($totalStudents) ?></h2>
                </div>

            </div>


            <div class="student-stat-card">

                <div class="stat-icon blue-icon">✓</div>

                <div>
                    <small>Active Students</small>
                    <h2><?= number_format($activeStudents) ?></h2>
                </div>

            </div>


            <div class="student-stat-card">

                <div class="stat-icon orange-icon">!</div>

                <div>
                    <small>Inactive Students</small>
                    <h2><?= number_format($inactiveStudents) ?></h2>
                </div>

            </div>

        </section>


        <!-- INDIVIDUAL STUDENT DETAILS -->
        <?php if ($viewStudent): ?>

            <section class="student-content-card">

                <div class="section-heading">

                    <h2>Student Information</h2>

                    <a
                        href="students.php"
                        class="admin-action view-action"
                    >
                        Back to Students
                    </a>

                </div>


                <div class="student-details-grid">

                    <div class="student-detail-item">

                        <small>Full Name</small>

                        <strong>
                            <?= htmlspecialchars(
                                trim(
                                    $viewStudent['first_name'] . ' ' .
                                    ($viewStudent['middle_name'] ?? '') . ' ' .
                                    $viewStudent['last_name']
                                )
                            ) ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Matric Number</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['matric_number']) ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Email</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['email'] ?? 'Not provided') ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Department</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['department']) ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Programme</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['programme']) ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Level</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['level']) ?>
                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Account Status</small>

                        <strong>

                            <span class="admin-status <?= htmlspecialchars($viewStudent['status']) ?>">
                                <?= htmlspecialchars($viewStudent['status']) ?>
                            </span>

                        </strong>

                    </div>


                    <div class="student-detail-item">

                        <small>Date Registered</small>

                        <strong>
                            <?= htmlspecialchars($viewStudent['created_at']) ?>
                        </strong>

                    </div>

                </div>

            </section>

        <?php elseif (isset($_GET['view'])): ?>

            <section class="student-content-card">
                <p>Student record not found.</p>
            </section>

        <?php endif; ?>


        <!-- STUDENT TABLE -->
        <section class="student-content-card">

            <div class="section-heading">

                <div>
                    <h2>Registered Students</h2>
                    <p>All students registered on SmartAttend</p>
                </div>

                <a
                    href="add_student.php"
                    class="admin-action activate-action"
                >
                    + Add New Student
                </a>

            </div>


            <!-- SEARCH -->
            <form method="GET" class="student-search-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search name, matric number, email or department..."
                    value="<?= htmlspecialchars($search) ?>"
                >

                <button type="submit">
                    Search
                </button>

                <?php if ($search !== ''): ?>

                    <a
                        href="students.php"
                        class="admin-action view-action"
                    >
                        Clear
                    </a>

                <?php endif; ?>

            </form>


            <div class="student-table-wrapper">

                <table class="student-table">

                    <thead>

                        <tr>
                            <th>S/N</th>
                            <th>Student Name</th>
                            <th>Matric Number</th>
                            <th>Department</th>
                            <th>Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>

                    </thead>


                    <tbody>

                    <?php if (count($students) > 0): ?>

                        <?php $sn = 1; ?>

                        <?php foreach ($students as $student): ?>

                            <tr>

                                <td>
                                    <?= $sn++ ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        trim(
                                            $student['first_name'] . ' ' .
                                            ($student['middle_name'] ?? '') . ' ' .
                                            $student['last_name']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student['matric_number']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student['department']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student['level']) ?>
                                </td>

                                <td>

                                    <span class="admin-status <?= htmlspecialchars($student['status']) ?>">
                                        <?= htmlspecialchars($student['status']) ?>
                                    </span>

                                </td>

                                <td>

                                    <a
                                        href="students.php?view=<?= (int) $student['id'] ?>"
                                        class="admin-action view-action"
                                    >
                                        View
                                    </a>


                                    <a
                                        href="edit_student.php?id=<?= (int) $student['id'] ?>"
                                        class="admin-action edit-action"
                                    >
                                        Edit
                                    </a>


                                    <?php if ($student['status'] === 'active'): ?>

                                        <form
                                            method="POST"
                                            class="admin-action-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="student_id"
                                                value="<?= (int) $student['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="inactive"
                                            >

                                            <button
                                                type="submit"
                                                class="admin-action deactivate-action"
                                                onclick="return confirm('Are you sure you want to deactivate this student?')"
                                            >
                                                Deactivate
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <form
                                            method="POST"
                                            class="admin-action-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="student_id"
                                                value="<?= (int) $student['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="active"
                                            >

                                            <button
                                                type="submit"
                                                class="admin-action activate-action"
                                                onclick="return confirm('Reactivate this student account?')"
                                            >
                                                Activate
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <form
                                        method="POST"
                                        class="admin-action-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars(csrf_token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_student"
                                        >

                                        <input
                                            type="hidden"
                                            name="student_id"
                                            value="<?= (int) $student['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="admin-action delete-action"
                                            onclick="return confirm('Are you sure you want to delete this student? This is only allowed if the student has no course registrations or attendance records.')"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="7" style="text-align:center; padding:25px;">
                                No student records found.
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <footer class="student-footer">

            SmartAttend | Emmanuel Alayande University of Education, Oyo

            <br>

            Knowledge, Truth and Service

        </footer>

    </main>

</div>


<script>
    // Mobile administrator sidebar controls.
    const adminMenuToggle = document.getElementById('adminMenuToggle');
    const adminSidebar = document.getElementById('adminSidebar');
    const adminSidebarOverlay = document.getElementById('adminSidebarOverlay');

    // Open the sidebar.
    function openAdminSidebar() {

        document.body.classList.add('admin-sidebar-open');

        adminMenuToggle.textContent = '✕';

        adminMenuToggle.setAttribute('aria-expanded', 'true');
        adminMenuToggle.setAttribute('aria-label', 'Close navigation menu');
    }

    // Close the sidebar.
    function closeAdminSidebar() {

        document.body.classList.remove('admin-sidebar-open');

        adminMenuToggle.textContent = '☰';

        adminMenuToggle.setAttribute('aria-expanded', 'false');
        adminMenuToggle.setAttribute('aria-label', 'Open navigation menu');
    }

    // Toggle the hamburger menu.
    adminMenuToggle.addEventListener('click', function () {

        if (document.body.classList.contains('admin-sidebar-open')) {

            closeAdminSidebar();

        } else {

            openAdminSidebar();

        }

    });

    // Close sidebar when the dark overlay is clicked.
    adminSidebarOverlay.addEventListener('click', closeAdminSidebar);

    // Close sidebar after selecting a navigation link.
    adminSidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeAdminSidebar);

    });

    // Close sidebar with the Escape key.
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeAdminSidebar();
        }

    });

    // Close sidebar when returning to desktop screen size.
    window.addEventListener('resize', function () {

        if (window.innerWidth > 700) {
            closeAdminSidebar();
        }

    });
</script>

</body>
</html>
