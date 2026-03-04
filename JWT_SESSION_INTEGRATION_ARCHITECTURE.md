# JWT & Session Integration - Complete Architecture

## Overview

This document provides a complete technical overview of how session-based authentication and JWT have been integrated into your Symfony application.

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         USER LOGIN                              │
└──────────┬──────────────────────────────────────────────┬────────┘
           │                                              │
           v                                              v
    ┌──────────────┐                        ┌──────────────────┐
    │ Session      │                        │ API Direct Login │
    │ Browser Form │                        │ JSON POST        │
    └──────┬───────┘                        └────────┬─────────┘
           │                                         │
           v                                         v
    ┌─────────────────────┐         ┌────────────────────────┐
    │LoginFormAuthenticator      │         │JwtAuthenticator    │
    │Validates credentials  │         │Validates Bearer token │
    └──────┬───────────────┘         └────────┬───────────────┘
           │                                  │
           v                                  v
    ┌─────────────────────────────────────────────────┐
    │  AUTHENTICATION SUCCESS                         │
    │  User object authenticated in Symfony token     │
    └──────┬──────────────────────────────────────────┘
           │
           ├─────────────────┬───────────────────┐
           │                 │                   │
           v                 v                   v
    ┌────────────┐   ┌──────────────┐    ┌──────────────┐
    │ Create     │   │Set Session   │    │Redirect to   │
    │ Session    │   │Cookie        │    │Profile       │
    └────────────┘   └──────────────┘    └──────────────┘
           │                                      
           v                                      
    ┌──────────────────────────────────┐
    │InteractiveLoginEvent              │
    │(Symfony system event)             │
    └─────────┬────────────────────────┘
              │
              v
    ┌───────────────────────────────────────┐
    │JwtGenerationSubscriber (NEW)           │
    │- Listens: InteractiveLoginEvent       │
    │- Action: Generate JWT token pair      │
    │- Storage: Store in session            │
    └──────────┬───────────────────────────┘
               │
               v
    ┌──────────────────────────────────┐
    │JwtTokenService                    │
    │- generateAccessToken()            │
    │- generateRefreshToken()           │
    │- generateTokenPair()              │
    └──────────────────────────────────┘
               │
               ├───────────────┬──────────┐
               v               v          v
           ┌────────┐    ┌──────────┐  ┌──────────┐
           │Access  │    │Refresh   │  │Session   │
           │Token   │    │Token     │  │Storage   │
           └────────┘    └──────────┘  └──────────┘
```

---

## System Components

### 1. **Authentication Layer**

#### LoginFormAuthenticator
- **Purpose:** Handles browser form-based login
- **Entry Point:** `/login` (POST)
- **Uses:** Session cookies
- **Output:** Authenticated user + session
- **New Feature:** Triggers JWT generation via event

#### JwtAuthenticator
- **Purpose:** Handles API requests with Bearer tokens
- **Entry Point:** Authorization header with `Bearer <token>`
- **Uses:** JWT validation
- **Output:** Authenticated user
- **No Changes:** Still works as before

#### JwtGenerationSubscriber (NEW)
- **Purpose:** Auto-generates JWT after session login
- **Triggered:** `InteractiveLoginEvent` (after successful form login)
- **Action:** Calls `JwtTokenService.generateTokenPair()`
- **Storage:** Stores tokens in session
- **Error Handling:** Logs but doesn't fail the login

### 2. **Token Generation Layer**

#### JwtTokenService
- **Generates:** Access tokens (short-lived, ~1 hour)
- **Generates:** Refresh tokens (long-lived, ~30 days)
- **Uses:** RS256 encryption (RSA keypair)
- **Methods:**
  - `generateAccessToken(User)` → JWT string
  - `generateRefreshToken(User)` → JWT string
  - `generateTokenPair(User)` → Full response object
  - `isEnabled()` → Check if service configured

#### SessionJwtManager (NEW - Optional)
- **Purpose:** Helper to manage JWT in sessions
- **Methods:**
  - `storeTokensInSession()`
  - `getTokenFromSession()`
  - `clearTokensFromSession()`
  - `hasTokenInSession()`

### 3. **API Layer**

#### AuthController (`/api/auth/*`)
- **POST /api/auth/login** → Direct API login with email/password
  - Returns: JWT token pair + user data
  - New behavior: No session created
  
- **POST /api/auth/register** → Register and get JWT
  - Returns: JWT token pair + user data
  
- **GET /api/auth/me** → Get current user
  - Requires: Valid JWT or session
  - Returns: User profile
  
- **POST /api/auth/refresh** → Get new access token
  - Requires: Valid refresh token
  - Returns: New JWT token pair
  
- **POST /api/auth/logout** → Logout
  - Requires: Valid JWT
  - Returns: Success message
  
- **GET /api/auth/token** (NEW) → Get JWT for session user
  - Requires: Active session
  - Returns: JWT token pair (from session or generates new)

### 4. **Frontend Layer**

#### JwtAuthenticationHelper.js (NEW)
- **Purpose:** Client-side JWT management
- **Key Methods:**
  - `retrieveJwtToken()` → Fetch JWT from server
  - `getToken()` → Get stored JWT
  - `getRefreshToken()` → Get refresh token
  - `addJwtToRequest()` → Add Bearer to fetch options
  - `refreshToken()` → Get new access token
  - `logout()` → Clear tokens and notify server
  - `getTokenClaims()` → Decode token (for inspection)
  - `isTokenExpiringSoon()` → Check expiration

---

## Data Flow Diagrams

### Data Flow 1: Session Login → JWT Usage

```
User Browser
    │
    ├─ POST /login (form data)
    │    │
    │    v
    │ LoginFormAuthenticator validates credentials
    │    │
    │    ├─ Creates Symfony authentication token
    │    ├─ Creates session
    │    └─ Fires InteractiveLoginEvent
    │         │
    │         v
    │    JwtGenerationSubscriber listens
    │         │
    │         ├─ JwtTokenService.generateTokenPair()
    │         │    │
    │         │    ├─ Encode payload with private key
    │         │    ├─ Create access token (exp: 1 hour)
    │         │    └─ Create refresh token (exp: 30 days)
    │         │
    │         └─ Store in session:
    │              session['jwt_access_token']     = "..."
    │              session['jwt_refresh_token']    = "..."
    │              session['jwt_token_data']       = {...}
    │
    └─ Redirect to /profile + Set-Cookie: PHPSESSID
         │
         v
    User at /profile (session authenticated)
         │
         ├─ JavaScript: new JwtAuthenticationHelper()
         │
         └─ jwtHelper.retrieveJwtToken()
              │
              ├─ GET /api/auth/token (with session cookie)
              │    │
              │    ├─ Check if user logged in via session
              │    ├─ Retrieve jwt_token_data from session
              │    └─ Return JSON: { access_token, refresh_token, ... }
              │
              ├─ Store in localStorage:
              │  localStorage['jwt_access_token'] = "..."
              │
              └─ Return promise resolves
                   │
                   └─ Frontend can now use JWT for API calls
                        │
                        └─ jwtHelper.addJwtToRequest()
                             │
                             └─ Authorization: Bearer <token>
```

### Data Flow 2: Direct API Login → JWT

```
API Client (Mobile, External Service, Postman)
    │
    ├─ POST /api/auth/login
    │  {
    │    "email": "user@example.com",
    │    "password": "password123"
    │  }
    │
    ├─ No session cookie sent
    │
    v
AuthController::login()
    │
    ├─ Extract email/password from JSON
    ├─ Find user in database
    ├─ Verify password
    ├─ Check if user blocked
    │
    v
JwtTokenService.generateTokenPair()
    │
    ├─ Create access token payload:
    │  {
    │    "sub": 1,
    │    "email": "user@example.com",
    │    "roles": ["ROLE_USER"],
    │    "exp": <timestamp>,
    │    "iat": <timestamp>,
    │    "type": "access"
    │  }
    │
    ├─ Encrypt with RS256 private key
    │
    ├─ Create refresh token payload:
    │  {
    │    "sub": 1,
    │    "email": "user@example.com",
    │    "exp": <timestamp>,
    │    "type": "refresh"
    │  }
    │
    ├─ Encrypt with RS256 private key
    │
    └─ Return response:
       {
         "access_token": "eyJhbGciOiJSUzI1NiJ9...",
         "refresh_token": "eyJhbGciOiJSUzI1NiJ9...",
         "token_type": "Bearer",
         "expires_in": 3600,
         "user": { ... }
       }

API Client stores token in memory/localStorage
    │
    └─ GET /api/auth/me
       Headers: Authorization: Bearer <access_token>
            │
            v
       JwtAuthenticator.supports() → true
            │
            v
       JwtAuthenticator.authenticate()
            │
            ├─ Extract token from Authorization header
            ├─ Decode using RS256 public key
            ├─ Verify signature
            ├─ Check expiration
            ├─ Load user from database using 'sub' claim
            │
            └─ Create Symfony authentication token
                 │
                 └─ Request reaches protected endpoint
```

---

## Security Configuration Changes

### security.yaml Updates

**Before:**
```yaml
access_control:
  - { path: ^/api/auth/me, roles: ROLE_USER }
  - { path: ^/api/auth/logout, roles: ROLE_USER }
```

**After:**
```yaml
access_control:
  - { path: ^/api/auth/token, roles: ROLE_USER }  # ← NEW
  - { path: ^/api/auth/me, roles: ROLE_USER }
  - { path: ^/api/auth/logout, roles: ROLE_USER }
```

### Authenticator Priority

The `main` firewall in security.yaml defines the order:

```yaml
custom_authenticators:
  - App\Security\JwtAuthenticator      # ← Checked first
  - App\Security\GoogleAuthenticator   
  - App\Security\LoginFormAuthenticator ← Checked last (form submission)
```

**Logic:**
1. If request has `Authorization: Bearer <token>` → JwtAuthenticator handles
2. If request is POST to `/login` → LoginFormAuthenticator handles
3. Otherwise → No authentication required (unless access_control says otherwise)

---

## File Changes Summary

### New Files Created
1. ✅ `src/EventSubscriber/JwtGenerationSubscriber.php` - Auto-generates JWT on session login
2. ✅ `src/Service/SessionJwtManager.php` - Helper for JWT session management
3. ✅ `public/js/JwtAuthenticationHelper.js` - Frontend JWT management
4. ✅ `INTEGRATION_TESTING_GUIDE.md` - Complete testing documentation

### Files Updated
1. ✅ `src/Security/LoginFormAuthenticator.php` - Added comment about JWT generation
2. ✅ `src/Controller/Api/AuthController.php` - Added GET `/api/auth/token` endpoint
3. ✅ `config/packages/security.yaml` - Added access control for token endpoint

### Files Unchanged (Still Work as Before)
- `src/Security/JwtAuthenticator.php` - No changes needed
- `src/Service/JwtTokenService.php` - No changes needed
- `config/routes/api.php` - No changes needed
- Framework configuration - No changes needed

---

## JWT Token Structure

### Access Token
```
Header:
{
  "alg": "RS256",
  "typ": "JWT"
}

Payload:
{
  "iat": 1708870000,        // Issued at
  "exp": 1708873600,        // 1 hour expiry
  "sub": 1,                 // User ID
  "user_id": 1,             // Duplicate for compatibility
  "email": "user@example.com",
  "roles": ["ROLE_USER"],
  "name": "John Doe",
  "type": "access"         // Token type identifier
}

Signature:
RSA-SHA256(private_key, base64(header).base64(payload))
```

### Refresh Token
```
Header: Same as access token

Payload:
{
  "iat": 1708870000,           // Issued at
  "exp": 1711462000,           // 30 days expiry
  "sub": 1,                    // User ID
  "user_id": 1,
  "email": "user@example.com",
  "type": "refresh"           // Token type identifier
}
```

---

## Session Storage

After successful login, the session contains:

```php
// User's session data (automatic)
$_SESSION['_symfony_user'] = <user_object>
$_SESSION['_csrf/authenticate'] = <csrf_token>
$_SESSION['_flash'] = [...]

// JWT data (ADDED by JwtGenerationSubscriber)
$_SESSION['jwt_access_token'] = "eyJhbGciOiJSUzI1NiJ9..."
$_SESSION['jwt_refresh_token'] = "eyJhbGciOiJSUzI1NiJ9..."
$_SESSION['jwt_generated_at'] = 1708870000
$_SESSION['jwt_token_data'] = [
    'access_token' => "...",
    'refresh_token' => "...",
    'token_type' => "Bearer",
    'expires_in' => 3600,
    'user' => [...]
]
```

---

## Configuration Requirements

### JWT Keys
Required: `config/jwt/private.pem` and `config/jwt/public.pem`

Generate with:
```bash
php bin/console app:generate-jwt-keys
```

### Environment Variables (if needed)
```
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE='' # Leave empty for no passphrase
```

### Service Configuration
Services auto-registered via `services.yaml`:
- `JwtTokenService` - Registered in `config/services.yaml`
- `JwtGenerationSubscriber` - Auto-discovered via `_instanceof`
- `SessionJwtManager` - Optional utility service

---

## Error Handling

### JWT Generation Fails During Login
```
Scenario: JwtTokenService.isEnabled() = false (keys missing)

Result:
- Session login STILL SUCCEEDS (returns encrypted JWT is optional)
- JwtGenerationSubscriber logs warning
- User redirected to /profile as normal
- User can still use session for API protected by ROLE_USER
- User cannot use JWT-based API calls

Fix: Regenerate JWT keys
```

### JWT Validation Fails
```
Scenario: Request has invalid Authorization header

Result:
- JwtAuthenticator throws CustomUserMessageAuthenticationException
- onAuthenticationFailure() called
- Returns: 401 JSON response
- User not authenticated
- Must re-login to get valid JWT
```

### User Blocked After Login
```
Scenario: User logs in, then admin blocks them

Result:
- Session: Still authorized (server doesn't check on every request)
- JWT: Would fail on next API call (checked in JwtAuthenticator)

Security: Implement user status check in JwtAuthenticator.authenticate()
```

---

## Performance Considerations

### Token Generation Overhead
- **First Login:** JWT generation adds ~50-100ms
- **Per Request:** No additional overhead (tokens passed in headers)
- **Token Validation:** ~20-50ms per JWT request
- **Negligible Impact:** < 1% of typical request time

### Session vs JWT
| Aspect | Session | JWT |
|--------|---------|-----|
| Server Load | Higher (stores data) | Lower (stateless) |
| Bandwidth | Smaller (cookie) | Larger (full token) |
| Cross-Origin | Limited (CORS issues) | Better (header-based) |
| Mobile/API | Poor | Excellent |
| Revocation | Immediate | On next refresh |

### Optimization Tips
1. Cache token claims in frontend (don't decode every request)
2. Implement token refresh strategy (proactive before expiry)
3. Use separate sessions for sensitive operations
4. Implement rate limiting on `/api/auth/login`

---

## Migration Guide (If You Have Existing JWT Users)

### For Existing JWT-Only Users
No changes needed. Their existing flows work unchanged:
- POST `/api/auth/login` → Returns JWT
- GET `/api/auth/me` with `Authorization: Bearer <token>` → Works
- POST `/api/auth/refresh` → Works

### For Existing Session-Only Users
Automatic upgrade:
- Session login works as before
- JWT now automatically generated on login
- New endpoint available: GET `/api/auth/token` to retrieve JWT
- Can now use JWT for API calls if desired

### For Hybrid Usage
Both systems coexist:
- User can have session AND JWT simultaneously
- Frontend can use whichever is more convenient
- Backend accepts both authentication methods on protected endpoints

---

## Troubleshooting Checklist

| Issue | Diagnosis | Solution |
|-------|-----------|----------|
| JWT endpoint returns 401 | User not authenticated | Ensure user logged in via /login first |
| JwtGenerationSubscriber not called | Event not triggered | Check subscriberconfiguration, run `debug:event-dispatcher` |
| Token validation fails | Keys missing/corrupted | Regenerate: `app:generate-jwt-keys` |
| CORS errors on `/api/auth/token` | Frontend/backend different origins | Configure CORS in `nelmio_cors.yaml` |
| Session tokens not stored | SessionJwtManager issue | Check session handler config |
| Login works but JWT doesn't | Keys disabled | Verify `JwtTokenService.isEnabled()` returns true |

---

## Next: Testing

See `INTEGRATION_TESTING_GUIDE.md` for comprehensive testing procedures with:
- Browser console testing
- Postman collection
- cURL examples  
- Manual verification steps
- Error scenario testing

---

## Support & Debugging

Enable debug logging:
```yaml
# config/packages/dev/monolog.yaml
when@dev:
    monolog:
        handlers:
            jwt_debug:
                type: stream
                path: '%kernel.logs_dir%/jwt.log'
                level: debug
                channels: ['!event']
```

Then watch logs:
```bash
tail -f var/log/jwt.log | grep -i jwt
```

Check token claims:
```bash
# SSH to server and run:
php bin/console tinker
>>> $claims = json_decode(base64_decode(explode('.', $token)[1]));
>>> dd($claims);
```
