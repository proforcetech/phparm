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

  const isAuthenticated = useMemo(() => !!token, [token])
  const isCustomer = useMemo(() => user?.role === 'customer', [user])
  const isStaff = useMemo(() => user && user.role !== 'customer', [user])
  const isAdmin = useMemo(() => user?.role === 'admin', [user])
  const portalReady = useMemo(() => isCustomer && !!portalConfig.nonce, [isCustomer, portalConfig])

  const checkAuth = useCallback(() => {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('user')
    const storedNonce = localStorage.getItem('portal_nonce')

    if (storedToken && storedUser) {
      setToken(storedToken)
      setUser(JSON.parse(storedUser))
    }

    if (storedNonce) {
      setPortalConfig((prev) => ({ ...prev, nonce: storedNonce }))
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

  const handleLoginSuccess = useCallback((data) => {
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

      if (data.user.role === 'customer') {
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
      setPortalConfig((prev) => ({ ...prev, nonce: null }))
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      localStorage.removeItem('portal_nonce')
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

  const hasPermission = useCallback((permission) => {
    if (!user) return false
    if (user.role?.toLowerCase() === 'admin') return true
    return user.permissions?.includes(permission)
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
      hasPermission,
      hasModule,
      hasModuleAccess,
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
      hasPermission,
      hasModule,
      hasModuleAccess,
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
