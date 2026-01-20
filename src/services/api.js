import axios from 'axios'
import env from '@/config/env'

const api = axios.create({
  baseURL: env.API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // Enable sending cookies for session-based auth
})

// Promise-based singleton to handle session expiration
// This ensures concurrent requests wait for the same expiration handling
let sessionExpirationPromise = null

// Request interceptor - add auth token (optional, for future JWT support)
// Currently using session-based auth via cookies (withCredentials: true)
api.interceptors.request.use(
  (config) => {
    // Optional: Add JWT token if available (for future token-based auth)
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
    // Token is read from meta tag (preferred) or XSRF-TOKEN cookie (fallback)
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
      || decodeURIComponent(
          document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] || ''
        )

    if (csrfToken && ['post', 'put', 'patch', 'delete'].includes(config.method?.toLowerCase())) {
      config.headers['X-CSRF-Token'] = csrfToken
    }

    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor - handle session expiration
api.interceptors.response.use(
  (response) => response,
  (error) => {
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

    // Handle session expiration (401 Unauthorized or 403 Forbidden)
    if (status === 401 || status === 403) {
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
