import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/leave-requests', { params })
    return response.data
  },

  async listMine(params = {}) {
    const response = await api.get('/leave-requests/mine', { params })
    return response.data
  },

  async create(payload) {
    const response = await api.post('/leave-requests', payload)
    return response.data
  },

  async approve(id, payload = {}) {
    const response = await api.post(`/leave-requests/${id}/approve`, payload)
    return response.data
  },

  async reject(id, payload = {}) {
    const response = await api.post(`/leave-requests/${id}/reject`, payload)
    return response.data
  },
}
