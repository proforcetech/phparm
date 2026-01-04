import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/inventory/stock-orders', { params })
    return response.data
  },

  async get(id) {
    const response = await api.get(`/inventory/stock-orders/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/inventory/stock-orders', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/inventory/stock-orders/${id}`, payload)
    return response.data
  },
}
