import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/labor-tasks', { params })
    return response.data
  },

  async getActiveTasks(params = {}) {
    const response = await api.get('/labor-tasks/active', { params })
    return response.data
  },

  async get(id) {
    const response = await api.get(`/labor-tasks/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/labor-tasks', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/labor-tasks/${id}`, payload)
    return response.data
  },

  async delete(id) {
    const response = await api.delete(`/labor-tasks/${id}`)
    return response.data
  },

  async getEfficiencyReport(params = {}) {
    const response = await api.get('/labor-tasks/efficiency', { params })
    return response.data
  },
}
