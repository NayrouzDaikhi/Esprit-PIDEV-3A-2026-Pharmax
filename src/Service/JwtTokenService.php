<?php

namespace App\Service;

use App\Entity\User;
use Firebase\JWT\JWT;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

class JwtTokenService
{
    /**
     * @var resource|\OpenSSLAsymmetricKey|null
     */
    private $privateKey = null;
    private int $tokenTtl;
    private int $refreshTokenTtl;
    private LoggerInterface $logger;
    private bool $isEnabled = true;

    public function __construct(
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger,
        int $tokenTtl = 3600,
        int $refreshTokenTtl = 2592000
    ) {
        $this->tokenTtl = $tokenTtl;
        $this->refreshTokenTtl = $refreshTokenTtl;
        $this->logger = $logger;

        try {
            // Get JWT configuration from parameters
            $secretKeyPath = $parameterBag->get('jwt_secret_key');
            $passphrase = $parameterBag->get('jwt_passphrase');
            
            // Check if private key file exists and is readable
            if (!file_exists($secretKeyPath)) {
                $this->isEnabled = false;
                $this->logger->warning('JWT private key file not found: ' . $secretKeyPath . '. JWT token generation disabled.');
                return;
            }

            // Load encrypted private key using OpenSSL with passphrase
            // For RS256 algorithm, Firebase JWT can work directly with OpenSSL key resources
            $keyPath = 'file://' . realpath($secretKeyPath);
            $privateKeyResource = @openssl_pkey_get_private($keyPath, $passphrase);
            
            if ($privateKeyResource === false) {
                $this->isEnabled = false;
                $openSSLError = openssl_error_string();
                $this->logger->error(
                    'Failed to load JWT private key from ' . $secretKeyPath . 
                    '. OpenSSL error: ' . ($openSSLError ?: 'Unknown error') .
                    '. Verify key file format and passphrase are correct.'
                );
                return;
            }

            // Store the OpenSSL key resource
            // Firebase JWT's RS256 algorithm accepts OpenSSL key resources directly
            $this->privateKey = $privateKeyResource;
            
            // Log successful initialization with key details
            $keyDetails = openssl_pkey_get_details($privateKeyResource);
            $this->logger->info('JWT private key loaded successfully. Key bits: ' . ($keyDetails['bits'] ?? 'unknown'));
        } catch (\Exception $e) {
            $this->isEnabled = false;
            $this->logger->error('Error initializing JwtTokenService: ' . $e->getMessage());
        }
    }

    /**
     * Check if JWT service is properly initialized
     */
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    /**
     * Generate JWT access token
     */
    public function generateAccessToken(User $user): string
    {
        if (!$this->isEnabled || !$this->privateKey) {
            throw new \RuntimeException('JWT service is not properly initialized. Keys may be missing or unreadable.');
        }

        $issuedAt = time();
        $expire = $issuedAt + $this->tokenTtl;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'sub' => $user->getId(),
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'name' => $user->getFullName(),
            'type' => 'access'
        ];

        try {
            $token = JWT::encode($payload, $this->privateKey, 'RS256');
            $this->logger->info("Access token generated for user: {$user->getEmail()}");
            return $token;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate access token: " . $e->getMessage());
            throw new \RuntimeException('Failed to generate JWT token');
        }
    }


    /**
     * Generate JWT refresh token (longer lived)
     */
    public function generateRefreshToken(User $user): string
    {
        if (!$this->isEnabled || !$this->privateKey) {
            throw new \RuntimeException('JWT service is not properly initialized. Keys may be missing or unreadable.');
        }

        $issuedAt = time();
        $expire = $issuedAt + $this->refreshTokenTtl;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'sub' => $user->getId(),
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => 'refresh'
        ];

        try {
            $token = JWT::encode($payload, $this->privateKey, 'RS256');
            $this->logger->info("Refresh token generated for user: {$user->getEmail()}");
            return $token;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate refresh token: " . $e->getMessage());
            throw new \RuntimeException('Failed to generate JWT token');
        }
    }

    /**
     * Generate both access and refresh tokens
     */
    public function generateTokenPair(User $user): array
    {
        return [
            'access_token' => $this->generateAccessToken($user),
            'refresh_token' => $this->generateRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenTtl,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getFullName(),
                'roles' => $user->getRoles()
            ]
        ];
    }

    /**
     * Get token expiration time
     */
    public function getTokenExpiresIn(): int
    {
        return $this->tokenTtl;
    }
}
