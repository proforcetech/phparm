import api from './api'

export const authService = {
  /**
   * Staff login
   */
  async login(email, password, recaptchaToken = null) {
    const response = await api.post('/auth/login', { email, password, recaptcha_token: recaptchaToken })
    return response.data
  },

  /**
   * Customer login
   */
  async customerLogin(email, password, recaptchaToken = null) {
    const response = await api.post('/auth/customer-login', { email, password, recaptcha_token: recaptchaToken })
    return response.data
  },

  async verifyTwoFactor(challengeToken, code, isCustomer = false) {
    const endpoint = isCustomer ? '/auth/customer-verify-2fa' : '/auth/verify-2fa'
    const response = await api.post(endpoint, { challenge_token: challengeToken, code })
    return response.data
  },

  /**
   * Logout
   */
  async logout() {
    const response = await api.post('/auth/logout')
    return response.data
  },

  /**
   * Register staff member
   */
  async register(data) {
    const response = await api.post('/auth/register', data)
    return response.data
  },

  /**
   * Request password reset
   */
  async requestPasswordReset(email, recaptchaToken = null) {
    const response = await api.post('/auth/forgot-password', { email, recaptcha_token: recaptchaToken })
    return response.data
  },

  /**
   * Reset password with token
   */
  async resetPassword(token, password) {
    const response = await api.post('/auth/reset-password', { token, password })
    return response.data
  },

  /**
   * Get current user
   */
  async me() {
    const response = await api.get('/auth/me')
    return response.data
  },

  /**
   * Update user profile
   */
  async updateProfile(data) {
    const response = await api.put('/users/me', data)
    return response.data
  },

  /**
   * Initiate 2FA setup - generates secret and QR code URL
   */
  async initiateTwoFactorSetup() {
    const response = await api.post('/auth/2fa/setup/initiate')
    return response.data
  },

  /**
   * Complete 2FA setup - verify code and activate 2FA
   */
  async completeTwoFactorSetup(code) {
    const response = await api.post('/auth/2fa/setup/complete', { code })
    return response.data
  },
}
