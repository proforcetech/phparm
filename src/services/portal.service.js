import api from './api'

export const portalService = {
  async bootstrap() {
    const response = await api.get('/customer-portal/bootstrap')
    return response.data
  },

  async getVehicles() {
    const response = await api.get('/customer/vehicles')
    return response.data
  },

  async addVehicle(payload) {
    const response = await api.post('/customer/vehicles', payload)
    return response.data
  },

  async getEstimates(params = {}) {
    const response = await api.get('/estimates', { params })
    return response.data
  },

  async getInvoices(params = {}) {
    const response = await api.get('/invoices', { params })
    return response.data
  },

  async getInvoiceById(id) {
    const response = await api.get(`/invoices/${id}`)
    return response.data
  },
}
