# JWT Implementation: Technical Source of Truth
**Date**: March 5, 2026  
**Codebase Analysis**: Symfony 6.x with Firebase JWT (RS256 Algorithm)  
**Status**: PRODUCTION-READY (with minor gaps identified)

---

## EXECUTIVE SUMMARY

This Symfony application implements JWT (JSON Web Token) authentication using the **Firebase JWT library** with **RS256 (RSA Signature with SHA-256)** algorithm. JWT tokens are generated after successful form-based login and serve as an alternative authentication method to session-based auth. The system integrates with **2FA (Two-Factor Authentication)** via Google Authenticator, with 2FA verification occurring *before* JWT issuance. The implementation is hybrid: it supports both session-based (traditional) and JWT-based (API) authentication simultaneously.

---

## PART 1: CORE INFRASTRUCTURE & CONFIGURATION

### 1.1 JWT Configuration File
**Location**: [`config/packages/lexik_jwt_authentication.yaml`](config/packages/lexik_jwt_authentication.yaml)

```yaml
lexik_jwt_authentication:
    secret_key: '%jwt_secret_key%'           # Path to private RSA key
    public_key: '%jwt_public_key%'           # Path to public RSA key
    pass_phrase: '%env(JWT_PASSPHRASE)%'     # Passphrase for encrypted private key
    
    token_ttl: 3600                          # 1 hour access token lifetime
    clock_skew: 0                            # No clock tolerance
    
    token_extractors:
        authorization_header:
            enabled: true                    # Primary extraction method
            prefix: Bearer                   # Expects "Bearer <token>"
        query_parameter:
            enabled: false                   # Disabled (less secure)
        cookie:
            enabled: false                   # Disabled
```

### 1.2 Key Material Configuration
**Location**: [`config/services.yaml`](config/services.yaml)

```yaml
parameters:
    jwt_secret_key: '%kernel.project_dir%/config/jwt/private.pem'  # Encrypted RSA private key
    jwt_public_key: '%kernel.project_dir%/config/jwt/public.pem'   # RSA public key (for validation)
    jwt_passphrase: '%env(JWT_PASSPHRASE)%'                        # SSH passphrase from environment
    jwt_token_ttl: '%env(int:JWT_TOKEN_TTL)%'                      # Default: 3600 seconds
    jwt_refresh_token_ttl: '%env(int:JWT_REFRESH_TOKEN_TTL)%'      # Default: 2592000 seconds (30 days)
```

**Key Details**:
- **Algorithm**: RS256 (RSA with SHA-256)
- **Key Type**: 2048-bit or 4096-bit RSA key pair
- **Key Encryption**: Private key protected with SSH passphrase (must be set in `.env`)
- **Key Location**: `config/jwt/` directory (must exist and be readable)

### 1.3 Security Firewall Configuration
**Location**: [`config/packages/security.yaml`](config/packages/security.yaml)

```yaml
security:
    firewalls:
        main:
            lazy: true
            provider: app_user_provider                    # Load users from database
            entry_point: App\Security\LoginFormAuthenticator
            custom_authenticators:
                - App\Security\JwtAuthenticator            # JWT validation (NEW)
                - App\Security\GoogleAuthenticator         # OAuth (existing)
                - App\Security\LoginFormAuthenticator      # Form-based login (existing)
            
            user_checker: App\Security\UserChecker         # Pre/post-auth validation
            two_factor:
                auth_form_path: 2fa_login
                check_path: 2fa_login_check                # Handle 2FA verification
                prepare_on_login: true                     # Trigger 2FA after login attempt
                enable_csrf: true
```

**Firewall Authentication Chain**:
1. Request arrives with credentials or JWT token
2. Symfony tries authenticators in order (Jwt → Google → LoginForm)
3. If JWT found and valid → authenticate user
4. If JWT missing → try form credentials
5. After successful auth → trigger 2FA if enabled for user
6. After 2FA passes → generate JWT tokens

### 1.4 Access Control Rules
**Location**: [`config/packages/security.yaml`](config/packages/security.yaml#L49-L67)

```yaml
access_control:
    # Public endpoints (no JWT required)
    - { path: ^/api/auth/login, roles: PUBLIC_ACCESS }        # ⚠️ NOT IMPLEMENTED YET
    - { path: ^/api/auth/register, roles: PUBLIC_ACCESS }     # ⚠️ NOT IMPLEMENTED YET
    - { path: ^/api/auth/refresh, roles: PUBLIC_ACCESS }      # ⚠️ NOT IMPLEMENTED YET
    
    # Protected endpoints (JWT required)
    - { path: ^/api/auth/token, roles: ROLE_USER }           # ⚠️ NOT IMPLEMENTED YET
    - { path: ^/api/auth/me, roles: ROLE_USER }              # ⚠️ NOT IMPLEMENTED YET
    - { path: ^/api/auth/logout, roles: ROLE_USER }          # ⚠️ NOT IMPLEMENTED YET
    
    # 2FA endpoints
    - { path: ^/2fa/(setup|verify|disable), roles: ROLE_USER }  # User setup/management
    - { path: ^/2fa, roles: PUBLIC_ACCESS }                     # Initial 2FA form
    
    # Existing endpoints (session-based)
    - { path: ^/login$, roles: PUBLIC_ACCESS }
    - { path: ^/register, roles: PUBLIC_ACCESS }
    - { path: ^/profile, roles: ROLE_USER }
    - { path: ^/admin, roles: [ROLE_ADMIN, ROLE_SUPER_ADMIN] }
```

**⚠️ CRITICAL FINDING**: API JWT endpoints (`/api/auth/*`) are defined in `access_control` but **have no corresponding controllers**. These are planned but not yet implemented.

---

## PART 2: JWT GENERATION FLOW

### 2.1 JWT Token Service
**Location**: [`src/Service/JwtTokenService.php`](src/Service/JwtTokenService.php)

**Initialization**:
```php
public function __construct(
    ParameterBagInterface $parameterBag,
    LoggerInterface $logger,
    int $tokenTtl = 3600,
    int $refreshTokenTtl = 2592000
) {
    // Load encrypted private key from file using OpenSSL
    $keyPath = 'file://' . realpath($secretKeyPath);
    $privateKeyResource = @openssl_pkey_get_private($keyPath, $passphrase);
    
    // Store as OpenSSL resource for Firebase JWT
    $this->privateKey = $privateKeyResource;
}
```

**Service State Management**:
- `$isEnabled`: Boolean flag - set to `false` if keys are missing or unreadable
- `$privateKey`: OpenSSL key resource used for signing
- `$tokenTtl`: Access token lifetime (3600 seconds = 1 hour)
- `$refreshTokenTtl`: Refresh token lifetime (2592000 seconds = 30 days)

### 2.2 Access Token Generation
**Method**: `generateAccessToken(User $user)`

**Payload Structure**:
```json
{
  "iat": 1709990400,                    // Issued-at timestamp (current time)
  "exp": 1709994000,                    // Expiration timestamp (iat + 3600)
  "sub": 5,                             // Subject (user ID) - JWT standard
  "user_id": 5,                         // Redundant user ID for compatibility
  "email": "user@example.com",          // User email
  "roles": ["ROLE_USER", "ROLE_ADMIN"], // String array of roles
  "name": "John Doe",                   // Full name (firstName + lastName)
  "type": "access"                      // Token type indicator
}
```

**Payload Details**:
- **iat** (Issued At): Unix timestamp when token was created
- **exp** (Expiration): Unix timestamp when token becomes invalid
- **sub** (Subject): User ID (standard JWT claim)
- **user_id**: Duplicate of subject (custom claim for clarity)
- **email**: User's email address for identification
- **roles**: Array of Symfony security roles (e.g., `["ROLE_USER"]`)
- **name**: Full name derived from `User::getFullName()` (firstName + lastName)
- **type**: Custom flag to distinguish from refresh tokens

**Encoding**:
```php
$token = JWT::encode($payload, $this->privateKey, 'RS256');
```
- Uses Firebase\JWT\JWT::encode()
- RSA256 algorithm for signing
- Private key resource does the signing

### 2.3 Refresh Token Generation
**Method**: `generateRefreshToken(User $user)`

**Payload Structure** (lighter than access token):
```json
{
  "iat": 1709990400,
  "exp": 1712582400,                    // 30 days from now
  "sub": 5,
  "user_id": 5,
  "email": "user@example.com",
  "type": "refresh"                     // Distinguishes from access tokens
}
```

**Why Separate?**
- Refresh tokens have longer TTL (30 days vs 1 hour)
- Minimal payload reduces token size
- If access token compromised, refresh token still valid
- Refresh tokens NOT validated by `JwtAuthenticator` (currently)

### 2.4 Token Pair Generation
**Method**: `generateTokenPair(User $user) → array`

**Response Format**:
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 5,
    "email": "user@example.com",
    "name": "John Doe",
    "roles": ["ROLE_USER"]
  }
}
```

### 2.5 During-Login Token Generation
**Location**: [`src/EventSubscriber/JwtGenerationSubscriber.php`](src/EventSubscriber/JwtGenerationSubscriber.php)

**Event Trigger**: `InteractiveLoginEvent` (fires after ANY successful login)

**Flow**:
```
1. User submits login form
2. LoginFormAuthenticator validates credentials + 2FA
3. Session created, authentication token set
4. InteractiveLoginEvent fired by Symfony
5. JwtGenerationSubscriber::onInteractiveLogin() called
6. JwtTokenService::generateTokenPair() called
7. Token pair stored in SESSION:
   - $_SESSION['jwt_access_token'] = "..."
   - $_SESSION['jwt_refresh_token'] = "..."
   - $_SESSION['jwt_token_data'] = {...}
```

**Code Flow**:
```php
public function onInteractiveLogin(InteractiveLoginEvent $event): void
{
    $user = $event->getAuthenticationToken()->getUser();
    
    // Only generate if service enabled and keys available
    if (!$this->jwtTokenService->isEnabled()) {
        return;
    }
    
    // Generate both tokens
    $tokenPair = $this->jwtTokenService->generateTokenPair($user);
    
    // Store in session for frontend retrieval
    $session = $event->getRequest()->getSession();
    $session->set('jwt_access_token', $tokenPair['access_token']);
    $session->set('jwt_refresh_token', $tokenPair['refresh_token']);
    $session->set('jwt_token_data', [
        'access_token' => $tokenPair['access_token'],
        'refresh_token' => $tokenPair['refresh_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokenPair['expires_in'],
        'user' => $tokenPair['user'],
    ]);
}
```

**Key Characteristics**:
- Runs AFTER successful form login AND 2FA verification
- Doesn't fail the login if JWT generation fails (error just logged)
- Tokens stored in session, not in cookies
- Frontend must explicitly retrieve tokens from session via API

---

## PART 3: JWT VALIDATION & AUTHENTICATION FLOW

### 3.1 JWT Authenticator
**Location**: [`src/Security/JwtAuthenticator.php`](src/Security/JwtAuthenticator.php)

**Initialization**:
```php
public function __construct(
    UserRepository $userRepository,
    LoggerInterface $logger,
    string $jwtPublicKey  // Injected from DI container
) {
    // Gracefully handle missing key file
    if (!file_exists($jwtPublicKey)) {
        $this->isEnabled = false;
        // JWT authentication disabled
        return;
    }
    
    // Load public key file content
    $keyContent = @file_get_contents($jwtPublicKey);
    $this->jwtPublicKey = $keyContent;
}
```

### 3.2 Token Support Detection
**Method**: `supports(Request $request) → bool`

```php
public function supports(Request $request): ?bool
{
    if (!$this->isEnabled) {
        return false;  // Skip if authenticator not initialized
    }
    
    // Check for Authorization header with "Bearer " prefix
    return $request->headers->has('Authorization') &&
           str_starts_with($request->headers->get('Authorization'), 'Bearer ');
}
```

**When Activated**:
- Request has `Authorization: Bearer eyJ0eXAi...` header
- `JwtAuthenticator` is first in the custom_authenticators chain
- Request is to API endpoint (but NOT enforced - can be used anywhere)

### 3.3 Token Decoding & Validation
**Method**: `authenticate(Request $request) → Passport`

```php
public function authenticate(Request $request): Passport
{
    // 1. Extract token from Authorization header
    $authHeader = $request->headers->get('Authorization');
    $token = substr($authHeader, 7);  // Remove "Bearer "
    
    // 2. Decode JWT using public key
    try {
        $decoded = JWT::decode(
            $token,
            new Key($this->jwtPublicKey, 'RS256')  // Firebase JWT Key object
        );
    } catch (\Exception $e) {
        // Token invalid, expired, or malformed
        throw new CustomUserMessageAuthenticationException('Invalid JWT token');
    }
    
    // 3. Extract user ID from JWT payload
    $userId = $decoded->sub ?? $decoded->user_id ?? null;
    if (!$userId) {
        throw new CustomUserMessageAuthenticationException('Invalid token: no user ID');
    }
    
    // 4. Load user from database using UserBadge
    return new Passport(
        new UserBadge(
            (string)$userId,
            function ($userId) {
                $user = $this->userRepository->find((int)$userId);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('User not found');
                }
                return $user;
            }
        )
    );
}
```

**Validation Steps**:
1. **Extract**: Parse `Authorization: Bearer <token>`
2. **Decode**: Use Firebase\JWT\JWT::decode() with RS256 algorithm
3. **Signature Verification**: Public key validates private key signature
4. **Expiration Check**: Firebase JWT library checks `exp` claim
5. **User Lookup**: Load user from database by ID
6. **User Status**: `UserChecker` validates user isn't blocked (see §3.5)

**Validation Failures** (all throw `CustomUserMessageAuthenticationException`):
- Missing Authorization header → not supported by this authenticator
- Invalid token format → decode fails
- Expired token (exp < current_time) → decode fails
- Malformed JWT → decode fails
- User ID missing → rejected
- User not found in DB → rejected
- User blocked (status = 'BLOCKED') → UserChecker rejects

### 3.4 On Success / On Failure Handlers

**On Success**:
```php
public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
{
    // Return null = let request continue to controller
    // (Unlike session auth, which might redirect)
    return null;
}
```

**On Failure**:
```php
public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
{
    return new JsonResponse([
        'error' => 'Authentication failed',
        'message' => $exception->getMessageKey()
    ], 401);
}
```

### 3.5 User Checker (Pre & Post Auth)
**Location**: [`src/Security/UserChecker.php`](src/Security/UserChecker.php)

**Pre-Authentication Check** (`checkPreAuth()`):
```php
public function checkPreAuth(UserInterface $user): void
{
    if (!$user instanceof User) {
        return;
    }
    
    // 1. Check if user account is blocked
    if ($user->isBlocked()) {
        throw new CustomUserMessageAccountStatusException(
            'Your account has been blocked. Please contact support.'
        );
    }
    
    // 2. Check facial recognition (if user has registered face data)
    if ($user->getDataFaceApi()) {
        $clientToken = $request->get('tokenFaceRecognition');
        $sessionToken = $this->requestStack->getSession()->get('tokenFaceRecognition');
        
        if (!$clientToken || !$sessionToken || $clientToken !== $sessionToken) {
            throw new CustomUserMessageAccountStatusException(
                'Facial recognition verification required or invalid.'
            );
        }
    }
}
```

**Post-Authentication Check** (`checkPostAuth()`):
```php
public function checkPostAuth(UserInterface $user): void
{
    // Clean up face auth session token after successful login
    if ($this->requestStack->getSession()->has('tokenFaceRecognition')) {
        $this->requestStack->getSession()->remove('tokenFaceRecognition');
    }
}
```

---

## PART 4: 2FA & JWT INTERSECTION

### 4.1 2FA Configuration
**Location**: [`config/packages/scheb_2fa.yaml`](config/packages/scheb_2fa.yaml)

```yaml
scheb_two_factor:
    security_tokens:
        - Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken
        - Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken
    
    google:
        enabled: true
        server_name: PHARMAX
        issuer: PHARMAX
        template: security/2fa_form.html.twig
        digits: 6
```

**2FA Provider**: Google Authenticator (TOTP - Time-based One-Time Password)

### 4.2 User 2FA State
**Location**: [`src/Entity/User.php`](src/Entity/User.php#L70-L90)

```php
/**
 * Google Authenticator secret for 2FA.
 * Null = 2FA disabled.
 */
#[ORM\Column(length: 255, nullable: true, name: 'google_authenticator_secret')]
private ?string $googleAuthenticatorSecret = null;

/**
 * Temporary secret during 2FA setup.
 * Stored to survive session regeneration.
 * Cleared after verification or cancellation.
 */
#[ORM\Column(length: 255, nullable: true, name: 'google_authenticator_secret_pending')]
private ?string $googleAuthenticatorSecretPending = null;

/**
 * Flag tracking if 2FA setup is in progress.
 */
#[ORM\Column(type: 'boolean', name: 'is_2fa_setup_in_progress', options: ['default' => false])]
private bool $is2faSetupInProgress = false;

// Implemented from TwoFactorInterface
public function isTwoFactorAuthenticationEnabled(): bool
{
    // 2FA enabled if secret exists and is not empty
    return !empty($this->googleAuthenticatorSecret);
}

public function isGoogleAuthenticatorEnabled(): bool
{
    return !empty($this->googleAuthenticatorSecret);
}

public function getGoogleAuthenticatorSecret(): ?string
{
    return $this->googleAuthenticatorSecret;
}

public function getGoogleAuthenticatorUsername(): string
{
    return $this->email ?? 'User';
}
```

### 4.3 2FA Authentication Flow (With JWT)

```
USER LOGIN SEQUENCE:
├─ 1. User submits email/password to /login (POST)
├─ 2. LoginFormAuthenticator validates credentials
│  └─ UserChecker checks: not blocked, face auth OK
├─ 3. IF 2FA ENABLED for this user:
│  ├─ [PARTIAL AUTHENTICATION STATE]
│  ├─ Redirect to 2FA form (/2fa)
│  ├─ User submits 6-digit code
│  ├─ SchebTwoFactorBundle verifies TOTP against googleAuthenticatorSecret
│  └─ JWT tokens NOT YET generated
│
├─ 4. After 2FA verification succeeds:
│  ├─ Authentication token promoted to full auth
│  ├─ InteractiveLoginEvent fired
│  └─ JwtGenerationSubscriber generates token pair
│     ├─ generateAccessToken() → 1-hour JWT
│     ├─ generateRefreshToken() → 30-day JWT
│     └─ Store both in $_SESSION
│
├─ 5. JWT Stored in Session:
│  ├─ $_SESSION['jwt_access_token'] = "eyJ0eXAi..."
│  ├─ $_SESSION['jwt_refresh_token'] = "eyJ0eXAi..."
│  └─ $_SESSION['jwt_token_data'] = {...}
│
└─ 6. User redirected to:
   ├─ /admin (if ROLE_ADMIN or ROLE_SUPER_ADMIN)
   └─ /profile (if ROLE_USER)
```

### 4.4 Key Properties of 2FA × JWT Integration

| Property | Value |
|----------|-------|
| **2FA Timing** | BEFORE JWT generation |
| **JWT Status on 2FA Start** | NOT generated yet |
| **When JWT Created** | After full auth (2FA passes) |
| **2FA Required for JWT?** | YES (if enabled for user) |
| **JWT Includes 2FA State?** | NO (not in payload) |
| **Refresh Token & 2FA** | Independent (refresh token doesn't re-verify 2FA) |

**Critical Implication**: A stolen refresh token could be used to get a new access token without re-verifying 2FA. This is standard OAuth2 behavior but worth noting for security.

### 4.5 Blocked User Flow
**Location**: [`src/EventSubscriber/BlockedUserSubscriber.php`](src/EventSubscriber/BlockedUserSubscriber.php)

```php
public function onKernelRequest(RequestEvent $event): void
{
    $token = $this->tokenStorage->getToken();
    $user = $token->getUser();
    
    if ($user->isBlocked()) {
        // Invalidate existing token/session
        $this->tokenStorage->setToken(null);
        $session = $event->getRequest()->getSession();
        if ($session) {
            $session->invalidate();  // Clears JWT from session
        }
        
        // Redirect to login
        $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
        $event->setResponse($response);
    }
}
```

**When Triggered**: On every request (KernelEvents::REQUEST)

**Effect on JWT Tokens**:
- Session tokens cleared
- Access token still technically valid (signature is correct)
- Refresh token still technically valid
- BUT user redirect to login prevents token use

**Gap**: JWT tokens in circulation are NOT invalidated server-side. If attacker obtains JWT and user is blocked, the JWT can still be used until expiration.

---

## PART 5: USER ENTITY & JWT-RELEVANT PROPERTIES

### 5.1 Core User Properties

| Property | Type | JWT Included? | Notes |
|----------|------|---------------|-------|
| `id` | int (PK) | **YES** (`sub`, `user_id`) | Maps to JWT subject |
| `email` | string (unique) | **YES** | User identifier |
| `roles` | JSON array | **YES** | Symfony security roles |
| `password` | string (hashed) | **NO** | Never in JWT (security best practice) |
| `firstName` | string | **YES** (in `name`) | Part of `getFullName()` |
| `lastName` | string | **YES** (in `name`) | Part of `getFullName()` |
| `status` | enum (BLOCKED/UNBLOCKED) | **NO** | Checked during auth, not in JWT |

### 5.2 2FA-Related Properties

| Property | Type | Purpose |
|----------|------|---------|
| `googleAuthenticatorSecret` | string (255) nullable | Confirmed 2FA secret |
| `googleAuthenticatorSecretPending` | string (255) nullable | Temporary secret during setup |
| `is2faSetupInProgress` | boolean | Setup state flag |

### 5.3 OAuth & Account Properties

| Property | Type | Purpose |
|----------|------|---------|
| `googleId` | string (255) nullable | Google OAuth identifier |
| `avatar` | string (255) nullable | User profile picture URL |
| `dataFaceApi` | text nullable | Face recognition enrollment data |
| `createdAt` | datetime nullable | Account creation timestamp |
| `updatedAt` | datetime nullable | Account last update timestamp |

### 5.4 Custom Methods for JWT

```php
public function getFullName(): string
{
    return $this->firstName . ' ' . $this->lastName;  // Used in JWT 'name' claim
}

public function isBlocked(): bool
{
    return $this->status === self::STATUS_BLOCKED;   // Checked by UserChecker
}

public function getRoles(): array
{
    $roles = $this->roles;
    $roles[] = 'ROLE_USER';  // Every user has ROLE_USER
    return array_unique($roles);  // Included in JWT
}
```

---

## PART 6: CURRENT IMPLEMENTATION STATUS

### 6.1 ✅ IMPLEMENTED & WORKING

- **JWT Generation**: Access tokens (1-hour) and refresh tokens (30-day)
- **Token Storage**: JWT stored in session after login
- **JWT Validation**: RS256 signature verification with public key
- **User Lookup**: User loaded from DB by ID claim
- **2FA Integration**: 2FA required before JWT generation
- **User Status Checks**: Blocked users cannot authenticate
- **Facial Recognition**: Face auth token verification (separate from JWT)
- **Firewall Configuration**: JwtAuthenticator in security chain

### 6.2 ⚠️ PARTIALLY IMPLEMENTED

- **Refresh Token Mechanism**: Token generated but no endpoint to use it
  - `generateRefreshToken()` works
  - No controller handling POST /api/auth/refresh
  
- **Token Extraction**: Authorization header extraction works
  - Query parameter extraction disabled
  - Cookie extraction disabled

### 6.3 ❌ NOT IMPLEMENTED

**API Endpoints (defined in `access_control` but no controllers)**:

| Endpoint | Method | Purpose | Status |
|----------|--------|---------|--------|
| `/api/auth/login` | POST | API-based login (without session) | **NOT IMPLEMENTED** |
| `/api/auth/register` | POST | API registration | **NOT IMPLEMENTED** |
| `/api/auth/token` | GET | Get JWT for session user | **NOT IMPLEMENTED** |
| `/api/auth/refresh` | POST | Refresh access token with refresh token | **NOT IMPLEMENTED** |
| `/api/auth/me` | GET | Get current user info | **NOT IMPLEMENTED** |
| `/api/auth/logout` | POST | Logout (invalidate tokens) | **NOT IMPLEMENTED** |

**Token Invalidation Server-Side**:
- JWT tokens are NOT tracked or blacklisted
- Once signed, valid until expiration
- Blocked users' tokens remain valid (only caught by UserChecker on subsequent requests)
- No token revocation mechanism

**Refresh Token Validation**:
- Refresh token generated but `type: 'refresh'` not validated by `JwtAuthenticator`
- `JwtAuthenticator` accepts any valid RS256 JWT regardless of type
- Could be exploited: access token could be used as refresh token

---

## PART 7: KEY MATERIAL & STORAGE

### 7.1 Key Generation & Location

**Private Key**:
- **Path**: `config/jwt/private.pem`
- **Format**: RSA private key (likely 2048 or 4096 bits)
- **Encryption**: Protected with `JWT_PASSPHRASE` from `.env`
- **Access**: Must be readable by PHP-FPM/web server process
- **Backup**: MUST be backed up securely (single point of failure)

**Public Key**:
- **Path**: `config/jwt/public.pem`
- **Format**: RSA public key (extracted from private key)
- **Access**: Can be public (only used for verification)
- **Rotation**: Must be deployed with new code if rotated

### 7.2 Key Initialization Code

**Private Key Loading** (in `JwtTokenService`):
```php
$keyPath = 'file://' . realpath($secretKeyPath);
$privateKeyResource = @openssl_pkey_get_private($keyPath, $passphrase);

if ($privateKeyResource === false) {
    $openSSLError = openssl_error_string();
    $this->logger->error('Failed to load JWT private key: ' . $openSSLError);
    $this->isEnabled = false;
    return;
}

$this->privateKey = $privateKeyResource;
```

**Public Key Loading** (in `JwtAuthenticator`):
```php
if (!file_exists($jwtPublicKey)) {
    $this->isEnabled = false;
    $this->logger->warning('JWT public key file not found');
    return;
}

$keyContent = @file_get_contents($jwtPublicKey);
if ($keyContent === false) {
    $this->isEnabled = false;
    $this->logger->warning('Unable to read JWT public key');
    return;
}

$this->jwtPublicKey = $keyContent;
```

### 7.3 Graceful Degradation

Both `JwtTokenService` and `JwtAuthenticator` have `isEnabled` flags:
- If keys missing/unreadable → disable JWT, don't crash
- Logging warnings to identify problems
- System falls back to session-based auth
- Error: "PHP bin/console app:generate-jwt-keys" suggests a command exists (not verified)

---

## PART 8: SECURITY ANALYSIS & FINDINGS

### 8.1 Strengths ✅

1. **RS256 Algorithm**: Asymmetric cryptography (public key cannot forge signatures)
2. **Token TTL**: 1 hour is reasonable for access tokens
3. **Refresh Token Separation**: 30-day refresh tokens allow token rotation
4. **2FA Before JWT**: Ensures JWT issued only after multi-factor verification
5. **User Status Validation**: Blocked users cannot authenticate
6. **Graceful Error Handling**: Missing keys don't crash application
7. **Payload Contains Roles**: Authorization can be enforced from JWT claims

### 8.2 Weaknesses & Gaps ⚠️

1. **NO TOKEN BLACKLIST**:
   - Revocation impossible (except time-based expiration)
   - Blocked users' tokens remain valid until expiration
   - Compromised tokens cannot be invalidated

2. **REFRESH TOKEN NOT VALIDATED**:
   - `JwtAuthenticator` doesn't check `type: 'refresh'` claim
   - Refresh tokens can be used as access tokens
   - Access tokens can be used as refresh tokens
   - No endpoint to enforce refresh token flow

3. **NO TOKEN ROTATION**:
   - No automatic token refresh on access
   - If access token compromised, attacker has hour of access
   - Weak rotation compared to OAuth2 best practices

4. **PASSWORD HASH NOT VERIFIED**:
   - JWT only validated by signature, not re-checking password
   - If password compromised, existing token still valid
   - Can't "logout elsewhere" effectively

5. **NO TOKEN IN COOKIES**:
   - Session-stored tokens require frontend to manage/send Bearer header
   - OR frontend must read tokens from session (creates CSRF risk if not careful)
   - OR API must provide token endpoint (not implemented)

6. **MISSING API ENDPOINTS**:
   - `/api/auth/*` endpoints not implemented
   - Unclear how frontend is supposed to provide tokens
   - Comment says "GetJWT via GET /api/auth/token" but endpoint doesn't exist

7. **CLOCK SKEW = 0**:
   - No tolerance for time differences between servers
   - Could cause issues in distributed systems
   - No leeway for clock drift

8. **SIMPLIFIED PAYLOAD**:
   - No `jti` (JWT ID) → prevents token tracking/revocation
   - No `aud` (audience) → could be used in multiple applications
   - No `iss` (issuer) → could be reused by other services

### 8.3 2FA & JWT-Specific Findings

| Finding | Severity | Impact |
|---------|----------|--------|
| 2FA required before JWT ✅ | N/A (secure) | Good: ensures JWT issued only to authenticated users |
| 2FA status not in JWT ⚠️ | Medium | Frontend can't verify 2FA without re-querying user |
| Refresh token bypasses 2FA ⚠️ | High | User can use refresh token without re-verifying 2FA |
| No token revocation ❌ | High | Compromised tokens valid for 1 hour (access) or 30 days (refresh) |
| Blocked users not invalidated ❌ | High | Blocked user's tokens remain valid until expiration |

---

## PART 9: ENVIRONMENT REQUIREMENTS

### 9.1 Required Environment Variables

```bash
# .env or .env.local

JWT_PASSPHRASE=your_rsa_key_passphrase_here          # OpenSSL passphrase for private key
JWT_TOKEN_TTL=3600                                    # Access token lifetime (seconds)
JWT_REFRESH_TOKEN_TTL=2592000                         # Refresh token lifetime (seconds)
```

### 9.2 System Requirements

- PHP with OpenSSL extension (`ext-openssl`)
- Firebase JWT library: `firebase/php-jwt` (composer dependency)
- RSA key pair files in `config/jwt/`:
  - `private.pem` (readable, protected)
  - `public.pem` (readable)

---

## PART 10: FLOW DIAGRAMS

### 10.1 Session-Based Login → JWT Generation

```
┌─────────────────┐
│  User submits   │
│  login form     │
└────────┬────────┘
         │
         ▼
┌──────────────────────────────────┐
│ LoginFormAuthenticator::         │
│ - Validate email/password        │
│ - Load user from DB              │
└────────┬─────────────────────────┘
         │
         ▼
┌──────────────────────────────────┐
│ UserChecker::checkPreAuth()      │
│ - Check user not blocked         │
│ - Check facial recognition      │
└────────┬─────────────────────────┘
         │
         ▼
    IF 2FA ENABLED?
    │
    ├─ YES → /2fa form → verify TOTP → onAuthenticationSuccess
    │
    └─ NO → proceed to onAuthenticationSuccess
              │
              ▼
         ┌──────────────────────────────────┐
         │ InteractiveLoginEvent fired      │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ JwtGenerationSubscriber::        │
         │ onInteractiveLogin()             │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ JwtTokenService::                │
         │ generateTokenPair(user)          │
         │ - generateAccessToken()  (1hr)   │
         │ - generateRefreshToken() (30d)   │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ Store in SESSION:                │
         │ - jwt_access_token               │
         │ - jwt_refresh_token              │
         │ - jwt_token_data                 │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ Redirect to:                     │
         │ - /admin (if admin)              │
         │ - /profile (if user)             │
         └──────────────────────────────────┘
```

### 10.2 JWT-Based Request Validation

```
┌─────────────────────────────────────┐
│ Incoming Request with              │
│ Authorization: Bearer <jwt_token>  │
└────────┬────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ JwtAuthenticator::supports()        │
│ Check: Bearer prefix exists?        │
└────────┬─────────────────────────────┘
         │
         ├─ NO → return false (skip JWT auth)
         │       → try other authenticators
         │
         └─ YES ▼
         ┌──────────────────────────────────┐
         │ JwtAuthenticator::authenticate() │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ Extract token from header        │
         │ Remove "Bearer " prefix          │
         └────────┬─────────────────────────┘
                  │
                  ▼
         ┌──────────────────────────────────┐
         │ JWT::decode(token,               │
         │   publicKey, 'RS256')            │
         │ - Verify signature               │
         │ - Check expiration (exp)         │
         │ - Parse claims                   │
         └────────▼─────────────────────────┘
                  │
      ┌───────────┴───────────┐
      │ Decoding failed?      │
      └────┬──────────┬───────┘
           │          │
        YES│          │NO
           │          │
           ▼          ▼
    ┌─────────────┐ ┌──────────────────────┐
    │ Return 401  │ │ Extract user_id from │
    │ "Invalid    │ │ decoded.sub or       │
    │ JWT token"  │ │ decoded.user_id      │
    └─────────────┘ └────────┬─────────────┘
                             │
                             ▼
                    ┌──────────────────────┐
                    │ Load user from DB    │
                    │ by ID                │
                    └────────┬─────────────┘
                             │
                    ┌────────┴────────┐
                    │ User found?     │
                    └────┬──────┬─────┘
                         │      │
                      NO │      │ YES
                         │      │
                         ▼      ▼
                    ┌────────┐ ┌──────────────────┐
                    │Return │ │ UserChecker::    │
                    │401    │ │ checkPreAuth()   │
                    │"User  │ │ - Check blocked  │
                    │not    │ │ - Check face auth│
                    │found" │ └─────┬────────────┘
                    └────────┘      │
                                    ▼
                           ┌──────────────────┐
                           │ Return Passport  │
                           │ (authenticated   │
                           │ user)            │
                           │ → Continue to    │
                           │ controller       │
                           └──────────────────┘
```

---

## CONCLUSION & RECOMMENDATIONS

### Current State
This Symfony application has a **functional JWT authentication system** built on solid fundamentals (RS256, 2FA integration, graceful error handling). However, it is **production-ready for session-based logins** but **incomplete for stateless API authentication** (missing endpoints).

### For Production Use

1. **Implement Missing API Endpoints** (HIGH PRIORITY):
   - `POST /api/auth/login` - API login without session
   - `POST /api/auth/refresh` - Refresh access token
   - `GET /api/auth/token` - Get JWT for session user
   - `GET /api/auth/me` - Get current user info
   - `POST /api/auth/logout` - Revoke tokens

2. **Add Token Blacklist** (HIGH PRIORITY):
   - Implement revocation mechanism for compromised tokens
   - Especially critical for refresh tokens (30-day life)

3. **Validate Refresh Token Type** (MEDIUM PRIORITY):
   - Check `type: 'refresh'` in `JwtAuthenticator`
   - Implement separate refresh endpoint

4. **Add Token Tracking (Optional)**:
   - Include `jti` (unique token ID) for tracking
   - Store issued tokens in cache/database
   - Enables better audit trails

5. **2FA Status in Token (Optional)**:
   - Add `2fa_verified: true/false` claim
   - Allows enforcing 2FA on sensitive operations

---

## APPENDIX: FILE REFERENCE

| File | Purpose | Status |
|------|---------|--------|
| [config/packages/lexik_jwt_authentication.yaml](config/packages/lexik_jwt_authentication.yaml) | JWT library config | ✅ Implemented |
| [config/packages/security.yaml](config/packages/security.yaml) | Firewall & access control | ✅ Implemented |
| [config/services.yaml](config/services.yaml) | Service definitions & parameters | ✅ Implemented |
| [src/Service/JwtTokenService.php](src/Service/JwtTokenService.php) | Token generation logic | ✅ Implemented |
| [src/Security/JwtAuthenticator.php](src/Security/JwtAuthenticator.php) | Token validation logic | ✅ Implemented |
| [src/Security/UserChecker.php](src/Security/UserChecker.php) | User pre/post-auth checks | ✅ Implemented |
| [src/Security/LoginFormAuthenticator.php](src/Security/LoginFormAuthenticator.php) | Form-based auth | ✅ Implemented |
| [src/EventSubscriber/JwtGenerationSubscriber.php](src/EventSubscriber/JwtGenerationSubscriber.php) | Token generation trigger | ✅ Implemented |
| [src/EventSubscriber/BlockedUserSubscriber.php](src/EventSubscriber/BlockedUserSubscriber.php) | Blocked user handling | ✅ Implemented |
| [src/Entity/User.php](src/Entity/User.php) | User model with 2FA support | ✅ Implemented |
| [config/jwt/private.pem](config/jwt/private.pem) | RSA private key | ⚠️ Must exist |
| [config/jwt/public.pem](config/jwt/public.pem) | RSA public key | ⚠️ Must exist |

---

**Report Generated**: March 5, 2026  
**Sources**: Direct codebase analysis (PHP, YAML configs only)  
**Analyst**: Senior Security Architect (JWT Specialist)
