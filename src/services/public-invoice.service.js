import axios from 'axios'

const publicApi = axios.create({
  baseURL: '/',
  headers: {
    'Content-Type': 'application/json',
  },
})

const publicInvoiceService = {
  async getInvoice(token) {
    const response = await publicApi.get(`/public/invoices/${token}`)
    return response.data
  },
  async createCheckout(token, payload) {
    const response = await publicApi.post(`/public/invoices/${token}/checkout`, payload)
    return response.data
  },
}

export default publicInvoiceService
