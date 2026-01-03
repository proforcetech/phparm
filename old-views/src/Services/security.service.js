import api from './api'

export const securityService = {
  async getRecaptchaSettings() {
    const response = await api.get('/public/security/recaptcha')
    return response.data
  },
}
