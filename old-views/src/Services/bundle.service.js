import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/bundles', { params })
    return response.data
  },

  async get(id) {
    const response = await api.get(`/bundles/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/bundles', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/bundles/${id}`, payload)
    return response.data
  },

  async remove(id) {
    const response = await api.delete(`/bundles/${id}`)
    return response.data
  },

  async fetchItemsForEstimate(id) {
    const response = await api.get(`/estimates/bundles/${id}/items`)
    return response.data
  },
}
