
<?php
require_once __DIR__ . '/../config/db.php';

// Make sure the student is logged in.
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$studentId = (int) $_SESSION['student_id'];

// Check whether the student account is active.
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

$success = '';
$error = '';

// Handle initial registration or permitted editing submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $rawSelectedCourses = $_POST['course_ids'] ?? [];

    if (!is_array($rawSelectedCourses)) {
        $error = "Invalid course selection. Please try again.";
    } else {
        $selectedCourses = [];

        foreach ($rawSelectedCourses as $rawCourseId) {
            $courseId = filter_var($rawCourseId, FILTER_VALIDATE_INT);

            if ($courseId === false || $courseId < 1) {
                $error = "One or more selected courses are invalid.";
                break;
            }

            $selectedCourses[] = (int) $courseId;
        }

        // Remove duplicates and sort course IDs for accurate comparison.
        $selectedCourses = array_values(array_unique($selectedCourses));
        sort($selectedCourses);

        if ($error === '') {
            if (empty($selectedCourses)) {
                $error = "Please keep at least one course registered.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Create the student's tracking row if this is their first use.
                    $createTracking = $pdo->prepare("
                        INSERT IGNORE INTO student_course_registration
                            (student_id, edit_count)
                        VALUES (?, 0)
                    ");
                    $createTracking->execute([$studentId]);

                    // Lock the tracking row while processing this submission.
                    $trackingStmt = $pdo->prepare("
                        SELECT initial_submitted_at, edit_count
                        FROM student_course_registration
                        WHERE student_id = ?
                        FOR UPDATE
                    ");
                    $trackingStmt->execute([$studentId]);
                    $tracking = $trackingStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$tracking) {
                        throw new RuntimeException(
                            "Could not load course registration tracking."
                        );
                    }

                    // Get the student's existing registered courses.
                    $existingStmt = $pdo->prepare("
                        SELECT course_id
                        FROM enrollments
                        WHERE student_id = ?
                    ");
                    $existingStmt->execute([$studentId]);

                    $existingCourses = array_map(
                        'intval',
                        $existingStmt->fetchAll(PDO::FETCH_COLUMN)
                    );

                    $isInitialRegistration =
                        empty($tracking['initial_submitted_at'])
                        && empty($existingCourses);

                    // Treat older registrations as completed initial registrations.
                    if (
                        empty($tracking['initial_submitted_at'])
                        && !empty($existingCourses)
                    ) {
                        $markLegacyRegistration = $pdo->prepare("
                            UPDATE student_course_registration
                            SET initial_submitted_at = NOW()
                            WHERE student_id = ?
                        ");
                        $markLegacyRegistration->execute([$studentId]);
                    }

                    $editCount = (int) $tracking['edit_count'];

                    // Check whether editing opportunities have been exhausted.
                    if (!$isInitialRegistration && $editCount >= 2) {
                        throw new DomainException(
                            "You have used both course-editing opportunities. Your registration is now locked."
                        );
                    }

                    // Confirm that every submitted course exists.
                    $placeholders = implode(
                        ',',
                        array_fill(0, count($selectedCourses), '?')
                    );

                    $validStmt = $pdo->prepare("
                        SELECT id
                        FROM courses
                        WHERE id IN ($placeholders)
                    ");
                    $validStmt->execute($selectedCourses);

                    $validCourseIds = array_map(
                        'intval',
                        $validStmt->fetchAll(PDO::FETCH_COLUMN)
                    );

                    sort($validCourseIds);

                    if ($validCourseIds !== $selectedCourses) {
                        throw new DomainException(
                            "One or more selected courses are no longer available. Please refresh and try again."
                        );
                    }

                    // FIRST SUBMISSION
                    if ($isInitialRegistration) {

                        $insert = $pdo->prepare("
                            INSERT INTO enrollments
                                (student_id, course_id)
                            VALUES (?, ?)
                        ");

                        foreach ($selectedCourses as $courseId) {
                            $insert->execute([
                                $studentId,
                                $courseId
                            ]);
                        }

                        $markInitial = $pdo->prepare("
                            UPDATE student_course_registration
                            SET initial_submitted_at = NOW(),
                                edit_count = 0
                            WHERE student_id = ?
                        ");
                        $markInitial->execute([$studentId]);

                        $pdo->commit();

                        $success = "Your courses have been registered successfully. You now have two opportunities to add or remove courses.";

                    } else {

                        // Sort existing courses before comparing.
                        $currentForComparison = $existingCourses;
                        sort($currentForComparison);

                        // IMPORTANT:
                        // If the submitted courses are exactly the same
                        // as the existing courses, do not use an opportunity.
                        if ($currentForComparison === $selectedCourses) {

                            $pdo->commit();

                            $success = "No changes were made to your course registration. Your editing opportunities have not been used.";

                        } else {

                            // Remove only this student's enrollments that were unchecked.
                            $deleteStmt = $pdo->prepare("
                                DELETE FROM enrollments
                                WHERE student_id = ?
                                  AND course_id NOT IN ($placeholders)
                            ");

                            $deleteStmt->execute(
                                array_merge(
                                    [$studentId],
                                    $selectedCourses
                                )
                            );

                            // Add newly selected courses without duplicating existing enrollments.
                            $insertStmt = $pdo->prepare("
                                INSERT INTO enrollments
                                    (student_id, course_id)
                                SELECT ?, ?
                                WHERE NOT EXISTS (
                                    SELECT 1
                                    FROM enrollments
                                    WHERE student_id = ?
                                      AND course_id = ?
                                )
                            ");

                            foreach ($selectedCourses as $courseId) {
                                $insertStmt->execute([
                                    $studentId,
                                    $courseId,
                                    $studentId,
                                    $courseId
                                ]);
                            }

                            // Count one opportunity only after actual changes.
                            $updateCount = $pdo->prepare("
                                UPDATE student_course_registration
                                SET initial_submitted_at =
                                        COALESCE(initial_submitted_at, NOW()),
                                    edit_count = edit_count + 1
                                WHERE student_id = ?
                            ");
                            $updateCount->execute([$studentId]);

                            $newEditCount = $editCount + 1;
                            $remaining = 2 - $newEditCount;

                            $pdo->commit();

                            $success = "Your course changes have been saved successfully. "
                                . ($remaining > 0
                                    ? "You have {$remaining} editing opportunity remaining."
                                    : "You have used both editing opportunities. Your registration is now locked.");
                        }
                    }

                } catch (DomainException $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $e->getMessage();

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        "Course registration error: " . $e->getMessage()
                    );

                    $error = "Something went wrong while saving your course registration. Please try again.";
                }
            }
        }
    }
}

// Get the student's registration status and editing opportunities.
$trackingStmt = $pdo->prepare("
    SELECT initial_submitted_at, edit_count
    FROM student_course_registration
    WHERE student_id = ?
    LIMIT 1
");
$trackingStmt->execute([$studentId]);

$tracking = $trackingStmt->fetch(PDO::FETCH_ASSOC);

$initialSubmitted = $tracking && !empty($tracking['initial_submitted_at']);

$editCount = $tracking ? (int) $tracking['edit_count'] : 0;

$remainingEdits = max(0, 2 - $editCount);

$registrationLocked = $initialSubmitted && $remainingEdits === 0;

// Get all available courses and identify courses registered by this student.
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.course_code,
        c.course_title,
        c.department,
        c.level,
        c.semester,
        CASE
            WHEN e.id IS NOT NULL THEN 1
            ELSE 0
        END AS already_registered
    FROM courses c
    LEFT JOIN enrollments e
        ON e.course_id = c.id
        AND e.student_id = ?
    ORDER BY c.course_code ASC
");

$stmt->execute([$studentId]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$registeredCount = 0;

foreach ($courses as $course) {
    if ($course['already_registered']) {
        $registeredCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Course Registration | SmartAttend</title>

    <link rel="stylesheet"
          href="../assets/css/student.css">

    <style>

        .course-registration-page {
            min-height: 100vh;
            background: #f4f7f4;
            padding: 35px 20px;
            color: #26352b;
        }

        .course-registration-container {
            max-width: 1100px;
            margin: auto;
        }

        .registration-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .registration-header h1 {
            color: #14532d;
            margin-bottom: 8px;
        }

        .registration-header p {
            color: #647067;
        }

        .registration-back {
            display: inline-block;
            background: #166534;
            color: white;
            text-decoration: none;
            padding: 11px 18px;
            border-radius: 7px;
            font-weight: 600;
        }

        .registration-back:hover {
            background: #14532d;
        }

        .registration-summary {
            background: white;
            border: 1px solid #e3ebe4;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .registration-summary strong {
            color: #166534;
            font-size: 22px;
        }

        .registration-message {
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .registration-success {
            background: #dcfce7;
            color: #166534;
        }

        .registration-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .available-courses {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .available-course-card {
            background: white;
            border: 1px solid #e3ebe4;
            border-radius: 11px;
            padding: 22px;
            transition: 0.2s;
        }

        .available-course-card:hover {
            border-color: #86b99a;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
        }

        .available-course-card.registered {
            border-color: #86b99a;
            background: #f7fcf8;
        }

        .course-select-row {
            display: flex;
            align-items: flex-start;
            gap: 13px;
        }

        .course-select-row input {
            width: 19px;
            height: 19px;
            margin-top: 4px;
            accent-color: #166534;
            cursor: pointer;
        }

        .course-information {
            flex: 1;
        }

        .course-information h3 {
            color: #14532d;
            margin-bottom: 7px;
            font-size: 18px;
        }

        .course-information p {
            color: #59665d;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .course-information small {
            display: block;
            color: #68756c;
            margin-top: 5px;
        }

        .registered-label {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 10px;
        }

        .register-submit-area {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
        }

        .register-submit-button {
            background: #166534;
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 7px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
        }

        .register-submit-button:hover {
            background: #14532d;
        }

        .registration-empty {
            background: white;
            border-radius: 10px;
            padding: 45px 20px;
            text-align: center;
            color: #68756c;
        }

        .registration-empty h3 {
            color: #14532d;
            margin-bottom: 10px;
        }

        .registration-footer {
            text-align: center;
            color: #7b867e;
            margin-top: 35px;
            font-size: 13px;
        }

        @media (max-width: 700px) {

            .available-courses {
                grid-template-columns: 1fr;
            }

            .course-registration-page {
                padding: 20px 12px;
            }

            .registration-submit-area {
                justify-content: stretch;
            }

            .register-submit-button {
                width: 100%;
            }

        }

    </style>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
</head>

<body>

<div class="course-registration-page">

    <div class="course-registration-container">

        <!-- HEADER -->
        <div class="registration-header">

            <div>

                <h1>Course Registration</h1>

                <p>
                    Select the courses you are taking this semester.
                </p>

            </div>

            <a href="dashboard.php" class="registration-back">
                &larr; Back to Dashboard
            </a>

        </div>

        <!-- REGISTRATION SUMMARY -->
        <div class="registration-summary">

            <p>
                Courses currently registered:
                <strong><?= $registeredCount ?></strong>
            </p>

            <?php if (!$initialSubmitted): ?>

                <p style="margin-top: 10px;">
                    This is your initial course registration. After submitting,
                    you will have <strong>two opportunities</strong> to add or remove courses.
                </p>

            <?php elseif (!$registrationLocked): ?>

                <p style="margin-top: 10px;">
                    Editing opportunities remaining:
                    <strong><?= $remainingEdits ?> of 2</strong>
                </p>

                <p style="margin-top: 6px; color: #647067;">
                    You can select or unselect courses below, then save your changes.
                </p>

            <?php else: ?>

                <p style="margin-top: 10px; color: #991b1b;">
                    Both editing opportunities have been used. Your course registration is locked.
                </p>

            <?php endif; ?>

        </div>

        <!-- SUCCESS MESSAGE -->
        <?php if ($success): ?>

            <div class="registration-message registration-success">
                <?= e($success) ?>
            </div>

        <?php endif; ?>

        <!-- ERROR MESSAGE -->
        <?php if ($error): ?>

            <div class="registration-message registration-error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <!-- AVAILABLE COURSES -->
        <?php if (count($courses) > 0): ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <div class="available-courses">

                    <?php foreach ($courses as $course): ?>

                        <div class="available-course-card
                            <?= $course['already_registered'] ? 'registered' : '' ?>">

                            <div class="course-select-row">

                                <input
                                    type="checkbox"
                                    name="course_ids[]"
                                    value="<?= (int) $course['id'] ?>"
                                    <?= $course['already_registered'] ? 'checked' : '' ?>
                                    <?= $registrationLocked ? 'disabled' : '' ?>
                                >

                                <div class="course-information">

                                    <h3>
                                        <?= e($course['course_code']) ?>
                                    </h3>

                                    <p>
                                        <?= e($course['course_title']) ?>
                                    </p>

                                    <small>
                                        Department:
                                        <?= e($course['department'] ?? 'N/A') ?>
                                    </small>

                                    <small>
                                        Level:
                                        <?= e($course['level'] ?? 'N/A') ?>
                                    </small>

                                    <small>
                                        Semester:
                                        <?= e($course['semester'] ?? 'N/A') ?>
                                    </small>

                                    <?php if ($course['already_registered']): ?>

                                        <span class="registered-label">
                                            Currently Registered
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <!-- SUBMIT OR SAVE COURSE CHANGES -->
                <?php if (!$registrationLocked): ?>

                    <div class="register-submit-area">

                        <button
                            type="submit"
                            class="register-submit-button"
                        >
                            <?= $initialSubmitted
                                ? 'Save Course Changes'
                                : 'Submit Course Registration' ?>
                        </button>

                    </div>

                <?php else: ?>

                    <div class="registration-message registration-error"
                         style="margin-top: 25px;">

                        Course editing is closed because you have used both opportunities.

                    </div>

                <?php endif; ?>

            </form>

        <?php else: ?>

            <div class="registration-empty">

                <h3>No Courses Available</h3>

                <p>
                    There are currently no courses in the system.
                    Please contact your administrator.
                </p>

            </div>

        <?php endif; ?>

        <!-- FOOTER -->
        <div class="registration-footer">

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
