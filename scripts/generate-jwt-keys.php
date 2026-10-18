<?php

declare(strict_types=1);

/**
 * Generate Lexik JWT RSA keypair on Windows when
 * `php bin/console lexik:jwt:generate-keypair` fails due to OpenSSL config.
 *
 * Usage: php scripts/generate-jwt-keys.php
 */

$backendDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend';
$envFile = $backendDir . DIRECTORY_SEPARATOR . '.env';
$jwtDir = $backendDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'jwt';
$privatePath = $jwtDir . DIRECTORY_SEPARATOR . 'private.pem';
$publicPath = $jwtDir . DIRECTORY_SEPARATOR . 'public.pem';

if (!is_file($envFile)) {
    fwrite(STDERR, "Missing backend/.env\n");
    exit(1);
}

$passphrase = null;
foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
    if (str_starts_with($line, 'JWT_PASSPHRASE=')) {
        $passphrase = substr($line, strlen('JWT_PASSPHRASE='));
        break;
    }
}

if (null === $passphrase || '' === $passphrase) {
    fwrite(STDERR, "JWT_PASSPHRASE not found in backend/.env\n");
    exit(1);
}

if (is_file($privatePath) && is_file($publicPath) && !in_array('--overwrite', $argv, true)) {
    fwrite(STDOUT, "JWT keys already exist in {$jwtDir}\n");
    exit(0);
}

if (!defined('OPENSSL_KEYTYPE_RSA')) {
    fwrite(STDERR, "PHP openssl extension is required (OPENSSL_KEYTYPE_RSA undefined)\n");
    exit(1);
}

$config = [
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$opensslConf = getenv('OPENSSL_CONF') ?: '';
if ('' === $opensslConf) {
    $candidates = [
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        'C:\\Program Files\\PHP\\8.4\\extras\\ssl\\openssl.cnf',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $opensslConf = $candidate;
            break;
        }
    }
}

if ('' !== $opensslConf && is_file($opensslConf)) {
    $config['config'] = $opensslConf;
}

if (!is_dir($jwtDir) && !mkdir($jwtDir, 0775, true) && !is_dir($jwtDir)) {
    fwrite(STDERR, "Cannot create {$jwtDir}\n");
    exit(1);
}

$resource = openssl_pkey_new($config);
if (false === $resource) {
    while ($message = openssl_error_string()) {
        fwrite(STDERR, $message . PHP_EOL);
    }
    exit(1);
}

if (!openssl_pkey_export($resource, $privateKey, $passphrase, $config)) {
    while ($message = openssl_error_string()) {
        fwrite(STDERR, $message . PHP_EOL);
    }
    exit(1);
}

$details = openssl_pkey_get_details($resource);
if (false === $details || empty($details['key'])) {
    fwrite(STDERR, "Failed to extract public key\n");
    exit(1);
}

file_put_contents($privatePath, $privateKey);
file_put_contents($publicPath, $details['key']);

fwrite(STDOUT, "Generated:\n  {$privatePath}\n  {$publicPath}\n");
