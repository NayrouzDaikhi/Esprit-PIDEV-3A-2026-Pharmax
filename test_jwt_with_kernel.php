<?php
/**
 * Direct test of JWT service with actual injection
 */
require_once 'vendor/autoload.php';

echo "Testing JWT Token Generation with Symfony...\n";
echo "=============================================\n\n";

// Load environment
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__ . '/.env');

// Create Symfony kernel
$kernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'dev', $_ENV['APP_DEBUG'] ?? false);
$kernel->boot();

$container = $kernel->getContainer();

try {
    // Get the JWT service from container
    $jwtService = $container->get(\App\Service\JwtTokenService::class);
    
    echo "JWT Service Status:\n";
    echo "  Is Enabled: " . ($jwtService->isEnabled() ? "YES" : "NO") . "\n\n";
    
    if (!$jwtService->isEnabled()) {
        echo "❌ JWT Service is disabled. Check application logs.\n";
        exit(1);
    }
    
    // Create a test user
    $em = $container->get('doctrine.orm.entity_manager');
    $userRepo = $em->getRepository(\App\Entity\User::class);
    $testUser = $userRepo->findOneBy(['email' => 'joujou@gmail.com']);
    
    if (!$testUser) {
        echo "❌ Test user not found in database\n";
        exit(1);
    }
    
    echo "Test User: " . $testUser->getEmail() . "\n\n";
    
    // Try to generate tokens
    echo "Attempting to generate JWT tokens...\n";
    $tokens = $jwtService->generateTokenPair($testUser);
    
    echo "✅ Token generation successful!\n\n";
    echo "Access Token Length: " . strlen($tokens['access_token']) . " chars\n";
    echo "Access Token Preview: " . substr($tokens['access_token'], 0, 50) . "...\n\n";
    echo "Token Data: " . json_encode([
        'token_type' => $tokens['token_type'],
        'expires_in' => $tokens['expires_in'],
        'user' => $tokens['user']
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    $kernel->shutdown();
}

echo "\n✅ All tests passed!\n";
