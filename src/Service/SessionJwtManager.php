<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

/**
 * Helper service to manage JWT tokens in session
 * Bridges session-based auth with JWT authentication
 */
class SessionJwtManager
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Store JWT token pair in session
     */
    public function storeTokensInSession(Request $request, array $tokenData): void
    {
        try {
            $session = $request->getSession();
            $session->set('jwt_access_token', $tokenData['access_token']);
            $session->set('jwt_refresh_token', $tokenData['refresh_token']);
            $session->set('jwt_token_data', $tokenData);
            $session->set('jwt_generated_at', time());
            
            $this->logger->info("JWT tokens stored in session");
        } catch (\Exception $e) {
            $this->logger->error("Failed to store JWT in session: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve JWT token from session
     */
    public function getTokenFromSession(Request $request): ?array
    {
        try {
            $session = $request->getSession();
            
            if (!$session->has('jwt_token_data')) {
                return null;
            }

            $tokenData = $session->get('jwt_token_data');
            $generatedAt = $session->get('jwt_generated_at', 0);

            // Optionally validate token expiration
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $isExpired = (time() - $generatedAt) > $expiresIn;

            if ($isExpired) {
                $this->logger->warning("JWT token in session has expired");
                return null;
            }

            return $tokenData;
        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve JWT from session: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear JWT tokens from session
     */
    public function clearTokensFromSession(Request $request): void
    {
        try {
            $session = $request->getSession();
            $session->remove('jwt_access_token');
            $session->remove('jwt_refresh_token');
            $session->remove('jwt_token_data');
            $session->remove('jwt_generated_at');
            
            $this->logger->info("JWT tokens cleared from session");
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear JWT from session: " . $e->getMessage());
        }
    }

    /**
     * Check if JWT is available in session
     */
    public function hasTokenInSession(Request $request): bool
    {
        return $request->getSession()->has('jwt_token_data');
    }
}
