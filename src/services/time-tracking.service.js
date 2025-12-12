import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/time-tracking', { params })
    return response.data
  },

  async start(payload) {
    const response = await api.post('/time-tracking/start', payload)
    return response.data
  },

  async stop(id, payload) {
    const response = await api.post(`/time-tracking/${id}/stop`, payload)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/time-tracking', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/time-tracking/${id}`, payload)
    return response.data
  },

  async approve(id, payload = {}) {
    const response = await api.post(`/time-tracking/${id}/approve`, payload)
    return response.data
  },

  async reject(id, payload = {}) {
    const response = await api.post(`/time-tracking/${id}/reject`, payload)
    return response.data
  },

  async technicianJobs() {
    const response = await api.get('/time-tracking/technician/jobs')
    return response.data
  },

  async technicianPortal() {
    const response = await api.get('/time-tracking/technician/portal')
    return response.data
  },
}
