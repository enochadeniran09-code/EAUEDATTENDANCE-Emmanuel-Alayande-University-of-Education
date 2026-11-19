
<?php

require_once __DIR__ . '/../config/auth.php';

require_role('lecturer');

$lecturerId = (int) $_SESSION['user_id'];

// Get and validate session ID
$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

if (!$sessionId || $sessionId < 1) {
    http_response_code(400);
    exit('Invalid attendance session.');
}

// Confirm that this session belongs to the logged-in lecturer
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.session_title,
        s.start_time,
        c.course_code,
        c.course_title
    FROM attendance_sessions s
    INNER JOIN courses c
        ON c.id = s.course_id
    WHERE s.id = ?
      AND s.lecturer_id = ?
    LIMIT 1
");

$stmt->execute([$sessionId, $lecturerId]);

$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    http_response_code(404);
    exit('Attendance session not found or you do not have permission to access it.');
}

// Get only the students recorded for this particular session
$stmt = $pdo->prepare("
    SELECT
        st.first_name,
        st.middle_name,
        st.last_name,
        st.matric_number,
        ar.attendance_time,
        ar.attendance_status
    FROM attendance_records ar
    INNER JOIN students st
        ON st.id = ar.student_id
    WHERE ar.session_id = ?
    ORDER BY ar.attendance_time ASC
");

$stmt->execute([$sessionId]);

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare a safe filename
$courseCode = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $session['course_code']
);

$filename = 'SmartAttend_' . $courseCode
    . '_Session_' . $sessionId . '_Report.csv';

// Send CSV download headers
header('Content-Type: text/csv; charset=UTF-8');

header(
    'Content-Disposition: attachment; filename="' . $filename . '"'
);

header('Pragma: no-cache');
header('Expires: 0');

// Open CSV output
$output = fopen('php://output', 'w');

// Protect spreadsheet cells against formula injection
$safeCsvValue = function ($value) {

    $value = (string) ($value ?? '');

    if (preg_match('/^[\t\r ]*[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return $value;
};

// Format date and time safely
$formatDateTime = function ($value) {

    if (empty($value)) {
        return 'N/A';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return 'N/A';
    }

    return date('d M Y, h:i A', $timestamp);
};

// Report information
fputcsv(
    $output,
    ['SMARTATTEND STUDENT ATTENDANCE REPORT'],
    ',',
    '"',
    ''
);

fputcsv(
    $output,
    ['Course Code', $safeCsvValue($session['course_code'])],
    ',',
    '"',
    ''
);

fputcsv(
    $output,
    ['Course Title', $safeCsvValue($session['course_title'])],
    ',',
    '"',
    ''
);

fputcsv(
    $output,
    ['Session Title', $safeCsvValue($session['session_title'])],
    ',',
    '"',
    ''
);

fputcsv(
    $output,
    ['Session Date', $formatDateTime($session['start_time'])],
    ',',
    '"',
    ''
);

fputcsv(
    $output,
    ['Total Students Present', count($students)],
    ',',
    '"',
    ''
);

// Blank row before student records
fputcsv($output, [], ',', '"', '');

// Student report headings
fputcsv(
    $output,
    [
        'S/N',
        'Student Name',
        'Matric Number',
        'Attendance Time',
        'Attendance Status'
    ],
    ',',
    '"',
    ''
);

// Write each student's attendance record
$serialNumber = 1;

foreach ($students as $student) {

    $fullName = trim(
        $student['first_name'] . ' ' .
        ($student['middle_name'] ?? '') . ' ' .
        ($student['last_name'] ?? '')
    );

    fputcsv(
        $output,
        [
            $serialNumber,
            $safeCsvValue($fullName),
            $safeCsvValue($student['matric_number']),
            $formatDateTime($student['attendance_time']),
            $safeCsvValue(ucfirst($student['attendance_status']))
        ],
        ',',
        '"',
        ''
    );

    $serialNumber++;
}

fclose($output);

exit;
