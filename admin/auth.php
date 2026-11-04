<?php
// Multi-Site AdsTracker Admin Authentication

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/inc/db.php';

function isLoggedIn() {
    return !empty($_SESSION['adstracker_logged_in']) && $_SESSION['adstracker_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Oturum süreniz doldu, lütfen tekrar giriş yapın.']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

function attemptLogin($username, $password) {
    $db = getTrackerDB();
    if (!$db) return false;

    $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['adstracker_logged_in'] = true;
        $_SESSION['adstracker_user_id'] = $user['id'];
        $_SESSION['adstracker_username'] = $user['username'];
        return true;
    }
    return false;
}

function logoutUser() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
