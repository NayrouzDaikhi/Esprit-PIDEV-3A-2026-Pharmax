<?php

namespace App\EventSubscriber;

use App\Service\JwtTokenService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
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
            InteractiveLoginEvent::class => 'onInteractiveLogin',
        ];
    }

    /**
     * Generate JWT token immediately after successful session login
     */
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getAuthenticationToken()->getUser();

        // Skip if JWT service is not enabled
        if (!$this->jwtTokenService->isEnabled()) {
            $this->logger->info('JWT generation skipped - service not enabled');
            return;
        }

        try {
            // Generate JWT token pair
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

            $this->logger->info("JWT tokens generated and stored in session for user: {$user->getEmail()}");
        } catch (\Exception $e) {
            // Don't fail the login if JWT generation fails
            $this->logger->error("Failed to generate JWT during session login: " . $e->getMessage());
        }
    }
}
