<?php
/**
 * Test JWT token generation with authentication
 * This script simulates a browser session with login
 */
require_once 'vendor/autoload.php';

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

echo "Testing JWT Token Generation with Browser Session...\n";
echo "===================================================\n\n";

// Create HTTP browser to simulate sessions
$client = new HttpBrowser(HttpClient::create());

// URL of the app
$baseUrl = 'http://127.0.0.1:8000';

// First, try to access the JWT token endpoint (should redirect to login)
echo "1. Accessing /api/auth/token without authentication...\n";
try {
    $response = $client->request('GET', $baseUrl . '/api/auth/token');
    echo "   Status: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Try accessing profile page to establish session
echo "\n2. Accessing /profile (to establish session)...\n";
try {
    $response = $client->request('GET', $baseUrl . '/profile');
    $status = $response->getStatusCode();
    echo "   Status: $status\n";
    
    if ($status === 200) {
        echo "   ✅ Session established\n";
    } else {
        echo "   ⚠️  Status is $status (might be redirect)\n";
    }
} catch (\Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Now try JWT endpoint again
echo "\n3. Accessing /api/auth/token with session...\n";
try {
    $response = $client->request('GET', $baseUrl . '/api/auth/token');
    $status = $response->getStatusCode();
    $content = $response->getContent();
    
    echo "   Status: $status\n";
    
    if ($status === 200) {
        echo "   ✅ Token endpoint returned 200!\n";
        
        // Try to parse JSON
        $data = json_decode($content, true);
        if ($data && isset($data['access_token'])) {
            echo "   ✅ Access token generated successfully!\n";
            echo "   Token preview: " . substr($data['access_token'], 0, 50) . "...\n";
            echo "   User: " . ($data['user']['email'] ?? 'unknown') . "\n";
        } else {
            echo "   Response content (first 300 chars):\n";
            echo "   " . substr($content, 0, 300) . "\n";
        }
    } else {
        echo "   Status $status - Response (first 300 chars):\n";
        echo "   " . substr($content, 0, 300) . "\n";
    }
} catch (\Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n4. Checking application logs...\n";
$logFile = 'var/log/dev.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $tokenErrors = [];
    $tokenSuccess = [];
    
    foreach ($lines as $line) {
        if (strpos($line, 'token') !== false || strpos($line, 'Token') !== false) {
            if (strpos($line, 'ERROR') !== false || strpos($line, 'error') !== false) {
                $tokenErrors[] = trim($line);
            } else if (strpos($line, 'INFO') !== false) {
                $tokenSuccess[] = trim($line);
            }
        }
    }
    
    if (!empty($tokenSuccess)) {
        echo "   ✅ Success logs:\n";
        foreach (array_slice($tokenSuccess, -3) as $log) {
            echo "      " . substr($log, 0, 100) . "...\n";
        }
    }
    
    if (!empty($tokenErrors)) {
        echo "   ❌ Error logs:\n";
        foreach (array_slice($tokenErrors, -3) as $log) {
            echo "      " . substr($log, 0, 100) . "...\n";
        }
    }
    
    if (empty($tokenSuccess) && empty($tokenErrors)) {
        echo "   No token-related logs found\n";
    }
}
