
<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$studentId = (int) $_SESSION['student_id'];

// Check whether the student account is active
$statusStmt = $pdo->prepare("
    SELECT status
    FROM students
    WHERE id = ?
    LIMIT 1
");
$statusStmt->execute([$studentId]);
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

$errors = [];

$stmt = $pdo->prepare("
    SELECT id, first_name, middle_name, last_name, matric_number,
           email, department, programme, level, profile_image
    FROM students
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($firstName === '') {
        $errors[] = "First name is required.";
    }

    if ($lastName === '') {
        $errors[] = "Last name is required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email address.";
    }

    // Check whether another student already uses this email
    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT id FROM students
            WHERE email = ? AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$email, $studentId]);

        if ($stmt->fetch()) {
            $errors[] = "This email address is already in use.";
        }
    }

    $newImageName = $student['profile_image'];

    // Process profile picture
    if (
        isset($_FILES['profile_image']) &&
        $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "There was a problem uploading your picture.";
        } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $errors[] = "Your picture must not exceed 2MB.";
        } else {
            $imageInfo = finfo_open(FILEINFO_MIME_TYPE);

            $mimeType = finfo_file(
                $imageInfo,
                $_FILES['profile_image']['tmp_name']
            );

            finfo_close($imageInfo);

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = "Only JPG, PNG and WEBP images are allowed.";
            } else {
                $uploadDirectory = __DIR__ . '/../uploads/profile/';

                if (!is_dir($uploadDirectory)) {
                    mkdir($uploadDirectory, 0755, true);
                }

                $newImageName = 'student_' . $studentId . '_' .
                    bin2hex(random_bytes(8)) . '.' .
                    $allowedTypes[$mimeType];

                if (!move_uploaded_file(
                    $_FILES['profile_image']['tmp_name'],
                    $uploadDirectory . $newImageName
                )) {
                    $errors[] = "Could not save the uploaded picture.";
                }
            }
        }
    }

    // Save profile changes
    if (!$errors) {
        $stmt = $pdo->prepare("
            UPDATE students
            SET first_name = ?,
                middle_name = ?,
                last_name = ?,
                email = ?,
                profile_image = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $firstName,
            $middleName,
            $lastName,
            $email,
            $newImageName,
            $studentId
        ]);

        // Remove old picture after successful replacement
        if (
            $newImageName !== $student['profile_image'] &&
            !empty($student['profile_image'])
        ) {
            $oldImage = __DIR__ . '/../uploads/profile/' .
                basename($student['profile_image']);

            if (is_file($oldImage)) {
                unlink($oldImage);
            }
        }

        $_SESSION['student_name'] = trim(
            $firstName . ' ' . $middleName . ' ' . $lastName
        );

        header("Location: profile.php?updated=1");
        exit;
    }

    // Keep submitted values visible if validation fails
    $student['first_name'] = $firstName;
    $student['middle_name'] = $middleName;
    $student['last_name'] = $lastName;
    $student['email'] = $email;
}

$profileImage = !empty($student['profile_image'])
    ? '../uploads/profile/' . rawurlencode($student['profile_image'])
    : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/student.css">
</head>

<body class="profile-page-body">

<div class="profile-page-container">

    <!-- PAGE HEADER -->
    <header class="profile-page-header">

        <div>
            <p class="profile-page-label">SMARTATTEND STUDENT PORTAL</p>
            <h1>My Profile</h1>
            <p>View and update your personal information.</p>
        </div>

        <a href="dashboard.php" class="profile-back-link">
            ← Back to Dashboard
        </a>

    </header>

    <!-- SUCCESS MESSAGE -->
    <?php if (isset($_GET['updated'])): ?>

        <div class="profile-success">
            <strong>✓</strong>
            Your profile has been updated successfully.
        </div>

    <?php endif; ?>

    <!-- ERROR MESSAGES -->
    <?php if ($errors): ?>

        <div class="profile-error">

            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- PROFILE CARD -->
    <div class="profile-edit-card">

        <!-- PROFILE PICTURE -->
        <div class="profile-picture-area">

            <?php if ($profileImage): ?>

                <img src="<?= e($profileImage) ?>"
                     alt="Student profile picture">

            <?php else: ?>

                <div class="large-profile-placeholder">
                    <?= e(strtoupper(substr($student['first_name'], 0, 1))) ?>
                </div>

            <?php endif; ?>

            <h2>
                <?= e(trim(
                    $student['first_name'] . ' ' .
                    ($student['middle_name'] ?? '') . ' ' .
                    ($student['last_name'] ?? '')
                )) ?>
            </h2>

            <p><?= e($student['matric_number']) ?></p>

            <span class="profile-status">Student Account</span>

        </div>

        <!-- PROFILE FORM -->
        <form method="POST" enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="profile-section-heading">
                <h3>Personal Information</h3>
                <p>Update the information you are permitted to change.</p>
            </div>

            <div class="profile-form-grid">

                <div class="profile-form-group">

                    <label for="first_name">First Name</label>

                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        value="<?= e($student['first_name']) ?>"
                        required
                    >

                </div>

                <div class="profile-form-group">

                    <label for="middle_name">Middle Name</label>

                    <input
                        type="text"
                        id="middle_name"
                        name="middle_name"
                        value="<?= e($student['middle_name'] ?? '') ?>"
                    >

                </div>

                <div class="profile-form-group">

                    <label for="last_name">Last Name</label>

                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?= e($student['last_name'] ?? '') ?>"
                        required
                    >

                </div>

                <div class="profile-form-group">

                    <label for="email">Email Address</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($student['email']) ?>"
                        required
                    >

                </div>

                <div class="profile-form-group">

                    <label>Matric Number</label>

                    <input
                        type="text"
                        value="<?= e($student['matric_number']) ?>"
                        disabled
                    >

                    <small>Your matric number cannot be changed here.</small>

                </div>

                <div class="profile-form-group">

                    <label>Department</label>

                    <input
                        type="text"
                        value="<?= e($student['department'] ?? '') ?>"
                        disabled
                    >

                </div>

                <div class="profile-form-group">

                    <label>Programme</label>

                    <input
                        type="text"
                        value="<?= e($student['programme'] ?? '') ?>"
                        disabled
                    >

                </div>

                <div class="profile-form-group">

                    <label>Level</label>

                    <input
                        type="text"
                        value="<?= e($student['level'] ?? '') ?>"
                        disabled
                    >

                </div>

                <div class="profile-form-group full-width">

                    <label for="profile_image">Upload Profile Picture</label>

                    <input
                        type="file"
                        id="profile_image"
                        name="profile_image"
                        accept=".jpg,.jpeg,.png,.webp"
                    >

                    <small>
                        Accepted formats: JPG, PNG and WEBP.
                        Maximum size: 2MB.
                    </small>

                </div>

            </div>

            <div class="profile-form-actions">

                <button type="submit" class="save-profile-button">
                    Save Changes
                </button>

                <a href="dashboard.php" class="profile-cancel-link">
                    Cancel
                </a>

            </div>

        </form>

    </div>

    <!-- FOOTER -->
    <footer class="profile-page-footer">
        © <?= date('Y') ?> SmartAttend. All rights reserved.
        <span>Knowledge, Truth and Service</span>
    </footer>

</div>

</body>
</html>
