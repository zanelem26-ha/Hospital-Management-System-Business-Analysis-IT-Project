<?php
/**
 * php/session_info.php — Session snapshot endpoint
 *
 * Returns the current authenticated session as JSON so the client-side
 * sync_manager.js can cache it in IndexedDB for offline use.
 *
 * GET only. Requires an active session.
 * if the user is not logged in, returns {"authenticated": false}.
 * if the user is logged in, returns {"authenticated": true,...}
 * Never returns the password or any secret credential.
 */

require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Require an active session (user must be logged in)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user_id'       => current_user_id(),
    'username'      => current_username(),
    'full_name'     => current_full_name(),
    'role'          => current_role(),
    'sync_token'    => get_sync_token(),
    'cached_at'     => time(),
    'expires_at'    => time() + SESSION_LIFETIME,
]);
