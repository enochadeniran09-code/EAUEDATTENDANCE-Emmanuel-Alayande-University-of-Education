
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');
// Display success message after editing a student
if (isset($_SESSION['student_update_success'])) {
    $message = $_SESSION['student_update_success'];
    $messageType = 'success';

    unset($_SESSION['student_update_success']);
}

$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

$message = '';
$messageType = 'error';

// Get student ID
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
}

if (!$studentId || $studentId < 1) {
    http_response_code(400);
    exit('Invalid student ID.');
}

// Fetch the student
$stmt = $pdo->prepare("
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
        created_at
    FROM students
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    // Collect submitted information
    $matricNumber = trim($_POST['matric_number'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $programme = trim($_POST['programme'] ?? '');
    $level = trim($_POST['level'] ?? '');

    // Preserve submitted values if validation fails
    $student['matric_number'] = $matricNumber;
    $student['first_name'] = $firstName;
    $student['middle_name'] = $middleName;
    $student['last_name'] = $lastName;
    $student['email'] = $email;
    $student['department'] = $department;
    $student['programme'] = $programme;
    $student['level'] = $level;

    // Required fields
    if (
        $matricNumber === '' ||
        $firstName === '' ||
        $lastName === '' ||
        $department === '' ||
        $programme === '' ||
        $level === ''
    ) {
        $message = 'Please fill in all required fields.';
    }

    // Validate email if supplied
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    }

    // Validate database field lengths
    elseif (
        strlen($matricNumber) > 50 ||
        strlen($firstName) > 100 ||
        strlen($middleName) > 100 ||
        strlen($lastName) > 100 ||
        strlen($email) > 150 ||
        strlen($department) > 150 ||
        strlen($programme) > 150 ||
        strlen($level) > 30
    ) {
        $message = 'One or more fields contain too many characters.';
    }

    else {
        try {
            // Check whether another student uses this matric number
            $duplicateCheck = $pdo->prepare("
                SELECT id
                FROM students
                WHERE matric_number = ?
                AND id != ?
                LIMIT 1
            ");

            $duplicateCheck->execute([$matricNumber, $studentId]);

            if ($duplicateCheck->fetch()) {
                $message = 'This matriculation number is already assigned to another student.';
            } else {

                // Update student information
                $update = $pdo->prepare("
                    UPDATE students
                    SET
                        matric_number = ?,
                        first_name = ?,
                        middle_name = ?,
                        last_name = ?,
                        email = ?,
                        department = ?,
                        programme = ?,
                        level = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $matricNumber,
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $email !== '' ? $email : null,
                    $department,
                    $programme,
                    $level,
                    $studentId
                ]);

               // Redirect to Registered Students after successful update
$_SESSION['student_update_success'] = 'Student information updated successfully.';

header("Location: students.php");
exit;
            }

        } catch (PDOException $e) {
            error_log('Edit student error: ' . $e->getMessage());
            $message = 'Unable to update the student record. Please try again.';
        }
    }
}

// Success notification
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = 'Student information updated successfully.';
    $messageType = 'success';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Student | SmartAttend</title>

    <link rel="stylesheet" href="../assets/css/student.css">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
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

        .edit-student-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #e5ebe6;
        }

        .edit-student-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #34483c;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #dce5de;
            border-radius: 7px;
            font-size: 14px;
            font-family: inherit;
            color: #26382e;
            background: #fff;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #197447;
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.08);
        }

        .form-hint {
            font-size: 11px;
            color: #718078;
        }

        .required-mark {
            color: #c0392b;
        }

        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .save-button,
        .cancel-button {
            padding: 12px 22px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-family: inherit;
        }

        .save-button {
            background: #145c39;
            color: #fff;
        }

        .save-button:hover {
            background: #103c29;
        }

        .cancel-button {
            background: #f0f3f1;
            color: #34483c;
        }

        .student-current-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .student-current-status.active {
            background: #e7f3eb;
            color: #145c39;
        }

        .student-current-status.inactive {
            background: #fdeaea;
            color: #a52828;
        }

        /* Admin mobile navigation */
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

            .student-sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 270px;
                max-width: 85%;
                height: 100vh;
                height: 100dvh;
                z-index: 1001;
                transform: translateX(-105%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            body.admin-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            .admin-sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
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
            }

            .student-topbar > div {
                flex: 1;
                min-width: 0;
            }

            body.admin-sidebar-open {
                overflow: hidden;
            }

            .edit-student-form {
                grid-template-columns: 1fr;
            }

            .form-group.full-width,
            .form-actions {
                grid-column: 1;
            }

            .edit-student-card {
                padding: 18px;
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

            <a href="students.php" class="active">
                <span>♧</span>
                Student Management
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
                <strong>Edit Student</strong>
                <small>Update student information</small>
            </div>

        </header>

        <section class="student-welcome">

            <div>
                <span class="welcome-label">STUDENT MANAGEMENT</span>

                <h1>Edit Student Information</h1>

                <p>
                    Update the personal and academic details
                    of a registered student.
                </p>
            </div>

            <div class="welcome-icon">✎</div>

        </section>

        <?php if ($message): ?>

            <div class="admin-message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <section class="edit-student-card">

            <div class="section-heading">

                <div>
                    <h2>Student Details</h2>
                    <p>
                        Matric Number:
                        <strong><?= htmlspecialchars($student['matric_number']) ?></strong>
                    </p>
                </div>

                <span class="student-current-status <?= htmlspecialchars($student['status']) ?>">
                    <?= htmlspecialchars($student['status']) ?>
                </span>

            </div>

            <form
                method="POST"
                action="edit_student.php?id=<?= (int) $studentId ?>"
                class="edit-student-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="student_id"
                    value="<?= (int) $studentId ?>"
                >

                <div class="form-group">
                    <label>
                        Matriculation Number
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="matric_number"
                        maxlength="50"
                        value="<?= htmlspecialchars($student['matric_number']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>
                        First Name
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="first_name"
                        maxlength="100"
                        value="<?= htmlspecialchars($student['first_name']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Middle Name</label>

                    <input
                        type="text"
                        name="middle_name"
                        maxlength="100"
                        value="<?= htmlspecialchars($student['middle_name'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label>
                        Last Name
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="last_name"
                        maxlength="100"
                        value="<?= htmlspecialchars($student['last_name']) ?>"
                        required
                    >
                </div>

                <div class="form-group full-width">
                    <label>Email Address</label>

                    <input
                        type="email"
                        name="email"
                        maxlength="150"
                        value="<?= htmlspecialchars($student['email'] ?? '') ?>"
                        placeholder="Enter student email address"
                    >
                </div>

                <div class="form-group">
                    <label>
                        Department
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="department"
                        maxlength="150"
                        value="<?= htmlspecialchars($student['department']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>
                        Programme
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="programme"
                        maxlength="150"
                        value="<?= htmlspecialchars($student['programme']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>
                        Level
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        type="text"
                        name="level"
                        maxlength="30"
                        value="<?= htmlspecialchars($student['level']) ?>"
                        placeholder="e.g. 100 Level"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Account Status</label>

                    <input
                        type="text"
                        value="<?= htmlspecialchars(ucfirst($student['status'])) ?>"
                        disabled
                    >

                    <span class="form-hint">
                        Change account status from the Student Management page.
                    </span>
                </div>

                <div class="form-actions">

                    <button type="submit" class="save-button">
                        Save Changes
                    </button>

                    <a href="students.php" class="cancel-button">
                        Cancel
                    </a>

                </div>

            </form>

        </section>

        <footer class="student-footer">
            SmartAttend | Emmanuel Alayande University of Education, Oyo
            <br>
            Knowledge, Truth and Service
        </footer>

    </main>

</div>

<script>
    // Mobile sidebar controls
    const adminMenuToggle = document.getElementById('adminMenuToggle');
    const adminSidebar = document.getElementById('adminSidebar');
    const adminSidebarOverlay = document.getElementById('adminSidebarOverlay');

    function openAdminSidebar() {
        document.body.classList.add('admin-sidebar-open');

        adminMenuToggle.textContent = '✕';
        adminMenuToggle.setAttribute('aria-expanded', 'true');
        adminMenuToggle.setAttribute('aria-label', 'Close navigation menu');
    }

    function closeAdminSidebar() {
        document.body.classList.remove('admin-sidebar-open');

        adminMenuToggle.textContent = '☰';
        adminMenuToggle.setAttribute('aria-expanded', 'false');
        adminMenuToggle.setAttribute('aria-label', 'Open navigation menu');
    }

    // Toggle hamburger menu
    adminMenuToggle.addEventListener('click', function () {

        if (document.body.classList.contains('admin-sidebar-open')) {
            closeAdminSidebar();
        } else {
            openAdminSidebar();
        }

    });

    // Close sidebar when overlay is clicked
    adminSidebarOverlay.addEventListener('click', closeAdminSidebar);

    // Close sidebar after selecting a navigation link
    adminSidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeAdminSidebar);
    });

    // Close sidebar with Escape key
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeAdminSidebar();
        }

    });

    // Close sidebar when screen returns to desktop size
    window.addEventListener('resize', function () {

        if (window.innerWidth > 700) {
            closeAdminSidebar();
        }

    });
</script>

</body>
</html>
