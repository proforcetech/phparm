import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/inventory', { params })
    return response.data
  },

  async getLowStock(params = {}) {
    const response = await api.get('/inventory/low-stock', { params })
    return response.data
  },

  async get(id) {
    const response = await api.get(`/inventory/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/inventory', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/inventory/${id}`, payload)
    return response.data
  },

  async remove(id) {
    const response = await api.delete(`/inventory/${id}`)
    return response.data
  },
}
