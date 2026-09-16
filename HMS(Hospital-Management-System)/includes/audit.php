<?php
/**
 * includes/audit.php — Audit logging helper
 *
 * Provides write_audit_log() — call this after every INSERT / UPDATE / DELETE
 * and on LOGIN / LOGOUT events.
 *
 * Design flow:
 *  - Never throws an exception; errors are written to the PHP error log only.
 *  - Uses the same PDO singleton as the rest of the app.
 *  - Session data is read directly so this file has no circular dependency.
 */

require_once __DIR__ . '/db.php';

/**
 * Write one row to audit_log.
 *
 * @param string      $action     LOGIN | LOGOUT | INSERT | UPDATE | DELETE | ACCESS_DENIED
 * @param string|null $table_name The DB table that was affected (null for auth events)
 * @param int|null    $record_id  Primary key of the affected row
 * @param mixed       $old_value  Array/scalar snapshot BEFORE the change — will be JSON-encoded
 * @param mixed       $new_value  Array/scalar snapshot AFTER the change  — will be JSON-encoded
 */
function write_audit_log(
    string  $action,
    ?string $table_name = null,
    ?int    $record_id  = null,
    mixed   $old_value  = null,
    mixed   $new_value  = null
): void {
    // Collect context
    $user_id    = isset($_SESSION['user_id'])  ? (int)$_SESSION['user_id']  : null;
    $username   = $_SESSION['username']        ?? null;
    $role       = $_SESSION['role']            ?? null;
    $ip_address = _audit_get_ip();
    $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
        ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
        : null;

    $old_json = ($old_value !== null) ? json_encode($old_value, JSON_UNESCAPED_UNICODE) : null;
    $new_json = ($new_value !== null) ? json_encode($new_value, JSON_UNESCAPED_UNICODE) : null;

    // Write
    try {
        $db   = get_db();
        $stmt = $db->prepare(
            'INSERT INTO audit_log
                (user_id, username, role, action, table_name, record_id,
                 old_value, new_value, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user_id, $username, $role,
            $action, $table_name, $record_id,
            $old_json, $new_json,
            $ip_address, $user_agent,
        ]);
    } catch (Throwable $e) {
        // Audit failure must never break the main request
        error_log('[HMS][audit] Failed to write audit log: ' . $e->getMessage());
    }
}

/**
 * Strip sensitive keys from an array before storing as audit snapshot.
 * Always call this before passing patient/user arrays to write_audit_log().
 *
 * @param  array    $data
 * @param  string[] $keys_to_remove  Defaults to common sensitive fields
 * @return array
 */
function audit_sanitize(array $data, array $keys_to_remove = ['password_hash', 'password', 'password_confirm']): array
{
    foreach ($keys_to_remove as $k) {
        unset($data[$k]);
    }
    return $data;
}

// Private helpers

function _audit_get_ip(): ?string
{
    // Prefer a forwarded IP only if the app is explicitly behind a trusted proxy.
    // In direct-server deployments use REMOTE_ADDR only to avoid spoofing.
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $ip ? mb_substr($ip, 0, 45) : null;
}
