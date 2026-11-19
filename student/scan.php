
<?php

require_once __DIR__ . '/../config/db.php';

// Keep the QR token available throughout the process.
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// A valid QR token contains 64 hexadecimal characters.
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(400);
    exit('Invalid QR code. Please scan the lecturer\'s QR code again.');
}

// Redirect students who are not logged in.
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php?token=' . urlencode($token));
    exit;
}

$studentId = (int) $_SESSION['student_id'];

// ============================================
// CHECK WHETHER THE STUDENT ACCOUNT IS ACTIVE
// ============================================

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

    header('Location: login.php');
    exit;
}

$studentName = $_SESSION['student_name'] ?? 'Student';

$error = '';
$success = '';
$session = null;

// ============================================
// AUTOMATICALLY CLOSE EXPIRED QR SESSION
// ============================================

$expireStmt = $pdo->prepare("
    UPDATE attendance_sessions
    SET status = 'closed'
    WHERE qr_token = ?
      AND status = 'active'
      AND end_time <= NOW()
");

$expireStmt->execute([$token]);

// ============================================
// FIND THE ACTIVE ATTENDANCE SESSION
// ============================================

$stmt = $pdo->prepare("
    SELECT *
    FROM attendance_sessions
    WHERE qr_token = ?
      AND status = 'active'
      AND start_time <= NOW()
      AND end_time > NOW()
    LIMIT 1
");

$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {

    $error = 'This attendance session is no longer active or the QR code is invalid.';

}

// ============================================
// VERIFY STUDENT COURSE ENROLMENT
// ============================================

if ($session) {

    $enrollmentCheck = $pdo->prepare("
        SELECT id
        FROM enrollments
        WHERE student_id = ?
          AND course_id = ?
        LIMIT 1
    ");

    $enrollmentCheck->execute([
        $studentId,
        $session['course_id']
    ]);

    if (!$enrollmentCheck->fetch()) {

        $error = 'You are not enrolled in this course. You cannot mark attendance.';

        $session = null;
    }
}

// ============================================
// CALCULATE DISTANCE BETWEEN GPS COORDINATES
// ============================================

function calculateDistance($lat1, $lon1, $lat2, $lon2) {

    $earthRadius = 6371000;

    $latDifference = deg2rad($lat2 - $lat1);
    $lonDifference = deg2rad($lon2 - $lon1);

    $a = sin($latDifference / 2) * sin($latDifference / 2)
       + cos(deg2rad($lat1))
       * cos(deg2rad($lat2))
       * sin($lonDifference / 2)
       * sin($lonDifference / 2);

    $a = min(1, max(0, $a));

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

// ============================================
// PROCESS ATTENDANCE SUBMISSION
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $session) {

    verify_csrf();

    // ========================================
    // AUTOMATICALLY CLOSE SESSION IF EXPIRED
    // ========================================

    $expirePostStmt = $pdo->prepare("
        UPDATE attendance_sessions
        SET status = 'closed'
        WHERE id = ?
          AND status = 'active'
          AND end_time <= NOW()
    ");

    $expirePostStmt->execute([$session['id']]);

    // ========================================
    // RECHECK SESSION BEFORE ACCEPTING ATTENDANCE
    // ========================================

    $sessionCheck = $pdo->prepare("
        SELECT id
        FROM attendance_sessions
        WHERE id = ?
          AND qr_token = ?
          AND status = 'active'
          AND start_time <= NOW()
          AND end_time > NOW()
        LIMIT 1
    ");

    $sessionCheck->execute([
        $session['id'],
        $token
    ]);

    if (!$sessionCheck->fetch()) {

        $error = 'This attendance session has expired or has been closed by the lecturer.';

        $session = null;
    }

    // Continue only if the session is still active.
    if ($session) {

        // ========================================
        // GET AND VALIDATE STUDENT LOCATION
        // ========================================

        $latitude = filter_var(
            $_POST['latitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        $longitude = filter_var(
            $_POST['longitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        $accuracy = filter_var(
            $_POST['accuracy'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if (
            $latitude === false ||
            $longitude === false ||
            $accuracy === false
        ) {

            $error = 'Please capture your current location before submitting.';

        } elseif (
            $latitude < -90 ||
            $latitude > 90 ||
            $longitude < -180 ||
            $longitude > 180 ||
            $accuracy < 0
        ) {

            $error = 'The location information is invalid. Please try again.';

        } elseif (
            !isset($_FILES['selfie']) ||
            $_FILES['selfie']['error'] !== UPLOAD_ERR_OK
        ) {

            $error = 'Please take your selfie before submitting attendance.';

        } elseif (
            $_FILES['selfie']['size'] <= 0 ||
            $_FILES['selfie']['size'] > 5 * 1024 * 1024
        ) {

            $error = 'Your selfie must be a valid image not larger than 5MB.';

        } else {

            // ====================================
            // CHECK LECTURER'S ALLOWED GPS RADIUS
            // ====================================

            $distance = calculateDistance(
                (float) $session['latitude'],
                (float) $session['longitude'],
                (float) $latitude,
                (float) $longitude
            );

            if ($distance > (float) $session['allowed_radius']) {

                $error = 'You are outside the allowed attendance location. '
                       . 'Please move closer to the lecture venue.';

            } else {

                // ====================================
                // CHECK FOR EXISTING ATTENDANCE
                // ====================================

                $check = $pdo->prepare("
                    SELECT id
                    FROM attendance_records
                    WHERE session_id = ?
                      AND student_id = ?
                    LIMIT 1
                ");

                $check->execute([
                    $session['id'],
                    $studentId
                ]);

                if ($check->fetch()) {

                    $error = 'You have already marked attendance for this session.';

                } else {

                    // ====================================
                    // VALIDATE THE SELFIE IMAGE
                    // ====================================

                    $tmpFile = $_FILES['selfie']['tmp_name'];

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($tmpFile);

                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];

                    // Verify that the uploaded file is a real image.
                    $imageInfo = @getimagesize($tmpFile);

                    if (
                        !isset($allowedTypes[$mimeType]) ||
                        $imageInfo === false
                    ) {

                        $error = 'Invalid selfie image. Please take your picture again.';

                    } else {

                        // ====================================
                        // SAVE THE SELFIE
                        // ====================================

                        $uploadDirectory = __DIR__ . '/uploads/selfies/';

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true) &&
                            !is_dir($uploadDirectory)
                        ) {

                            $error = 'Unable to create the selfie upload folder.';

                        } else {

                            $fileName = bin2hex(random_bytes(16))
                                      . '.' . $allowedTypes[$mimeType];

                            $destination = $uploadDirectory . $fileName;

                            if (!move_uploaded_file($tmpFile, $destination)) {

                                $error = 'Unable to save your selfie. Please try again.';

                            } else {

                                $selfiePath = 'uploads/selfies/' . $fileName;

                                // ====================================
                                // SAVE ATTENDANCE RECORD
                                // ====================================

                                try {

                                    // Final database check prevents attendance
                                    // from being inserted after expiry or closure.

                                    $insert = $pdo->prepare("
                                        INSERT INTO attendance_records (
                                            session_id,
                                            student_id,
                                            selfie_path,
                                            student_latitude,
                                            student_longitude,
                                            location_accuracy,
                                            attendance_method,
                                            attendance_status,
                                            attendance_time
                                        )
                                        SELECT
                                            id,
                                            ?,
                                            ?,
                                            ?,
                                            ?,
                                            ?,
                                            'qr',
                                            'present',
                                            NOW()
                                        FROM attendance_sessions
                                        WHERE id = ?
                                          AND qr_token = ?
                                          AND status = 'active'
                                          AND start_time <= NOW()
                                          AND end_time > NOW()
                                    ");

                                    $insert->execute([
                                        $studentId,
                                        $selfiePath,
                                        $latitude,
                                        $longitude,
                                        $accuracy,
                                        $session['id'],
                                        $token
                                    ]);

                                    if ($insert->rowCount() === 1) {

                                        $success = 'Attendance marked successfully!';

                                    } else {

                                        // Keep the ID before clearing the session.
                                        $expiredSessionId = (int) $session['id'];

                                        // Delete the selfie because attendance
                                        // was not successfully recorded.
                                        if (is_file($destination)) {
                                            unlink($destination);
                                        }

                                        // Close the session if its deadline passed.
                                        $expireFinalStmt = $pdo->prepare("
                                            UPDATE attendance_sessions
                                            SET status = 'closed'
                                            WHERE id = ?
                                              AND status = 'active'
                                              AND end_time <= NOW()
                                        ");

                                        $expireFinalStmt->execute([
                                            $expiredSessionId
                                        ]);

                                        $error = 'This attendance session has expired or has been closed by the lecturer.';

                                        $session = null;
                                    }

                                } catch (PDOException $e) {

                                    // Delete the selfie if attendance saving fails.
                                    if (is_file($destination)) {
                                        unlink($destination);
                                    }

                                    // MySQL error 1062 means duplicate entry.
                                    if (
                                        isset($e->errorInfo[1]) &&
                                        (int) $e->errorInfo[1] === 1062
                                    ) {

                                        $error = 'You have already marked attendance for this session.';

                                    } else {

                                        $error = 'Unable to save your attendance. Please try again.';

                                    }
                                }
                            }
                        }
                    }
                }
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

    <title>Mark Attendance - SmartAttend</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, sans-serif;
            background: #f4f7f5;
            color: #222;
        }

        .container {
            max-width: 520px;
            margin: 30px auto;
            padding: 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        h2 {
            text-align: center;
            color: #145c39;
        }

        .session-info {
            background: #e7f3eb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.7;
            border-left: 4px solid #197447;
        }

        label {
            display: block;
            margin: 18px 0 10px;
            font-weight: bold;
            color: #145c39;
        }

        button {
            width: 100%;
            padding: 13px;
            margin-top: 12px;
            border: none;
            border-radius: 7px;
            background: #145c39;
            color: white;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #103c29;
        }

        button:disabled {
            background: #999;
            cursor: not-allowed;
        }

        .secondary {
            background: #555;
        }

        .secondary:hover {
            background: #333;
        }

        video, #selfiePreview {
            display: none;
            width: 100%;
            margin-top: 12px;
            border-radius: 10px;
            background: #111;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .error {
            background: #ffe5e5;
            color: #a00000;
        }

        .success {
            background: #e2f8e8;
            color: #12632a;
        }

        .status {
            margin-top: 12px;
            font-size: 14px;
            color: #555;
            line-height: 1.5;
        }

        .back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #197447;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

    </style>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
</head>

<body>

<div class="container">

    <h2>SmartAttend</h2>

    <p>Welcome, <?= e($studentName) ?>.</p>

    <?php if ($error): ?>

        <div class="message error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($success): ?>

        <div class="message success">
            <?= e($success) ?>
        </div>

        <p>Your attendance has been recorded successfully.</p>

        <a class="back-link" href="dashboard.php">
            Return to Student Dashboard
        </a>

    <?php elseif ($session): ?>

        <div class="session-info">

            <strong>Attendance Session:</strong>
            <?= e($session['session_title']) ?>

            <br>

            <strong>Allowed Radius:</strong>
            <?= e($session['allowed_radius']) ?> metres

            <br>

            <strong>Session Ends:</strong>
            <?= e($session['end_time']) ?>

        </div>

        <form
            method="POST"
            enctype="multipart/form-data"
            id="attendanceForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="token"
                value="<?= e($token) ?>"
            >

            <input
                type="hidden"
                name="latitude"
                id="latitude"
            >

            <input
                type="hidden"
                name="longitude"
                id="longitude"
            >

            <input
                type="hidden"
                name="accuracy"
                id="accuracy"
            >

            <label>Step 1: Capture Your Location</label>

            <button type="button" id="locationButton">
                Get My Current Location
            </button>

            <div class="status" id="locationStatus">
                Your location has not been captured.
            </div>

            <label>Step 2: Take Your Selfie</label>

            <video
                id="cameraPreview"
                autoplay
                playsinline
                muted
            ></video>

            <canvas id="selfieCanvas" hidden></canvas>

            <img
                id="selfiePreview"
                alt="Captured selfie preview"
            >

            <button type="button" id="startCamera">
                Open Front Camera
            </button>

            <button
                type="button"
                id="captureSelfie"
                style="display:none;"
            >
                Take My Selfie
            </button>

            <button
                type="button"
                id="retakeSelfie"
                class="secondary"
                style="display:none;"
            >
                Retake Selfie
            </button>

            <input
                type="file"
                name="selfie"
                id="selfie"
                accept="image/jpeg,image/png,image/webp"
                hidden
                required
            >

            <div class="status" id="cameraStatus">
                Open your front camera to take a selfie.
            </div>

            <button type="submit" id="submitButton">
                Mark My Attendance
            </button>

        </form>

    <?php endif; ?>

</div>

<script>

const locationButton = document.getElementById('locationButton');
const locationStatus = document.getElementById('locationStatus');

const startCameraButton = document.getElementById('startCamera');
const captureButton = document.getElementById('captureSelfie');
const retakeButton = document.getElementById('retakeSelfie');

const cameraPreview = document.getElementById('cameraPreview');
const selfieCanvas = document.getElementById('selfieCanvas');
const selfiePreview = document.getElementById('selfiePreview');
const selfieInput = document.getElementById('selfie');

const cameraStatus = document.getElementById('cameraStatus');
const attendanceForm = document.getElementById('attendanceForm');

let cameraStream = null;

if (attendanceForm) {

    // ====================================
    // GET THE STUDENT'S LOCATION
    // ====================================

    locationButton.addEventListener('click', function () {

        if (!navigator.geolocation) {

            locationStatus.textContent =
                'Your browser does not support location services.';

            return;
        }

        locationStatus.textContent = 'Getting your location...';

        locationButton.disabled = true;

        navigator.geolocation.getCurrentPosition(

            function (position) {

                document.getElementById('latitude').value =
                    position.coords.latitude;

                document.getElementById('longitude').value =
                    position.coords.longitude;

                document.getElementById('accuracy').value =
                    position.coords.accuracy;

                locationStatus.textContent =
                    'Location captured successfully. Accuracy: ' +
                    Math.round(position.coords.accuracy) + ' metres.';

                locationButton.disabled = false;

            },

            function (error) {

                let message = 'Unable to get your location.';

                if (error.code === error.PERMISSION_DENIED) {

                    message = 'Location permission was denied. Please allow location access in your browser.';

                } else if (error.code === error.POSITION_UNAVAILABLE) {

                    message = 'Your location is currently unavailable.';

                } else if (error.code === error.TIMEOUT) {

                    message = 'Location request timed out. Please try again.';

                }

                locationStatus.textContent = message;

                locationButton.disabled = false;

            },

            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }

        );

    });


    // ====================================
    // OPEN THE FRONT CAMERA
    // ====================================

    startCameraButton.addEventListener('click', async function () {

        try {

            if (
                !navigator.mediaDevices ||
                !navigator.mediaDevices.getUserMedia
            ) {

                cameraStatus.textContent =
                    'Camera access is unavailable. Open SmartAttend using its HTTPS address or localhost.';

                return;
            }

            cameraStatus.textContent = 'Opening your front camera...';

            if (cameraStream) {

                cameraStream.getTracks().forEach(track => track.stop());

            }

            try {

                cameraStream = await navigator.mediaDevices.getUserMedia({

                    video: {
                        facingMode: 'user'
                    },

                    audio: false

                });

            } catch (cameraError) {

                cameraStream = await navigator.mediaDevices.getUserMedia({

                    video: true,
                    audio: false

                });

            }

            cameraPreview.srcObject = cameraStream;

            cameraPreview.style.display = 'block';
            selfiePreview.style.display = 'none';

            await cameraPreview.play();

            startCameraButton.style.display = 'none';
            captureButton.style.display = 'block';
            retakeButton.style.display = 'none';

            cameraStatus.textContent =
                'Camera is ready. Position your face and take your selfie.';

        } catch (error) {

            if (
                error.name === 'NotAllowedError' ||
                error.name === 'PermissionDeniedError'
            ) {

                cameraStatus.textContent =
                    'Camera permission was denied. Allow camera access for this website in your device settings.';

            } else if (error.name === 'NotFoundError') {

                cameraStatus.textContent =
                    'No camera was found on this device.';

            } else if (error.name === 'NotReadableError') {

                cameraStatus.textContent =
                    'The camera may already be in use by another application.';

            } else {

                cameraStatus.textContent =
                    'Unable to open the camera. Please try again. ' + error.message;

            }

        }

    });


    // ====================================
    // CAPTURE THE SELFIE
    // ====================================

    captureButton.addEventListener('click', function () {

        if (!cameraStream || !cameraPreview.videoWidth) {

            cameraStatus.textContent =
                'Please open the camera before taking your selfie.';

            return;
        }

        const width = cameraPreview.videoWidth;
        const height = cameraPreview.videoHeight;

        selfieCanvas.width = width;
        selfieCanvas.height = height;

        const context = selfieCanvas.getContext('2d');

        context.translate(width, 0);
        context.scale(-1, 1);

        context.drawImage(
            cameraPreview,
            0,
            0,
            width,
            height
        );

        selfieCanvas.toBlob(function (blob) {

            if (!blob) {

                cameraStatus.textContent =
                    'Unable to capture your selfie. Please try again.';

                return;
            }

            const selfieFile = new File(
                [blob],
                'student-selfie.jpg',
                {
                    type: 'image/jpeg'
                }
            );

            const dataTransfer = new DataTransfer();

            dataTransfer.items.add(selfieFile);

            selfieInput.files = dataTransfer.files;

            selfiePreview.src = URL.createObjectURL(blob);

            selfiePreview.style.display = 'block';
            cameraPreview.style.display = 'none';

            cameraStream.getTracks().forEach(track => track.stop());

            cameraStream = null;

            captureButton.style.display = 'none';
            retakeButton.style.display = 'block';

            cameraStatus.textContent =
                'Selfie captured successfully. You can retake it if necessary.';

        }, 'image/jpeg', 0.85);

    });


    // ====================================
    // RETAKE THE SELFIE
    // ====================================

    retakeButton.addEventListener('click', function () {

        selfieInput.value = '';

        selfiePreview.src = '';
        selfiePreview.style.display = 'none';

        retakeButton.style.display = 'none';
        startCameraButton.style.display = 'block';

        cameraStatus.textContent =
            'You can open the front camera and take another selfie.';

    });


    // ====================================
    // CHECK REQUIRED INFORMATION
    // BEFORE SUBMITTING ATTENDANCE
    // ====================================

    attendanceForm.addEventListener('submit', function (event) {

        const latitude = document.getElementById('latitude').value;
        const longitude = document.getElementById('longitude').value;

        const selfie = selfieInput.files.length;

        if (!latitude || !longitude) {

            event.preventDefault();

            alert('Please capture your current location first.');

            return;
        }

        if (!selfie) {

            event.preventDefault();

            alert('Please take your selfie before marking attendance.');

            return;
        }

    });

}

</script>

</body>
</html>
