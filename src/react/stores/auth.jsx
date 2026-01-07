import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { authService } from '../../services/auth.service'
import { portalService } from '../../services/portal.service'

const AuthContext = createContext(null)

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const checkAuth = useCallback(async () => {
    try {
      const response = await authService.getCurrentUser()
      // Normalize user data: Handle if API wraps user in a 'data' property
      const userData = response.data || response
      setUser(userData)
      return userData
    } catch (error) {
      setUser(null)
      // We don't throw here to avoid unhandled rejections in the UI on initial load
      return null
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
    try {
      // Attempt backend logout
      await authService.logout()
    } catch (error) {
      console.error("Logout API error", error)
    } finally {
      // Clear local state
      setUser(null)
      // Force hard redirect to login page to ensure clean state and prevent JSON response
      window.location.href = '/login'
    }
  }, [])

  const hasPermission = useCallback((permission) => {
    if (!user) return false
    if (user.role?.toLowerCase() === 'admin') return true
    return user.permissions?.includes(permission)
  }, [user])

  const hasModule = useCallback((moduleName) => {
    if (!user) return false
    
    // FIX: Robust check for admin role (case-insensitive)
    if (user.role?.toLowerCase() === 'admin') return true
    
    // Check explicit module assignment
    const modules = user.modules || []
    return modules.includes(moduleName)
  }, [user])

  const isTechnician = useCallback(() => {
    return user?.role === 'technician'
  }, [user])

  // Computed properties for compatibility with Navbar
  const isAdmin = useMemo(() => user?.role?.toLowerCase() === 'admin', [user])
  const isCustomer = useMemo(() => user?.role === 'customer', [user])

  const value = useMemo(() => ({
    user,
    loading,
    login,
    logout,
    checkAuth,
    hasPermission,
    hasModule,
    isTechnician,
    // Add compatibility props for Navbar.jsx
    isAdmin,
    isCustomer
  }), [user, loading, login, logout, checkAuth, hasPermission, hasModule, isTechnician, isAdmin, isCustomer])

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

// Export alias so Navbar.jsx (which imports useAuthStore) works correctly
export const useAuthStore = useAuth
