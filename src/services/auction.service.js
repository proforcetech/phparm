import api from './api'

export default {
  async listLots(params = {}) {
    const response = await api.get('/auction/lots', { params })
    return response.data
  },

  async createLot(payload) {
    const response = await api.post('/auction/lots', payload)
    return response.data
  },

  async updateLot(id, payload) {
    const response = await api.put(`/auction/lots/${id}`, payload)
    return response.data
  },

  async getSummary(params = {}) {
    const response = await api.get('/auction/reports/summary', { params })
    return response.data
  },
}
