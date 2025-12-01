import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth.service'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const isAuthenticated = computed(() => !!token.value)
  const isCustomer = computed(() => user.value?.role === 'customer')
  const isStaff = computed(() => user.value && user.value.role !== 'customer')
  const isAdmin = computed(() => user.value?.role === 'admin')

  /**
   * Check if user is logged in (on app mount)
   */
  function checkAuth() {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('user')

    if (storedToken && storedUser) {
      token.value = storedToken
      user.value = JSON.parse(storedUser)
    }
  }

  /**
   * Login user
   */
  async function login(email, password, isCustomerLogin = false) {
    loading.value = true
    error.value = null

    try {
      const data = isCustomerLogin
        ? await authService.customerLogin(email, password)
        : await authService.login(email, password)

      if (data.token && data.user) {
        token.value = data.token
        user.value = data.user

        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))

        // Redirect based on role
        if (data.user.role === 'customer') {
          router.push('/portal')
        } else {
          router.push('/dashboard')
        }

        return data
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Login failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Logout user
   */
  async function logout() {
    try {
      await authService.logout()
    } catch (err) {
      console.error('Logout error:', err)
    } finally {
      user.value = null
      token.value = null
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      router.push('/login')
    }
  }

  /**
   * Register new staff member
   */
  async function register(userData) {
    loading.value = true
    error.value = null

    try {
      const data = await authService.register(userData)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Registration failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Request password reset
   */
  async function requestPasswordReset(email) {
    loading.value = true
    error.value = null

    try {
      const data = await authService.requestPasswordReset(email)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Password reset request failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Reset password with token
   */
  async function resetPassword(token, password) {
    loading.value = true
    error.value = null

    try {
      const data = await authService.resetPassword(token, password)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Password reset failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Update user profile
   */
  async function updateProfile(userData) {
    loading.value = true
    error.value = null

    try {
      const data = await authService.updateProfile(userData)
      user.value = data.user
      localStorage.setItem('user', JSON.stringify(data.user))
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Profile update failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  return {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    isCustomer,
    isStaff,
    isAdmin,
    checkAuth,
    login,
    logout,
    register,
    requestPasswordReset,
    resetPassword,
    updateProfile,
  }
})
