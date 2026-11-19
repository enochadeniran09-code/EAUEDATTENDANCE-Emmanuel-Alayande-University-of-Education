
<?php

// Load authentication and database connection
require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];
$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';
$lecturerInitial = strtoupper(substr(trim($lecturerName), 0, 1));

$error = '';

// Preserve form values if validation fails
$selectedCourse = (int) ($_POST['course_id'] ?? 0);
$sessionTitle = trim($_POST['session_title'] ?? '');
$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';
$allowedRadius = $_POST['allowed_radius'] ?? '100';

// Get lecturer's assigned courses
$stmt = $pdo->prepare("
    SELECT id, course_code, course_title
    FROM courses
    WHERE lecturer_id = ?
    ORDER BY course_code ASC
");

$stmt->execute([$lecturerId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process attendance session form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $courseId = (int) ($_POST['course_id'] ?? 0);
    $sessionTitle = trim($_POST['session_title'] ?? '');
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';
    $allowedRadius = (int) ($_POST['allowed_radius'] ?? 100);

    // Verify that course belongs to lecturer
    $check = $pdo->prepare("
        SELECT id FROM courses
        WHERE id = ? AND lecturer_id = ?
    ");

    $check->execute([
        $courseId,
        $lecturerId
    ]);

    if (!$check->fetch()) {

        $error = "Please select a valid course.";

    } elseif ($sessionTitle === '') {

        $error = "Please enter a session title.";

    } elseif (
        !is_numeric($latitude) ||
        !is_numeric($longitude) ||
        (float) $latitude < -90 ||
        (float) $latitude > 90 ||
        (float) $longitude < -180 ||
        (float) $longitude > 180
    ) {

        $error = "Please provide valid location coordinates.";

    } elseif ($allowedRadius < 10 || $allowedRadius > 1000) {

        $error = "Radius must be between 10 and 1000 metres.";

    } else {

        // Generate a unique QR token
        $qrToken = bin2hex(random_bytes(32));

        // Create attendance session
        $stmt = $pdo->prepare("
            INSERT INTO attendance_sessions
            (
                course_id,
                lecturer_id,
                session_title,
                latitude,
                longitude,
                allowed_radius,
                qr_token,
                status,
                start_time,
                end_time,
                created_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                'active',
                NOW(),
                DATE_ADD(NOW(), INTERVAL 2 HOUR),
                NOW()
            )
        ");

        $stmt->execute([
            $courseId,
            $lecturerId,
            $sessionTitle,
            $latitude,
            $longitude,
            $allowedRadius,
            $qrToken
        ]);

        // Redirect after successful creation
        header("Location: dashboard.php?session_created=1");
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Attendance Session | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <!-- Use the same stylesheet as the lecturer dashboard -->
    <link rel="stylesheet" href="../assets/css/student.css">

    <style>

        /* SESSION FORM */

        .session-form-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 28px;
            border: 1px solid #e5ebe7;
            margin-bottom: 25px;
        }

        .session-form-card h2 {
            color: #145c39;
            font-size: 20px;
            margin-bottom: 8px;
        }

        .session-form-description {
            color: #718078;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .session-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .session-form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .session-form-group.full-width {
            grid-column: 1 / -1;
        }

        .session-form-group label {
            color: #34483d;
            font-size: 13px;
            font-weight: 700;
        }

        .session-form-group input,
        .session-form-group select {
            width: 100%;
            box-sizing: border-box;
            padding: 13px 14px;
            border: 1px solid #dce5df;
            border-radius: 7px;
            background: #ffffff;
            color: #26382e;
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .session-form-group input:focus,
        .session-form-group select:focus {
            border-color: #145c39;
            box-shadow: 0 0 0 3px rgba(20, 92, 57, 0.09);
        }

        .session-form-group input::placeholder {
            color: #a0aaa4;
        }

        .session-field-hint {
            color: #829087;
            font-size: 11px;
            line-height: 1.5;
        }

        /* LOCATION BUTTON */

        .location-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: fit-content;
            padding: 11px 16px;
            border: 1px solid #cce4d4;
            border-radius: 7px;
            background: #e8f4ec;
            color: #145c39;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .location-button:hover {
            background: #145c39;
            color: #ffffff;
        }

        .location-message {
            display: none;
            padding: 11px 13px;
            margin-top: 10px;
            border-radius: 6px;
            background: #e8f4ec;
            color: #145c39;
            font-size: 12px;
            line-height: 1.5;
        }

        /* FORM ALERT */

        .session-error {
            padding: 13px 15px;
            margin-bottom: 22px;
            border: 1px solid #f3c8c5;
            border-radius: 7px;
            background: #fff0ee;
            color: #b42318;
            font-size: 13px;
            line-height: 1.5;
        }

        .session-info {
            display: flex;
            gap: 12px;
            padding: 15px;
            margin-top: 22px;
            border-radius: 8px;
            background: #f0f7f2;
            border: 1px solid #dcece1;
        }

        .session-info-icon {
            color: #145c39;
            font-size: 19px;
            font-weight: bold;
        }

        .session-info p {
            color: #53675b;
            font-size: 12px;
            line-height: 1.6;
            margin: 0;
        }

        /* FORM ACTIONS */

        .session-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .session-submit-button {
            padding: 13px 20px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: #ffffff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .session-submit-button:hover {
            background: #103c29;
        }

        .session-submit-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .session-back-button {
            display: inline-block;
            padding: 12px 18px;
            border: 1px solid #dce5df;
            border-radius: 7px;
            color: #53675b;
            background: #ffffff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: 0.2s;
        }

        .session-back-button:hover {
            background: #f0f7f2;
            color: #145c39;
        }

        .no-courses-message {
            padding: 18px;
            border-radius: 8px;
            background: #fff0ee;
            color: #b42318;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 700px) {

            .session-form-grid {
                grid-template-columns: 1fr;
            }

            .session-form-group.full-width {
                grid-column: auto;
            }

            .session-form-card {
                padding: 20px 16px;
            }

            .session-form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .session-submit-button,
            .session-back-button {
                width: 100%;
                text-align: center;
                box-sizing: border-box;
            }

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

            <a class="active" href="session.php">
                <span>＋</span> Create Session
            </a>

            <a href="dashboard.php#my-courses">
                <span>▤</span> My Courses
            </a>

            <a href="dashboard.php#attendance-sessions">
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

                <h1>Create Attendance Session</h1>

                <p>
                    Set up a new attendance session for your students.
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
                    Create a New Session
                </h2>

                <p>
                    Select your course, provide your classroom location
                    and create an attendance session for your students.
                </p>

            </div>

            <div class="welcome-icon">＋</div>

        </section>

        <!-- FORM SECTION -->
        <section class="student-content-card">

            <div class="section-heading">

                <div>

                    <h2>Session Details</h2>

                    <p>
                        Complete the information below to create your session.
                    </p>

                </div>

            </div>

            <div class="session-form-card">

                <h2>Attendance Session Information</h2>

                <p class="session-form-description">
                    Enter the details of the class you want to take attendance for.
                </p>

                <?php if ($error): ?>

                    <div class="session-error">
                        <?= e($error) ?>
                    </div>

                <?php endif; ?>

                <?php if (empty($courses)): ?>

                    <div class="no-courses-message">
                        No courses have been assigned to your account yet.
                        Please contact the administrator.
                    </div>

                <?php else: ?>

                    <form method="POST" action="">

                        <!-- CSRF SECURITY TOKEN -->
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <div class="session-form-grid">

                            <!-- COURSE -->
                            <div class="session-form-group full-width">

                                <label for="course_id">
                                    Select Course
                                </label>

                                <select
                                    name="course_id"
                                    id="course_id"
                                    required
                                >

                                    <option value="">
                                        Choose a course
                                    </option>

                                    <?php foreach ($courses as $course): ?>

                                        <option
                                            value="<?= (int) $course['id'] ?>"
                                            <?= $selectedCourse === (int) $course['id'] ? 'selected' : '' ?>
                                        >
                                            <?= e($course['course_code']) ?> -
                                            <?= e($course['course_title']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- SESSION TITLE -->
                            <div class="session-form-group full-width">

                                <label for="session_title">
                                    Session Title
                                </label>

                                <input
                                    type="text"
                                    name="session_title"
                                    id="session_title"
                                    placeholder="e.g. CSC 301 Morning Lecture"
                                    value="<?= e($sessionTitle) ?>"
                                    required
                                >

                                <span class="session-field-hint">
                                    Give this attendance session a meaningful title.
                                </span>

                            </div>

                            <!-- LATITUDE -->
                            <div class="session-form-group">

                                <label for="latitude">
                                    Lecturer Latitude
                                </label>

                                <input
                                    type="number"
                                    step="any"
                                    name="latitude"
                                    id="latitude"
                                    placeholder="Enter latitude"
                                    value="<?= e($latitude) ?>"
                                    required
                                >

                            </div>

                            <!-- LONGITUDE -->
                            <div class="session-form-group">

                                <label for="longitude">
                                    Lecturer Longitude
                                </label>

                                <input
                                    type="number"
                                    step="any"
                                    name="longitude"
                                    id="longitude"
                                    placeholder="Enter longitude"
                                    value="<?= e($longitude) ?>"
                                    required
                                >

                            </div>

                            <!-- LOCATION BUTTON -->
                            <div class="session-form-group full-width">

                                <button
                                    type="button"
                                    class="location-button"
                                    id="locationButton"
                                    onclick="getLocation()"
                                >
                                    <span>⌖</span>
                                    Get My Location
                                </button>

                                <div
                                    class="location-message"
                                    id="locationMessage"
                                ></div>

                                <span class="session-field-hint">
                                    Allow your browser to access your location
                                    so students can verify their attendance location.
                                </span>

                            </div>

                            <!-- ALLOWED RADIUS -->
                            <div class="session-form-group full-width">

                                <label for="allowed_radius">
                                    Allowed Attendance Radius (metres)
                                </label>

                                <input
                                    type="number"
                                    name="allowed_radius"
                                    id="allowed_radius"
                                    value="<?= e($allowedRadius) ?>"
                                    min="10"
                                    max="1000"
                                    required
                                >

                                <span class="session-field-hint">
                                    Students must be within this distance
                                    from your registered classroom location.
                                    Minimum: 10 metres. Maximum: 1000 metres.
                                </span>

                            </div>

                        </div>

                        <!-- SESSION INFORMATION -->
                        <div class="session-info">

                            <div class="session-info-icon">ⓘ</div>

                            <p>
                                <strong>Session duration:</strong>
                                Every new attendance session will automatically
                                remain active for two hours. After the end time,
                                it should be closed by the automatic expiry
                                process on your dashboard.
                            </p>

                        </div>

                        <!-- FORM ACTIONS -->
                        <div class="session-form-actions">

                            <button
                                type="submit"
                                class="session-submit-button"
                            >
                                Create Attendance Session
                            </button>

                            <a
                                href="dashboard.php"
                                class="session-back-button"
                            >
                                Back to Dashboard
                            </a>

                        </div>

                    </form>

                <?php endif; ?>

            </div>

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

<!-- LOCATION JAVASCRIPT -->
<script>

function getLocation() {

    const button = document.getElementById('locationButton');
    const message = document.getElementById('locationMessage');

    if (!navigator.geolocation) {

        alert("Geolocation is not supported by this browser.");
        return;

    }

    button.disabled = true;
    button.textContent = "Getting Location...";

    navigator.geolocation.getCurrentPosition(

        function(position) {

            document.getElementById('latitude').value =
                position.coords.latitude;

            document.getElementById('longitude').value =
                position.coords.longitude;

            message.style.display = 'block';

            message.textContent =
                "Your location has been captured successfully.";

            button.disabled = false;
            button.innerHTML = "<span>✓</span> Location Captured";

        },

        function(error) {

            let errorMessage =
                "Unable to get your location. Please allow location access and try again.";

            if (error.code === error.PERMISSION_DENIED) {

                errorMessage =
                    "Location permission was denied. Please allow location access in your browser settings.";

            } else if (error.code === error.POSITION_UNAVAILABLE) {

                errorMessage =
                    "Your current location is unavailable. Please try again.";

            } else if (error.code === error.TIMEOUT) {

                errorMessage =
                    "The location request timed out. Please try again.";

            }

            alert(errorMessage);

            button.disabled = false;
            button.innerHTML = "<span>⌖</span> Get My Location";

        },

        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }

    );

}

</script>

</body>
</html>
