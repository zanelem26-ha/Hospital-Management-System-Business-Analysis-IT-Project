<?php
/**
 * pages/forgot_password.php — Public (unauthenticated) password-reset flow.
 *
 * POST action=verify         : confirm identity — Patient by ID number,
 *                               staff roles by username or email (same
 *                               identifier accepted at login) — and stash
 *                               the matched user_id in the session.
 * POST action=reset_password : set a new password hash for the account
 *                               verified in the step above.
 *
 * Never calls require_login() — this exists specifically for logged-out users.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired request. Please refresh and try again.']);
    exit;
}

$db     = get_db();
$action = $_POST['action'] ?? '';

// Step 1: verify identity, stash the matched user_id for the next step
if ($action === 'verify') {
    $role = trim($_POST['role'] ?? '');

    if (!in_array($role, ROLES, true)) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid role.']);
        exit;
    }

    if ($role === 'Patient') {
        $id_number = trim($_POST['id_number'] ?? '');
        if ($id_number === '') {
            echo json_encode(['ok' => false, 'error' => 'ID number is required.']);
            exit;
        }
        // A lightweight shape check only — this is matching an *existing*
        // record, not validating new input, so it deliberately skips the
        // full validate_sa_id() Luhn/structure check used at registration.
        // Older seed/legacy records can have an id_number on file that
        // never passed that stricter check, and should still be able to
        // reset their password.
        if (!preg_match('/^\d{13}$/', $id_number)) {
            echo json_encode(['ok' => false, 'error' => 'ID number must be exactly 13 digits.']);
            exit;
        }

        // user_id is nullable on patients (walk-in records have no login
        // account) — the join naturally excludes those from matching here.
        $s = $db->prepare(
            'SELECT u.user_id, p.first_name
             FROM patients p
             JOIN users u ON u.user_id = p.user_id
             WHERE p.id_number = ? AND u.role = ? AND u.is_active = 1
             LIMIT 1'
        );
        $s->execute([$id_number, $role]);
        $row = $s->fetch();

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'User ID not found, try again.']);
            exit;
        }

        $_SESSION['pwd_reset_uid']     = (int)$row['user_id'];
        $_SESSION['pwd_reset_expires'] = time() + SESSION_LIFETIME;
        echo json_encode(['ok' => true, 'first_name' => $row['first_name']]);
        exit;
    }

    // Staff roles (Doctor/Nurse/Admin/SuperAdmin/Pharmacist) have no ID
    // number on file — verify by the same identifier accepted at login:
    // username OR email, in a single field.
    $identifier = trim($_POST['username'] ?? '');
    if ($identifier === '') {
        echo json_encode(['ok' => false, 'error' => 'Username or email is required.']);
        exit;
    }

    $s = $db->prepare(
        'SELECT user_id, full_name
         FROM users
         WHERE (username = ? OR (email = ? AND email IS NOT NULL))
           AND role = ? AND is_active = 1
         LIMIT 1'
    );
    $s->execute([$identifier, $identifier, $role]);
    $row = $s->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Account not found, try again.']);
        exit;
    }

    $_SESSION['pwd_reset_uid']     = (int)$row['user_id'];
    $_SESSION['pwd_reset_expires'] = time() + SESSION_LIFETIME;
    echo json_encode(['ok' => true, 'first_name' => $row['full_name']]);
    exit;
}

// Step 2: set the new password for the account verified above
if ($action === 'reset_password') {
    $uid = $_SESSION['pwd_reset_uid']     ?? null;
    $exp = $_SESSION['pwd_reset_expires'] ?? 0;

    if (!$uid || time() > $exp) {
        unset($_SESSION['pwd_reset_uid'], $_SESSION['pwd_reset_expires']);
        echo json_encode(['ok' => false, 'restart' => true, 'error' => 'Verification expired. Please start again.']);
        exit;
    }

    $new_pass  = $_POST['new_password']     ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    if (strlen($new_pass) < 10
        || !preg_match('/[A-Z]/', $new_pass)
        || !preg_match('/[a-z]/', $new_pass)
        || !preg_match('/[0-9]/', $new_pass)
        || !preg_match('/[^a-zA-Z0-9]/', $new_pass)
    ) {
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 10 characters and include an uppercase letter, a lowercase letter, a number, and a special character.']);
        exit;
    }
    if ($new_pass !== $conf_pass) {
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        exit;
    }

    $s = $db->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $s->execute([$uid]);
    $row = $s->fetch();

    if (!$row) {
        unset($_SESSION['pwd_reset_uid'], $_SESSION['pwd_reset_expires']);
        echo json_encode(['ok' => false, 'restart' => true, 'error' => 'Account no longer exists. Please start again.']);
        exit;
    }

    if (password_verify($new_pass, $row['password_hash'])) {
        echo json_encode(['ok' => false, 'clear' => true, 'error' => 'Password is the same as previous. Please choose a different password.']);
        exit;
    }

    $s = $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $s->execute([password_hash($new_pass, PASSWORD_DEFAULT), $uid]);

    write_audit_log('PASSWORD_RESET', 'users', $uid, null, ['action' => 'password_reset_via_forgot_password']);

    // Single-use — the verification can't be replayed for a second reset.
    unset($_SESSION['pwd_reset_uid'], $_SESSION['pwd_reset_expires']);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
