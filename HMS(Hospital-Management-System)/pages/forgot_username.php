<?php
/**
 * pages/forgot_username.php — Public (unauthenticated) username-lookup flow.
 *
 * POST action=lookup — reveal the username for an account identified by a
 * role-specific credential:
 *   Patient              -> SA ID number
 *   Doctor                -> HPCSA practice number
 *   Nurse                 -> SANC practice number
 *   Admin / SuperAdmin    -> email address
 *
 * Pharmacist is not supported — the schema has no distinct identifier for
 * that role (no practice/license number, no pharmacists table), and there's
 * nothing else to look it up by without asking for the username itself.
 *
 * Never calls require_login() — this exists specifically for logged-out users.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

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

$db      = get_db();
$role    = trim($_POST['role'] ?? '');
$allowed = array_diff(ROLES, ['Pharmacist']);

if (!in_array($role, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Please select a valid role.']);
    exit;
}

$value = trim($_POST['value'] ?? '');
if ($value === '') {
    echo json_encode(['ok' => false, 'error' => 'This field is required.']);
    exit;
}

switch ($role) {
    case 'Patient':
        // Lightweight shape check only — matching an existing record, not
        // validating new input (see forgot_password.php for the same call).
        if (!preg_match('/^\d{13}$/', $value)) {
            echo json_encode(['ok' => false, 'error' => 'ID number must be exactly 13 digits.']);
            exit;
        }
        $s = $db->prepare(
            'SELECT u.username
             FROM patients p JOIN users u ON u.user_id = p.user_id
             WHERE p.id_number = ? AND u.role = ? AND u.is_active = 1
             LIMIT 1'
        );
        $s->execute([$value, $role]);
        break;

    case 'Doctor':
        if (!preg_match('/^[a-zA-Z0-9]{1,20}$/', $value)) {
            echo json_encode(['ok' => false, 'error' => 'Practice Number must be letters and numbers only, up to 20 characters.']);
            exit;
        }
        $s = $db->prepare(
            'SELECT u.username
             FROM doctors d JOIN users u ON u.user_id = d.user_id
             WHERE d.practice_number = ? AND u.role = ? AND u.is_active = 1
             LIMIT 1'
        );
        $s->execute([$value, $role]);
        break;

    case 'Nurse':
        if (!preg_match('/^[a-zA-Z0-9]{1,20}$/', $value)) {
            echo json_encode(['ok' => false, 'error' => 'SANC Number must be letters and numbers only, up to 20 characters.']);
            exit;
        }
        $s = $db->prepare(
            'SELECT u.username
             FROM nurses n JOIN users u ON u.user_id = n.user_id
             WHERE n.practice_number = ? AND u.role = ? AND u.is_active = 1
             LIMIT 1'
        );
        $s->execute([$value, $role]);
        break;

    case 'Admin':
    case 'SuperAdmin':
        if (mb_strlen($value) > 60) {
            echo json_encode(['ok' => false, 'error' => 'Email Address must be at most 60 characters.']);
            exit;
        }
        $s = $db->prepare(
            'SELECT username FROM users WHERE email = ? AND role = ? AND is_active = 1 LIMIT 1'
        );
        $s->execute([$value, $role]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Please select a valid role.']);
        exit;
}

$row = $s->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No account found for those details. Please try again.']);
    exit;
}

echo json_encode(['ok' => true, 'username' => $row['username']]);
