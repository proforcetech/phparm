import api from './api'

export default {
  async listCases(params = {}) {
    const response = await api.get('/storage/impound-cases', { params })
    return response.data
  },

  async createCase(payload) {
    const response = await api.post('/storage/impound-cases', payload)
    return response.data
  },

  async updateCase(id, payload) {
    const response = await api.put(`/storage/impound-cases/${id}`, payload)
    return response.data
  },
}
