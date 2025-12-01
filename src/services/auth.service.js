import api from './api'

export const authService = {
  /**
   * Staff login
   */
  async login(email, password) {
    const response = await api.post('/auth/login', { email, password })
    return response.data
  },

  /**
   * Customer login
   */
  async customerLogin(email, password) {
    const response = await api.post('/auth/customer-login', { email, password })
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
  async requestPasswordReset(email) {
    const response = await api.post('/auth/password-reset', { email })
    return response.data
  },

  /**
   * Reset password with token
   */
  async resetPassword(token, password) {
    const response = await api.post('/auth/password-reset/confirm', { token, password })
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
    const response = await api.put('/auth/profile', data)
    return response.data
  },
}
