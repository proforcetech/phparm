import { api } from './api'

const invoiceService = {
  async getAll(params: Record<string, unknown> = {}) {
    const response = await api.get('/invoices', { params })
    return response.data
  },

  async getById(id: number | string) {
    const response = await api.get(`/invoices/${id}`)
    return response.data
  },

  async create(data: Record<string, unknown>) {
    const response = await api.post('/invoices', data)
    return response.data
  },

  async createCreditMemo(id: number | string, data: Record<string, unknown>) {
    const response = await api.post(`/invoices/${id}/credit-memos`, data)
    return response.data
  },

  async update(id: number | string, data: Record<string, unknown>) {
    const response = await api.put(`/invoices/${id}`, data)
    return response.data
  },

  async delete(id: number | string) {
    const response = await api.delete(`/invoices/${id}`)
    return response.data
  },

  async send(id: number | string) {
    const response = await api.post(`/invoices/${id}/send`)
    return response.data
  },

  async generatePdf(id: number | string) {
    const response = await api.get(`/invoices/${id}/pdf`, {
      responseType: 'blob',
    })
    return response.data
  },

  async processPayment(id: number | string, paymentData: Record<string, unknown>) {
    const response = await api.post(`/invoices/${id}/payment`, paymentData)
    return response.data
  },

  async createCheckout(id: number | string, provider: string, options: Record<string, unknown> = {}) {
    const response = await api.post(`/invoices/${id}/checkout`, {
      provider,
      ...options,
    })
    return response.data
  },

  async refund(id: number | string, data: Record<string, unknown>) {
    const response = await api.post(`/invoices/${id}/refund`, data)
    return response.data
  },

  async getStats() {
    const response = await api.get('/invoices/stats')
    return response.data
  },
}

export default invoiceService
