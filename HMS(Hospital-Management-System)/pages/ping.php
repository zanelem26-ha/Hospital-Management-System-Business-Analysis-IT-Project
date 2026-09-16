<?php
/**
 * ping.php — Lightweight connectivity probe for offline detection.
 * Called by sync_manager.js to check real server reachability.
 * No session, no DB — fast and stateless.
 */
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
