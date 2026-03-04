<?php

namespace App\Security;

use App\Repository\UserRepository;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
    ) {}

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    public function supports(Request $request): bool
    {
        // Prefer checking route name to be robust to path changes
        return $request->attributes->get('_route') === 'app_login' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        /**
         * The login form is built with Symfony Forms (`LoginFormType`),
         * so all submitted fields are nested under the form name.
         *
         * For `LoginFormType` the default form name is "login_form", which means
         * the request payload looks like:
         *
         *   login_form[email_username]
         *   login_form[password]
         *   login_form[_csrf_token]
         *
         * We therefore need to read from the "login_form" array instead of
         * looking for flat request keys.
         */
        $formData = (array) $request->request->all('login_form');

        $email = (string) ($formData['email_username'] ?? '');
        $password = (string) ($formData['password'] ?? '');
        $csrfToken = (string) ($formData['_csrf_token'] ?? '');

        if (empty($email)) {
            throw new CustomUserMessageAuthenticationException('Email or username cannot be empty.');
        }

        // Try to find user by email
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }

        // Check if user is blocked
        if ($user->isBlocked()) {
            throw new CustomUserMessageAuthenticationException('This account has been blocked.');
        }

        // Store email in session for error redisplay
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, fn($email) => $this->userRepository->findByEmail($email)),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        $user = $token->getUser();

        // Add a friendly welcome flash for users who just signed in
        $displayName = method_exists($user, 'getFullName') ? $user->getFullName() : $user->getUserIdentifier();
        $request->getSession()->getFlashBag()->add('login_success', sprintf('Welcome back, %s!', $displayName));

        // JWT is automatically generated and stored in session by JwtGenerationSubscriber
        // Frontend can retrieve it via GET /api/auth/token endpoint

        // Redirect based on roles (admin or super admin)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return new RedirectResponse($this->urlGenerator->generate('admin_user_index'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_profile'));
    }
}
