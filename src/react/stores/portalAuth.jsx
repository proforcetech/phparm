import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

import { portalAuthService } from '../../services/portal/auth.service'
import {
  PORTAL_TOKEN_KEY,
  PORTAL_USER_KEY,
  PORTAL_ACCOUNT_KEY,
  clearPortalSession,
} from '../../services/portal/api'

const PortalAuthContext = createContext(null)

function readJson(key) {
  if (typeof window === 'undefined' || !window.localStorage) return null
  try {
    const raw = window.localStorage.getItem(key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function readToken() {
  if (typeof window === 'undefined' || !window.localStorage) return null
  return window.localStorage.getItem(PORTAL_TOKEN_KEY)
}

/**
 * Phase 2a — portal_user authentication context.
 *
 * Independent of the staff AuthProvider (../stores/auth.jsx) so that a
 * portal user signing in does not displace a staff session in the same
 * browser. State is hydrated synchronously from namespaced localStorage
 * (`portal.*` keys) so the first render of a deep-linked portal page has
 * a known auth status without an async flicker.
 */
export function PortalAuthProvider({ children }) {
  const [token, setToken] = useState(() => readToken())
  const [user, setUser] = useState(() => readJson(PORTAL_USER_KEY))
  const [account, setAccount] = useState(() => readJson(PORTAL_ACCOUNT_KEY))
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  const isAuthenticated = useMemo(() => Boolean(token), [token])

  // Effective permission set is computed by the backend (PortalPermissionService::effective)
  // and serialized into account.permissions. We only build a Set for O(1) lookups
  // in `can()`. Tier is exposed separately so the UI can render the badge label
  // even on the rare race where permissions hasn't synced yet.
  const permissionSet = useMemo(() => {
    const list = Array.isArray(account?.permissions) ? account.permissions : []
    return new Set(list)
  }, [account])

  const can = useCallback(
    (permission) => permissionSet.has(permission),
    [permissionSet]
  )

  const tier = account?.role_tier || null

  const refresh = useCallback(async () => {
    if (!readToken()) return null
    try {
      const data = await portalAuthService.me()
      if (data?.user) setUser(data.user)
      if (data?.portal_account) setAccount(data.portal_account)
      return data
    } catch (err) {
      // Auth interceptor will already have cleared on 401/403; just sync state.
      if (err.response?.status === 401 || err.response?.status === 403) {
        setToken(null)
        setUser(null)
        setAccount(null)
      }
      throw err
    }
  }, [])

  // On mount, validate the cached token by calling /me. Cheap and ensures
  // a stale localStorage entry doesn't render an authenticated UI.
  useEffect(() => {
    if (!token) return
    refresh().catch(() => {})
  }, [token, refresh])

  const login = useCallback(async (email, password) => {
    setLoading(true)
    setError(null)
    try {
      const data = await portalAuthService.login(email, password)
      setToken(data.access_token)
      setUser(data.user)
      setAccount(data.portal_account)
      return data
    } catch (err) {
      const message = err.response?.data?.message || 'Login failed'
      setError(message)
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  // Phase 2e — SSO callback path. The IdP redirects back to /p/auth/sso with
  // ?state=&code=; the callback page calls this to exchange them for a
  // portal-scoped JWT pair and seed the auth context the same way password
  // login does.
  const completeSso = useCallback(async ({ state, code }) => {
    setLoading(true)
    setError(null)
    try {
      const data = await portalAuthService.completeSso({ state, code })
      setToken(data.access_token)
      setUser(data.user)
      setAccount(data.portal_account)
      return data
    } catch (err) {
      const message = err.response?.data?.message || 'SSO sign-in failed'
      setError(message)
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const logout = useCallback(async () => {
    await portalAuthService.logout()
    clearPortalSession()
    setToken(null)
    setUser(null)
    setAccount(null)
    if (typeof window !== 'undefined') {
      window.location.assign('/p/login')
    }
  }, [])

  const value = useMemo(
    () => ({
      token,
      user,
      account,
      loading,
      error,
      isAuthenticated,
      login,
      logout,
      refresh,
      tier,
      can,
      completeSso,
    }),
    [token, user, account, loading, error, isAuthenticated, login, logout, refresh, tier, can, completeSso]
  )

  return <PortalAuthContext.Provider value={value}>{children}</PortalAuthContext.Provider>
}

export function usePortalAuth() {
  const ctx = useContext(PortalAuthContext)
  if (!ctx) {
    throw new Error('usePortalAuth must be used within a PortalAuthProvider')
  }
  return ctx
}
