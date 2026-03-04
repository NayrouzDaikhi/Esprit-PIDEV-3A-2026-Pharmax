/**
 * JWT Authentication Helper - provides utilities for managing JWT in browser
 * Use this to seamlessly handle both session and JWT authentication
 * 
 * WORKFLOW:
 * 1. User logs in via browser form (/login)
 * 2. Server creates session + JWT (automatically)
 * 3. Frontend calls retrieveJwtToken() to get JWT from server
 * 4. Frontend stores JWT in localStorage/sessionStorage
 * 5. Frontend uses JWT for API calls via addJwtToRequest()
 */

class JwtAuthenticationHelper {
    constructor(options = {}) {
        this.tokenEndpoint = options.tokenEndpoint || '/api/auth/token';
        this.loginEndpoint = options.loginEndpoint || '/api/auth/login';
        this.storageKey = options.storageKey || 'jwt_access_token';
        this.refreshTokenKey = options.refreshTokenKey || 'jwt_refresh_token';
        this.expiresInKey = options.expiresInKey || 'jwt_expires_in';
        this.storage = options.storage || localStorage; // localStorage or sessionStorage
        this.debug = options.debug || false;
    }

    /**
     * Login with email and password, get JWT tokens directly
     * Use this for API-based authentication (no session form)
     * 
     * @param {string} email - User email
     * @param {string} password - User password
     * @returns {Promise<Object>} Token data: { access_token, refresh_token, expires_in, token_type, user }
     */
    async loginWithCredentials(email, password) {
        try {
            this.log('Logging in with credentials...');
            
            const response = await fetch(this.loginEndpoint, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ email, password })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.message || `HTTP ${response.status}: Login failed`);
            }

            const tokenData = await response.json();
            
            // Store token in browser storage
            this.storeTokenData(tokenData);
            this.log('Login successful, tokens stored', tokenData.user);
            
            return tokenData;
        } catch (error) {
            this.logError('Login failed', error);
            throw error;
        }
    }

    /**
     * Retrieve JWT token from server (for users who logged in via session form)
     * Call this after redirected from /login form
     * 
     * @returns {Promise<Object>} Token data: { access_token, refresh_token, expires_in, token_type }
     */
    async retrieveJwtToken() {
        try {
            this.log('Retrieving JWT token from server...');
            
            const response = await fetch(this.tokenEndpoint, {
                method: 'GET',
                credentials: 'include', // Include session cookies
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const tokenData = await response.json();
            
            // Store token in browser storage
            this.storeTokenData(tokenData);
            this.log('JWT token retrieved and stored successfully', tokenData);
            
            return tokenData;
        } catch (error) {
            this.logError('Failed to retrieve JWT token', error);
            throw error;
        }
    }

    /**
     * Store token data in browser storage
     * @param {Object} tokenData - Token response from server
     */
    storeTokenData(tokenData) {
        try {
            this.storage.setItem(this.storageKey, tokenData.access_token);
            this.storage.setItem(this.refreshTokenKey, tokenData.refresh_token);
            this.storage.setItem(this.expiresInKey, Date.now() + (tokenData.expires_in * 1000));
            this.log('Token data stored in browser');
        } catch (error) {
            this.logError('Failed to store token data', error);
        }
    }

    /**
     * Get stored JWT access token
     * @returns {string|null} JWT token or null if not found
     */
    getToken() {
        return this.storage.getItem(this.storageKey);
    }

    /**
     * Get stored JWT refresh token
     * @returns {string|null} Refresh token or null if not found
     */
    getRefreshToken() {
        return this.storage.getItem(this.refreshTokenKey);
    }

    /**
     * Check if token is stored and not expired
     * @returns {boolean}
     */
    hasValidToken() {
        const token = this.getToken();
        if (!token) return false;

        const expiresAt = parseInt(this.storage.getItem(this.expiresInKey) || 0);
        return Date.now() < expiresAt;
    }

    /**
     * Clear JWT tokens from browser storage
     */
    clearTokens() {
        this.storage.removeItem(this.storageKey);
        this.storage.removeItem(this.refreshTokenKey);
        this.storage.removeItem(this.expiresInKey);
        this.log('JWT tokens cleared from storage');
    }

    /**
     * Alias for clearTokens() - for backward compatibility
     */
    clearTokenData() {
        return this.clearTokens();
    }

    /**
     * Add JWT Bearer token to fetch request headers
     * Use this when making API calls that require authentication
     * 
     * @param {Object} options - Fetch options (headers, method, body, etc.)
     * @returns {Object} Enhanced options with Authorization header
     * 
     * EXAMPLE:
     * const opts = helper.addJwtToRequest({ method: 'GET' });
     * fetch('/api/endpoint', opts); // Bearer token automatically added
     */
    addJwtToRequest(options = {}) {
        const token = this.getToken();
        
        if (!token) {
            this.logError('No JWT token found. User may need to log in.');
            return options;
        }

        // Clone headers or create new object
        const headers = options.headers || {};
        
        // Add Bearer token
        headers['Authorization'] = `Bearer ${token}`;
        
        return {
            ...options,
            headers,
            credentials: 'include' // Include cookies if needed
        };
    }

    /**
     * Refresh JWT access token using refresh token
     * Call this when access token expires
     * 
     * @param {string} refreshEndpoint - Your API refresh endpoint (default: /api/auth/refresh)
     * @returns {Promise<Object>} New token data
     */
    async refreshToken(refreshEndpoint = '/api/auth/refresh') {
        try {
            const refreshToken = this.getRefreshToken();
            
            if (!refreshToken) {
                throw new Error('No refresh token available');
            }

            this.log('Refreshing JWT token...');

            const response = await fetch(refreshEndpoint, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ refresh_token: refreshToken })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const tokenData = await response.json();
            this.storeTokenData(tokenData);
            this.log('JWT token refreshed successfully');
            
            return tokenData;
        } catch (error) {
            this.logError('Failed to refresh JWT token', error);
            this.clearTokens();
            throw error;
        }
    }

    /**
     * Logout: clear local tokens and optionally notify server
     * 
     * @param {string} logoutEndpoint - Your API logout endpoint (default: /api/auth/logout)
     * @returns {Promise<void>}
     */
    async logout(logoutEndpoint = '/api/auth/logout') {
        try {
            this.log('Logging out...');
            
            // Notify server
            await fetch(logoutEndpoint, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            }).catch(err => {
                // Continue logout even if server request fails
                this.logError('Server logout notification failed (continuing)', err);
            });

            // Clear local tokens
            this.clearTokens();
            this.log('Logout complete');
        } catch (error) {
            this.logError('Logout error', error);
            // Still clear tokens even if error
            this.clearTokens();
        }
    }

    /**
     * Decode JWT token to inspect claims (basic decoding without verification)
     * DO NOT use this for security validation - this is client-side only
     * 
     * @param {string} token - JWT token to decode
     * @returns {Object|null} Decoded payload or null if invalid
     */
    decodeToken(token = null) {
        token = token || this.getToken();
        
        if (!token) return null;

        try {
            const parts = token.split('.');
            if (parts.length !== 3) return null;

            const payload = JSON.parse(atob(parts[1]));
            return payload;
        } catch (error) {
            this.logError('Failed to decode token', error);
            return null;
        }
    }

    /**
     * Get token claims (useful for checking user info from token)
     * @returns {Object} Token claims: { sub, email, roles, name, exp, etc. }
     */
    getTokenClaims() {
        return this.decodeToken();
    }

    /**
     * Check if token is about to expire (within 5 minutes)
     * @returns {boolean}
     */
    isTokenExpiringSoon(minutesThreshold = 5) {
        const claims = this.getTokenClaims();
        if (!claims || !claims.exp) return false;

        const expiresAt = claims.exp * 1000; // Convert to milliseconds
        const thresholdMs = minutesThreshold * 60 * 1000;
        
        return Date.now() > (expiresAt - thresholdMs);
    }

    // Utility logging methods
    log(...args) {
        if (this.debug) {
            console.log('[JwtAuth]', ...args);
        }
    }

    logError(...args) {
        if (this.debug) {
            console.error('[JwtAuth]', ...args);
        }
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = JwtAuthenticationHelper;
}
