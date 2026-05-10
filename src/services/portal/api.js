import axios from 'axios'
import env from '@/config/env'

/**
 * Phase 2a — dedicated axios client for the new portal tree.
 *
 * Why a separate client? The staff client (src/services/api.js) leans on
 * httpOnly session cookies + CSRF and bounces to /login on 401. Portal
 * users authenticate with a `scope=portal` JWT minted by /api/portal/auth/login,
 * carried as a bearer token. Sharing one client would entangle the two
 * auth contexts and break the "isolated portal scope" the backend enforces
 * via Middleware::portalAuth.
 *
 * Storage keys are namespaced (`portal.*`) so a portal user logging in on
 * the same browser as a staff user does not clobber the staff session.
 */

export const PORTAL_TOKEN_KEY = 'portal.access_token'
export const PORTAL_REFRESH_KEY = 'portal.refresh_token'
export const PORTAL_ACCOUNT_KEY = 'portal.account'
export const PORTAL_USER_KEY = 'portal.user'

export function readPortalToken() {
  if (typeof window === 'undefined' || !window.localStorage) return null
  return window.localStorage.getItem(PORTAL_TOKEN_KEY)
}

export function writePortalSession({ accessToken, refreshToken, user, account }) {
  if (typeof window === 'undefined' || !window.localStorage) return
  if (accessToken) window.localStorage.setItem(PORTAL_TOKEN_KEY, accessToken)
  if (refreshToken) window.localStorage.setItem(PORTAL_REFRESH_KEY, refreshToken)
  if (user) window.localStorage.setItem(PORTAL_USER_KEY, JSON.stringify(user))
  if (account) window.localStorage.setItem(PORTAL_ACCOUNT_KEY, JSON.stringify(account))
}

export function clearPortalSession() {
  if (typeof window === 'undefined' || !window.localStorage) return
  window.localStorage.removeItem(PORTAL_TOKEN_KEY)
  window.localStorage.removeItem(PORTAL_REFRESH_KEY)
  window.localStorage.removeItem(PORTAL_USER_KEY)
  window.localStorage.removeItem(PORTAL_ACCOUNT_KEY)
}

const portalApi = axios.create({
  baseURL: env.API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
})

portalApi.interceptors.request.use((config) => {
  const token = readPortalToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

portalApi.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data
    const url = error.config?.url || ''

    // 401/403 outside the login flow → portal session is gone or the user
    // has been moved to a tenant they no longer belong to. Clear and bounce
    // to the portal login. Skip the bounce for the login call itself so the
    // login form can surface a "bad credentials" message.
    const isAuthFailure = status === 401 || status === 403
    const isLoginCall = url.includes('/portal/auth/login')
    if (isAuthFailure && !isLoginCall && typeof window !== 'undefined') {
      clearPortalSession()
      const here = window.location.pathname
      if (!here.startsWith('/p/login')) {
        window.location.assign('/p/login?expired=1')
      }
    }
    return Promise.reject(error)
  }
)

export default portalApi
