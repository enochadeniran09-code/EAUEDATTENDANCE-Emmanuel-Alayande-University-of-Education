
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// Administrator information
$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'error';

// Preserve submitted values
$firstName = trim($_POST['first_name'] ?? '');
$middleName = trim($_POST['middle_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$matricNumber = strtoupper(trim($_POST['matric_number'] ?? ''));
$email = trim($_POST['email'] ?? '');
$department = trim($_POST['department'] ?? '');
$programme = trim($_POST['programme'] ?? '');
$level = trim($_POST['level'] ?? '');

$levels = ['100', '200', '300', '400'];

// Available university programmes
$programmes = [
    'B.Sc. (Ed.) Biology Education',
    'B.Sc. Biology',
    'B.Sc. (Ed.) Integrated Science',
    'B.Sc. (Ed.) Chemistry Education',
    'B.Sc. Chemistry',
    'B.Sc. (Ed.) Mathematics Education',
    'B.Sc. (Ed.) Computer Science Education',
    'B.Sc. Computer Science',
    'B.Sc. (Ed.) Physics Education',
    'B.Sc. (Ed.) Health Education',
    'B.Sc. (Ed.) Human Kinetic/Physical Education',
    'B.Sc. (Ed.) Economics Education',
    'B.Sc. Economics',
    'B.Sc. (Ed.) Environmental Education',
    'B.Sc. (Ed.) Geography Education',
    'B.Sc. (Ed.) Educational Management',
    'B.Ed. Education Technology',
    'B.Tech. (Ed.) Building Technology Education',
    'B.Tech. (Ed.) Metal Work Technology Education',
    'B.Tech. (Ed.) Automobile Technology Education',
    'B.Tech. (Ed.) Wood Work Technology Education',
    'B.Tech. (Ed.) Electrical/Electronic Education',
    'B.A. (Ed.) History and International Studies',
    'B.A. (Ed.) English/Literature in English',
    'B.A. English Language and Literary Studies',
    'B.LIS. Library and Information Science',
    'B.A. (Ed.) French',
    'B.A. (Ed.) Yoruba',
    'B.A. (Ed.) Hausa',
    'B.Sc. (Ed.) Political Science Education',
    'B.Sc. Political Science',
    'B.Sc. (Ed.) Social Work',
    'B.Sc. (Ed.) Social Studies and Civic Education',
    'B.Sc. (Ed.) Special Education',
    'B.Ed. Guidance and Counselling',
    'B.Ed. Adult and Continuing Education',
    'B.Ed. Early Childhood Education',
    'B.Ed. Primary Education',
    'B.A. (Ed.) Arabic Education',
    'B.A. (Ed.) Christian Religious Studies',
    'B.A. (Ed.) Islamic Studies',
    'B.A. (Ed.) Creative Arts Education',
    'B.A. (Ed.) Music',
    'B.A. (Ed.) Fine and Applied Arts',
    'B.A. (Ed.) Theatre Arts',
    'B.Sc. (Ed.) Agricultural Science Education',
    'B.Sc. (Ed.) Home Economics Education',
    'B.Sc. (Ed.) Business Education',
    'B.Sc. (Ed.) Entrepreneurship Education',
    'B.Sc. Accounting'
];

$selectedCourses = $_POST['course_ids'] ?? [];

if (!is_array($selectedCourses)) {
    $selectedCourses = [];
}

// Load available courses
$courses = [];

try {
    $courseQuery = $pdo->query("
        SELECT id, course_code, course_title, department, level, semester
        FROM courses
        ORDER BY department, level, semester, course_code
    ");

    $courses = $courseQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = 'Unable to load available courses. Please try again.';
}

// Handle student creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Basic validation
    if (
        $firstName === '' ||
        $lastName === '' ||
        $matricNumber === '' ||
        $email === '' ||
        $department === '' ||
        $programme === '' ||
        $level === ''
    ) {
        $message = 'Please complete all required student details.';

    } elseif (!in_array($level, $levels, true)) {

        $message = 'Invalid academic level. Select 100, 200, 300 or 400 Level.';

    } elseif (!in_array($programme, $programmes, true)) {

        $message = 'Please select a valid programme.';

    } elseif (!preg_match('/^EAUED[0-9]{4,}$/i', $matricNumber)) {

        $message = 'Invalid matric number. Use the format EAUED followed by at least four digits.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';

    } elseif (strlen($password) < 8) {

        $message = 'The password must contain at least 8 characters.';

    } elseif ($password !== $confirmPassword) {

        $message = 'The passwords do not match.';

    } elseif (empty($selectedCourses)) {

        $message = 'Please select at least one course for the student.';

    } else {

        // Validate selected course IDs
        $courseIds = [];

        foreach ($selectedCourses as $courseId) {

            $validatedId = filter_var(
                $courseId,
                FILTER_VALIDATE_INT
            );

            if ($validatedId && $validatedId > 0) {
                $courseIds[] = $validatedId;
            }
        }

        $courseIds = array_values(array_unique($courseIds));

        if (empty($courseIds)) {

            $message = 'Please select at least one valid course.';

        } else {

            try {

                // Check that all selected courses exist
                $placeholders = implode(
                    ',',
                    array_fill(0, count($courseIds), '?')
                );

                $checkCourses = $pdo->prepare("
                    SELECT id
                    FROM courses
                    WHERE id IN ($placeholders)
                ");

                $checkCourses->execute($courseIds);

                $validCourseIds = array_map(
                    'intval',
                    $checkCourses->fetchAll(PDO::FETCH_COLUMN)
                );

                if (count($validCourseIds) !== count($courseIds)) {

                    $message = 'One or more selected courses are invalid. Please select again.';

                } else {

                    // Check for duplicate matric number or email
                    $duplicateCheck = $pdo->prepare("
                        SELECT id
                        FROM students
                        WHERE matric_number = ? OR email = ?
                        LIMIT 1
                    ");

                    $duplicateCheck->execute([
                        $matricNumber,
                        $email
                    ]);

                    if ($duplicateCheck->fetch()) {

                        $message = 'A student with this matric number or email already exists.';

                    } else {

                        // Begin database transaction
                        $pdo->beginTransaction();

                        // Hash the student's password
                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        // Create student account
                        $insertStudent = $pdo->prepare("
                            INSERT INTO students (
                                matric_number,
                                first_name,
                                middle_name,
                                last_name,
                                email,
                                department,
                                programme,
                                level,
                                password,
                                status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                        ");

                        $insertStudent->execute([
                            $matricNumber,
                            $firstName,
                            $middleName !== '' ? $middleName : null,
                            $lastName,
                            $email,
                            $department,
                            $programme,
                            $level,
                            $hashedPassword
                        ]);

                        $studentId = (int) $pdo->lastInsertId();

                        // Assign selected courses to the student
                        $insertEnrollment = $pdo->prepare("
                            INSERT INTO enrollments (student_id, course_id)
                            VALUES (?, ?)
                        ");

                        foreach ($validCourseIds as $courseId) {

                            $insertEnrollment->execute([
                                $studentId,
                                $courseId
                            ]);
                        }

                        // Initialize course registration tracking
                        $trackingInsert = $pdo->prepare("
                            INSERT IGNORE INTO student_course_registration (
                                student_id,
                                edit_count
                            ) VALUES (?, 0)
                        ");

                        $trackingInsert->execute([$studentId]);

                        // Mark administrator's assignment as initial registration
                        $trackingUpdate = $pdo->prepare("
                            UPDATE student_course_registration
                            SET initial_submitted_at = NOW(),
                                edit_count = 0
                            WHERE student_id = ?
                        ");

                        $trackingUpdate->execute([$studentId]);

                        // Save everything
                        $pdo->commit();

                        // Clear submitted form values
                        $firstName = '';
                        $middleName = '';
                        $lastName = '';
                        $matricNumber = '';
                        $email = '';
                        $department = '';
                        $programme = '';
                        $level = '';
                        $selectedCourses = [];

                        $message = 'Student account created successfully, and selected courses have been assigned.';
                        $messageType = 'success';
                    }
                }

            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Add student error: ' . $e->getMessage());

                $message = 'Unable to create the student account. Please check the details and try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <title>Add New Student | SmartAttend</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --green: #145c39;
            --dark-green: #103c29;
            --main-green: #197447;
            --light-green: #e7f3eb;
            --gold: #c9a227;
            --white: #ffffff;
            --background: #f4f7f5;
            --text: #26352c;
            --muted: #718078;
            --border: #dfe8e1;
            --danger: #b42318;
        }

        body {
            font-family: Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        body.admin-menu-open {
            overflow: hidden;
        }

        /* TOP BAR */
        .topbar {
            background: var(--dark-green);
            color: white;
            padding: 17px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
        }

        .brand span {
            color: var(--gold);
        }

        .admin-info {
            font-size: 14px;
            color: #e5eee8;
        }

        /* MOBILE HAMBURGER BUTTON */
        .admin-mobile-menu {
            display: none;
            width: 42px;
            height: 38px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 6px;
            background: transparent;
            color: white;
            font-size: 25px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* MOBILE SIDEBAR */
        .admin-mobile-sidebar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 270px;
            max-width: 85%;
            height: 100vh;
            height: 100dvh;
            background: var(--dark-green);
            z-index: 1001;
            padding: 25px 18px;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
        }

        .admin-mobile-sidebar.open {
            transform: translateX(0);
        }

        .menu-brand {
            color: white;
            font-size: 22px;
            font-weight: 700;
            padding: 8px 10px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            margin-bottom: 20px;
        }

        .menu-brand span {
            color: var(--gold);
        }

        .admin-mobile-sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .admin-mobile-sidebar nav a {
            display: block;
            color: #e5eee8;
            text-decoration: none;
            padding: 13px 14px;
            border-radius: 7px;
            font-size: 14px;
            transition: 0.2s;
        }

        .admin-mobile-sidebar nav a:hover,
        .admin-mobile-sidebar nav a.active {
            background: var(--main-green);
            color: white;
        }

        /* MOBILE OVERLAY */
        .admin-mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .admin-mobile-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        /* PAGE CONTENT */
        .container {
            max-width: 1050px;
            margin: 35px auto;
            padding: 0 20px 40px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 27px;
            color: var(--dark-green);
            margin-bottom: 8px;
        }

        .page-heading p {
            color: var(--muted);
            font-size: 14px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 18px;
            color: var(--green);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .form-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 18px rgba(16, 60, 41, 0.05);
        }

        .section-title {
            font-size: 17px;
            color: var(--dark-green);
            padding-bottom: 13px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 19px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: #39483e;
        }

        input,
        select {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 7px;
            outline: none;
            background: white;
            font-size: 14px;
            color: var(--text);
        }

        input:focus,
        select:focus {
            border-color: var(--main-green);
            box-shadow: 0 0 0 3px rgba(25, 116, 71, 0.09);
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 48px;
        }

        .toggle-password {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: var(--green);
            border-radius: 6px;
            cursor: pointer;
        }

        .toggle-password:hover {
            background: var(--light-green);
        }

        .toggle-password svg {
            width: 21px;
            height: 21px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .course-intro {
            color: var(--muted);
            font-size: 13px;
            margin: -8px 0 17px;
            line-height: 1.6;
        }

        .course-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 28px;
        }

        .course-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: 0.2s;
        }

        .course-item:hover {
            border-color: var(--main-green);
            background: #f8fcf9;
        }

        .course-item:has(input:checked) {
            border-color: var(--main-green);
            background: var(--light-green);
        }

        .course-item input {
            width: 17px;
            height: 17px;
            margin-top: 2px;
            accent-color: var(--green);
            flex-shrink: 0;
        }

        .course-details {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .course-details strong {
            color: var(--dark-green);
            font-size: 14px;
        }

        .course-details span {
            font-size: 12px;
            color: var(--muted);
        }

        .no-courses {
            padding: 20px;
            background: #fff8e7;
            border: 1px solid #f0dfb1;
            border-radius: 8px;
            color: #765c19;
            font-size: 14px;
        }

        .message {
            padding: 14px 16px;
            border-radius: 7px;
            margin-bottom: 22px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.success {
            background: #e8f6ed;
            color: #176638;
            border: 1px solid #b9dfc5;
        }

        .message.error {
            background: #fff0ef;
            color: var(--danger);
            border: 1px solid #f2c5c1;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid var(--border);
            padding-top: 22px;
        }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }

        .btn-cancel {
            background: #eef2ef;
            color: #435248;
        }

        .btn-submit {
            background: var(--green);
            color: white;
        }

        .btn-submit:hover {
            background: var(--dark-green);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* MOBILE RESPONSIVENESS */
        @media (max-width: 700px) {

            .admin-mobile-menu {
                display: flex;
            }

            .admin-mobile-sidebar {
                display: block;
            }

            .admin-mobile-overlay {
                display: block;
                pointer-events: none;
            }

            .admin-mobile-overlay.show {
                pointer-events: auto;
            }

            .topbar {
                padding: 14px 18px;
            }

            .admin-info {
                font-size: 12px;
                text-align: right;
            }
        }

        @media (max-width: 650px) {
            .form-card {
                padding: 20px;
            }

            .form-grid,
            .course-list {
                grid-template-columns: 1fr;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .form-actions .btn {
                text-align: center;
                width: 100%;
            }

            .container {
                margin-top: 25px;
            }
        }
    </style>
</head>

<body>

<!-- MOBILE SIDEBAR -->
<aside class="admin-mobile-sidebar" id="adminMobileSidebar">

    <div class="menu-brand">
        Smart<span>Attend</span>
    </div>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="create_lecturer.php">Create Lecturer</a>
        <a href="lecturers.php">Manage Lecturers</a>
        <a href="students.php">Student Management</a>
        <a href="add_student.php" class="active">Add Student</a>
    </nav>

</aside>

<!-- MOBILE OVERLAY -->
<div class="admin-mobile-overlay" id="adminMobileOverlay"></div>

<!-- TOP BAR -->
<header class="topbar">

    <div class="topbar-left">

        <button
            type="button"
            class="admin-mobile-menu"
            id="adminMobileMenu"
            aria-label="Open navigation menu"
            aria-expanded="false"
            aria-controls="adminMobileSidebar"
        >
            ☰
        </button>

        <div class="brand">
            Smart<span>Attend</span>
        </div>

    </div>

    <div class="admin-info">
        Administrator: <?php echo e($adminName); ?>
    </div>

</header>

<main class="container">

    <a href="students.php" class="back-link">
        &larr; Back to Registered Students
    </a>

    <div class="page-heading">
        <h1>Add New Student</h1>
        <p>Create a student account and assign their courses.</p>
    </div>

    <div class="form-card">

        <?php if ($message !== ''): ?>
            <div class="message <?php echo e($messageType); ?>">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($_SESSION['csrf_token']); ?>"
            >

            <h2 class="section-title">Student Information</h2>

            <div class="form-grid">

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        value="<?php echo e($firstName); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input
                        type="text"
                        id="middle_name"
                        name="middle_name"
                        value="<?php echo e($middleName); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?php echo e($lastName); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="matric_number">Matric Number</label>
                    <input
                        type="text"
                        id="matric_number"
                        name="matric_number"
                        value="<?php echo e($matricNumber); ?>"
                        placeholder="EAUED0001"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo e($email); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="department">Department</label>
                    <input
                        type="text"
                        id="department"
                        name="department"
                        value="<?php echo e($department); ?>"
                        required
                    >
                </div>

                <!-- PROGRAMME DROPDOWN -->
                <div class="form-group">
                    <label for="programme">Programme</label>

                    <select id="programme" name="programme" required>
                        <option value="">Select Programme</option>

                        <?php foreach ($programmes as $programmeOption): ?>
                            <option
                                value="<?php echo e($programmeOption); ?>"
                                <?php echo $programme === $programmeOption ? 'selected' : ''; ?>
                            >
                                <?php echo e($programmeOption); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- ACADEMIC LEVEL DROPDOWN -->
                <div class="form-group">
                    <label for="level">Level</label>

                    <select id="level" name="level" required>
                        <option value="">Select Academic Level</option>

                        <?php foreach ($levels as $academicLevel): ?>
                            <option
                                value="<?php echo e($academicLevel); ?>"
                                <?php echo $level === $academicLevel ? 'selected' : ''; ?>
                            >
                                <?php echo e($academicLevel); ?> Level
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- STUDENT PASSWORD -->
                <div class="form-group">
                    <label for="password">Student Password</label>

                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="togglePassword('password', this)"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- CONFIRM PASSWORD -->
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>

                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="togglePassword('confirm_password', this)"
                            aria-label="Show confirm password"
                            aria-pressed="false"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

            </div>

            <h2 class="section-title">Assign Courses</h2>

            <p class="course-intro">
                Select the courses the student should be enrolled in.
                The student will be able to make their permitted course
                changes later through their student portal.
            </p>

            <?php if (!empty($courses)): ?>

                <div class="course-list">

                    <?php foreach ($courses as $course): ?>

                        <label class="course-item">

                            <input
                                type="checkbox"
                                name="course_ids[]"
                                value="<?php echo (int) $course['id']; ?>"
                                <?php
                                echo in_array(
                                    (string) $course['id'],
                                    array_map('strval', $selectedCourses),
                                    true
                                ) ? 'checked' : '';
                                ?>
                            >

                            <span class="course-details">

                                <strong>
                                    <?php echo e($course['course_code']); ?>
                                    - <?php echo e($course['course_title']); ?>
                                </strong>

                                <span>
                                    Department: <?php echo e($course['department']); ?>
                                </span>

                                <span>
                                    Level: <?php echo e($course['level']); ?>
                                    | Semester: <?php echo e($course['semester']); ?>
                                </span>

                            </span>

                        </label>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="no-courses">
                    No courses are currently available. Please add courses
                    before creating a student account.
                </div>

            <?php endif; ?>

            <div class="form-actions">

                <a href="students.php" class="btn btn-cancel">
                    Cancel
                </a>

                <button
                    type="submit"
                    class="btn btn-submit"
                    <?php echo empty($courses) ? 'disabled' : ''; ?>
                >
                    Create Student Account
                </button>

            </div>

        </form>

    </div>

</main>

<script>
    // Eye and crossed-eye password toggle
    function togglePassword(inputId, button) {

        const passwordInput = document.getElementById(inputId);

        if (!passwordInput) {
            return;
        }

        const isHidden = passwordInput.type === 'password';

        if (isHidden) {

            passwordInput.type = 'text';

            button.setAttribute('aria-label', 'Hide password');
            button.setAttribute('aria-pressed', 'true');

            // Crossed-eye icon
            button.innerHTML = `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 3l18 18"/>
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                    <path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.5 15.5 0 0 1-3.1 3.9"/>
                    <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"/>
                </svg>
            `;

        } else {

            passwordInput.type = 'password';

            button.setAttribute('aria-label', 'Show password');
            button.setAttribute('aria-pressed', 'false');

            // Normal eye icon
            button.innerHTML = `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            `;
        }
    }

    // Mobile sidebar controls
    const mobileMenu = document.getElementById('adminMobileMenu');
    const mobileSidebar = document.getElementById('adminMobileSidebar');
    const mobileOverlay = document.getElementById('adminMobileOverlay');

    function openAdminMenu() {
        mobileSidebar.classList.add('open');
        mobileOverlay.classList.add('show');
        document.body.classList.add('admin-menu-open');

        mobileMenu.textContent = '✕';
        mobileMenu.setAttribute('aria-expanded', 'true');
        mobileMenu.setAttribute('aria-label', 'Close navigation menu');
    }

    function closeAdminMenu() {
        mobileSidebar.classList.remove('open');
        mobileOverlay.classList.remove('show');
        document.body.classList.remove('admin-menu-open');

        mobileMenu.textContent = '☰';
        mobileMenu.setAttribute('aria-expanded', 'false');
        mobileMenu.setAttribute('aria-label', 'Open navigation menu');
    }

    mobileMenu.addEventListener('click', function () {
        if (mobileSidebar.classList.contains('open')) {
            closeAdminMenu();
        } else {
            openAdminMenu();
        }
    });

    // Close menu when overlay is clicked
    mobileOverlay.addEventListener('click', closeAdminMenu);

    // Close menu after selecting a navigation link
    mobileSidebar.querySelectorAll('nav a').forEach(function (link) {
        link.addEventListener('click', closeAdminMenu);
    });

    // Close menu with Escape key
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAdminMenu();
        }
    });

    // Close menu when returning to desktop screen size
    window.addEventListener('resize', function () {
        if (window.innerWidth > 700) {
            closeAdminMenu();
        }
    });
</script>

</body>
</html>
