import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

import { authService } from '../../services/auth.service'
import { portalService } from '../../services/portal.service'
import { serviceLineService } from '../../services/serviceLine.service'
import { initCsrfToken } from '../../services/api'

const AuthContext = createContext(null)

const SERVICE_LINE_STORAGE_KEY = 'phparm.currentServiceLineId'

function readStoredServiceLineId() {
  if (typeof window === 'undefined' || !window.localStorage) {
    return null
  }
  const raw = window.localStorage.getItem(SERVICE_LINE_STORAGE_KEY)
  if (raw === null || raw === '') {
    return null
  }
  const parsed = Number.parseInt(raw, 10)
  return Number.isFinite(parsed) ? parsed : null
}

function resolveInitialServiceLineId(serviceLines, primaryId) {
  const stored = readStoredServiceLineId()
  if (stored !== null && serviceLines.some((line) => line.id === stored)) {
    return stored
  }
  if (
    primaryId !== null &&
    primaryId !== undefined &&
    serviceLines.some((line) => line.id === primaryId)
  ) {
    return primaryId
  }
  return null
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [token, setToken] = useState(null)
  const [impersonation, setImpersonation] = useState(null)
  const [portalConfig, setPortalConfig] = useState({
    apiBase: '/api',
    nonce: null,
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [pendingChallenge, setPendingChallenge] = useState(null)
  const [serviceLines, setServiceLines] = useState([])
  const [primaryServiceLineId, setPrimaryServiceLineId] = useState(null)
  const [currentServiceLineId, setCurrentServiceLineIdState] = useState(null)

  // Whenever the user payload changes, sync derived service-line state.
  // Uses the rule: localStorage > user.primary_service_line_id > null.
  useEffect(() => {
    if (!user) {
      setServiceLines([])
      setPrimaryServiceLineId(null)
      setCurrentServiceLineIdState(null)
      return
    }
    const lines = Array.isArray(user.service_lines) ? user.service_lines : []
    const primaryId =
      typeof user.primary_service_line_id === 'number'
        ? user.primary_service_line_id
        : user.primary_service_line_id != null
          ? Number.parseInt(user.primary_service_line_id, 10) || null
          : null
    setServiceLines(lines)
    setPrimaryServiceLineId(primaryId)
    setCurrentServiceLineIdState(resolveInitialServiceLineId(lines, primaryId))
  }, [user])

  const currentServiceLine = useMemo(() => {
    if (currentServiceLineId === null || currentServiceLineId === undefined) {
      return null
    }
    return serviceLines.find((line) => line.id === currentServiceLineId) ?? null
  }, [serviceLines, currentServiceLineId])

  const setCurrentServiceLine = useCallback(
    (id) => {
      const numericId =
        typeof id === 'number' ? id : Number.parseInt(id, 10)
      if (!Number.isFinite(numericId)) {
        return
      }
      // 1. Update local React state immediately (UI is optimistic).
      setCurrentServiceLineIdState(numericId)
      // 2. Persist to localStorage so the choice survives reloads.
      try {
        if (typeof window !== 'undefined' && window.localStorage) {
          window.localStorage.setItem(SERVICE_LINE_STORAGE_KEY, String(numericId))
        }
      } catch (storageErr) {
        // Non-fatal: state still updates in memory.
        console.warn('[Auth] Failed to persist current service line:', storageErr)
      }
      // 3. Fire-and-forget server sync. Caller (component) handles toast on failure.
      return serviceLineService
        .setPrimary(numericId)
        .then((data) => {
          if (data && typeof data.primary_id === 'number') {
            setPrimaryServiceLineId(data.primary_id)
          }
          if (data && Array.isArray(data.service_lines) && data.service_lines.length > 0) {
            setServiceLines(data.service_lines)
          }
          return data
        })
    },
    []
  )

  const isAuthenticated = useMemo(() => !!token, [token])
  const isCustomer = useMemo(() => user?.role?.toLowerCase() === 'customer', [user])
  const isStaff = useMemo(() => user && user.role?.toLowerCase() !== 'customer', [user])
  const isAdmin = useMemo(() => user?.role?.toLowerCase() === 'admin', [user])
  const portalReady = useMemo(() => isCustomer && !!portalConfig.nonce, [isCustomer, portalConfig])

  const checkAuth = useCallback(async () => {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('user')
    const storedImpersonation = localStorage.getItem('impersonation')
    const storedNonce = localStorage.getItem('portal_nonce')

    // Load cached data immediately for faster initial render
    if (storedToken && storedUser) {
      setToken(storedToken)
      setUser(JSON.parse(storedUser))
    } else if (storedUser) {
      // Even without token in localStorage, load cached user (JWT may be in httpOnly cookie)
      setUser(JSON.parse(storedUser))
    }

    if (storedImpersonation) {
      setImpersonation(JSON.parse(storedImpersonation))
    }

    if (storedNonce) {
      setPortalConfig((prev) => ({ ...prev, nonce: storedNonce }))
    }

    // Always try to fetch fresh user data from API
    // JWT tokens are now in httpOnly cookies, so we can't check localStorage for auth status
    try {
      const data = await authService.me()
      if (data.user) {
        setUser(data.user)
        setToken('authenticated') // Set a placeholder to indicate authenticated state
        localStorage.setItem('user', JSON.stringify(data.user))

        // Initialize CSRF token now that we're authenticated
        // This ensures the token is ready before any state-changing requests
        await initCsrfToken()
      }

      if (data.impersonation !== undefined) {
        setImpersonation(data.impersonation)
        if (data.impersonation) {
          localStorage.setItem('impersonation', JSON.stringify(data.impersonation))
        } else {
          localStorage.removeItem('impersonation')
        }
      }
    } catch (error) {
      // If API call fails (401), user is not authenticated - clear any stale data
      if (error.response?.status === 401) {
        setUser(null)
        setToken(null)
        localStorage.removeItem('user')
        localStorage.removeItem('auth_token')
      }
    }
  }, [])

  const fetchCurrentUser = useCallback(async () => {
    try {
      const data = await authService.me()
      if (data.user) {
        setUser(data.user)
        localStorage.setItem('user', JSON.stringify(data.user))
      }
      if (data.impersonation !== undefined) {
        setImpersonation(data.impersonation)
        if (data.impersonation) {
          localStorage.setItem('impersonation', JSON.stringify(data.impersonation))
        } else {
          localStorage.removeItem('impersonation')
        }
      }
      return data
    } catch (err) {
      await logout()
      throw err
    }
  }, [])

  const handleLoginSuccess = useCallback((data) => {
    if (data.user) {
      // Token may be provided for backwards compatibility, but JWT is now primarily
      // stored in httpOnly cookies for security (XSS protection)
      if (data.token) {
        setToken(data.token)
        // Only store token in localStorage if explicitly provided (backwards compat)
        // New secure flow uses httpOnly cookies which don't need localStorage
        if (data.use_legacy_storage) {
          localStorage.setItem('auth_token', data.token)
        }
      }

      setUser(data.user)
      setImpersonation(null)

      localStorage.setItem('user', JSON.stringify(data.user))
      localStorage.removeItem('impersonation')

      if (data.api_base) {
        setPortalConfig((prev) => ({ ...prev, apiBase: data.api_base }))
      }

      if (data.user.role?.toLowerCase() === 'customer' && data.nonce) {
        setPortalConfig((prev) => ({ ...prev, nonce: data.nonce }))
        localStorage.setItem('portal_nonce', data.nonce)
      } else {
        setPortalConfig((prev) => ({ ...prev, nonce: null }))
        localStorage.removeItem('portal_nonce')
      }

      if (data.user.role?.toLowerCase() === 'customer') {
        window.location.assign('/portal')
      } else {
        window.location.assign('/cp/dashboard')
      }
    }
  }, [])

  const login = useCallback(
    async (email, password, isCustomerLogin = false, recaptchaToken = null) => {
      setLoading(true)
      setError(null)

      try {
        const data = isCustomerLogin
          ? await authService.customerLogin(email, password, recaptchaToken)
          : await authService.login(email, password, recaptchaToken)

        if (data.status === '2fa_required') {
          setPendingChallenge({
            token: data.challenge_token,
            isCustomer: isCustomerLogin,
          })
          return data
        }

        handleLoginSuccess(data)
        return data
      } catch (err) {
        setError(err.response?.data?.message || 'Login failed')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [handleLoginSuccess]
  )

  const verifyTwoFactor = useCallback(
    async (code) => {
      if (!pendingChallenge) {
        throw new Error('No pending two-factor challenge')
      }

      setLoading(true)
      setError(null)

      try {
        const data = await authService.verifyTwoFactor(
          pendingChallenge.token,
          code,
          pendingChallenge.isCustomer
        )

        setPendingChallenge(null)
        handleLoginSuccess(data)

        return data
      } catch (err) {
        setError(err.response?.data?.message || 'Two-factor verification failed')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [handleLoginSuccess, pendingChallenge]
  )

  const logout = useCallback(async () => {
    try {
      await authService.logout()
    } catch (err) {
      console.error('Logout error:', err)
    } finally {
      setUser(null)
      setToken(null)
      setImpersonation(null)
      setPortalConfig((prev) => ({ ...prev, nonce: null }))
      setServiceLines([])
      setPrimaryServiceLineId(null)
      setCurrentServiceLineIdState(null)
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      localStorage.removeItem('portal_nonce')
      localStorage.removeItem('impersonation')
      localStorage.removeItem(SERVICE_LINE_STORAGE_KEY)
      window.location.assign('/login')
    }
  }, [])

  const register = useCallback(async (userData) => {
    setLoading(true)
    setError(null)

    try {
      const data = await authService.register(userData)
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Registration failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const requestPasswordReset = useCallback(async (email, recaptchaToken = null) => {
    setLoading(true)
    setError(null)

    try {
      const data = await authService.requestPasswordReset(email, recaptchaToken)
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Password reset request failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const resetPassword = useCallback(async (resetToken, password) => {
    setLoading(true)
    setError(null)

    try {
      const data = await authService.resetPassword(resetToken, password)
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Password reset failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const acceptInvite = useCallback(async (inviteToken, password) => {
    setLoading(true)
    setError(null)

    try {
      const data = await authService.acceptInvite(inviteToken, password)
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Invitation acceptance failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateProfile = useCallback(async (userData) => {
    setLoading(true)
    setError(null)

    try {
      const data = await authService.updateProfile(userData)
      if (data.user) {
        setUser(data.user)
        localStorage.setItem('user', JSON.stringify(data.user))
      }
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Profile update failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const bootstrapPortal = useCallback(async () => {
    if (!isCustomer) {
      return null
    }

    const data = await portalService.bootstrap()

    if (data.user) {
      setUser(data.user)
      localStorage.setItem('user', JSON.stringify(data.user))
    }

    if (data.token) {
      setToken(data.token)
      localStorage.setItem('auth_token', data.token)
    }

    if (data.api_base) {
      setPortalConfig((prev) => ({ ...prev, apiBase: data.api_base }))
    }

    if (data.nonce) {
      setPortalConfig((prev) => ({ ...prev, nonce: data.nonce }))
      localStorage.setItem('portal_nonce', data.nonce)
    }

    return data
  }, [isCustomer])

  const stopImpersonation = useCallback(async () => {
    const data = await authService.stopImpersonation()
    if (data.user) {
      setUser(data.user)
      localStorage.setItem('user', JSON.stringify(data.user))
    }
    setImpersonation(data.impersonation ?? null)
    if (data.impersonation) {
      localStorage.setItem('impersonation', JSON.stringify(data.impersonation))
    } else {
      localStorage.removeItem('impersonation')
    }
    return data
  }, [])

  const startImpersonation = useCallback(async (userId) => {
    const data = await authService.impersonate(userId)
    if (data.user) {
      setUser(data.user)
      localStorage.setItem('user', JSON.stringify(data.user))
    }
    setImpersonation(data.impersonation ?? null)
    if (data.impersonation) {
      localStorage.setItem('impersonation', JSON.stringify(data.impersonation))
    }
    return data
  }, [])

  const hasPermission = useCallback((permission) => {
    if (!user) return false
    if (user.role?.toLowerCase() === 'admin') return true
    const permissions = user.permissions ?? []
    if (permissions.includes('*')) return true
    if (permissions.includes(permission)) return true
    return permissions.some((granted) => {
      if (granted.endsWith('.*')) {
        const prefix = granted.slice(0, -2)
        return permission.startsWith(`${prefix}.`)
      }
      return false
    })
  }, [user])

  const hasModule = useCallback((moduleName) => {
    if (!user || !user.modules) return false
    if (user.role?.toLowerCase() === 'admin') return true
    return user.modules.includes(moduleName)
  }, [user])

  const hasModuleAccess = useCallback((moduleKey) => {
    // Admins always have access
    if (user?.role?.toLowerCase() === 'admin') return true
    // Check if module is in user's accessible modules
    if (user?.accessible_modules) {
      return user.accessible_modules.includes(moduleKey)
    }
    // Fallback to hasModule for backwards compatibility
    return hasModule(moduleKey)
  }, [user, hasModule])

  const value = useMemo(
    () => ({
      user,
      token,
      impersonation,
      portalConfig,
      loading,
      error,
      pendingChallenge,
      isAuthenticated,
      isCustomer,
      isStaff,
      isAdmin,
      portalReady,
      checkAuth,
      fetchCurrentUser,
      login,
      logout,
      bootstrapPortal,
      register,
      requestPasswordReset,
      resetPassword,
      acceptInvite,
      updateProfile,
      verifyTwoFactor,
      startImpersonation,
      stopImpersonation,
      hasPermission,
      hasModule,
      hasModuleAccess,
      // Phase 11: WOMS multi-trade service-line state.
      serviceLines,
      primaryServiceLineId,
      currentServiceLineId,
      currentServiceLine,
      setCurrentServiceLine,
    }),
    [
      bootstrapPortal,
      checkAuth,
      error,
      fetchCurrentUser,
      isAdmin,
      isAuthenticated,
      isCustomer,
      isStaff,
      login,
      logout,
      pendingChallenge,
      impersonation,
      portalConfig,
      portalReady,
      register,
      requestPasswordReset,
      resetPassword,
      acceptInvite,
      token,
      updateProfile,
      user,
      verifyTwoFactor,
      startImpersonation,
      stopImpersonation,
      hasPermission,
      hasModule,
      hasModuleAccess,
      serviceLines,
      primaryServiceLineId,
      currentServiceLineId,
      currentServiceLine,
      setCurrentServiceLine,
    ]
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuthStore() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuthStore must be used within an AuthProvider.')
  }

  return context
}
