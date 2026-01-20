import { api } from './api'

export const authService = {
  async login(email: string, password: string, recaptchaToken: string | null = null) {
    const response = await api.post('/auth/login', { email, password, recaptcha_token: recaptchaToken })
    return response.data
  },

  async customerLogin(email: string, password: string, recaptchaToken: string | null = null) {
    const response = await api.post('/auth/customer-login', {
      email,
      password,
      recaptcha_token: recaptchaToken,
    })
    return response.data
  },

  async verifyTwoFactor(challengeToken: string, code: string, isCustomer = false) {
    const endpoint = isCustomer ? '/auth/customer-verify-2fa' : '/auth/verify-2fa'
    const response = await api.post(endpoint, { challenge_token: challengeToken, code })
    return response.data
  },

  async logout() {
    const response = await api.post('/auth/logout')
    return response.data
  },

  async register(data: Record<string, unknown>) {
    const response = await api.post('/auth/register', data)
    return response.data
  },

  async requestPasswordReset(email: string, recaptchaToken: string | null = null) {
    const response = await api.post('/auth/forgot-password', {
      email,
      recaptcha_token: recaptchaToken,
    })
    return response.data
  },

  async resetPassword(token: string, password: string) {
    const response = await api.post('/auth/reset-password', { token, password })
    return response.data
  },

  async acceptInvite(token: string, password: string) {
    const response = await api.post('/auth/accept-invite', { token, password })
    return response.data
  },

  async me() {
    const response = await api.get('/auth/me')
    return response.data
  },

  async getCurrentUser() {
    return this.me()
  },

  async updateProfile(data: Record<string, unknown>) {
    const response = await api.put('/users/me', data)
    return response.data
  },

  async initiateTwoFactorSetup() {
    const response = await api.post('/auth/2fa/setup/initiate')
    return response.data
  },

  async completeTwoFactorSetup(code: string) {
    const response = await api.post('/auth/2fa/setup/complete', { code })
    return response.data
  },

  async getAccessibleModules() {
    const response = await api.get('/modules/accessible')
    return response.data
  },

  async listSessions() {
    const response = await api.get('/auth/sessions')
    return response.data
  },

  async impersonate(userId: number | string) {
    const response = await api.post('/auth/impersonate', { user_id: userId })
    return response.data
  },

  async revokeSession(sessionId: number | string) {
    const response = await api.delete(`/auth/sessions/${sessionId}`)
    return response.data
  },

  async stopImpersonation() {
    const response = await api.post('/auth/impersonate/stop')
    return response.data
  },
}
