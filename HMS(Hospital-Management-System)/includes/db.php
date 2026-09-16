<?php
/**
 * Database connection — PDO singleton.
 * All calls MUST use prepared statements; never interpolate user data into SQL.
 */

require_once __DIR__ . '/../config/config.php';

function get_db(): PDO
{
    static $pdo = null;

    // If the PDO instance is not yet created, create it
    // Only one instance will be created and reused for subsequent calls
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements only
        ];

        // Azure MySQL rejects plaintext connections (--require_secure_transport=ON)
        if (defined('DB_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Keep CURRENT_TIMESTAMP/NOW() columns in sync with PHP's
            // Africa/Johannesburg (UTC+2) timezone set in config.php.
            $pdo->exec("SET time_zone = '+02:00'");
        } catch (PDOException $e) {
            // Log full detail server-side; never expose to browser
            error_log('[HMS] DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('Service temporarily unavailable. Please contact the system administrator.');
        }
    }

    return $pdo;
}
