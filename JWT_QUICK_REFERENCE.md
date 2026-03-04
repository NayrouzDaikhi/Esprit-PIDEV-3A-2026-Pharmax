# JWT Authentication - Quick Reference Guide

## ✅ How It Works Now (After Fixes)

### Firewall Authentication Chain
```
Request arrives
    ↓
JwtAuthenticator.supports() checks if Bearer token exists
    ├─ YES (Bearer token) → JwtAuthenticator.authenticate() validates token ✓
    └─ NO (no Bearer token) → returns false, delegates to next authenticator
    ↓
GoogleAuthenticator checks if OAuth flow
    ├─ YES (OAuth) → authenticates via Google ✓
    └─ NO → delegates to next authenticator
    ↓
LoginFormAuthenticator checks if form submission
    ├─ YES (form login) → authenticates with credentials ✓
    └─ NO → delegates to RememberMeAuthenticator
    ↓
RememberMeAuthenticator checks for remember-me cookie
    ├─ YES (valid cookie) → authenticates from cookie ✓
    └─ NO → request is anonymous
```

### Error Handling
```
JwtAuthenticator.constructor()
├─ If keys missing → Log warning, disable JWT, return
├─ If keys unreadable → Log warning, disable JWT, return
└─ If keys valid → Set $jwtPublicKey, enable JWT

JwtAuthenticator.supports()
├─ If JWT disabled → Return false (skip to next authenticator)
└─ If Bearer token exists → Return true (try to authenticate)

JwtAuthenticator.authenticate()
├─ If JWT disabled → Throw exception (already caught in supports)
├─ If token invalid → Throw CustomUserMessageAuthenticationException
├─ If user not found → Throw CustomUserMessageAuthenticationException
└─ If token valid → Create Passport with User

JwtTokenService.generateAccessToken()
├─ If service disabled → Throw RuntimeException
├─ If keys missing → Throw RuntimeException
└─ If token valid → Return token string
```

---

## 🔧 Configuration Summary

### What Was Fixed

#### 1. JwtAuthenticator.php
```php
// BEFORE: Throws exception immediately (breaks site)
if (!file_exists($jwtPublicKey)) {
    throw new \RuntimeException('...');
}

// AFTER: Gracefully disables JWT
if (!file_exists($jwtPublicKey)) {
    $this->isEnabled = false;
    $this->logger->warning('...');
    return;
}
```

#### 2. JwtAuthenticator.supports()
```php
// BEFORE: No check for disabled JWT
public function supports(Request $request): ?bool
{
    return $request->headers->has('Authorization') && ...;
}

// AFTER: Checks if JWT is enabled
public function supports(Request $request): ?bool
{
    if (!$this->isEnabled) {
        return false; // Skip to next authenticator
    }
    return $request->headers->has('Authorization') && ...;
}
```

#### 3. JwtTokenService.php
```php
// BEFORE: Crashes if keys missing
$this->privateKey = file_get_contents($parameterBag->get('jwt_secret_key'));

// AFTER: Gracefully handles missing/unreadable keys
if (!file_exists($secretKeyPath)) {
    $this->isEnabled = false;
    $this->logger->warning('...');
    return;
}

$keyContent = @file_get_contents($secretKeyPath);
if ($keyContent === false) {
    $this->isEnabled = false;
    return;
}
$this->privateKey = $keyContent;
```

#### 4. services.yaml
```yaml
# BEFORE: Incorrect parameter names
App\Service\JwtTokenService:
    arguments:
        $jwtSecretKey: ...  # Doesn't match constructor
        $jwtPassphrase: ... # Doesn't match constructor

# AFTER: Correct parameter names
App\Service\JwtTokenService:
    arguments:
        $tokenTtl: '%jwt_token_ttl%'
        $refreshTokenTtl: '%jwt_refresh_token_ttl%'
```

---

## 🧪 Testing the Fixes

### Test 1: Verify Site Loads
```bash
# These should return HTML (not 500 error)
curl http://localhost:8000/login
curl http://localhost:8000/register
curl http://localhost:8000/
```

### Test 2: Test JWT Registration
```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "Password123",
    "firstName": "John",
    "lastName": "Doe"
  }'
```

Response:
```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refresh_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 1,
    "email": "test@example.com",
    "firstName": "John",
    "lastName": "Doe",
    "fullName": "John Doe",
    "roles": ["ROLE_USER"],
    "status": "active"
  }
}
```

### Test 3: Use JWT Token
```bash
HEADER="Authorization: Bearer eyJhbGciOi..."
curl -H "$HEADER" http://localhost:8000/api/auth/me
```

Response:
```json
{
  "id": 1,
  "email": "test@example.com",
  "firstName": "John",
  "lastName": "Doe",
  "fullName": "John Doe",
  "roles": ["ROLE_USER"],
  "status": "active"
}
```

### Test 4: Invalid Token
```bash
curl -H "Authorization: Bearer INVALID" http://localhost:8000/api/auth/me
```

Response (401 Unauthorized):
```json
{
  "error": "Authentication failed",
  "message": "Invalid JWT token"
}
```

### Test 5: Expired Token
Wait 1 hour or modify token to simulate expiration:
```bash
curl -H "Authorization: Bearer MODIFIED_TOKEN" http://localhost:8000/api/auth/me
```

Response (401 Unauthorized):
```json
{
  "error": "Authentication failed",
  "message": "Token has expired"
}
```

---

## 📊 System Architecture (After Fixes)

```
┌─────────────────────────────────────────────────────────────┐
│                    Your Application                          │
│                  (Symfony 6.4 Framework)                     │
└──────────────────────────┬──────────────────────────────────┘
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
    ┌───▼────┐        ┌───▼────┐        ┌───▼────┐
    │ Session│        │ Google │        │  JWT   │
    │  Auth  │        │ OAuth2 │        │  Auth  │
    │(forms) │        │        │        │        │
    └────────┘        └────────┘        └───┬────┘
        │                  │                  │
        └──────────────────┼──────────────────┘
                           │
                ┌──────────▼──────────┐
                │  SecurityFirewall   │
                │  (in order):        │
                │  1. JwtAuthenticator│
                │  2. GoogleAuth      │
                │  3. FormLoginAuth   │
                │  4. RememberMeAuth  │
                └──────────┬──────────┘
                           │
            ┌──────────────▼──────────────┐
            │   User Checker              │
            │ (UserChecker validates)     │
            │ • User exists               │
            │ • User not blocked          │
            │ • User not deleted          │
            └──────────────┬──────────────┘
                           │
                    ┌──────▼──────┐
                    │ LoadedUser  │
                    │  Request is │
                    │  processed  │
                    └─────────────┘
```

---

## 🔑 Key Concepts

### JWT Token Structure
```
eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature
│ Part 1: Header      │ Part 2: Payload    │ Part 3: Signature │
│ {alg: "RS256"...}   │ {sub: 1, exp:...}  │ base64encoded     │
```

### Token Lifecycle
- **Created**: POST /api/auth/login or /api/auth/register
- **Stored**: Client stores in localStorage or session storage
- **Used**: Sent in every API request: `Authorization: Bearer {token}`
- **Validated**: JwtAuthenticator verifies signature with public key
- **Expires**: After JWT_TOKEN_TTL (3600 seconds = 1 hour)
- **Refreshed**: POST /api/auth/refresh to get new token

### Security Models

#### Session-Based (Traditional)
- Server creates session after login
- Browser stores PHPSESSID cookie
- Cookie automatically sent with each request
- Session data stored server-side
- Vulnerable to CSRF without tokens

#### JWT-Based (Stateless)
- Server creates token after login
- Client stores token, must send in Authorization header
- No server-side session storage needed
- Good for mobile apps and microservices
- Vulnerable to XSS if stored in localStorage

#### OAuth2-Based (Google, GitHub, etc.)
- User redirected to provider (Google)
- Provider verifies credentials
- Provider redirects back with authorization code
- Server exchanges code for access token
- User is now authenticated

---

## 📝 Logging & Debugging

### JWT Authenticator Logs
Check `var/log/dev.log` for:

**Warning Logs:**
```log
JWT public key file not found: /path/to/public.pem. JWT authentication disabled.
JWT decode failed: Signature verification failed. Token: eyJhbGc...
JWT user not found in database: 123
```

**Error Logs:**
```log
Error initializing JWT authenticator: Could not read file
```

### JWT Service Logs
```log
Access token generated for user: test@example.com
Refresh token generated for user: test@example.com
Failed to generate access token: Key is not valid for this operation
```

---

## 🚀 Deployment Checklist

- [ ] Generate fresh keys in production: `php bin/console app:generate-jwt-keys`
- [ ] Set JWT environment variables in production `.env.local`
- [ ] Restrict CORS origins to your domain (not `*`)
- [ ] Use HTTPS only for JWT endpoints
- [ ] Add rate limiting to `/api/auth/login`
- [ ] Monitor `var/log/prod.log` for JWT errors
- [ ] Test email verification (if implemented)
- [ ] Test password reset flow
- [ ] Test token refresh flow with mobile app
- [ ] Load test authentication endpoints

---

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| 500 error on all pages | Check if JWT keys exist: `ls config/jwt/` |
| "Invalid JWT token" on valid token | Check if token expired (> JWT_TOKEN_TTL) |
| JWT endpoints return 404 | Run `php bin/console cache:clear` |
| Form login stops working | Check LoginFormAuthenticator in security.yaml |
| CORS errors on API | Check nelmio_cors.yaml allow_origin setting |
| Keys missing after deployment | Run `php bin/console app:generate-jwt-keys` |

---

## 📚 Additional Resources

- **JWT Standard**: https://tools.ietf.org/html/rfc7519
- **Symfony Security**: https://symfony.com/doc/current/security.html
- **Firebase JWT**: https://github.com/firebase/php-jwt
- **Lexik Bundle**: https://github.com/LexikJWT/LexikJWTAuthenticationBundle
- **JWT Decoder**: https://jwt.io (paste token to see claims)

---

**Last Updated:** 2026-02-25
**Status:** ✅ All Systems Green
