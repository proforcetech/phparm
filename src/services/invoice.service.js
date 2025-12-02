import api from './api'

export default {
  /**
   * Get all invoices with filters
   */
  async getAll(params = {}) {
    const response = await api.get('/invoices', { params })
    return response.data
  },

  /**
   * Get single invoice by ID
   */
  async getById(id) {
    const response = await api.get(`/invoices/${id}`)
    return response.data
  },

  /**
   * Create new invoice
   */
  async create(data) {
    const response = await api.post('/invoices', data)
    return response.data
  },

  /**
   * Update existing invoice
   */
  async update(id, data) {
    const response = await api.put(`/invoices/${id}`, data)
    return response.data
  },

  /**
   * Delete invoice
   */
  async delete(id) {
    const response = await api.delete(`/invoices/${id}`)
    return response.data
  },

  /**
   * Send invoice to customer
   */
  async send(id) {
    const response = await api.post(`/invoices/${id}/send`)
    return response.data
  },

  /**
   * Generate PDF for invoice
   */
  async generatePdf(id) {
    const response = await api.get(`/invoices/${id}/pdf`, {
      responseType: 'blob',
    })
    return response.data
  },

  /**
   * Process payment for invoice
   */
  async processPayment(id, paymentData) {
    const response = await api.post(`/invoices/${id}/payment`, paymentData)
    return response.data
  },

  /**
   * Create checkout session for invoice
   */
  async createCheckout(id, provider, options = {}) {
    const response = await api.post(`/invoices/${id}/checkout`, {
      provider,
      ...options,
    })
    return response.data
  },

  /**
   * Refund payment
   */
  async refund(id, data) {
    const response = await api.post(`/invoices/${id}/refund`, data)
    return response.data
  },

  /**
   * Get invoice statistics
   */
  async getStats() {
    const response = await api.get('/invoices/stats')
    return response.data
  },
}
