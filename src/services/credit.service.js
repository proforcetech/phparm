import api from './api'

export const creditService = {
  async getCustomerHistory() {
    const response = await api.get('/credit-accounts/customer/history')
    return response.data
  },

  async submitPayment(payload) {
    const response = await api.post('/credit-accounts/customer/payments', payload)
    return response.data
  },
}
