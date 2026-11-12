<?php

/**
 * PHP CGI / some proxies drop Authorization before Lexik can read it.
 * Restore it from common fallbacks (REDIRECT_*, X-Delivery-Authorization, getallheaders).
 */
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    return;
}

if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

    return;
}

if (!empty($_SERVER['HTTP_X_DELIVERY_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_X_DELIVERY_AUTHORIZATION'];

    return;
}

if (!\function_exists('getallheaders')) {
    return;
}

$headers = getallheaders();
if (!\is_array($headers)) {
    return;
}

foreach ($headers as $name => $value) {
    if (!\is_string($value) || $value === '') {
        continue;
    }
    $key = strtolower((string) $name);
    if ($key === 'authorization' || $key === 'x-delivery-authorization') {
        $_SERVER['HTTP_AUTHORIZATION'] = $value;

        return;
    }
}
