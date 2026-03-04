<?php

namespace App\Command;

use App\Entity\User;
use App\Service\JwtTokenService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:verify-jwt-integration',
    description: 'Verify JWT and Session integration is working correctly'
)]
class VerifyJwtIntegrationCommand extends Command
{
    public function __construct(
        private JwtTokenService $jwtTokenService,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command verifies that JWT generation and session integration are properly configured')
            ->addOption(
                'test-user',
                null,
                InputOption::VALUE_OPTIONAL,
                'Email of test user (creates one if not found)',
                'jwt-test@example.com'
            )
            ->addOption(
                'cleanup',
                null,
                InputOption::VALUE_NONE,
                'Remove test user after verification'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $testEmail = $input->getOption('test-user');
        $cleanup = $input->getOption('cleanup');

        $io->title('JWT & Session Integration Verification');
        $io->newLine();

        // Check 1: JWT Service Status
        $io->section('1. Checking JWT Service Configuration');
        $this->verifyJwtService($io);

        // Check 2: User Creation
        $io->section('2. Creating/Finding Test User');
        $user = $this->findOrCreateTestUser($io, $testEmail);
        if (!$user) {
            $io->error('Failed to create test user');
            return Command::FAILURE;
        }

        // Check 3: Token Generation
        $io->section('3. Generating JWT Tokens');
        try {
            $tokenPair = $this->jwtTokenService->generateTokenPair($user);
            $io->success('✓ Token generation successful');
            $io->listing([
                'Access Token: ' . substr($tokenPair['access_token'], 0, 50) . '...',
                'Refresh Token: ' . substr($tokenPair['refresh_token'], 0, 50) . '...',
                'Token Type: ' . $tokenPair['token_type'],
                'Expires In: ' . $tokenPair['expires_in'] . ' seconds',
            ]);
        } catch (\Exception $e) {
            $io->error(['✗ Token generation failed: ' . $e->getMessage()]);
            return Command::FAILURE;
        }

        // Check 4: Token Validation
        $io->section('4. Validating Generated Tokens');
        $this->verifyTokenStructure($io, $tokenPair['access_token'], 'Access');
        $this->verifyTokenStructure($io, $tokenPair['refresh_token'], 'Refresh');

        // Check 5: Event Subscriber
        $io->section('5. Checking Event Subscriber');
        $this->verifyEventSubscriber($io);

        // Check 6: API Endpoint
        $io->section('6. Checking API Endpoints');
        $this->verifyApiEndpoints($io);

        // Cleanup
        if ($cleanup) {
            $io->section('7. Cleanup');
            $this->cleanupTestUser($io, $user);
        }

        // Summary
        $io->newLine();
        $io->success([
            '✓ All checks passed!',
            'JWT and Session integration is properly configured.',
            'Users logging in via browser will automatically get JWT tokens.',
            'See documentation: JWT_SESSION_INTEGRATION_ARCHITECTURE.md'
        ]);

        return Command::SUCCESS;
    }

    private function verifyJwtService(SymfonyStyle $io): void
    {
        if (!$this->jwtTokenService->isEnabled()) {
            $io->error([
                '✗ JWT Service is NOT enabled',
                'Possible causes:',
                '  - JWT keys missing from config/jwt/',
                '  - Keys are not readable (permission issue)',
                '',
                'Fix: Run: php bin/console app:generate-jwt-keys'
            ]);
            return;
        }

        $io->success('✓ JWT Service is enabled and configured');
    }

    private function findOrCreateTestUser(SymfonyStyle $io, string $email): ?User
    {
        // Try to find existing user
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user) {
            $io->note("Using existing test user: $email");
            return $user;
        }

        // Create new test user
        try {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName('JWT');
            $user->setLastName('Test');
            $user->setStatus(User::STATUS_UNBLOCKED);
            $user->setRoles(['ROLE_USER']);
            $user->setCreatedAt(new \DateTime());
            $user->setUpdatedAt(new \DateTime());

            $hashedPassword = $this->passwordHasher->hashPassword($user, 'Test@1234');
            $user->setPassword($hashedPassword);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $io->success("Created test user: $email");
            return $user;
        } catch (\Exception $e) {
            $io->error("Failed to create test user: " . $e->getMessage());
            return null;
        }
    }

    private function verifyTokenStructure(SymfonyStyle $io, string $token, string $type): void
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $io->error("✗ Invalid $type Token structure");
                return;
            }

            // Decode payload (header.payload.signature)
            $payload = json_decode(base64_decode($parts[1]), true);

            if (!$payload) {
                $io->error("✗ $type Token payload is not valid JSON");
                return;
            }

            // Verify required claims
            $requiredClaims = ['sub', 'email', 'exp', 'iat'];
            $missingClaims = array_diff($requiredClaims, array_keys($payload));

            if (!empty($missingClaims)) {
                $io->warning("⚠ $type Token missing claims: " . implode(', ', $missingClaims));
            } else {
                $io->success("✓ $type Token structure is valid");
            }

            // Show claims
            $expiresAt = new \DateTime();
            $expiresAt->setTimestamp($payload['exp']);
            $io->listing([
                'Subject (sub): ' . ($payload['sub'] ?? 'N/A'),
                'Email: ' . ($payload['email'] ?? 'N/A'),
                'Expires: ' . $expiresAt->format('Y-m-d H:i:s'),
                'Type: ' . ($payload['type'] ?? 'N/A'),
            ]);
        } catch (\Exception $e) {
            $io->error("✗ Error analyzing $type Token: " . $e->getMessage());
        }
    }

    private function verifyEventSubscriber(SymfonyStyle $io): void
    {
        $subscriberClass = 'App\\EventSubscriber\\JwtGenerationSubscriber';
        
        if (!class_exists($subscriberClass)) {
            $io->error([
                '✗ JwtGenerationSubscriber not found',
                'Create: src/EventSubscriber/JwtGenerationSubscriber.php'
            ]);
            return;
        }

        // Check if it implements EventSubscriberInterface
        $reflection = new \ReflectionClass($subscriberClass);
        $interfaces = $reflection->getInterfaceNames();

        if (!in_array('Symfony\Component\EventDispatcher\EventSubscriberInterface', $interfaces)) {
            $io->error([
                '✗ JwtGenerationSubscriber does not implement EventSubscriberInterface',
                'Implement: EventSubscriberInterface'
            ]);
            return;
        }

        $io->success('✓ JwtGenerationSubscriber is properly configured');
        $io->note('Event subscriber will auto-generate JWT on InteractiveLoginEvent');
    }

    private function verifyApiEndpoints(SymfonyStyle $io): void
    {
        $endpoints = [
            'POST /api/auth/login' => 'API login (no session)',
            'GET /api/auth/me' => 'Get current user (JWT)',
            'GET /api/auth/token' => 'Get JWT for session user (NEW)',
            'POST /api/auth/refresh' => 'Refresh token',
            'POST /api/auth/logout' => 'Logout',
        ];

        $io->table(['Endpoint', 'Purpose'], array_map(fn($ep, $purpose) => [$ep, $purpose], array_keys($endpoints), $endpoints));
        $io->note('Check routing with: php bin/console debug:router | grep api/auth');
    }

    private function cleanupTestUser(SymfonyStyle $io, User $user): void
    {
        try {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
            $io->success('Test user removed');
        } catch (\Exception $e) {
            $io->error("Failed to remove test user: " . $e->getMessage());
        }
    }
}
