<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\JwtTokenService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Psr\Log\LoggerInterface;

class JwtGenerationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JwtTokenService $jwtTokenService,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // LoginSuccessEvent fires AFTER full authentication (including 2FA verification)
            // This prevents JWT generation before 2FA is completed
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    /**
     * Generate JWT token AFTER successful login AND 2FA verification.
     * 
     * SECURITY NOTE: LoginSuccessEvent is fired ONLY after:
     * 1. Credentials validated (email/password)
     * 2. User status verified (not blocked)
     * 3. 2FA verification completed (if enabled)
     * 
     * This prevents the 2FA bypass vulnerability where JWT was issued before 2FA.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getAuthenticatedToken()->getUser();

        // Type guard: only process if user is our User entity
        if (!$user instanceof User) {
            $this->logger->info('JWT generation skipped - not a User entity');
            return;
        }

        // Skip if JWT service is not enabled
        if (!$this->jwtTokenService->isEnabled()) {
            $this->logger->info('JWT generation skipped - service not enabled');
            return;
        }

        try {
            // Generate JWT token pair
            // At this point, 2FA has been verified (if required) since LoginSuccessEvent
            // is only fired after FULL authentication chain completes
            $tokenPair = $this->jwtTokenService->generateTokenPair($user);
            
            // Store in session so frontend can retrieve it
            $session = $request->getSession();
            $session->set('jwt_access_token', $tokenPair['access_token']);
            $session->set('jwt_refresh_token', $tokenPair['refresh_token']);
            $session->set('jwt_token_data', [
                'access_token' => $tokenPair['access_token'],
                'refresh_token' => $tokenPair['refresh_token'],
                'token_type' => 'Bearer',
                'expires_in' => $tokenPair['expires_in'],
                'user' => $tokenPair['user'],
            ]);

            $this->logger->info("JWT tokens generated and stored in session for user: {$user->getEmail()} (2FA verified)");
        } catch (\Exception $e) {
            // Don't fail the login if JWT generation fails
            $this->logger->error("Failed to generate JWT during session login: " . $e->getMessage());
        }
    }
}
