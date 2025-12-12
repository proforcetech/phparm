import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth.service'
import { portalService } from '@/services/portal.service'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(null)
  const portalConfig = ref({
    apiBase: '/api',
    nonce: null,
  })
  const loading = ref(false)
  const error = ref(null)
  const pendingChallenge = ref(null)

  const isAuthenticated = computed(() => !!token.value)
  const isCustomer = computed(() => user.value?.role === 'customer')
  const isStaff = computed(() => user.value && user.value.role !== 'customer')
  const isAdmin = computed(() => user.value?.role === 'admin')
  const portalReady = computed(() => isCustomer.value && !!portalConfig.value.nonce)

  /**
   * Check if user is logged in (on app mount)
   */
  function checkAuth() {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('user')
    const storedNonce = localStorage.getItem('portal_nonce')

    if (storedToken && storedUser) {
      token.value = storedToken
      user.value = JSON.parse(storedUser)
    }

    if (storedNonce) {
      portalConfig.value.nonce = storedNonce
    }
  }

  async function fetchCurrentUser() {
    try {
      const data = await authService.me()
      if (data.user) {
        user.value = data.user
        localStorage.setItem('user', JSON.stringify(data.user))
      }
      return data
    } catch (err) {
      await logout()
      throw err
    }
  }

  /**
   * Login user
   */
  async function login(email, password, isCustomerLogin = false, recaptchaToken = null) {
    loading.value = true
    error.value = null

    try {
      const data = isCustomerLogin
        ? await authService.customerLogin(email, password, recaptchaToken)
        : await authService.login(email, password, recaptchaToken)

      if (data.status === '2fa_required') {
        pendingChallenge.value = {
          token: data.challenge_token,
          isCustomer: isCustomerLogin,
        }
        return data
      }

      if (data.token && data.user) {
        token.value = data.token
        user.value = data.user

        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))

        if (data.api_base) {
          portalConfig.value.apiBase = data.api_base
        }

        if (data.user.role === 'customer' && data.nonce) {
          portalConfig.value.nonce = data.nonce
          localStorage.setItem('portal_nonce', data.nonce)
        } else {
          portalConfig.value.nonce = null
          localStorage.removeItem('portal_nonce')
        }

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

  async function verifyTwoFactor(code) {
    if (!pendingChallenge.value) {
      throw new Error('No pending two-factor challenge')
    }

    loading.value = true
    error.value = null

    try {
      const data = await authService.verifyTwoFactor(
        pendingChallenge.value.token,
        code,
        pendingChallenge.value.isCustomer
      )

      pendingChallenge.value = null

      if (data.token && data.user) {
        token.value = data.token
        user.value = data.user

        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))

        if (data.api_base) {
          portalConfig.value.apiBase = data.api_base
        }

        if (data.user.role === 'customer' && data.nonce) {
          portalConfig.value.nonce = data.nonce
          localStorage.setItem('portal_nonce', data.nonce)
        } else {
          portalConfig.value.nonce = null
          localStorage.removeItem('portal_nonce')
        }

        if (data.user.role === 'customer') {
          router.push('/portal')
        } else {
          router.push('/dashboard')
        }
      }

      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Two-factor verification failed'
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
      portalConfig.value.nonce = null
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      localStorage.removeItem('portal_nonce')
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
  async function requestPasswordReset(email, recaptchaToken = null) {
    loading.value = true
    error.value = null

    try {
      const data = await authService.requestPasswordReset(email, recaptchaToken)
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

  async function bootstrapPortal() {
    if (!isCustomer.value) {
      return null
    }

    const data = await portalService.bootstrap()

    if (data.user) {
      user.value = data.user
      localStorage.setItem('user', JSON.stringify(data.user))
    }

    if (data.token) {
      token.value = data.token
      localStorage.setItem('auth_token', data.token)
    }

    if (data.api_base) {
      portalConfig.value.apiBase = data.api_base
    }

    if (data.nonce) {
      portalConfig.value.nonce = data.nonce
      localStorage.setItem('portal_nonce', data.nonce)
    }

    return data
  }

  return {
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
    updateProfile,
    verifyTwoFactor,
  }
})
