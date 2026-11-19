
<?php

require_once __DIR__ . '/db.php';

// Check if user is logged in
function require_user() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit;
    }
}

// Check user role
function require_role($role) {
    require_user();

    if ($_SESSION['user_role'] !== $role) {
        http_response_code(403);
        exit("Access denied. You do not have permission to view this page.");
    }
}

// Redirect logged-in users to their dashboard
function redirect_user_dashboard() {

    if (!isset($_SESSION['user_id'])) {
        return;
    }

    if ($_SESSION['user_role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit;
    }

    if ($_SESSION['user_role'] === 'lecturer') {
        header("Location: ../lecturer/dashboard.php");
        exit;
    }

    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Login verification
function authenticate_user($pdo, $email, $password, $requiredRole) {

    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, password, role
         FROM users
         WHERE email = ? AND role = ?
         LIMIT 1"
    );

    $stmt->execute([$email, $requiredRole]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    return true;
}

// Secure logout
function logout_user() {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
?>
