
<?php
require_once 'config/db.php';

// Redirect logged-in students
if (isset($_SESSION['student_id'])) {
    header("Location: student/dashboard.php");
    exit;
}

// Redirect logged-in staff
if (isset($_SESSION['user_id'])) {

    if ($_SESSION['user_role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }

    if ($_SESSION['user_role'] === 'lecturer') {
        header("Location: lecturer/dashboard.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Emmanuel Alayande University of Education, Oyo</title><link rel="icon" href="assets/eaued logo.png" type="image/png" sizes="16x16">
    <link rel="stylesheet" href="assets/css/home.css">
</head>

<body>

<div class="portal-home">

    <!-- UNIVERSITY HEADER -->
    <header class="university-header">

        <div class="university-brand">

            <!-- University Logo -->
            <img
                src="assets/eaued logo.png"
                alt="Emmanuel Alayande University Logo"
                class="university-logo"
            >

            <!-- University Information -->
            <div class="university-details">

                <h2>Emmanuel Alayande University of Education, Oyo</h2>

                <p>Oyo, Oyo State, Nigeria</p>

                <span>Knowledge, Truth and Service</span>

            </div>

        </div>

    </header>


    <!-- MAIN CONTENT -->
    <main>

        <!-- WELCOME SECTION -->
        <section class="hero-section">

            <div class="hero-content">

                <span class="hero-label">
                    STUDENT ATTENDANCE MANAGEMENT SYSTEM
                </span>

                <h1>
                    Welcome
                </h1>

                <p class="hero-description">
                    A reliable and convenient digital attendance management
                    platform designed to support effective attendance
                    monitoring at Emmanuel Alayande University of Education,
                    Oyo.
                </p>

                <p class="hero-instruction">
                    Select your portal to continue.
                </p>

            </div>

        </section>


        <!-- PORTAL SELECTION -->
        <section class="portal-section">

            <div class="section-heading">

                <span>PORTAL ACCESS</span>

                <h2>Choose Your Portal</h2>

                <p>
                    Select the portal that applies to your role in the
                    university.
                </p>

            </div>


            <div class="portal-grid">

                <!-- STUDENT PORTAL -->
                <a href="student/login.php" class="portal-card">

                    <div class="portal-card-top">

                        <div class="portal-icon student-icon">S</div>

                        <span class="portal-number"></span>

                    </div>

                    <h3>Student Portal</h3>

                    <p>
                        Access your student account, scan attendance QR codes
                        and view your attendance records.
                    </p>

                    <div class="portal-card-bottom">
                        <span>Student Login</span>
                        <span class="arrow">→</span>
                    </div>

                </a>


                <!-- LECTURER PORTAL -->
                <a href="lecturer/login.php" class="portal-card">

                    <div class="portal-card-top">

                        <div class="portal-icon lecturer-icon">L</div>

                        <span class="portal-number"></span>

                    </div>

                    <h3>Lecturer Portal</h3>

                    <p>
                        Manage assigned courses, create attendance sessions
                        and monitor student attendance.
                    </p>

                    <div class="portal-card-bottom">
                        <span>Lecturer Login</span>
                        <span class="arrow">→</span>
                    </div>

                </a>


                <!-- ADMINISTRATOR PORTAL -->
                <a href="admin/login.php" class="portal-card">

                    <div class="portal-card-top">

                        <div class="portal-icon admin-icon">A</div>

                        <span class="portal-number"></span>

                    </div>

                    <h3>Administrator Portal</h3>

                    <p>
                        Manage users, oversee the attendance platform
                        and monitor system activities.
                    </p>

                    <div class="portal-card-bottom">
                        <span>Administrator Login</span>
                        <span class="arrow">→</span>
                    </div>

                </a>

            </div>

        </section>

    </main>


    <!-- FOOTER -->
    <footer class="portal-footer">

        <p class="footer-university">
            EMMANUEL ALAYANDE UNIVERSITY OF EDUCATION, OYO
        </p>

        <p class="footer-motto">
            Knowledge, Truth and Service
        </p>

        <div class="footer-divider"></div>

        <p class="footer-system">
            Student Attendance Management System
        </p>

        <p class="copyright">
            &copy; <?php echo date('Y'); ?>
            Emmanuel Alayande University of Education, Oyo.
            All rights reserved.
        </p>

    </footer>

</div>

</body>
</html>
