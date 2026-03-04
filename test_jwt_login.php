<?php
/**
 * Test JWT with API login endpoint
 */
require_once 'vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$client = HttpClient::create();
$baseUrl = 'http://127.0.0.1:8000';

echo "JWT Token Generation Test\n";
echo "========================\n\n";

// Step 1: Login via API to get tokens
echo "1. Logging in via /api/auth/login...\n";
try {
    $response = $client->request('POST', $baseUrl . '/api/auth/login', [
        'json' => [
            'email' => 'joujou@gmail.com',
            'password' => 'Test123!'  // Adjust to your test user password
        ]
    ]);
    
    $status = $response->getStatusCode();
    echo "   Status: $status\n";
    
    if ($status === 201 || $status === 200) {
        $data = json_decode($response->getContent(), true);
        
        if (isset($data['access_token'])) {
            echo "   ✅ Login successful!\n";
            echo "   Access Token: " . substr($data['access_token'], 0, 50) . "...\n";
            echo "   User: " . ($data['user']['email'] ?? 'unknown') . "\n";
            
            // Step 2: Use token to access protected endpoint
            echo "\n2. Testing protected endpoint with token...\n";
            $protectedResponse = $client->request('GET', $baseUrl . '/api/auth/token', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $data['access_token']
                ]
            ]);
            
            $pStatus = $protectedResponse->getStatusCode();
            echo "   Status: $pStatus\n";
            
            if ($pStatus === 200) {
                echo "   ✅ Protected endpoint accessible!\n";
            }
        } else {
            echo "   Response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
        }
    } else {
        echo "   ❌ Login failed with status $status\n";
        echo "   Response: " . substr($response->getContent(), 0, 200) . "\n";
    }
} catch (\Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

// Step 3: Check logs for any errors
echo "\n3. Checking logs for JWT errors...\n";
$logFile = 'var/log/dev.log';
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    
    if (strpos($content, 'Failed to generate access token') !== false) {
        echo "   ❌ JWT Token generation failed (see error in logs)\n";
    } else if (strpos($content, 'JWT') !== false || strpos($content, 'token') !== false) {
        echo "   ✅ No JWT generation errors found\n";
    } else {
        echo "   No JWT logs found\n";
    }
}

echo "\n✅ Test complete!\n";
