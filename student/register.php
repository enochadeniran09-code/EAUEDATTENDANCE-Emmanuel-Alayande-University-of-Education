
<?php
require_once '../config/db.php';

if (isset($_SESSION['student_id'])) {
    header("Location: dashboard.php");
    exit;
}

$errors = [];

// Only academic levels available in the university
$levels = ['100', '200', '300', '400'];

// All available university programmes
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $matric = strtoupper(trim($_POST['matric_number'] ?? ''));
    $first = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $department = trim($_POST['department'] ?? '');
    $programme = trim($_POST['programme'] ?? '');
    $level = trim($_POST['level'] ?? '');

    $pass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Validate required fields
    if (
        $matric === '' || $first === '' || $last === '' ||
        $email === '' || $department === '' ||
        $programme === '' || $level === ''
    ) {
        $errors[] = "Please fill in all required fields.";
    }

    // Validate academic level
    if (!in_array($level, $levels, true)) {
        $errors[] = "Invalid academic level. Select 100, 200, 300 or 400 Level.";
    }

    // Validate selected programme
    if (!in_array($programme, $programmes, true)) {
        $errors[] = "Please select a valid programme.";
    }

    // Validate email address
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate password
    if (strlen($pass) < 8) {
        $errors[] = "Password must contain at least 8 characters.";
    }

    // Confirm password
    if ($pass !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {

        // Check for existing matric number or email
        $check = $pdo->prepare(
            "SELECT id FROM students
             WHERE matric_number = ? OR email = ?"
        );

        $check->execute([$matric, $email]);

        if ($check->fetch()) {
            $errors[] = "Matric number or email already exists.";
        }
    }

    if (empty($errors)) {

        // Securely hash password
        $hashedPassword = password_hash(
            $pass,
            PASSWORD_DEFAULT
        );

        try {

            $sql = "INSERT INTO students
            (
                matric_number,
                first_name,
                middle_name,
                last_name,
                email,
                department,
                programme,
                level,
                password
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                $matric,
                $first,
                $middle ?: null,
                $last,
                $email,
                $department,
                $programme,
                $level,
                $hashedPassword
            ]);

            set_flash(
                "Registration successful! You can now log in.",
                "success"
            );

            header("Location: login.php");
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Student Registration | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet"
          href="../assets/css/student.css">
</head>

<body>

<div class="auth-container">

    <div class="auth-card">

        <div class="brand">
            <div class="brand-icon">SA</div>
            <h1>SmartAttend</h1>
            <p>Student Attendance Management System</p>
        </div>

        <h2>Create Student Account</h2>

        <p class="subtitle">
            Register to access your student attendance portal.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="registerForm">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <div class="form-group">
                <label>Matric Number</label>
                <input type="text"
                       name="matric_number"
                       placeholder="Enter matric number"
                       value="<?= e($_POST['matric_number'] ?? '') ?>"
                       required>
            </div>

            <div class="form-row">

                <div class="form-group">
                    <label>First Name</label>
                    <input type="text"
                           name="first_name"
                           placeholder="First name"
                           value="<?= e($_POST['first_name'] ?? '') ?>"
                           required>
                </div>

                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text"
                           name="middle_name"
                           placeholder="Middle name"
                           value="<?= e($_POST['middle_name'] ?? '') ?>">
                </div>

            </div>

            <div class="form-group">
                <label>Last Name</label>
                <input type="text"
                       name="last_name"
                       placeholder="Last name"
                       value="<?= e($_POST['last_name'] ?? '') ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email"
                       name="email"
                       placeholder="example@email.com"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Department</label>
                <input type="text"
                       name="department"
                       placeholder="Enter your department"
                       value="<?= e($_POST['department'] ?? '') ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Programme</label>

                <select name="programme" required>

                    <option value="">Select your programme</option>

                    <?php foreach ($programmes as $programmeOption): ?>

                        <option
                            value="<?= e($programmeOption) ?>"
                            <?= (($_POST['programme'] ?? '') === $programmeOption)
                                ? 'selected' : '' ?>>

                            <?= e($programmeOption) ?>

                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label>Academic Level</label>

                <select name="level" required>

                    <option value="">Select your level</option>

                    <?php foreach ($levels as $levelOption): ?>

                        <option
                            value="<?= $levelOption ?>"
                            <?= (($_POST['level'] ?? '') === $levelOption)
                                ? 'selected' : '' ?>>

                            <?= $levelOption ?> Level

                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label>Password</label>

                <div class="password-box">

                    <input type="password"
                           name="password"
                           id="password"
                           placeholder="Create password"
                           minlength="8"
                           required>

                    <button type="button"
                            class="toggle-password"
                            data-toggle-password="password">
                        Show
                    </button>

                </div>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>

                <div class="password-box">

                    <input type="password"
                           name="confirm_password"
                           id="confirm_password"
                           placeholder="Confirm password"
                           required>

                    <button type="button"
                            class="toggle-password"
                            data-toggle-password="confirm_password">
                        Show
                    </button>

                </div>

                <small id="passwordMessage"></small>
            </div>

            <button type="submit" class="primary-btn">
                Create Account
            </button>

        </form>

        <p class="auth-footer">
            Already have an account?
            <a href="login.php">Login here</a>
        </p>

    </div>

</div>

<script src="../assets/js/auth.js"></script>

</body>
</html>
