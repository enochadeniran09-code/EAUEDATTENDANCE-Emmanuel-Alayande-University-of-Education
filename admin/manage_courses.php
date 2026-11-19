
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

$error = '';
$success = '';

$search = trim($_GET['search'] ?? '');
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editId = ($editId && $editId > 0) ? $editId : 0;

// Get all lecturer accounts.
$stmt = $pdo->prepare("
    SELECT id, full_name, email
    FROM users
    WHERE role = 'lecturer'
    ORDER BY full_name ASC
");
$stmt->execute();
$lecturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle course actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    // ADD OR UPDATE COURSE
    if ($action === 'save_course') {

        $courseId = (int)($_POST['course_id'] ?? 0);

        $courseCode = strtoupper(trim($_POST['course_code'] ?? ''));
        $courseTitle = trim($_POST['course_title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $level = trim($_POST['level'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $lecturerId = (int)($_POST['lecturer_id'] ?? 0);

        if (
            $courseCode === '' ||
            $courseTitle === '' ||
            $department === '' ||
            $level === '' ||
            $semester === '' ||
            $lecturerId <= 0
        ) {
            $error = 'Please fill in all the fields correctly.';

        } elseif (!in_array($semester, ['First Semester', 'Second Semester'], true)) {

            $error = 'Please select a valid semester.';

        } else {

            // Confirm that the selected lecturer exists.
            $checkLecturer = $pdo->prepare("
                SELECT id
                FROM users
                WHERE id = ? AND role = 'lecturer'
            ");

            $checkLecturer->execute([$lecturerId]);

            if (!$checkLecturer->fetch()) {

                $error = 'Please select a valid lecturer.';

            } else {

                // Check for duplicate course codes.
                if ($courseId > 0) {

                    $checkCourse = $pdo->prepare("
                        SELECT id
                        FROM courses
                        WHERE course_code = ?
                        AND id != ?
                    ");

                    $checkCourse->execute([$courseCode, $courseId]);

                } else {

                    $checkCourse = $pdo->prepare("
                        SELECT id
                        FROM courses
                        WHERE course_code = ?
                    ");

                    $checkCourse->execute([$courseCode]);
                }

                if ($checkCourse->fetch()) {

                    $error = 'This course code already exists.';

                } else {

                    try {

                        if ($courseId > 0) {

                            // Confirm the course exists before updating.
                            $checkExisting = $pdo->prepare("
                                SELECT id
                                FROM courses
                                WHERE id = ?
                            ");

                            $checkExisting->execute([$courseId]);

                            if (!$checkExisting->fetch()) {

                                $error = 'The course you are trying to edit was not found.';

                            } else {

                                $update = $pdo->prepare("
                                    UPDATE courses
                                    SET
                                        course_code = ?,
                                        course_title = ?,
                                        department = ?,
                                        level = ?,
                                        semester = ?,
                                        lecturer_id = ?
                                    WHERE id = ?
                                ");

                                $update->execute([
                                    $courseCode,
                                    $courseTitle,
                                    $department,
                                    $level,
                                    $semester,
                                    $lecturerId,
                                    $courseId
                                ]);

                                header('Location: manage_courses.php?updated=1');
                                exit;
                            }

                        } else {

                            // Add a new course.
                            $insert = $pdo->prepare("
                                INSERT INTO courses (
                                    course_code,
                                    course_title,
                                    department,
                                    level,
                                    semester,
                                    lecturer_id,
                                    created_at
                                )
                                VALUES (?, ?, ?, ?, ?, ?, NOW())
                            ");

                            $insert->execute([
                                $courseCode,
                                $courseTitle,
                                $department,
                                $level,
                                $semester,
                                $lecturerId
                            ]);

                            header('Location: manage_courses.php?success=1');
                            exit;
                        }

                    } catch (PDOException $e) {

                        $error = 'Unable to save the course. Please try again.';
                    }
                }
            }
        }

        // Keep the submitted values if validation fails.
        $editId = $courseId;
    }


    // DELETE COURSE
    if ($action === 'delete_course') {

        $courseId = (int)($_POST['course_id'] ?? 0);

        if ($courseId <= 0) {

            $error = 'Invalid course selected.';

        } else {

            try {

                $checkExisting = $pdo->prepare("
                    SELECT id, course_code
                    FROM courses
                    WHERE id = ?
                ");

                $checkExisting->execute([$courseId]);
                $courseToDelete = $checkExisting->fetch(PDO::FETCH_ASSOC);

                if (!$courseToDelete) {

                    $error = 'The selected course could not be found.';

                } else {

                    // Check whether students are enrolled in this course.
                    $checkEnrollments = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM enrollments
                        WHERE course_id = ?
                    ");

                    $checkEnrollments->execute([$courseId]);
                    $enrollmentCount = (int)$checkEnrollments->fetchColumn();

                    // Check whether the course has attendance sessions.
                    $checkSessions = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM attendance_sessions
                        WHERE course_id = ?
                    ");

                    $checkSessions->execute([$courseId]);
                    $sessionCount = (int)$checkSessions->fetchColumn();

                    if ($enrollmentCount > 0 || $sessionCount > 0) {

                        $error = 'This course cannot be deleted because it has '
                            . $enrollmentCount . ' student enrollment(s) and '
                            . $sessionCount . ' attendance session(s). '
                            . 'Existing student and attendance records are protected.';

                    } else {

                        $delete = $pdo->prepare("
                            DELETE FROM courses
                            WHERE id = ?
                        ");

                        $delete->execute([$courseId]);

                        header('Location: manage_courses.php?deleted=1');
                        exit;
                    }
                }

            } catch (PDOException $e) {

                $error = 'Unable to delete this course. It may have related records.';
            }
        }
    }
}


// Success messages.
if (isset($_GET['success'])) {
    $success = 'Course added and assigned successfully!';
}

if (isset($_GET['updated'])) {
    $success = 'Course details updated successfully!';
}

if (isset($_GET['deleted'])) {
    $success = 'Course deleted successfully!';
}


// Load course being edited.
$editCourse = null;

if ($editId > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM courses
        WHERE id = ?
    ");

    $stmt->execute([$editId]);
    $editCourse = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editCourse && !$error) {
        $error = 'The course you selected could not be found.';
        $editId = 0;
    }
}


// Prepare form values.
$formCourse = [
    'course_code' => $editCourse['course_code'] ?? '',
    'course_title' => $editCourse['course_title'] ?? '',
    'department' => $editCourse['department'] ?? '',
    'level' => $editCourse['level'] ?? '',
    'semester' => $editCourse['semester'] ?? '',
    'lecturer_id' => $editCourse['lecturer_id'] ?? ''
];

// Preserve submitted values when validation fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && ($_POST['action'] ?? '') === 'save_course') {

    $formCourse = [
        'course_code' => $_POST['course_code'] ?? '',
        'course_title' => $_POST['course_title'] ?? '',
        'department' => $_POST['department'] ?? '',
        'level' => $_POST['level'] ?? '',
        'semester' => $_POST['semester'] ?? '',
        'lecturer_id' => $_POST['lecturer_id'] ?? ''
    ];
}


// Get all courses, including assigned lecturer names.
$sql = "
    SELECT
        courses.id,
        courses.course_code,
        courses.course_title,
        courses.department,
        courses.level,
        courses.semester,
        courses.lecturer_id,
        users.full_name AS lecturer_name
    FROM courses
    LEFT JOIN users
        ON courses.lecturer_id = users.id
";

$params = [];

if ($search !== '') {

    $sql .= "
        WHERE
            courses.course_code LIKE ?
            OR courses.course_title LIKE ?
            OR courses.department LIKE ?
            OR courses.level LIKE ?
            OR users.full_name LIKE ?
    ";

    $searchValue = '%' . $search . '%';

    $params = [
        $searchValue,
        $searchValue,
        $searchValue,
        $searchValue,
        $searchValue
    ];
}

$sql .= " ORDER BY courses.course_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Course count.
$totalCourses = count($courses);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Course Management | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .course-page {
            max-width: 1200px;
            margin: 35px auto;
            padding: 20px;
        }

        /* Mobile menu header */
        .course-mobile-header {
            display: none;
        }

        .admin-menu-toggle {
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

        .admin-menu-toggle:hover {
            background: #103c29;
        }

        /* Mobile sidebar */
        .admin-mobile-sidebar {
            display: none;
        }

        .admin-mobile-overlay {
            display: none;
        }

        .course-header {
            margin-bottom: 25px;
        }

        .course-header h1 {
            color: #145c39;
            margin-bottom: 7px;
        }

        .course-header p {
            color: #666;
        }

        .course-card {
            background: #fff;
            padding: 28px;
            border-radius: 13px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.07);
            margin-bottom: 25px;
        }

        .course-card h2 {
            color: #145c39;
            margin-bottom: 8px;
        }

        .course-description {
            color: #777;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .course-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            margin-top: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 13px;
            border: 1px solid #d5ddd8;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #197447;
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.10);
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .save-btn {
            background: #176b45;
            color: #fff;
        }

        .save-btn:hover {
            background: #105536;
        }

        .cancel-btn {
            background: #edf1ee;
            color: #333;
        }

        .cancel-btn:hover {
            background: #dfe6e1;
        }

        .edit-btn {
            background: #f0b429;
            color: #263328;
        }

        .edit-btn:hover {
            background: #dfa51e;
        }

        .delete-btn {
            background: #fff0f0;
            color: #b42318;
        }

        .delete-btn:hover {
            background: #ffe0e0;
        }

        .message {
            padding: 13px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
        }

        .error-message {
            background: #fff0f0;
            color: #a00000;
            border-left: 4px solid #c62828;
        }

        .success-message {
            background: #e8f5eb;
            color: #176b45;
            border-left: 4px solid #197447;
        }

        .course-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .course-count {
            background: #e8f5eb;
            color: #176b45;
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .search-form {
            display: flex;
            gap: 10px;
            max-width: 500px;
            width: 100%;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 12px;
            border: 1px solid #d5ddd8;
            border-radius: 8px;
            outline: none;
        }

        .search-form input:focus {
            border-color: #176b45;
        }

        .search-btn {
            background: #176b45;
            color: #fff;
        }

        .clear-search {
            color: #176b45;
            text-decoration: none;
            align-self: center;
            font-size: 13px;
            font-weight: 600;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .course-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            min-width: 850px;
        }

        .course-table th,
        .course-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #e7ece8;
            text-align: left;
            font-size: 13px;
        }

        .course-table th {
            background: #145c39;
            color: #fff;
            font-weight: 600;
        }

        .course-table tbody tr:hover {
            background: #f7faf8;
        }

        .course-code {
            color: #176b45;
            font-weight: 700;
        }

        .lecturer-name {
            font-weight: 600;
        }

        .not-assigned {
            color: #a66b00;
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .action-buttons .btn {
            padding: 8px 11px;
            font-size: 12px;
        }

        .delete-form {
            display: inline;
            margin: 0;
        }

        .empty-state {
            text-align: center !important;
            padding: 30px !important;
            color: #777;
        }

        .notice {
            background: #fff8e6;
            border-left: 4px solid #e0a526;
            padding: 12px 15px;
            border-radius: 7px;
            color: #74530b;
            font-size: 13px;
            margin-top: 15px;
        }

        @media (max-width: 700px) {

            .course-page {
                margin: 0 auto;
                padding: 12px;
                width: 100%;
                box-sizing: border-box;
            }

            .course-mobile-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 0 18px;
                margin-bottom: 10px;
            }

            .course-mobile-header > div {
                min-width: 0;
            }

            .course-mobile-header strong {
                display: block;
                color: #145c39;
                font-size: 16px;
            }

            .course-mobile-header small {
                display: block;
                color: #718078;
                font-size: 12px;
                margin-top: 3px;
            }

            .course-header {
                margin-bottom: 20px;
            }

            .course-header h1 {
                font-size: 24px;
            }

            .course-card {
                padding: 20px 15px;
            }

            .course-form {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }

            .search-form {
                max-width: 100%;
            }

            .course-summary {
                align-items: stretch;
            }

            /* Sidebar drawer */
            .admin-mobile-sidebar {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 270px;
                max-width: 85%;
                height: 100vh;
                height: 100dvh;
                background: #103c29;
                color: #fff;
                z-index: 2001;
                transform: translateX(-105%);
                transition: transform 0.3s ease;
                overflow-y: auto;
                box-shadow: 5px 0 20px rgba(0, 0, 0, 0.15);
            }

            body.admin-menu-open .admin-mobile-sidebar {
                transform: translateX(0);
            }

            .admin-mobile-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 2000;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            body.admin-menu-open .admin-mobile-overlay {
                opacity: 1;
                visibility: visible;
            }

            body.admin-menu-open {
                overflow: hidden;
            }

            .mobile-sidebar-brand {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 25px 20px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            }

            .mobile-brand-symbol {
                width: 43px;
                height: 43px;
                border-radius: 10px;
                background: #fff;
                color: #145c39;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 800;
                font-size: 17px;
            }

            .mobile-sidebar-brand h2 {
                font-size: 18px;
                margin: 0;
                color: #fff;
            }

            .mobile-sidebar-brand small {
                display: block;
                color: #c5d8cc;
                font-size: 11px;
                margin-top: 4px;
            }

            .mobile-sidebar-profile {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 20px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            }

            .mobile-profile-initial {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: #e8f5eb;
                color: #145c39;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
            }

            .mobile-sidebar-profile strong {
                display: block;
                color: #fff;
                font-size: 13px;
            }

            .mobile-sidebar-profile small {
                display: block;
                color: #c5d8cc;
                font-size: 11px;
                margin-top: 3px;
            }

            .mobile-sidebar-navigation {
                padding: 18px 12px;
                flex: 1;
            }

            .mobile-sidebar-navigation a {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 13px 14px;
                margin-bottom: 5px;
                color: #e5eee8;
                text-decoration: none;
                font-size: 13px;
                border-radius: 7px;
                transition: background 0.2s ease;
            }

            .mobile-sidebar-navigation a span {
                width: 20px;
                text-align: center;
                font-size: 17px;
            }

            .mobile-sidebar-navigation a:hover,
            .mobile-sidebar-navigation a.active {
                background: #197447;
                color: #fff;
            }

            .mobile-sidebar-bottom {
                padding: 18px;
                border-top: 1px solid rgba(255, 255, 255, 0.12);
            }

            .mobile-sidebar-bottom form {
                margin: 0;
            }

            .mobile-sidebar-logout {
                width: 100%;
                padding: 12px;
                border: none;
                border-radius: 7px;
                background: rgba(255, 255, 255, 0.12);
                color: #fff;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                text-align: left;
            }

            .mobile-sidebar-logout:hover {
                background: rgba(255, 255, 255, 0.2);
            }
        }
    </style>
</head>

<body>

<!-- MOBILE ADMIN SIDEBAR -->
<aside class="admin-mobile-sidebar" id="adminMobileSidebar">

    <div class="mobile-sidebar-brand">

        <div class="mobile-brand-symbol">SA</div>

        <div>
            <h2>SmartAttend</h2>
            <small>Administrator Portal</small>
        </div>

    </div>

    <div class="mobile-sidebar-profile">

        <div class="mobile-profile-initial">
            <?= e($adminInitial) ?>
        </div>

        <div>
            <strong><?= e($adminName) ?></strong>
            <small>Administrator</small>
        </div>

    </div>

    <nav class="mobile-sidebar-navigation">

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

        <a href="manage_courses.php" class="active">
            <span>▤</span>
            Course Management
        </a>

        <a href="students.php">
            <span>♧</span>
            Student Management
        </a>

        <a href="add_student.php">
            <span>＋</span>
            Add Student
        </a>

    </nav>

    <div class="mobile-sidebar-bottom">

        <form action="logout.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <button type="submit" class="mobile-sidebar-logout">
                Logout
            </button>

        </form>

    </div>

</aside>

<!-- SIDEBAR OVERLAY -->
<div
    class="admin-mobile-overlay"
    id="adminMobileOverlay"
></div>


<div class="course-page">

    <!-- MOBILE TOP HEADER AND HAMBURGER -->
    <div class="course-mobile-header">

        <button
            type="button"
            class="admin-menu-toggle"
            id="adminMenuToggle"
            aria-label="Open navigation menu"
            aria-expanded="false"
            aria-controls="adminMobileSidebar"
        >
            ☰
        </button>

        <div>
            <strong>SmartAttend</strong>
            <small>Administrator Portal</small>
        </div>

    </div>


    <div class="course-header">

        <h1>Course Management</h1>

        <p>
            Add, update, assign and manage courses in SmartAttend.
        </p>

    </div>


    <?php if ($error): ?>

        <div class="message error-message">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div class="message success-message">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <!-- ADD / EDIT COURSE -->
    <div class="course-card">

        <h2>
            <?= $editId > 0 ? 'Edit Course' : 'Add New Course' ?>
        </h2>

        <p class="course-description">
            <?= $editId > 0
                ? 'Update the course information or assign it to another lecturer.'
                : 'Enter the course details and select the lecturer responsible for the course.' ?>
        </p>

        <?php if (empty($lecturers)): ?>

            <div class="message error-message">
                No lecturer accounts are available.
                Please create a lecturer account before adding or editing a course.
            </div>

        <?php else: ?>

            <form method="POST" action="" class="course-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save_course"
                >

                <input
                    type="hidden"
                    name="course_id"
                    value="<?= (int)$editId ?>"
                >

                <div class="form-group">

                    <label for="course_code">
                        Course Code
                    </label>

                    <input
                        type="text"
                        id="course_code"
                        name="course_code"
                        placeholder="e.g. CSC 301"
                        value="<?= e($formCourse['course_code']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="course_title">
                        Course Title
                    </label>

                    <input
                        type="text"
                        id="course_title"
                        name="course_title"
                        placeholder="e.g. Web Development"
                        value="<?= e($formCourse['course_title']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="department">
                        Department
                    </label>

                    <input
                        type="text"
                        id="department"
                        name="department"
                        placeholder="e.g. Computer Science"
                        value="<?= e($formCourse['department']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="level">
                        Level
                    </label>

                    <input
                        type="text"
                        id="level"
                        name="level"
                        placeholder="e.g. 300 Level"
                        value="<?= e($formCourse['level']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="semester">
                        Semester
                    </label>

                    <select
                        id="semester"
                        name="semester"
                        required
                    >

                        <option value="">
                            Select Semester
                        </option>

                        <option
                            value="First Semester"
                            <?= $formCourse['semester'] === 'First Semester' ? 'selected' : '' ?>
                        >
                            First Semester
                        </option>

                        <option
                            value="Second Semester"
                            <?= $formCourse['semester'] === 'Second Semester' ? 'selected' : '' ?>
                        >
                            Second Semester
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label for="lecturer_id">
                        Assign Lecturer
                    </label>

                    <select
                        id="lecturer_id"
                        name="lecturer_id"
                        required
                    >

                        <option value="">
                            Select Lecturer
                        </option>

                        <?php foreach ($lecturers as $lecturer): ?>

                            <option
                                value="<?= (int)$lecturer['id'] ?>"
                                <?= (string)$formCourse['lecturer_id'] === (string)$lecturer['id'] ? 'selected' : '' ?>
                            >
                                <?= e($lecturer['full_name']) ?>
                                (<?= e($lecturer['email']) ?>)
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group full-width">

                    <div class="form-buttons">

                        <button type="submit" class="btn save-btn">
                            <?= $editId > 0 ? 'Update Course' : 'Add Course' ?>
                        </button>

                        <?php if ($editId > 0): ?>

                            <a
                                href="manage_courses.php"
                                class="btn cancel-btn"
                            >
                                Cancel Editing
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>


    <!-- ALL COURSES -->
    <div class="course-card">

        <div class="course-summary">

            <div>

                <h2>All Courses</h2>

                <p class="course-description">
                    View and manage all courses registered in SmartAttend.
                </p>

                <span class="course-count">
                    <?= $totalCourses ?> course(s) found
                </span>

            </div>


            <form
                method="GET"
                action="manage_courses.php"
                class="search-form"
            >

                <input
                    type="text"
                    name="search"
                    placeholder="Search course, department or lecturer..."
                    value="<?= e($search) ?>"
                >

                <button type="submit" class="btn search-btn">
                    Search
                </button>

                <?php if ($search !== ''): ?>

                    <a
                        href="manage_courses.php"
                        class="clear-search"
                    >
                        Clear
                    </a>

                <?php endif; ?>

            </form>

        </div>


        <div class="table-wrapper">

            <table class="course-table">

                <thead>

                    <tr>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Department</th>
                        <th>Level</th>
                        <th>Semester</th>
                        <th>Lecturer</th>
                        <th>Actions</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if (empty($courses)): ?>

                        <tr>

                            <td colspan="7" class="empty-state">

                                <?= $search !== ''
                                    ? 'No courses match your search.'
                                    : 'No courses have been added yet.' ?>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($courses as $course): ?>

                            <tr>

                                <td class="course-code">
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

                                <td>

                                    <?php if ($course['lecturer_name']): ?>

                                        <span class="lecturer-name">
                                            <?= e($course['lecturer_name']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="not-assigned">
                                            Not assigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="action-buttons">

                                        <a
                                            href="manage_courses.php?edit=<?= (int)$course['id'] ?>"
                                            class="btn edit-btn"
                                        >
                                            Edit
                                        </a>


                                        <form
                                            method="POST"
                                            action="manage_courses.php"
                                            class="delete-form"
                                            onsubmit="return confirm('Are you sure you want to delete <?= e($course['course_code']) ?>? This action cannot be undone.');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_course"
                                            >

                                            <input
                                                type="hidden"
                                                name="course_id"
                                                value="<?= (int)$course['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn delete-btn"
                                            >
                                                Delete
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <div class="notice">

            <strong>Important:</strong>

            Courses with student enrollments or attendance sessions
            cannot be deleted. This protects existing student registration
            and attendance records.

        </div>

    </div>

</div>


<script>
    // Mobile administrator sidebar controls.
    const adminMenuToggle = document.getElementById('adminMenuToggle');
    const adminMobileSidebar = document.getElementById('adminMobileSidebar');
    const adminMobileOverlay = document.getElementById('adminMobileOverlay');

    function openAdminMenu() {

        document.body.classList.add('admin-menu-open');

        adminMenuToggle.textContent = '✕';

        adminMenuToggle.setAttribute('aria-expanded', 'true');
        adminMenuToggle.setAttribute('aria-label', 'Close navigation menu');
    }

    function closeAdminMenu() {

        document.body.classList.remove('admin-menu-open');

        adminMenuToggle.textContent = '☰';

        adminMenuToggle.setAttribute('aria-expanded', 'false');
        adminMenuToggle.setAttribute('aria-label', 'Open navigation menu');
    }

    // Toggle the sidebar.
    adminMenuToggle.addEventListener('click', function () {

        if (document.body.classList.contains('admin-menu-open')) {

            closeAdminMenu();

        } else {

            openAdminMenu();

        }

    });

    // Close menu when the dark overlay is clicked.
    adminMobileOverlay.addEventListener('click', closeAdminMenu);

    // Close menu after selecting a navigation link.
    adminMobileSidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeAdminMenu);

    });

    // Close menu with the Escape key.
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeAdminMenu();
        }

    });

    // Close menu when returning to desktop screen size.
    window.addEventListener('resize', function () {

        if (window.innerWidth > 700) {
            closeAdminMenu();
        }

    });
</script>

</body>
</html>
