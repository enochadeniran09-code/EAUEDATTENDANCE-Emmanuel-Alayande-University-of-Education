
<?php
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

// Get all registered lecturers
$stmt = $pdo->query("
    SELECT id, full_name, email, created_at
    FROM users
    WHERE role = 'lecturer'
    ORDER BY id DESC
");

$lecturers = $stmt->fetchAll();

$totalLecturers = count($lecturers);

$flash = get_flash();

$flashType = '';
$flashMessage = '';

if (is_array($flash)) {
    $flashType = $flash['type'] ?? 'success';
    $flashMessage = $flash['message'] ?? '';
} elseif (is_string($flash)) {
    $flashType = 'success';
    $flashMessage = $flash;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Lecturers | SmartAttend</title>
 <link rel="icon"
          type="image/png"
          href="../assets/eaued%20logo.png?v=2">
    <link rel="stylesheet" href="../assets/css/student.css">

    <style>
        .lecturer-summary-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 22px;
            display: flex;
            align-items: center;
            gap: 17px;
            margin-bottom: 25px;
            box-shadow: 0 5px 18px rgba(22,74,46,0.04);
        }

        .lecturer-summary-icon {
            width: 48px;
            height: 48px;
            min-width: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: var(--light-green);
            color: var(--green);
            font-size: 23px;
        }

        .lecturer-summary-card p {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 5px;
        }

        .lecturer-summary-card h2 {
            color: var(--green);
            font-size: 25px;
        }

        .lecturer-add-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 16px;
            background: var(--green);
            color: var(--white);
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            transition: background 0.2s, transform 0.2s;
            white-space: nowrap;
        }

        .lecturer-add-button:hover {
            background: var(--dark-green);
            transform: translateY(-2px);
        }

        .lecturer-table-name {
            color: var(--green);
            font-weight: 700;
        }

        .lecturer-email {
            color: #607267;
        }

        .lecturer-id {
            color: var(--gold);
            font-weight: 800;
        }

        .lecturer-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .lecturer-empty-state {
            text-align: center;
            padding: 45px 15px;
            background: #fbfdfb;
            border: 1px dashed #d5e3d9;
            border-radius: 9px;
        }

        .lecturer-empty-state .empty-icon {
            color: var(--gold);
            font-size: 35px;
            margin-bottom: 12px;
        }

        .lecturer-empty-state h3 {
            color: var(--green);
            font-size: 16px;
            margin-bottom: 8px;
        }

        .lecturer-empty-state p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
            margin-bottom: 17px;
        }

        .lecturer-table-count {
            color: var(--muted);
            font-size: 12px;
            margin-top: 15px;
        }

        /* Mobile menu button */
        .admin-menu-toggle {
            display: none;
            width: 44px;
            height: 44px;
            min-width: 44px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: white;
            font-size: 25px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        /* Sidebar overlay */
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
                z-index: 1001;
                transform: translateX(-105%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            body.admin-sidebar-open .student-sidebar {
                transform: translateX(0);
            }

            .admin-sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 1000;
            }

            body.admin-sidebar-open .admin-sidebar-overlay {
                display: block;
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
                position: relative;
                z-index: 999;
            }

            .student-topbar > div {
                flex: 1;
                min-width: 0;
            }

            .student-topbar h1 {
                font-size: 20px;
            }

            .student-topbar p {
                font-size: 11px;
            }

            .lecturer-summary-card {
                padding: 17px;
            }

            .section-heading {
                align-items: flex-start;
                gap: 12px;
                flex-direction: column;
            }

            .lecturer-add-button {
                width: 100%;
            }

            .lecturer-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .lecturer-table-wrapper table {
                min-width: 600px;
            }

            body.admin-sidebar-open {
                overflow: hidden;
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
            <h2>SmartAttend</h2>
        </div>

        <div class="sidebar-profile">
            <div class="profile-placeholder">
                <?= e($adminInitial) ?>
            </div>

            <h3><?= e($adminName) ?></h3>
            <p>System Administrator</p>
        </div>

        <nav class="sidebar-navigation">

            <a href="dashboard.php">
                <span>⌂</span>
                Dashboard
            </a>

            <a href="create_lecturer.php">
                <span>＋</span>
                Create Lecturer
            </a>

            <a href="lecturers.php" class="active">
                <span>♧</span>
                Manage Lecturers
            </a>

        </nav>

        <div class="sidebar-bottom">
            <form action="../logout.php" method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= e(csrf_token()) ?>">

                <button type="submit" class="sidebar-logout">
                    ↪ Logout
                </button>
            </form>
        </div>

    </aside>

    <!-- MOBILE SIDEBAR OVERLAY -->
    <div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

    <!-- MAIN CONTENT -->
    <main class="student-main">

        <div class="student-topbar">

            <!-- THREE-LINE MENU BUTTON -->
            <button
                type="button"
                class="admin-menu-toggle"
                id="adminMenuToggle"
                aria-label="Open navigation menu"
                aria-expanded="false"
            >
                ☰
            </button>

            <div>
                <h1>Manage Lecturers</h1>
                <p>Administrator Portal / Lecturer Management</p>
            </div>

        </div>

        <!-- WELCOME BANNER -->
        <section class="student-welcome">

            <div>
                <p class="welcome-label">LECTURER MANAGEMENT</p>

                <h2>Registered Lecturers</h2>

                <p>
                    View and manage the lecturer accounts registered
                    on the SmartAttend attendance management system.
                </p>
            </div>

            <div class="welcome-icon">♧</div>

        </section>

        <!-- LECTURER SUMMARY -->
        <div class="lecturer-summary-card">

            <div class="lecturer-summary-icon">♧</div>

            <div>
                <p>Total Registered Lecturers</p>
                <h2><?= number_format($totalLecturers) ?></h2>
            </div>

        </div>

        <!-- LECTURER RECORDS -->
        <section class="student-content-card">

            <div class="section-heading">

                <div>
                    <h2>Lecturer Records</h2>
                    <p>List of all lecturer accounts in SmartAttend.</p>
                </div>

                <a href="create_lecturer.php" class="lecturer-add-button">
                    <span>＋</span>
                    Add Lecturer
                </a>

            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="alert <?= $flashType === 'error' ? 'error' : 'success' ?>">
                    <?= e($flashMessage) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($lecturers)): ?>

                <div class="lecturer-table-wrapper">

                    <table class="student-table">

                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Full Name</th>
                                <th>Email Address</th>
                                <th>Date Created</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($lecturers as $index => $lecturer): ?>

                                <tr>
                                    <td class="lecturer-id">
                                        <?= $index + 1 ?>
                                    </td>

                                    <td class="lecturer-table-name">
                                        <?= e($lecturer['full_name']) ?>
                                    </td>

                                    <td class="lecturer-email">
                                        <?= e($lecturer['email']) ?>
                                    </td>

                                    <td>
                                        <?= date(
                                            'd M Y, h:i A',
                                            strtotime($lecturer['created_at'])
                                        ) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <p class="lecturer-table-count">
                    Showing <?= number_format($totalLecturers) ?>
                    registered lecturer(s).
                </p>

            <?php else: ?>

                <div class="lecturer-empty-state">

                    <div class="empty-icon">♧</div>

                    <h3>No Lecturers Registered Yet</h3>

                    <p>
                        There are currently no lecturer accounts in the
                        system. Create a lecturer account to get started.
                    </p>

                    <a href="create_lecturer.php" class="lecturer-add-button">
                        ＋ Create First Lecturer
                    </a>

                </div>

            <?php endif; ?>

        </section>

        <footer class="student-footer">
            © <?= date('Y') ?> SmartAttend |
            Emmanuel Alayande University of Education, Oyo
            <br>
            <strong>Knowledge, Truth and Service</strong>
        </footer>

    </main>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const menuButton = document.getElementById('adminMenuToggle');
    const overlay = document.getElementById('adminSidebarOverlay');
    const sidebar = document.getElementById('adminSidebar');

    if (!menuButton || !overlay || !sidebar) {
        return;
    }

    function openSidebar() {
        document.body.classList.add('admin-sidebar-open');
        menuButton.setAttribute('aria-expanded', 'true');
        menuButton.setAttribute('aria-label', 'Close navigation menu');
        menuButton.textContent = '✕';
    }

    function closeSidebar() {
        document.body.classList.remove('admin-sidebar-open');
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Open navigation menu');
        menuButton.textContent = '☰';
    }

    menuButton.addEventListener('click', function () {

        if (document.body.classList.contains('admin-sidebar-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }

    });

    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 700) {
            closeSidebar();
        }
    });

});
</script>

</body>
</html>
