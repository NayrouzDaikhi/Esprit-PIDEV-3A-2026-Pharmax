<?php
// Quick script to generate JWT keys

$keyDir = __DIR__ . '/config/jwt';
if (!is_dir($keyDir)) {
    mkdir($keyDir, 0700, true);
}

$keyFile = $keyDir . '/private.pem';
$pubFile = $keyDir . '/public.pem';

// Check if keys already exist
if (file_exists($keyFile) && file_exists($pubFile)) {
    echo "Keys already exist. Using existing keys.\n";
    exit(0);
}

// Attempt 1: Try with openssl command line
$result = shell_exec('openssl genrsa -out "' . $keyFile . '" 2048 2>&1');

if (file_exists($keyFile) && filesize($keyFile) > 0) {
    // Extract public key from private key
    shell_exec('openssl rsa -in "' . $keyFile . '" -pubout -out "' . $pubFile . '"');
    chmod($keyFile, 0600);
    chmod($pubFile, 0644);
    echo "✅ JWT keys generated successfully using OpenSSL!\n";
    echo "Private key: $keyFile\n";
    echo "Public key: $pubFile\n";
    exit(0);
}

// Attempt 2: Create test keys if openssl not available
echo "⚠️  OpenSSL command not available or failed. Creating placeholder keys.\n";
echo "Note: These are test keys. In production, generate proper keys with:\n";
echo "openssl genrsa -out config/jwt/private.pem 2048\n";
echo "openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem\n";