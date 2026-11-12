<?php

/**
 * Router for PHP's built-in server:
 *   php -S 127.0.0.1:8080 -t public public/router.php
 *
 * Restores Authorization when the SAPI strips it (common cause of
 * Lexik "JWT Token not found" right after a successful login).
 */

require_once __DIR__.'/restore_authorization.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__.$path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__.'/index.php';
