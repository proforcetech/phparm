import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import { authService } from '../../services/auth.service'
import { portalService } from '../../services/portal.service'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [token, setToken] = useState(null)
  const [portalConfig, setPortalConfig] = useState({
    apiBase: '/api',
    nonce: null,
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [pendingChallenge, setPendingChallenge] = useState(null)
  // Module access state
  const [enabledModules, setEnabledModules] = useState([])
  const [sidebarKeys, setSidebarKeys] = useState([])
  const [accessibleRoutes, setAccessibleRoutes] = useState([])

  const isAuthenticated = useMemo(() => !!token, [token])
  const isCustomer = useMemo(() => user?.role === 'customer', [user])
  const isStaff = useMemo(() => user && user.role !== 'customer', [user])
  const isAdmin = useMemo(() => user?.role === 'admin', [user])
  const portalReady = useMemo(() => isCustomer && !!portalConfig.nonce, [isCustomer, portalConfig])

  const checkAuth = useCallback(() => {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('user')
    const storedNonce = localStorage.getItem('portal_nonce')
    const storedModules = localStorage.getItem('enabled_modules')
    const storedSidebarKeys = localStorage.getItem('sidebar_keys')

    if (storedToken && storedUser) {
      setToken(storedToken)
      setUser(JSON.parse(storedUser))
    }

    if (storedNonce) {
      setPortalConfig((prev) => ({ ...prev, nonce: storedNonce }))
    }

    if (storedModules) {
      setEnabledModules(JSON.parse(storedModules))
    }

    if (storedSidebarKeys) {
      setSidebarKeys(JSON.parse(storedSidebarKeys))
    }
  }, [])

  const fetchCurrentUser = useCallback(async () => {
    try {
      const data = await authService.me()
      if (data.user) {
        setUser(data.user)
        localStorage.setItem('user', JSON.stringify(data.user))
      }
      return data
    } catch (err) {
      await logout()
      throw err
    }
  }, [])

  const fetchAccessibleModules = useCallback(async () => {
    try {
      const data = await authService.getAccessibleModules()
      if (data.modules) {
        setEnabledModules(data.modules)
        localStorage.setItem('enabled_modules', JSON.stringify(data.modules))
      }
      if (data.sidebar_keys) {
        setSidebarKeys(data.sidebar_keys)
        localStorage.setItem('sidebar_keys', JSON.stringify(data.sidebar_keys))
      }
      if (data.routes) {
        setAccessibleRoutes(data.routes)
      }
      return data
    } catch (err) {
      console.error('Failed to fetch accessible modules:', err)
      return null
    }
  }, [])

  const hasModuleAccess = useCallback(
    (moduleKey) => {
      // Admin has access to all enabled modules
      if (user?.role === 'admin') {
        return true
      }
      return enabledModules.includes(moduleKey)
    },
    [enabledModules, user]
  )

  const hasSidebarKey = useCallback(
    (key) => {
      // Admin sees all sidebar items
      if (user?.role === 'admin') {
        return true
      }
      // If no sidebar keys loaded yet, show all (loading state)
      if (sidebarKeys.length === 0) {
        return true
      }
      return sidebarKeys.includes(key)
    },
    [sidebarKeys, user]
  )

  const handleLoginSuccess = useCallback(
    async (data) => {
      if (data.token && data.user) {
        setToken(data.token)
        setUser(data.user)

        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))

        if (data.api_base) {
          setPortalConfig((prev) => ({ ...prev, apiBase: data.api_base }))
        }

        if (data.user.role === 'customer' && data.nonce) {
          setPortalConfig((prev) => ({ ...prev, nonce: data.nonce }))
          localStorage.setItem('portal_nonce', data.nonce)
        } else {
          setPortalConfig((prev) => ({ ...prev, nonce: null }))
          localStorage.removeItem('portal_nonce')
        }

        // Fetch accessible modules for staff users
        if (data.user.role !== 'customer') {
          try {
            await fetchAccessibleModules()
          } catch (err) {
            console.error('Failed to fetch modules on login:', err)
          }
        }

        if (data.user.role === 'customer') {
          window.location.assign('/portal')
        } else {
          window.location.assign('/cp/dashboard')
        }
      }
    },
    [fetchAccessibleModules]
  )

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
      setPortalConfig((prev) => ({ ...prev, nonce: null }))
      setEnabledModules([])
      setSidebarKeys([])
      setAccessibleRoutes([])
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      localStorage.removeItem('portal_nonce')
      localStorage.removeItem('enabled_modules')
      localStorage.removeItem('sidebar_keys')
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

  const value = useMemo(
    () => ({
      user,
      token,
      portalConfig,
      loading,
      error,
      pendingChallenge,
      isAuthenticated,
      isCustomer,
      isStaff,
      isAdmin,
      portalReady,
      // Module access
      enabledModules,
      sidebarKeys,
      accessibleRoutes,
      hasModuleAccess,
      hasSidebarKey,
      fetchAccessibleModules,
      // Actions
      checkAuth,
      fetchCurrentUser,
      login,
      logout,
      bootstrapPortal,
      register,
      requestPasswordReset,
      resetPassword,
      updateProfile,
      verifyTwoFactor,
    }),
    [
      accessibleRoutes,
      bootstrapPortal,
      checkAuth,
      enabledModules,
      error,
      fetchAccessibleModules,
      fetchCurrentUser,
      hasModuleAccess,
      hasSidebarKey,
      isAdmin,
      isAuthenticated,
      isCustomer,
      isStaff,
      loading,
      login,
      logout,
      pendingChallenge,
      portalConfig,
      portalReady,
      register,
      requestPasswordReset,
      resetPassword,
      sidebarKeys,
      token,
      updateProfile,
      user,
      verifyTwoFactor,
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
