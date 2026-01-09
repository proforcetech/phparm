import api from './api'

export default {
  async listFees(params = {}) {
    const response = await api.get('/storage/fees', { params })
    return response.data
  },
  async automateFees(payload = {}) {
    const response = await api.post('/storage/fees/automate', payload)
    return response.data
  },

  async createFee(payload) {
    const response = await api.post('/storage/fees', payload)
    return response.data
  },

  async updateFee(id, payload) {
    const response = await api.put(`/storage/fees/${id}`, payload)
    return response.data
  },

  async removeFee(id) {
    const response = await api.delete(`/storage/fees/${id}`)
    return response.data
  },
}
