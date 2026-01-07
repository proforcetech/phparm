import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import { authService } from '../../services/auth.service'
import { portalService } from '../../services/portal.service'

const AuthContext = createContext(null)

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const checkAuth = useCallback(async () => {
    try {
      const userData = await authService.getCurrentUser()
      setUser(userData)
      return userData
    } catch (error) {
      setUser(null)
      throw error
    } finally {
      setLoading(false)
    }
  }, [])

  const login = useCallback(async (credentials) => {
    const response = await authService.login(credentials)
    await checkAuth()
    return response
  }, [checkAuth])

  const logout = useCallback(async () => {
    await authService.logout()
    setUser(null)
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
    login,
    logout,
    checkAuth,
    hasPermission,
    hasModule,
    isTechnician,
    checkPortalAuth
  }), [user, loading, login, logout, checkAuth, hasPermission, hasModule, isTechnician, checkPortalAuth])

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
