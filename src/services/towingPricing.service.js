import api from './api'

export default {
  // Service Classes
  async listServiceClasses(params = {}) {
    const response = await api.get('/towing/service-classes', { params })
    return response.data
  },

  async getServiceClass(id) {
    const response = await api.get(`/towing/service-classes/${id}`)
    return response.data
  },

  async createServiceClass(payload) {
    const response = await api.post('/towing/service-classes', payload)
    return response.data
  },

  async updateServiceClass(id, payload) {
    const response = await api.put(`/towing/service-classes/${id}`, payload)
    return response.data
  },

  async deleteServiceClass(id) {
    const response = await api.delete(`/towing/service-classes/${id}`)
    return response.data
  },

  // Service Types
  async listServiceTypes(params = {}) {
    const response = await api.get('/towing/service-types', { params })
    return response.data
  },

  async getServiceType(id) {
    const response = await api.get(`/towing/service-types/${id}`)
    return response.data
  },

  async createServiceType(payload) {
    const response = await api.post('/towing/service-types', payload)
    return response.data
  },

  async updateServiceType(id, payload) {
    const response = await api.put(`/towing/service-types/${id}`, payload)
    return response.data
  },

  async deleteServiceType(id) {
    const response = await api.delete(`/towing/service-types/${id}`)
    return response.data
  },

  // Price Matrix
  async listPriceMatrix(params = {}) {
    const response = await api.get('/towing/pricing-matrix', { params })
    return response.data
  },

  async getFullMatrix() {
    const response = await api.get('/towing/pricing-matrix/full')
    return response.data
  },

  async getPriceMatrix(id) {
    const response = await api.get(`/towing/pricing-matrix/${id}`)
    return response.data
  },

  async createPriceMatrix(payload) {
    const response = await api.post('/towing/pricing-matrix', payload)
    return response.data
  },

  async updatePriceMatrix(id, payload) {
    const response = await api.put(`/towing/pricing-matrix/${id}`, payload)
    return response.data
  },

  async upsertPriceMatrix(payload) {
    const response = await api.post('/towing/pricing-matrix/upsert', payload)
    return response.data
  },

  async bulkUpsertPriceMatrix(entries) {
    const response = await api.post('/towing/pricing-matrix/bulk', { entries })
    return response.data
  },

  async deletePriceMatrix(id) {
    const response = await api.delete(`/towing/pricing-matrix/${id}`)
    return response.data
  },

  // Price Calculation
  async calculatePrice(payload) {
    const response = await api.post('/towing/calculate-price', payload)
    return response.data
  },
}
