<?php
// AdsTracker Pro - Root Entrypoint
// Doğrudan ana alan adına girildiğinde (örn: https://ads.mg.web.tr/)
// kullanıcı giriş yapmışsa panele, yapmamışsa doğrudan giriş ekranına yönlendirir.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['adstracker_logged_in']) && $_SESSION['adstracker_logged_in'] === true) {
    header('Location: admin/index.php');
} else {
    header('Location: admin/login.php');
}
exit;
