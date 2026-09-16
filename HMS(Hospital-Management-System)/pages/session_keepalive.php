<?php
/**
 * pages/session_keepalive.php — Session heartbeat endpoint.
 *
 * POST only. Requires an active session.
 * Auth: requires active session (require_login) + per-session X-Sync-Token header.
 * CSRF: not used here — X-Sync-Token + SameSite cookie + JSON Content-Type provide equivalent protection.
 */

require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_login(); // refreshes $_SESSION['last_active']

// Validate sync token
$headers = function_exists('getallheaders') ? getallheaders() : [];
$received_token = $headers['X-Sync-Token']
    ?? $headers['x-sync-token']
    ?? ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');

if (!hash_equals(get_sync_token(), $received_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid sync token']);
    exit;
}

echo json_encode(['ok' => true, 'expires_at' => time() + SESSION_LIFETIME]);
