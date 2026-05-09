import api from './api'

export const stepUpService = {
  async verify(code) {
    const response = await api.post('/auth/step-up', { code })
    return response.data
  },

  async status() {
    const response = await api.get('/auth/step-up/status')
    return response.data
  },
}

export default stepUpService
