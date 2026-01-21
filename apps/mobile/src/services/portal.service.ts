import { api } from './api'
import { getCachedEntities, replaceCachedEntities } from '../utils/localCache'

const extractList = (payload: any) => {
  if (Array.isArray(payload)) {
    return payload
  }
  if (Array.isArray(payload?.data)) {
    return payload.data
  }
  return []
}

export const portalService = {
  async bootstrap() {
    const response = await api.get('/customer-portal/bootstrap')
    return response.data
  },

  async getVehicles() {
    const response = await api.get('/customer/vehicles')
    return response.data
  },

  async addVehicle(payload: Record<string, unknown>) {
    const response = await api.post('/customer/vehicles', payload)
    return response.data
  },

  async getEstimates(params: Record<string, unknown> = {}) {
    const response = await api.get('/estimates', { params })
    return response.data
  },

  async getInvoices(params: Record<string, unknown> = {}) {
    const response = await api.get('/invoices', { params })
    return response.data
  },

  async getWorkorders(params: Record<string, unknown> = {}) {
    try {
      const response = await api.get('/workorders', { params })
      const data = response.data
      const items = extractList(data)
      if (items.length > 0) {
        await replaceCachedEntities('workorders', items)
      }
      return data
    } catch (error) {
      const cached = await getCachedEntities('workorders')
      if (cached.length > 0) {
        return cached
      }
      throw error
    }
  },

  async getInvoiceById(id: number | string) {
    const response = await api.get(`/invoices/${id}`)
    return response.data
  },
}
