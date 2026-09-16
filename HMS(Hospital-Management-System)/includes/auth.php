<?php
/**
 * Authentication & session helpers.
 * Every page that requires a login must call require_login() or require_role()
 */

require_once __DIR__ . '/../config/config.php';

// Starts session with secure cookie settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',      // Cookie available across the entire domain
        'secure'   => false,   // Set TRUE when running over HTTPS in production
        'httponly' => true,    // Prevents JavaScript access to the session cookie
        'samesite' => 'Strict', // Prevents CSRF by restricting cross-site requests
    ]);
    session_start();
}

// Guards
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Redirect to login page if the user is not authenticated.
 * Also checks for session inactivity and enforces SESSION_LIFETIME.
 * If the session has expired, it destroys the session and redirects to login with a timeout message
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    // Server-side session inactivity check to enforce SESSION_LIFETIME. 
    // This is in addition to the client-side JS timeout
    $now = time();
    if (isset($_SESSION['last_active']) && ($now - $_SESSION['last_active']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = $now; // Update last active timestamp on each request to keep the session alive

    // Reissue the session cookie with updated expiration to extend the session lifetime on each request
    // Keep the same session ID but update the cookie expiration time
    // Keeps in sync with server-side session timeout and client-side JS timeout
    //
    // +10s buffer: the browser drops a cookie once now >= expires, but the
    // inactivity check above only fires on strictly > SESSION_LIFETIME. Without
    // this buffer both boundaries land on the same instant, so a request can
    // arrive with the cookie already gone right as the server would still have
    // accepted it — is_logged_in() then sees no session at all and redirects to
    // plain index.php, skipping the friendly ?timeout=1 message entirely.
    setcookie(session_name(), session_id(), [
        'expires'  => $now + SESSION_LIFETIME + 10,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Require the current user to have one of the given roles.
 * Redirects to login if unauthenticated; returns 403 if role is insufficient.
 *
 * @param string[] $allowed_roles
 */
function require_role(array $allowed_roles): void
{
    require_login();
    if (!in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
           . '<body><h1>403 — Access Denied</h1><p>You do not have permission to view this page.</p>'
           . '<a href="' . BASE_URL . '/pages/dashboard.php">Go to Dashboard</a></body></html>';
        exit;
    }
}

// Current-user helpers
function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_role(): string
{
    return $_SESSION['role'] ?? '';
}

function current_username(): string
{
    return $_SESSION['username'] ?? '';
}

function current_full_name(): string
{
    return $_SESSION['full_name'] ?? current_username();
}

// CSRF protection
// Cross-Site Request Forgery (CSRF) tokens help prevent unauthorized actions by 
// ensuring that requests made to the server are intentional and from the authenticated user.
// 32 bytes encoded as 64-character hex string, e.g., "a3f5c2e1b4d6f7a8c9e0b1d2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2"
function get_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
    }
    return $_SESSION['csrf_token']; // Returns a 64-character hex string (32 bytes)
}

/**
 * Verify a submitted CSRF token against the one stored in the session.
 * Uses timing-safe comparison to prevent timing attacks.
 */
function verify_csrf(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden CSRF input field — use inside every HTML <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Sync token (offline sync manager)

/**
 * Return (and lazily create) a per-session sync token used by sync_manager.js
 * to authenticate offline-queued operations sent to sync_receive.php.
 */
function get_sync_token(): string
{
    if (empty($_SESSION['sync_token'])) {
        $_SESSION['sync_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['sync_token'];
}

// Flash messages

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

/**
 * Retrieve and clear a flash message. Returns null if none set.
 * @param string $type  'success' | 'error' | 'warning' | 'info'
 */
function flash_get(string $type): ?string
{
    $msg = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $msg;
}

// Redirect helper

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

// Input sanitization helper

/**
 * Sanitize a string value from user input.
 * Use this for display; always use prepared statements for DB writes.
 */
function h(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

// SA ID validation

/**
 * Validate a South African 13-digit ID number.
 *
 * Structure: YYMMDD G SSS C A Z
 *   YYMMDD — date of birth
 *   G      — gender  (0-4 = Female, 5-9 = Male)
 *   SSS    — sequence number
 *   C      — citizenship (0 = SA citizen, 1 = permanent resident)
 *   A      — legacy digit (ignored in validation)
 *   Z      — Luhn check digit
 *
 * @return string|null  Error message, or null if the ID is valid.
 */
function validate_sa_id(string $id): ?string
{
    // 1. Must be exactly 13 digits
    if (!preg_match('/^\d{13}$/', $id)) {
        return 'SA ID must be exactly 13 digits.';
    }

    // 2. Date of birth
    $yy   = (int)substr($id, 0, 2);
    $mm   = (int)substr($id, 2, 2);
    $dd   = (int)substr($id, 4, 2);
    $year = ($yy <= (int)date('y')) ? 2000 + $yy : 1900 + $yy;

    if (!checkdate($mm, $dd, $year)) {
        return 'SA ID contains an invalid date of birth (digits 1–6 must be YYMMDD).';
    }

    // 3. Citizenship digit: 0 = SA citizen, 1 = permanent resident
    if ($id[10] !== '0' && $id[10] !== '1') {
        return 'SA ID has an invalid citizenship digit (digit 11 must be 0 or 1).';
    }

    // 4. Luhn check digit to validate the entire ID number
    $sum       = 0;
    $alternate = false;
    for ($i = 12; $i >= 0; $i--) {
        $n = (int)$id[$i];
        if ($alternate) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum      += $n;
        $alternate = !$alternate;
    }

    if ($sum % 10 !== 0) {
        return 'SA ID number provided is invalid, please verify the number and try again.';
    }

    return null;
}
