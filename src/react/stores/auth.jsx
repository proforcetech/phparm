import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import { authService } from '../../services/auth.service'
import { portalService } from '../../services/portal.service'

const AuthContext = createContext(null)

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [pendingChallenge, setPendingChallenge] = useState(null)

  const checkAuth = useCallback(async () => {
    try {
      const userData = await authService.getCurrentUser()
      setUser(userData)
      // Store user data in localStorage for router guards
      localStorage.setItem('user', JSON.stringify(userData))
      return userData
    } catch (err) {
      setUser(null)
      // Clear user data from localStorage on auth failure
      localStorage.removeItem('user')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const login = useCallback(async (email, password, remember = false, recaptchaToken = null) => {
    setError(null)
    setLoading(true)
    try {
      const response = await authService.login({ email, password, remember, recaptcha_token: recaptchaToken })

      // Check if 2FA is required
      if (response.requires_2fa) {
        setPendingChallenge({ email, password, remember, recaptchaToken })
        return { requires_2fa: true }
      }

      await checkAuth()
      return response
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Login failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [checkAuth])

  const verifyTwoFactor = useCallback(async (code) => {
    if (!pendingChallenge) {
      throw new Error('No pending 2FA challenge')
    }

    setError(null)
    setLoading(true)
    try {
      const response = await authService.verifyTwoFactor({
        email: pendingChallenge.email,
        password: pendingChallenge.password,
        two_factor_code: code,
        remember: pendingChallenge.remember,
        recaptcha_token: pendingChallenge.recaptchaToken
      })

      setPendingChallenge(null)
      await checkAuth()
      return response
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Verification failed')
      throw err
    } finally {
      setLoading(false)
    }
  }, [pendingChallenge, checkAuth])

  const logout = useCallback(async () => {
    await authService.logout()
    setUser(null)
    // Clear user data from localStorage
    localStorage.removeItem('user')
    localStorage.removeItem('auth_token')
  }, [])

  const hasPermission = useCallback((permission) => {
    if (!user) return false
    if (user.role?.toLowerCase() === 'admin') return true
    return user.permissions?.includes(permission)
  }, [user])

  const hasModule = useCallback((moduleName) => {
    if (!user || !user.modules) return false
    // FIX: Case-insensitive check for admin role
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

  const isTechnician = useCallback(() => {
    return user?.role === 'technician'
  }, [user])

  const checkPortalAuth = useCallback(async () => {
    try {
      const userData = await portalService.getCurrentUser()
      setUser(userData)
      return userData
    } catch (error) {
      setUser(null)
      throw error
    } finally {
      setLoading(false)
    }
  }, [])

  const value = useMemo(() => ({
    user,
    loading,
    error,
    pendingChallenge,
    login,
    logout,
    verifyTwoFactor,
    checkAuth,
    hasPermission,
    hasModule,
    hasModuleAccess,
    isTechnician,
    checkPortalAuth
  }), [user, loading, error, pendingChallenge, login, logout, verifyTwoFactor, checkAuth, hasPermission, hasModule, hasModuleAccess, isTechnician, checkPortalAuth])

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}

// Alias for backwards compatibility
export const useAuthStore = useAuth
