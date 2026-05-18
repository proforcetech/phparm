import axios from 'axios'
import env from '@/config/env'

const api = axios.create({
  baseURL: env.API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // Enable sending cookies for session-based auth and httpOnly JWT cookies
  // Axios built-in XSRF handling - reads cookie and sets header automatically
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-CSRF-Token',
})

// Promise-based singleton to handle session expiration
// This ensures concurrent requests wait for the same expiration handling
let sessionExpirationPromise = null

// Cache for CSRF token to avoid repeated DOM queries
let cachedCsrfToken = null
let csrfTokenFetchPromise = null

function buildApiUrl(path) {
  const baseUrl = env.API_BASE_URL || '/api'
  const normalizedBase = baseUrl.replace(/\/+$/, '')
  const normalizedPath = path.startsWith('/') ? path : `/${path}`

  return `${normalizedBase}${normalizedPath}`
}

function normalizeRequestPath(url = '') {
  if (!url) return ''

  try {
    const parsed = new URL(url, window.location.origin)
    return parsed.pathname
  } catch (e) {
    return url.startsWith('/') ? url : `/${url}`
  }
}

function shouldHandleSessionExpiration(error) {
  const status = error.response?.status
  const responseData = error.response?.data

  if ((status !== 401 && status !== 403) || responseData?.error === 'csrf_token_invalid') {
    return false
  }

  // Forced password rotation is NOT a session expiration: the user is still
  // authenticated, the middleware is just gating non-allowlisted endpoints
  // until they rotate. The PasswordRotationOverlay handles the resolution;
  // bouncing to /login here would clear their session and break the flow.
  if (responseData?.error === 'password_change_required') {
    return false
  }

  // Step-up TOTP is not a session expiration either — the user is
  // authenticated but needs to re-prove possession of their TOTP device.
  // The StepUpProvider handles the prompt + retry; we must not bounce.
  if (responseData?.error === 'step_up_required') {
    return false
  }

  const path = normalizeRequestPath(error.config?.url || '')
  const authFlowPaths = new Set([
    '/auth/login',
    '/auth/customer-login',
    '/auth/verify-2fa',
    '/auth/customer-verify-2fa',
    '/auth/register',
    '/auth/forgot-password',
    '/auth/reset-password',
    '/auth/accept-invite',
    '/auth/refresh',
  ])

  return !authFlowPaths.has(path)
}

/**
 * Get CSRF token from multiple sources with fallback chain:
 * 1. Cached token (if available)
 * 2. Meta tag in document head
 * 3. XSRF-TOKEN cookie (may not work through dev proxy)
 * 4. Fetch from /api/csrf-token endpoint
 */
async function getCsrfToken({ forceFetch = false } = {}) {
  // Return cached token if available
  if (!forceFetch && cachedCsrfToken) {
    return cachedCsrfToken
  }

  // Try meta tag first
  const metaToken = document.querySelector('meta[name="csrf-token"]')?.content
  if (!forceFetch && metaToken) {
    cachedCsrfToken = metaToken
    return metaToken
  }

  // Try cookie (note: may not work when using Vite dev proxy due to cross-port cookie issues)
  try {
    const cookieToken = decodeURIComponent(
      document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1] || ''
    )
    if (!forceFetch && cookieToken) {
      cachedCsrfToken = cookieToken
      return cookieToken
    }
  } catch (e) {
    // Cookie parsing failed, continue to API fetch
  }

  // Fetch from endpoint - this is the most reliable method when using a dev proxy
  if (!csrfTokenFetchPromise) {
    csrfTokenFetchPromise = axios.get(buildApiUrl('/csrf-token'), {
      withCredentials: true
    })
      .then(response => {
        cachedCsrfToken = response.data?.token || null
        csrfTokenFetchPromise = null
        return cachedCsrfToken
      })
      .catch((err) => {
        console.error('[CSRF] Failed to fetch token:', err)
        csrfTokenFetchPromise = null
        return null
      })
  }

  return csrfTokenFetchPromise
}

/**
 * Clear the cached CSRF token (call after logout or when token is rejected)
 */
export function clearCsrfToken() {
  cachedCsrfToken = null
}

// Registered by StepUpProvider on mount. When a request returns 403 with
// `error: step_up_required`, the response interceptor calls this handler;
// it must resolve once a successful step-up verification has been recorded
// (the retry will then succeed) or reject if the user cancels.
let stepUpPromptHandler = null
let pendingStepUpPromise = null

export function registerStepUpHandler(handler) {
  stepUpPromptHandler = handler
}

export function unregisterStepUpHandler(handler) {
  if (stepUpPromptHandler === handler) {
    stepUpPromptHandler = null
  }
}

/**
 * Refresh the CSRF token by fetching a new one from the server
 */
export async function refreshCsrfToken() {
  clearCsrfToken()
  return getCsrfToken({ forceFetch: true })
}

/**
 * Initialize CSRF token - call this on app load to ensure token is ready
 * before any state-changing requests are made
 */
export async function initCsrfToken() {
  if (cachedCsrfToken) {
    return cachedCsrfToken
  }
  return getCsrfToken()
}

// Request interceptor - handles auth and CSRF tokens
// JWT is now stored in httpOnly cookies, so we don't need to add Authorization header
// for most requests. Bearer token is only used for explicit API clients.
api.interceptors.request.use(
  async (config) => {
    // Only add Bearer token from localStorage for backwards compatibility
    // New auth flow uses httpOnly cookies (sent automatically with withCredentials: true)
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    // Optional: Add portal nonce header
    const portalNonce = localStorage.getItem('portal_nonce')
    if (portalNonce) {
      config.headers['X-Portal-Nonce'] = portalNonce
    }

    // Add CSRF token for state-changing requests (POST, PUT, PATCH, DELETE)
    if (['post', 'put', 'patch', 'delete'].includes(config.method?.toLowerCase())) {
      const csrfToken = await getCsrfToken()
      if (csrfToken) {
        config.headers['X-CSRF-Token'] = csrfToken
      }
    }

    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor - handle session expiration and CSRF errors
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status
    const responseData = error.response?.data

    if (status === 422) {
      const normalizeErrorBag = (errors) => {
        if (!errors) return null
        if (Array.isArray(errors)) {
          return { _form: errors.filter(Boolean).join(' ') }
        }
        if (typeof errors === 'string') {
          return { _form: errors }
        }
        if (typeof errors === 'object') {
          return Object.entries(errors).reduce((acc, [key, value]) => {
            if (Array.isArray(value)) {
              acc[key] = value.filter(Boolean).join(' ')
            } else if (value && typeof value === 'object') {
              acc[key] = Object.values(value).flat().filter(Boolean).join(' ')
            } else if (value != null) {
              acc[key] = String(value)
            }
            return acc
          }, {})
        }
        return null
      }

      const normalizedErrors = normalizeErrorBag(responseData?.errors || responseData?.error)
      if (normalizedErrors) {
        error.response.data = {
          ...responseData,
          errors: normalizedErrors,
          message: responseData?.message || 'Please check the highlighted fields.'
        }
      }
    }

    // Handle step-up required: prompt for fresh TOTP, then retry the original
    // request once. Concurrent requests share a single prompt promise so the
    // user is asked one time even if many requests fire in parallel.
    if (
      status === 403 &&
      responseData?.error === 'step_up_required' &&
      stepUpPromptHandler &&
      !error.config?._stepUpRetry
    ) {
      error.config._stepUpRetry = true
      try {
        if (!pendingStepUpPromise) {
          pendingStepUpPromise = Promise.resolve(stepUpPromptHandler({
            message: responseData?.message || null,
          })).finally(() => {
            pendingStepUpPromise = null
          })
        }
        await pendingStepUpPromise
        return api.request(error.config)
      } catch (cancelErr) {
        return Promise.reject(error)
      }
    }

    // Handle CSRF token errors - refresh token and retry once
    if (status === 403 && responseData?.error === 'csrf_token_invalid') {
      // Only retry once to prevent infinite loops
      if (!error.config._csrfRetry) {
        error.config._csrfRetry = true
        clearCsrfToken()
        const newToken = await refreshCsrfToken()
        if (newToken) {
          error.config.headers['X-CSRF-Token'] = newToken
          return api.request(error.config)
        }
      }
    }

    // Handle session expiration (401 Unauthorized or 403 Forbidden)
    // Skip CSRF errors as they're handled above
    if (shouldHandleSessionExpiration(error)) {
      // If already handling session expiration, wait for that to complete
      if (sessionExpirationPromise) {
        return sessionExpirationPromise.then(() => Promise.reject(error))
      }

      // Create a new promise for session expiration handling
      sessionExpirationPromise = new Promise((resolve) => {
        // Log the intercepted auth failure for debugging (avoid logging full response to prevent sensitive data exposure)
        console.warn('[Auth] Session expiration detected:', {
          status,
          url: error.config?.url,
          method: error.config?.method,
          message: responseData?.message || 'No message provided',
        })

        // Check user role before clearing to determine which login page to use
        const storedUser = localStorage.getItem('user')
        let isCustomer = false
        try {
          if (storedUser) {
            const user = JSON.parse(storedUser)
            isCustomer = user.role === 'customer'
          }
        } catch (e) {
          // Invalid user data, default to staff login
        }

        // Clear all authentication data
        localStorage.removeItem('auth_token')
        localStorage.removeItem('user')
        localStorage.removeItem('portal_nonce')

        // Show a user-friendly message about session expiration
        const isSessionExpired = status === 401
        const message = isSessionExpired
          ? 'Your session has expired. Please log in again.'
          : 'Access denied. Please log in again.'

        // Redirect to appropriate login page based on user role
        const loginPath = isCustomer ? '/customer-login' : '/login'

        const params = new URLSearchParams({
          expired: isSessionExpired ? '1' : '0',
          message: message,
        })

        window.location.assign(`${loginPath}?${params.toString()}`)

        // Reset promise after a delay to allow redirect
        setTimeout(() => {
          sessionExpirationPromise = null
          resolve()
        }, 1000)
      })

      return sessionExpirationPromise.then(() => Promise.reject(error))
    }

    return Promise.reject(error)
  }
)

export default api
