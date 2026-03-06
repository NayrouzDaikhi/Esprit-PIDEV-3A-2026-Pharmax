<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $email = $googleUser->getEmail();

                if (!$email) {
                    throw new AuthenticationException('Unable to get email from Google');
                }

                // Try to find existing user by email (with fresh database lookup)
                $this->entityManager->clear(); // Clear cache to get fresh data from DB
                $user = $this->userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    // Create new user
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($googleUser->getFirstName() ?? 'Google');
                    $user->setLastName($googleUser->getLastName() ?? 'User');
                    $user->setRoles(['ROLE_USER']);
                    $user->setStatus(User::STATUS_UNBLOCKED);
                    
                    // Generate random password (won't be used for OAuth login)
                    $randomPassword = bin2hex(random_bytes(16));
                    $user->setPassword($randomPassword);
                    
                    $user->setCreatedAt(new \DateTime());
                    
                    try {
                        $this->entityManager->persist($user);
                        $this->entityManager->flush();
                    } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                        // Race condition: another request already created this user
                        // Clear and fetch again
                        $this->entityManager->clear();
                        $user = $this->userRepository->findOneBy(['email' => $email]);
                        if (!$user) {
                            throw new AuthenticationException('Failed to create or retrieve user for email: ' . $email);
                        }
                    }
                } else {
                    // Update existing user
                    $user->setUpdatedAt(new \DateTime());
                }

                // Set/update Google-specific fields
                $user->setGoogleId($googleUser->getId());
                $user->setAvatar($googleUser->getAvatar());

                // Persist changes
                try {
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    // Log but don't fail - user is valid for login
                    error_log('Failed to update user: ' . $e->getMessage());
                }

                return $user;
            }),
            [new RememberMeBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        // Redirect based on user role
        if (in_array('ROLE_ADMIN', $user->getRoles()) || in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
            return new RedirectResponse($this->router->generate('admin_dashboard'));
        }

        return new RedirectResponse($this->router->generate('app_register'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new RedirectResponse(
            $this->router->generate('app_login'),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * Called when an anonymous user tries to access a protected page.
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse(
            $this->router->generate('app_login'),
            Response::HTTP_SEE_OTHER,
        );
    }
}
