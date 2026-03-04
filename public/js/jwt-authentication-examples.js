/**
 * JWT Authentication Usage Examples
 * 
 * How to use JwtAuthenticationHelper in your application
 */

// Initialize the helper
const jwtHelper = new JwtAuthenticationHelper({
    debug: true,  // Enable console logging
    storage: localStorage  // or sessionStorage
});

// ============================================
// EXAMPLE 1: Login with email/password (API)
// ============================================
async function loginAndGetToken() {
    try {
        const data = await jwtHelper.loginWithCredentials(
            'joujou@gmail.com',
            'Test123!'
        );
        
        console.log('✅ Login successful!');
        console.log('Access Token:', data.access_token);
        console.log('User:', data.user);
        
        // Token is automatically stored in localStorage
        // You can now use it for API calls
        return data;
    } catch (error) {
        console.error('❌ Login failed:', error.message);
    }
}

// ============================================
// EXAMPLE 2: Get token from session (Web Form)
// ============================================
async function getTokenFromSession() {
    try {
        const data = await jwtHelper.retrieveJwtToken();
        
        console.log('✅ Token retrieved from session!');
        console.log('Access Token:', data.access_token);
        
        return data;
    } catch (error) {
        console.error('❌ Failed to retrieve token:', error.message);
    }
}

// ============================================
// EXAMPLE 3: Use stored token for API calls
// ============================================
async function makeAuthenticatedRequest(url, options = {}) {
    const token = jwtHelper.getToken();
    
    if (!token) {
        console.error('No token available. Please login first.');
        return null;
    }
    
    const headers = {
        ...options.headers,
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
    };
    
    try {
        const response = await fetch(url, {
            ...options,
            headers
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        console.error('Request failed:', error.message);
        throw error;
    }
}

// ============================================
// EXAMPLE 4: Check if user is authenticated
// ============================================
function isAuthenticated() {
    return jwtHelper.hasValidToken();
}

// ============================================
// EXAMPLE 5: Clear authentication (logout)
// ============================================
function logout() {
    jwtHelper.clearTokenData();
    console.log('Logged out');
    // Redirect to login page
    window.location.href = '/login';
}

// ============================================
// USAGE IN YOUR APPLICATION
// ============================================

// After page loads, check if already authenticated
document.addEventListener('DOMContentLoaded', () => {
    if (isAuthenticated()) {
        console.log('✅ User is already authenticated');
        // Can make API calls
    } else {
        console.log('⚠️ User not authenticated, showing login');
        // Show login form or redirect
    }
});

// Example: Login button click handler
// document.getElementById('loginBtn').addEventListener('click', async () => {
//     const email = document.getElementById('emailInput').value;
//     const password = document.getElementById('passwordInput').value;
//     
//     const result = await loginAndGetToken();
//     if (result) {
//         // Navigate to dashboard or protected page
//         window.location.href = '/dashboard';
//     }
// });

// Example: Make API call with authentication
// async function fetchUserData() {
//     const userData = await makeAuthenticatedRequest('/api/user/profile');
//     console.log('User data:', userData);
// }
