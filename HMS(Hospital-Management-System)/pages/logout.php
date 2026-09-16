<?php
/**
 * php/logout.php — Destroy the session and redirect to the login page.
 */

require_once '../includes/auth.php';
require_once '../includes/audit.php';

// Audit before session is destroyed (session data still available)
write_audit_log('LOGOUT');

// Overwrite session data, then destroy
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: ' . BASE_URL . '/index.php');
exit;
