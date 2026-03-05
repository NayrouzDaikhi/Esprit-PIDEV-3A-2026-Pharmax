<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\JwtTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private JwtTokenService $jwtTokenService,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private UserRepository $userRepository,
        private string $jwtPublicKey,
        private int $tokenTtl = 3600
    ) {}

    /**
     * GET /api/auth/token
     * 
     * Retrieve JWT tokens for current authenticated session user.
     * JWT tokens are generated during login and stored in session.
     * This endpoint allows the frontend to fetch them after login.
     * 
     * SECURITY:
     * - Requires ROLE_USER (authenticated)
     * - Returns tokens from session (not re-generating)
     * - Only valid within same session
     * 
     * @return JsonResponse
     */
    #[Route('/token', name: 'token', methods: ['GET'])]
    public function getToken(): JsonResponse
    {
        // Require authentication
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            // Retrieve tokens from session
            $request = $this->requestStack->getCurrentRequest();
            $session = $request->getSession();
            
            $jwtData = $session->get('jwt_token_data');
            
            if (!$jwtData) {
                // If tokens not in session, generate them now
                // This can happen if user logged in before JWT subscriber existed
                $this->logger->info("JWT tokens not found in session, generating for user: {$user->getEmail()}");
                
                if (!$this->jwtTokenService->isEnabled()) {
                    return new JsonResponse([
                        'error' => 'Service Unavailable',
                        'message' => 'JWT service is not properly configured'
                    ], Response::HTTP_SERVICE_UNAVAILABLE);
                }
                
                $jwtData = $this->jwtTokenService->generateTokenPair($user);
                
                // Store in session for future use
                $session->set('jwt_access_token', $jwtData['access_token']);
                $session->set('jwt_refresh_token', $jwtData['refresh_token']);
                $session->set('jwt_token_data', $jwtData);
            }
            
            return new JsonResponse($jwtData, Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve JWT tokens: " . $e->getMessage());
            return new JsonResponse([
                'error' => 'Internal Server Error',
                'message' => 'Failed to retrieve tokens'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/auth/refresh
     * 
     * Refresh access token using refresh token.
     * 
     * REQUEST:
     * {
     *     "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
     * }
     * 
     * RESPONSE (Success):
     * {
     *     "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
     *     "token_type": "Bearer",
     *     "expires_in": 3600,
     *     "user": { ... }
     * }
     * 
     * SECURITY:
     * - Validates refresh token signature using RS256
     * - Checks token expiration
     * - Loads user from database to ensure they still exist and aren't blocked
     * - Refresh tokens have 30-day TTL
     * - Returns new access token (1-hour TTL)
     * 
     * @return JsonResponse
     */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            // Get refresh token from request body
            $data = json_decode($request->getContent(), true);
            $refreshToken = $data['refresh_token'] ?? null;
            
            if (!$refreshToken) {
                return new JsonResponse([
                    'error' => 'Bad Request',
                    'message' => 'refresh_token is required'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Decode refresh token to get user ID
            try {
                if (!file_exists($this->jwtPublicKey)) {
                    return new JsonResponse([
                        'error' => 'Service Unavailable',
                        'message' => 'JWT service is not properly configured'
                    ], Response::HTTP_SERVICE_UNAVAILABLE);
                }
                
                $publicKeyContent = file_get_contents($this->jwtPublicKey);
                $decoded = JWT::decode(
                    $refreshToken,
                    new Key($publicKeyContent, 'RS256')
                );
            } catch (\Exception $e) {
                $this->logger->warning("Refresh token decode failed: " . $e->getMessage());
                return new JsonResponse([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid or expired refresh token'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Validate token type
            if (($decoded->type ?? null) !== 'refresh') {
                $this->logger->warning("Invalid token type in refresh request: " . ($decoded->type ?? 'unknown'));
                return new JsonResponse([
                    'error' => 'Unauthorized',
                    'message' => 'Token is not a refresh token'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Get user ID from token
            $userId = $decoded->sub ?? $decoded->user_id ?? null;
            if (!$userId) {
                return new JsonResponse([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid token: no user ID'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Load user from database
            $user = $this->userRepository->find((int)$userId);
            
            if (!$user) {
                $this->logger->warning("Refresh token used for non-existent user: {$userId}");
                return new JsonResponse([
                    'error' => 'Unauthorized',
                    'message' => 'User not found'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Check if user is blocked
            if ($user->isBlocked()) {
                $this->logger->warning("Refresh token used for blocked user: {$user->getEmail()}");
                return new JsonResponse([
                    'error' => 'Forbidden',
                    'message' => 'User account is blocked'
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Generate new access token
            if (!$this->jwtTokenService->isEnabled()) {
                return new JsonResponse([
                    'error' => 'Service Unavailable',
                    'message' => 'JWT service is not properly configured'
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            
            $newAccessToken = $this->jwtTokenService->generateAccessToken($user);
            
            return new JsonResponse([
                'access_token' => $newAccessToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->tokenTtl,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getFullName(),
                    'roles' => $user->getRoles()
                ]
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error("Refresh token error: " . $e->getMessage());
            return new JsonResponse([
                'error' => 'Internal Server Error',
                'message' => 'Failed to refresh token'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/auth/me
     * 
     * Get current authenticated user information.
     * 
     * RESPONSE:
     * {
     *     "id": 5,
     *     "email": "user@example.com",
     *     "name": "John Doe",
     *     "roles": ["ROLE_USER"],
     *     "2fa_enabled": true
     * }
     * 
     * SECURITY:
     * - Requires ROLE_USER (authenticated)
     * - Returns current user from authentication token
     * 
     * @return JsonResponse
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function getCurrentUser(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], Response::HTTP_UNAUTHORIZED);
        }
        
        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'roles' => $user->getRoles(),
            '2fa_enabled' => $user->isTwoFactorAuthenticationEnabled()
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/auth/logout
     * 
     * Logout and clear JWT tokens from session.
     * 
     * SECURITY:
     * - Requires ROLE_USER (authenticated)
     * - Clears JWT tokens from session
     * - Note: JWT tokens themselves are not invalidated server-side
     *   (they remain valid until natural expiration)
     * 
     * @return JsonResponse
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();
        
        // Clear JWT tokens from session
        $session->remove('jwt_access_token');
        $session->remove('jwt_refresh_token');
        $session->remove('jwt_token_data');
        
        // Invalidate session
        $session->invalidate();
        
        return new JsonResponse([
            'message' => 'Logged out successfully'
        ], Response::HTTP_OK);
    }
}
