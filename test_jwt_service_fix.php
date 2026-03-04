<?php
/**
 * Direct test script to verify OpenSSL key loading
 */
require_once 'vendor/autoload.php';

echo "Testing JWT Key Loading (Direct OpenSSL)...\n";
echo "==========================================\n\n";

// Load environment
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__ . '/.env');

$projectDir = __DIR__;
$secretKeyPath = $projectDir . '/config/jwt/private.pem';
$passphrase = $_ENV['JWT_PASSPHRASE'] ?? null;

echo "Testing with:\n";
echo "  File: $secretKeyPath\n";
echo "  File exists: " . (file_exists($secretKeyPath) ? "YES" : "NO") . "\n";
echo "  Passphrase length: " . strlen($passphrase) . " chars\n\n";

// Direct OpenSSL test
echo "Step 1: Test loading encrypted key with passphrase\n";
$keyPath = 'file://' . realpath($secretKeyPath);
echo "  Using path: $keyPath\n";

$privateKeyResource = @openssl_pkey_get_private($keyPath, $passphrase);

if ($privateKeyResource === false) {
    echo "  ❌ Failed to load key\n";
    while ($msg = openssl_error_string()) {
        echo "     OpenSSL: $msg\n";
    }
    exit(1);
} else {
    echo "  ✅ Key loaded as resource\n";
}

// Get key details
$details = openssl_pkey_get_details($privateKeyResource);
echo "  Key bits: " . $details['bits'] . "\n\n";

// Skip export test - it has OpenSSL config issues on Windows
echo "Step 2: Skipping export test (OpenSSL config issue reported)\n\n";

// Test Firebase JWT encoding with resource
echo "Step 3: Test Firebase JWT encoding with key resource\n";
try {
    $payload = [
        'iat' => time(),
        'exp' => time() + 3600,
        'sub' => 1,
        'email' => 'test@example.com',
        'type' => 'access'
    ];
    
    // Try using the key resource directly
    $token = \Firebase\JWT\JWT::encode($payload, $privateKeyResource, 'RS256');
    echo "  ✅ Token generated successfully with key resource\n";
    echo "  Token length: " . strlen($token) . " chars\n";
    echo "  Token preview: " . substr($token, 0, 50) . "...\n\n";
} catch (\Exception $e) {
    echo "  ❌ Firebase JWT with resource failed: " . $e->getMessage() . "\n\n";
    
    // Try with encrypted PEM content
    echo "  Attempting with encrypted PEM content...\n";
    try {
        $encryptedPEM = file_get_contents($secretKeyPath);
        $token = \Firebase\JWT\JWT::encode($payload, $encryptedPEM, 'RS256');
        echo "  ✅ Token generated with encrypted PEM\n";
        echo "  Token length: " . strlen($token) . " chars\n";
    } catch (\Exception $e2) {
        echo "  ❌ Firebase JWT with encrypted PEM also failed: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

echo "✅ All tests passed! Key loading and token generation works.\n";

