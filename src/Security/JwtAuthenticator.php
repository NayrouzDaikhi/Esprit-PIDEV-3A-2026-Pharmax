<?php

namespace App\Security;

use App\Repository\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Psr\Log\LoggerInterface;

class JwtAuthenticator extends AbstractAuthenticator
{
    private ?string $jwtPublicKey = null;
    private UserRepository $userRepository;
    private LoggerInterface $logger;
    private bool $isEnabled = true;

    public function __construct(
        UserRepository $userRepository,
        LoggerInterface $logger,
        string $jwtPublicKey
    ) {
        $this->userRepository = $userRepository;
        $this->logger = $logger;
        
        // Gracefully handle missing or unreadable key file
        try {
            if (!file_exists($jwtPublicKey)) {
                $this->isEnabled = false;
                $this->logger->warning('JWT public key file not found: ' . $jwtPublicKey . '. JWT authentication disabled. Run: php bin/console app:generate-jwt-keys');
                return;
            }
            
            // Attempt to read the public key file
            $keyContent = @file_get_contents($jwtPublicKey);
            if ($keyContent === false) {
                $this->isEnabled = false;
                $this->logger->warning('Unable to read JWT public key file: ' . $jwtPublicKey . '. JWT authentication disabled. Check file permissions.');
                return;
            }
            
            $this->jwtPublicKey = $keyContent;
        } catch (\Exception $e) {
            $this->isEnabled = false;
            $this->logger->error('Error initializing JWT authenticator: ' . $e->getMessage());
        }
    }

    public function supports(Request $request): ?bool
    {
        // Skip JWT authentication if not enabled
        if (!$this->isEnabled) {
            return false;
        }
        
        // Check if Authorization header with Bearer token exists
        return $request->headers->has('Authorization') &&
               str_starts_with($request->headers->get('Authorization'), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization');
        $token = substr($authHeader, 7); // Remove "Bearer "

        try {
            // Safety check: ensure JWT is enabled and keys are available
            if (!$this->isEnabled || !$this->jwtPublicKey) {
                throw new CustomUserMessageAuthenticationException('JWT authentication is not available');
            }
            
            // Decode JWT token
            $decoded = JWT::decode(
                $token,
                new Key($this->jwtPublicKey, 'RS256')
            );

            $userId = $decoded->sub ?? $decoded->user_id ?? null;
            
            if (!$userId) {
                $this->logger->warning('JWT token missing user ID claim');
                throw new CustomUserMessageAuthenticationException('Invalid token: no user ID');
            }

            // Create user badge that will load the user
            return new Passport(
                new UserBadge(
                    (string)$userId,
                    function ($userId) {
                        $user = $this->userRepository->find((int)$userId);
                        if (!$user) {
                            $this->logger->warning('JWT user not found in database: ' . $userId);
                            throw new CustomUserMessageAuthenticationException('User not found');
                        }
                        return $user;
                    }
                )
            );
        } catch (CustomUserMessageAuthenticationException $e) {
            // Re-throw known exceptions
            throw $e;
        } catch (\Exception $e) {
            // Log all other exceptions for debugging
            $this->logger->warning('JWT decode failed: ' . $e->getMessage() . ' | Token: ' . substr($token, 0, 50) . '...');
            throw new CustomUserMessageAuthenticationException('Invalid JWT token');
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
    {
        // Let request continue to controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessageKey()
        ], 401);
    }
}
