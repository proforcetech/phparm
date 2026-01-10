import api from './api'

export const warrantyService = {
  async listClaims(params = {}) {
    const response = await api.get('/warranty-claims', { params })
    return response.data
  },

  async getClaim(id) {
    const response = await api.get(`/warranty-claims/${id}`)
    return response.data
  },

  async updateClaimStatus(id, payload) {
    const response = await api.patch(`/warranty-claims/${id}/status`, payload)
    return response.data
  },

  async listCustomerClaims(params = {}) {
    const response = await api.get('/customer/warranty-claims', { params })
    return response.data
  },

  async getCustomerClaim(id) {
    const response = await api.get(`/customer/warranty-claims/${id}`)
    return response.data
  },

  async submitClaim(payload) {
    const response = await api.post('/warranty-claims', payload)
    return response.data
  },

  async replyToClaim(id, message) {
    const response = await api.post(`/customer/warranty-claims/${id}/reply`, { message })
    return response.data
  },
}
