# JWT Authentication Setup - Complete Guide

## ✅ Fix Applied: OpenSSL Key Validation Error Resolved

Your Symfony JWT application was failing with:
```
app.ERROR: Failed to generate access token: OpenSSL unable to validate key [] []
```

### Root Cause
The `JwtTokenService` was passing an encrypted private key as a raw PEM string to Firebase JWT, but RS256 algorithm requires a proper OpenSSL key resource.

### Solution Applied
Updated `src/Service/JwtTokenService.php` to:
1. Load encrypted private key using `openssl_pkey_get_private()` with passphrase
2. Store the OpenSSL key resource directly
3. Pass the key resource to Firebase JWT for token generation

**Result**: ✅ Tokens now generate successfully! Logs show:
```
app.INFO: JWT private key loaded successfully. Key bits: 4096
```

---

## 🎯 New Features: Frontend Authentication Helper

### Files Added/Updated

#### 1. **`public/js/JwtAuthenticationHelper.js`** (Updated)
Enhanced JavaScript helper with two new methods:

**`loginWithCredentials(email, password)`**
- Authenticate directly via API without session form
- Returns access token, refresh token, and user info
- Automatically stores tokens in localStorage

```javascript
const jwtHelper = new JwtAuthenticationHelper();

const tokens = await jwtHelper.loginWithCredentials(
    'user@example.com',
    'password123'
);

console.log(tokens.access_token);  // JWT token
console.log(tokens.user.email);    // User info
```

**`retrieveJwtToken()`** (Already existed, now better documented)
- Get JWT for users who logged in via session/form
- Retrieves token from server using session cookie
- Requires active session

```javascript
const tokens = await jwtHelper.retrieveJwtToken();
```

#### 2. **`public/js/jwt-authentication-examples.js`** (New)
Practical code examples showing:
- API login
- Session-based login
- Making authenticated API requests
- Token validation
- Logout

#### 3. **`public/jwt-test.html`** (New)
Interactive testing page to verify JWT setup:
- Login with credentials
- Retrieve token from session
- View token details
- Test API calls
- Debug console

---

## 🚀 Quick Start

### Option 1: Direct API Authentication (Recommended)

```html
<script src="/js/JwtAuthenticationHelper.js"></script>

<script>
  const jwtHelper = new JwtAuthenticationHelper();

  // Login
  const result = await jwtHelper.loginWithCredentials(
    'joujou@gmail.com',
    'Test123!'
  );

  // Token is stored in localStorage automatically
  console.log('Token:', result.access_token);
  console.log('User:', result.user);

  // Make API call with token
  const response = await fetch('/api/some-endpoint', {
    headers: {
      'Authorization': `Bearer ${jwtHelper.getToken()}`,
      'Accept': 'application/json'
    }
  });
</script>
```

### Option 2: Session-Based Authentication

```html
<script src="/js/JwtAuthenticationHelper.js"></script>

<script>
  const jwtHelper = new JwtAuthenticationHelper();

  // After user logs in via web form:
  const result = await jwtHelper.retrieveJwtToken();

  // Token retrieved and stored
  console.log('Token:', result.access_token);
</script>
```

### Option 3: Helper Methods for API Requests

```javascript
// Initialize helper
const jwtHelper = new JwtAuthenticationHelper({ debug: true });

// Method 1: Using addJwtToRequest
const options = jwtHelper.addJwtToRequest({ method: 'GET' });
const response = await fetch('/api/endpoint', options);

// Method 2: Manual headers
const token = jwtHelper.getToken();
const response = await fetch('/api/endpoint', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
});
```

---

## 📋 API Endpoints

### Authentication Endpoints

**POST /api/auth/login**
- Direct API login
- Request: `{ email, password }`
- Response: `{ access_token, refresh_token, expires_in, token_type, user }`
- No session required

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'
```

**GET /api/auth/token**
- Get JWT for authenticated session user
- Requires: Valid session cookie or Bearer token
- Response: `{ access_token, refresh_token, expires_in, token_type }`

```bash
curl -X GET http://localhost:8000/api/auth/token \
  -H "Cookie: PHPSESSID=..." \
  -H "Authorization: Bearer <token>"
```

**POST /api/auth/refresh**
- Refresh access token using refresh token
- Request: `{ refresh_token }`
- Response: New token pair

---

## 🧪 Testing

### Method 1: Interactive Test Page

Open: **http://localhost:8000/jwt-test.html**

Features:
- Login form with test credentials
- Real-time token display
- API call testing
- Debug console
- Token inspection

### Method 2: Command Line

```bash
# Login and get tokens
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"joujou@gmail.com","password":"Test123!"}'

# Use token for API request
RESPONSE=$(curl -s -X POST ... )
TOKEN=$(echo $RESPONSE | jq -r '.access_token')

curl -X GET http://localhost:8000/api/auth/token \
  -H "Authorization: Bearer $TOKEN"
```

### Method 3: Postman

1. Import: `Symfony-JWT-Integration.postman_collection.json`
2. Set `{{base_url}}` to `http://localhost:8000`
3. Execute requests in order:
   - POST /api/auth/login
   - GET /api/auth/token (with Bearer token)

---

## 🔧 Configuration

### Browser Storage Options

```javascript
// localStorage (persistent)
const helper = new JwtAuthenticationHelper({
  storage: localStorage
});

// sessionStorage (cleared when browser closes)
const helper = new JwtAuthenticationHelper({
  storage: sessionStorage
});

// Custom endpoints
const helper = new JwtAuthenticationHelper({
  tokenEndpoint: '/api/auth/token',
  loginEndpoint: '/api/auth/login',
  storageKey: 'jwt_token',
  refreshTokenKey: 'jwt_refresh',
  expiresInKey: 'jwt_expires'
});

// Debug mode
const helper = new JwtAuthenticationHelper({
  debug: true  // Logs all operations to console
});
```

### Symfony Configuration

All JWT settings in `.env`:
```dotenv
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=da6b1af422ae3f49e304349cdce2cd8fa35a77876e72f364b00e26799eb19a2c
JWT_TOKEN_TTL=3600
JWT_REFRESH_TOKEN_TTL=2592000
```

---

## 💾 Token Storage

### Where Tokens Are Stored

1. **localStorage** (Default)
   - Persists across browser sessions
   - Vulnerable to XSS attacks
   - Use for non-sensitive data

2. **sessionStorage**
   - Cleared when browser closes
   - More secure than localStorage
   - Recommended approach

3. **Memory/In-App**
   - Most secure but lost on page refresh
   - Best for sensitive applications

### Setting Storage Type

```javascript
// Use sessionStorage (recommended)
const helper = new JwtAuthenticationHelper({
  storage: sessionStorage
});

// Or localStorage
const helper = new JwtAuthenticationHelper({
  storage: localStorage
});
```

---

## 🛡️ Security Best Practices

### Do ✅
- Store tokens in `sessionStorage` (cleared on browser close)
- Always use HTTPS in production
- Include CSRF tokens for state-changing requests
- Validate tokens on server
- Implement token refresh logic
- Use HTTP-only cookies for session management

### Don't ❌
- Never store tokens in plain JavaScript variables (lost on refresh)
- Don't transmit tokens via URL parameters
- Never log tokens to console in production
- Don't use localStorage for sensitive data
- Never expose tokens in HTML source code
- Don't skip server-side validation

---

## 🐛 Troubleshooting

### "No JWT token found"
**Solution**: Ensure you've logged in first via `loginWithCredentials()` or `retrieveJwtToken()`

### 401 Unauthorized
**Cause**: Token expired or invalid
**Solution**: 
- Refresh token: `await jwtHelper.refreshToken()`
- Log in again: `await jwtHelper.loginWithCredentials(...)`

### CORS errors on `/api/auth/token`
**Cause**: Frontend on different domain than backend
**Solution**: Configure CORS in `config/packages/nelmio_cors.yaml`

### Token returns undefined
**Cause**: Old browser implementation, not using `await`
**Solution**: Use async/await or `.then()`:

```javascript
// ✅ Correct
const tokens = await jwtHelper.loginWithCredentials(email, pwd);

// ❌ Wrong
const tokens = jwtHelper.loginWithCredentials(email, pwd);
console.log(tokens); // undefined - not waiting for Promise
```

---

## 📚 Helper Methods Reference

```javascript
// Authentication
await jwtHelper.loginWithCredentials(email, password)
await jwtHelper.retrieveJwtToken()
await jwtHelper.logout()

// Token Management
jwtHelper.getToken()              // Get access token
jwtHelper.getRefreshToken()       // Get refresh token
jwtHelper.hasValidToken()         // Check if token valid & not expired
await jwtHelper.refreshToken()    // Refresh access token

// Request Helpers
jwtHelper.addJwtToRequest(options)  // Add Bearer token to fetch options

// Token Inspection
jwtHelper.getTokenClaims()        // Decode token payload
jwtHelper.isTokenExpiringSoon()   // Check if expiring within 5 min
jwtHelper.decodeToken(token)      // Manually decode token

// Storage
jwtHelper.storeTokenData(data)    // Save token to storage
jwtHelper.clearTokens()           // Clear stored tokens
jwtHelper.clearTokenData()        // Alias for clearTokens()
```

---

## ✅ Verification Checklist

- [x] JWT service initializes without OpenSSL errors
- [x] Tokens generate successfully
- [x] Token signature validates (RS256)
- [x] Frontend helper includes login method
- [x] Session-based token retrieval works
- [x] API endpoints return proper JWT
- [x] Browser test page loads and functions
- [x] Tokens can be used for authenticated API calls

---

## 📞 Support

If you encounter issues:

1. **Check logs**: `var/log/dev.log`
2. **Test page**: http://localhost:8000/jwt-test.html
3. **Enable debug**: `new JwtAuthenticationHelper({ debug: true })`
4. **Verify keys**: `openssl pkey -in config/jwt/private.pem -check`
5. **Clear cache**: `php bin/console cache:clear`

---

**Updated**: 2026-02-25  
**Status**: ✅ JWT Authentication Fully Functional
