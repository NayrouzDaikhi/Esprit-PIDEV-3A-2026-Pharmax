<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\JwtTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private JwtTokenService $jwtTokenService,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Login with email and password
     * POST /api/auth/login
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate input
        if (!isset($data['email']) || !isset($data['password'])) {
            return $this->json([
                'error' => 'Invalid credentials',
                'message' => 'Email and password are required'
            ], 400);
        }

        $email = $data['email'];
        $plainPassword = $data['password'];

        // Find user
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return $this->json([
                'error' => 'Invalid credentials',
                'message' => 'Email not found'
            ], 401);
        }

        // Check if user is blocked
        if ($user->isBlocked()) {
            return $this->json([
                'error' => 'Access denied',
                'message' => 'Your account has been blocked'
            ], 403);
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $plainPassword)) {
            return $this->json([
                'error' => 'Invalid credentials',
                'message' => 'Invalid password'
            ], 401);
        }

        // Generate tokens
        $tokens = $this->jwtTokenService->generateTokenPair($user);

        return $this->json($tokens, 201);
    }

    /**
     * Refresh access token using refresh token
     * POST /api/auth/refresh
     */
    #[Route('/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['refresh_token'])) {
            return $this->json([
                'error' => 'Invalid request',
                'message' => 'Refresh token is required'
            ], 400);
        }

        // In a real app, you'd decode and validate the refresh token
        // For now, we'll use the current authenticated user if available
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid refresh token'
            ], 401);
        }

        $tokens = $this->jwtTokenService->generateTokenPair($user);

        return $this->json($tokens);
    }

    /**
     * Get current authenticated user info
     * GET /api/auth/me
     */
    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function getCurrentUser(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'roles' => $user->getRoles(),
                'status' => $user->getStatus(),
                'createdAt' => $user->getCreatedAt()?->format('c'),
                'avatar' => $user->getAvatar()
            ]
        ]);
    }

    /**
     * Logout (client-side token deletion)
     * POST /api/auth/logout
     */
    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Token is invalidated when its TTL expires
        // You can optionally blacklist token in database here
        
        return $this->json([
            'success' => true,
            'message' => 'Logged out successfully. Please delete your token on client side.'
        ]);
    }

    /**
     * Register new user and get JWT tokens
     * POST /api/auth/register
     */
    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate input
        if (!isset($data['email'], $data['password'], $data['firstName'], $data['lastName'])) {
            return $this->json([
                'error' => 'Invalid input',
                'message' => 'Email, password, firstName, and lastName are required'
            ], 400);
        }

        $email = $data['email'];
        $password = $data['password'];
        $firstName = $data['firstName'];
        $lastName = $data['lastName'];

        // Check if user exists
        if ($this->userRepository->findOneBy(['email' => $email])) {
            return $this->json([
                'error' => 'User exists',
                'message' => 'Email already registered'
            ], 409);
        }

        // Validate password strength
        if (strlen($password) < 8) {
            return $this->json([
                'error' => 'Weak password',
                'message' => 'Password must be at least 8 characters'
            ], 400);
        }

        // Create new user
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setStatus(User::STATUS_UNBLOCKED);
        $user->setRoles(['ROLE_USER']);
        $user->setCreatedAt(new \DateTime());
        $user->setUpdatedAt(new \DateTime());

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Save user
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Generate tokens
        $tokens = $this->jwtTokenService->generateTokenPair($user);

        return $this->json($tokens, 201);
    }

    /**
     * Get JWT token for currently authenticated session user
     * useful for frontend to retrieve JWT after session login
     * GET /api/auth/token
     */
    #[Route('/token', name: 'api_auth_token', methods: ['GET'])]
    public function getToken(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json([
                'error' => 'Not authenticated',
                'message' => 'No user session or JWT found'
            ], 401);
        }

        try {
            // Check if JWT tokens exist in session (set by JwtGenerationSubscriber)
            $session = $request->getSession();
            $tokenData = $session->get('jwt_token_data');

            if ($tokenData) {
                // Return cached token data from session
                return $this->json($tokenData);
            }

            // If not in session, generate fresh tokens
            // (e.g., for API users logging in directly to get tokens)
            $tokens = $this->jwtTokenService->generateTokenPair($user);
            return $this->json($tokens);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Token generation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
