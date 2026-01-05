import env from '@/config/env'
import api from './api'

export const securityService = {
  async getRecaptchaSettings() {
    try {
      const response = await api.get('/public/security/recaptcha')
      return response.data
    } catch (error) {
      return {
        enabled: !!env.RECAPTCHA_SITE_KEY,
        site_key: env.RECAPTCHA_SITE_KEY || null,
        score_threshold: 0.5,
      }
    }
  },
}
