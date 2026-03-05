# 2FA Implementation & Debugging Report

**Date:** March 5, 2026  
**Status:** ✅ All Issues Resolved  
**Scope:** Two-Factor Authentication (2FA) Implementation with `scheb/2fa-bundle`

---

## 1. Summary of Errors & Solutions

During the implementation and testing of the 2FA system, several critical issues were identified and resolved. This document serves as a reference for the fixes applied.

### 🔴 Error 1: `InvalidConfigurationException` (Window Option)
**Symptom:**  
HTTP 500 Error regarding "Unrecognized option 'window' under 'scheb_two_factor.google'".

**Cause:**  
The `window` configuration option (used to allow time drift) is deprecated or not supported in the current version of the bundle.

**Solution:**  
- **File:** `config/packages/scheb_2fa.yaml`
- **Action:** Removed the `window: 1` line.
- **Note:** The `leeway` option should be used if time drift tolerance is needed in the future.

---

### 🔴 Error 2: 2FA Bypass / Redirection Failure
**Symptom:**  
After logging in, users with 2FA enabled were redirected directly to the profile page instead of the 2FA code entry page. Or, in some configurations, the request would "fall through" causing unexpected behavior.

**Cause:**  
The `LoginFormAuthenticator::onAuthenticationSuccess` method was returning `null`. In the modern Symfony Authenticator system, returning `null` allows the request to continue to the controller, skipping the `LoginSuccessEvent` which the 2FA bundle listens to for interception.

**Solution:**  
- **File:** `src/Security/LoginFormAuthenticator.php`
- **Action:** Updated `onAuthenticationSuccess` to **always return a `RedirectResponse`** (e.g., to the profile).
- **Mechanism:** The `scheb/2fa-bundle` listens for this successful response, checks if the user requires 2FA, and *replaces* the redirect with a redirect to the 2FA form.

---

### 🔴 Error 3: "Invalid CSRF Token"
**Symptom:**  
Submitting the 2FA code resulted in an "Invalid CSRF Token" error.

**Cause:**  
There was a mismatch between the CSRF token ID expected by the firewall configuration (defaulting to "2fa") and the token being generated/validated.

**Solution:**  
- **File:** `config/packages/security.yaml`
- **Action:** Explicitly defined `csrf_token_id: two_factor_auth`.
- **File:** `templates/security/2fa_form.html.twig`
- **Action:** Updated the hidden input to generate the token using the matching ID: `{{ csrf_token('two_factor_auth') }}`.

---

### 🔴 Error 4: Form Field Mismatch & Missing Name
**Symptom:**  
The 2FA code was not being processed, or the form submission failed silently.

**Cause:**  
The input field in the template was named `auth_code`, but the bundle expects `_auth_code` by default.

**Solution:**  
- **File:** `templates/security/2fa_form.html.twig`
- **Action:** Renamed the input field to `name="_auth_code"` and ensured the CSRF token field was present.

---

### 🔴 Error 5: "No 2FA setup in progress" (Double Submission)
**Symptom:**  
When clicking "Verify", users received an error saying "No 2FA setup in progress," but reloading the page showed 2FA was successfully enabled.

**Cause:**  
**Race Condition:** The browser sent two requests (e.g., via HTML form submit AND JavaScript `fetch`).
1. **Request A** succeeds: Validates code → Enables 2FA → Clears "pending secret".
2. **Request B** fails: Tries to validate → Finds no "pending secret" (because A cleared it) → Returns error.

**Solution:**  
- **File:** `src/Controller/TwoFactorAuthController.php`
- **Action:** Added logic in the `verify()` method to check if the user *already has 2FA enabled*. If the "pending secret" is missing but 2FA is active, we treat it as a successful verification (idempotency).

---

### 🔴 Error 6: 2FA Bypass / Incorrect Redirect to Profile
**Symptom:**  
Users with Google Authenticator enabled were incorrectly redirected to their profile instead of the 2FA challenge page during login.

**Cause:**  
Multiple root causes were interacting:
1. **Missing Interface Implementation:** The `User` entity was not explicitly implementing `TwoFactorInterface` from the `scheb/2fa-bundle`. Symfony bundles often use `instanceof TwoFactorInterface` checks to determine whether to apply 2FA interception. Without this, the bundle "saw" the user as a standard user and skipped 2FA verification entirely.
2. **Hardcoded URL Paths Instead of Route Names:** The security configuration used `auth_form_path: /2fa` and `check_path: /2fa_check` (URL paths) instead of route names. The bundle's internal redirection logic is more robust and explicit when using formal route names.
3. **Clock Drift in TOTP Verification:** Even if redirection was working, slight time mismatches between server and mobile device could cause silent failures in code validation.

**Solution:**  
- **File:** `src/Entity/User.php`
  - **Action:** Added `use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;` to imports.
  - **Action:** Updated class declaration to explicitly implement `TwoFactorInterface`.
  - **Action:** Ensured `isGoogleAuthenticatorEnabled()` returns `!empty($this->googleAuthenticatorSecret)` to prevent blank-string bypasses.

- **File:** `config/packages/security.yaml`
  - **Action:** Changed `auth_form_path: /2fa` to `auth_form_path: 2fa_login`.
  - **Action:** Changed `check_path: /2fa_check` to `check_path: 2fa_login_check`.
  - **Note:** `prepare_on_login: true` was already present to enable immediate redirection.

- **File:** `config/routes/scheb_2fa.yaml`
  - **Action:** Removed the duplicate `2fa_login` route definition that was pointing to the bundle's form controller, preventing route name conflicts.
  - **Note:** Ensured `TwoFactorAuthController::login2fa` is the sole owner of the `2fa_login` route via PHP attributes.

- **File:** `src/Controller/TwoFactorAuthController.php`
  - **Action:** Updated `verify()` method to call `$totp->verify($code, null, 1)` with a window of `1` to tolerate ±30 seconds of clock drift.

- **File:** `src/Security/LoginFormAuthenticator.php`
  - **Action:** Removed the unused `use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;` import (the bundle handles interception via event listeners, not in the authenticator).

**Result:**  
The bundle now correctly:
1. Recognizes the `User` as a valid 2FA implementation.
2. Intercepts successful login responses via route names.
3. Handles time-drift tolerantly during TOTP verification.

---

## 2. Feature Enhancements

### ✅ UI Improvements
- **2FA Form:** Created a clean, standalone template for the 2FA login page (`templates/security/2fa_form.html.twig`) matching the style of the setup page, removing the dashboard sidebar/header for a focused security verification experience.
- **Profile Page:** Added a "Configure 2FA" button to the user profile (both Front & Back office) that only appears if 2FA is **not** yet enabled.

### ✅ Code Quality
- **Standardization:** Ensured strict typing and proper redirects in the Authentication controller.
- **Security:** CSRF protection is now explicitly configured and working for all 2FA actions.

---

## 3. Final Status

The Authentication system is now fully functional with the following flow:
1. **Login:** User enters email/password.
2. **Setup (Optional):** User clicks "Configure 2FA" in profile → Scans QR → Verifies Code.
3. **Interception:** Next login, user is intercepted after password verification.
4. **2FA Entry:** User enters 6-digit code.
5. **Success:** User is fully authenticated and redirected to their profile.
