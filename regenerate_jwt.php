<?php
// regenerate_jwt.php
$passphrase = 'da6b1af422ae3f49e304349cdce2cd8fa35a77876e72f364b00e26799eb19a2c'; // <-- YOUR EXACT PASSPHRASE
$configDir = __DIR__ . '/config/jwt';
$configFile = __DIR__ . '/openssl.cnf';   // path to our minimal config

echo "Regenerating JWT keys...\n";
echo "Passphrase: " . $passphrase . "\n";
echo "Target directory: " . $configDir . "\n";
echo "OpenSSL config file: " . $configFile . "\n";

// Ensure config file exists
if (!file_exists($configFile)) {
    file_put_contents($configFile, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n[req]\n");
    echo "Created minimal OpenSSL config.\n";
}

// Create config directory if needed
if (!is_dir($configDir)) {
    mkdir($configDir, 0777, true);
    echo "Created directory.\n";
}

// Generate new RSA key with explicit config
$config = [
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'config' => $configFile,
];
$resource = openssl_pkey_new($config);
if (!$resource) {
    echo "❌ Failed to generate key pair.\n";
    while ($msg = openssl_error_string()) echo "  - $msg\n";
    exit(1);
}

// Export private key with passphrase
if (!openssl_pkey_export($resource, $privateKey, $passphrase, $config)) {
    echo "❌ Failed to export private key.\n";
    while ($msg = openssl_error_string()) echo "  - $msg\n";
    exit(1);
}

// Get public key
$publicKey = openssl_pkey_get_details($resource)['key'];

// Save files
file_put_contents($configDir . '/private.pem', $privateKey);
file_put_contents($configDir . '/public.pem', $publicKey);

echo "✅ Keys written to $configDir\n";

// Verify
$test = openssl_pkey_get_private('file://' . $configDir . '/private.pem', $passphrase);
if ($test) {
    echo "✅ Verification: private key loads successfully.\n";
} else {
    echo "❌ Verification failed – key still not loadable.\n";
    while ($msg = openssl_error_string()) echo "  - $msg\n";
}

// Optional: delete the temporary config
// unlink($configFile);