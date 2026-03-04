<?php
// test_jwt_key.php
$keyFile = __DIR__ . '/config/jwt/private.pem';
$passphrase = 'da6b1af422ae3f49e304349cdce2cd8fa35a77876e72f364b00e26799eb19a2c'; // Use your actual passphrase from .env

echo "Checking JWT private key...\n";
echo "Key file path: " . $keyFile . "\n";

if (!file_exists($keyFile)) {
    echo "❌ Key file not found at: " . $keyFile . "\n";
    exit(1);
}

echo "✅ Key file exists\n";

$keyContent = file_get_contents($keyFile);
echo "File size: " . filesize($keyFile) . " bytes\n";
echo "First 50 chars: " . substr($keyContent, 0, 50) . "...\n";

// Try to load the private key
echo "\nAttempting to load private key with passphrase: " . $passphrase . "\n";
$privateKey = openssl_pkey_get_private('file://' . $keyFile, $passphrase);

if ($privateKey === false) {
    echo "❌ Failed to load private key\n";
    echo "OpenSSL errors:\n";
    while ($msg = openssl_error_string()) {
        echo "  - " . $msg . "\n";
    }
} else {
    echo "✅ Private key loaded successfully!\n";
    $details = openssl_pkey_get_details($privateKey);
    echo "Key type: " . $details['type'] . "\n";
    echo "Key bits: " . $details['bits'] . "\n";
}

// Also try without passphrase (if key might be unencrypted)
echo "\nAttempting to load private key WITHOUT passphrase...\n";
$privateKey2 = openssl_pkey_get_private('file://' . $keyFile);
if ($privateKey2 === false) {
    echo "❌ Failed to load private key without passphrase\n";
} else {
    echo "✅ Private key loaded successfully WITHOUT passphrase!\n";
}