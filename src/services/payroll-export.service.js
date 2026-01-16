import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/payroll/exports', { params })
    return response.data
  },

  async exportPayroll(payload) {
    const response = await api.post('/payroll/exports', payload)
    return response.data
  },
}
