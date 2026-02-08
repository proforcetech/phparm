# Mobile App - Remaining Security & Quality Items

Items identified during the full code audit (Feb 2026). Grouped by priority.

## Critical (Before Production)

### 1. Encrypt SQLite database with SQLCipher
- **Files**: `src/services/database.ts`, `src/utils/localCache.ts`
- **Issue**: Work orders, customers, vehicles, inspections stored as plaintext JSON in SQLite. Accessible on rooted/jailbroken devices.
- **Fix**: Integrate `expo-sqlite` with SQLCipher or encrypt the `data` column before insertion.
- **Effort**: Requires native module changes and a new EAS build.

### 2. Migrate sensitive user data from AsyncStorage to SecureStore
- **File**: `src/stores/authStore.ts`
- **Issue**: User objects (`name`, `email`, `role`, `permissions`), impersonation data, and portal nonces stored in unencrypted `AsyncStorage`. Only the JWT token uses SecureStore.
- **Fix**: Move `user`, `impersonation`, and `portal_nonce` keys to `expo-secure-store`, or encrypt values before storing in AsyncStorage.

### 3. Encrypt offline queue payloads
- **File**: `src/utils/offlineQueue.ts`
- **Issue**: Pending API requests (customer data, vehicle info) stored in plaintext AsyncStorage.
- **Fix**: Encrypt queue payloads before storage using a key from SecureStore.

### 4. Remove `.env` from git history
- **Issue**: The `.env` file containing `EXPO_PUBLIC_API_BASE_URL=https://fixitfor.us/api` was previously committed. The file is now gitignored but remains in history.
- **Fix**: Run `git filter-repo` or BFG Repo-Cleaner to purge `.env` from all commits. Rotate any exposed credentials.

## High (Before Beta)

### 5. Implement certificate pinning
- **File**: `src/services/api.ts`
- **Issue**: No SSL/TLS certificate pinning. Vulnerable to MITM with rogue CA certificates.
- **Fix**: Use a certificate pinning library (e.g., `react-native-ssl-pinning` or custom TrustManager) for production builds.

### 6. Strip console.log/warn/error in production
- **Files**: Multiple (30+ instances across stores, services, screens)
- **Issue**: Error objects logged to console may contain tokens, PII, or internal details.
- **Fix**: Add a Babel plugin (`babel-plugin-transform-remove-console`) for production builds, or replace with a proper error reporting service (Sentry, Bugsnag).

### 7. Remove deprecated Android permissions
- **File**: `app.config.ts` lines 79-80
- **Issue**: `WRITE_EXTERNAL_STORAGE` and `READ_EXTERNAL_STORAGE` are deprecated on API 33+ (Android 13). May trigger Google Play warnings.
- **Fix**: Remove both permissions and use scoped storage APIs instead.

## Medium (Production Hardening)

### 8. Implement session inactivity timeout
- **Issue**: No automatic logout after inactivity. Unattended device remains authenticated.
- **Fix**: Track last interaction timestamp, require biometric re-auth after 15 minutes of inactivity.

### 9. Add token refresh mechanism
- **File**: `src/services/api.ts`
- **Issue**: 401 responses trigger token removal and logout. No automatic refresh attempt.
- **Fix**: Implement refresh token flow in the 401 interceptor (requires backend support).

### 10. Biometric fallback to password
- **File**: `App.tsx`
- **Issue**: Biometric failure forces full logout instead of password re-entry.
- **Fix**: Add password input fallback screen on biometric failure.

### 11. Client-side input validation
- **File**: `src/screens/LoginScreen.tsx`
- **Issue**: Email/password only trimmed, no format validation.
- **Fix**: Add email format validation for better UX (backend validation still required).

### 12. Sanitize user-facing error messages
- **File**: `src/services/damageReport.service.ts` line 94
- **Issue**: Raw HTTP status and response body exposed in thrown errors.
- **Fix**: Show generic error messages to users, log detailed errors separately.

## Low (Nice to Have)

### 13. Move EAS project ID to environment variable
- **File**: `app.config.ts` line 40
- **Issue**: EAS project ID hardcoded. Not a secret, but cleaner as env var.

### 14. Document JWT client-side validation limitations
- **File**: `src/utils/jwt.ts`
- **Issue**: Client only checks expiry, no signature verification (expected but undocumented).

### 15. Update react-native-web
- **File**: `package.json`
- **Issue**: `react-native-web` ^0.21.0 may need update if web target is planned with React 19.
