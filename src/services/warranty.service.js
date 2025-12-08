import api from './api'

export const warrantyService = {
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
