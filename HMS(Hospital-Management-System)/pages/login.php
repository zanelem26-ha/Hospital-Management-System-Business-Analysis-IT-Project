<?php
/**
 * php/login.php — Login POST handler
 *
 * Validates credentials, initialises the session, then redirects.
 * Accepts only POST; GETs are redirected to index.php.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

// Reject non-POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/index.php');
}

// CSRF check
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Invalid or expired request. Please try again.');
    redirect('/index.php');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password-login'] ?? '';

// Basic presence validation (second layer; JS handles first)
if ($username === '' || $password === '') {
    flash_set('error', 'Username/email and password are required.');
    redirect('/index.php');
}

$db = get_db();

// Accept login by username OR email address
$stmt = $db->prepare(
    'SELECT user_id, username, password_hash, role, full_name, is_active
     FROM users
     WHERE username = ? OR (email = ? AND email IS NOT NULL)
     LIMIT 1'
);
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

// Constant-time password verification
if (!$user || !password_verify($password, $user['password_hash'])) {
    // Identical error message for both "user not found" and "wrong password"
    // to prevent username enumeration
    flash_set('error', 'Invalid username or password.');
    redirect('/index.php');
}

if (!(bool)$user['is_active']) {
    flash_set('error', 'Your account has been deactivated. Please contact an administrator.');
    redirect('/index.php');
}

// Successful authentication
// Regenerate session ID to prevent session fixation attacks
session_regenerate_id(true);

$_SESSION['user_id']   = (int)$user['user_id'];
$_SESSION['username']  = $user['username'];
$_SESSION['role']      = $user['role'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['login_at']  = time();

// Store role-specific profile IDs for convenience
switch ($user['role']) {
    case 'Patient':
        $s = $db->prepare('SELECT patient_id FROM patients WHERE user_id = ? LIMIT 1');
        $s->execute([(int)$user['user_id']]);
        $row = $s->fetch();
        $_SESSION['patient_id'] = $row['patient_id'] ?? null;
        break;

    case 'Doctor':
        $s = $db->prepare('SELECT doctor_id FROM doctors WHERE user_id = ? LIMIT 1');
        $s->execute([(int)$user['user_id']]);
        $row = $s->fetch();
        $_SESSION['doctor_id'] = $row['doctor_id'] ?? null;
        break;

    case 'Nurse':
        $s = $db->prepare('SELECT nurse_id FROM nurses WHERE user_id = ? LIMIT 1');
        $s->execute([(int)$user['user_id']]);
        $row = $s->fetch();
        $_SESSION['nurse_id'] = $row['nurse_id'] ?? null;
        break;
}

// Rotate CSRF token after login
unset($_SESSION['csrf_token']);

// Audit: successful login
write_audit_log('LOGIN');

flash_set('success', 'Welcome back, ' . $user['full_name'] . '!');
redirect('/pages/dashboard.php');
