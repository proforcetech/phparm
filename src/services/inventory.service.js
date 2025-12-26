import api from './api'

export default {
  async list(params = {}) {
    const response = await api.get('/inventory', { params })
    return response.data
  },

  async getLowStock(params = {}) {
    const response = await api.get('/inventory/low-stock', { params })
    return response.data
  },

  async get(id) {
    const response = await api.get(`/inventory/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await api.post('/inventory', payload)
    return response.data
  },

  async update(id, payload) {
    const response = await api.put(`/inventory/${id}`, payload)
    return response.data
  },

  async remove(id) {
    const response = await api.delete(`/inventory/${id}`)
    return response.data
  },

  /**
   * Search inventory parts with optional vehicle compatibility filter
   * @param {string} query - Search query
   * @param {number|null} vehicleMasterId - Optional vehicle master ID for compatibility filter
   * @param {number} limit - Maximum results
   * @returns {Promise}
   */
  async searchParts(query, vehicleMasterId = null, limit = 20) {
    const params = { query, limit }
    if (vehicleMasterId) {
      params.vehicle_master_id = vehicleMasterId
    }
    const response = await api.get('/inventory/search-parts', { params })
    return response.data
  },

  /**
   * Find inventory item by SKU
   * @param {string} sku - SKU to search for
   * @returns {Promise}
   */
  async findBySku(sku) {
    const response = await api.get(`/inventory/by-sku/${encodeURIComponent(sku)}`)
    return response.data
  },

  /**
   * Get vehicle compatibility entries for an inventory item
   * @param {number} id - Inventory item ID
   * @returns {Promise}
   */
  async getVehicleCompatibility(id) {
    const response = await api.get(`/inventory/${id}/vehicle-compatibility`)
    return response.data
  },

  /**
   * Add vehicle compatibility entry
   * @param {number} id - Inventory item ID
   * @param {number} vehicleMasterId - Vehicle master ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async addVehicleCompatibility(id, vehicleMasterId, notes = null) {
    const response = await api.post(`/inventory/${id}/vehicle-compatibility`, {
      vehicle_master_id: vehicleMasterId,
      notes
    })
    return response.data
  },

  /**
   * Bulk add vehicle compatibility entries
   * @param {number} id - Inventory item ID
   * @param {number[]} vehicleMasterIds - Array of vehicle master IDs
   * @returns {Promise}
   */
  async bulkAddVehicleCompatibility(id, vehicleMasterIds) {
    const response = await api.post(`/inventory/${id}/vehicle-compatibility/bulk`, {
      vehicle_master_ids: vehicleMasterIds
    })
    return response.data
  },

  /**
   * Remove vehicle compatibility entry
   * @param {number} id - Inventory item ID
   * @param {number} vehicleMasterId - Vehicle master ID
   * @returns {Promise}
   */
  async removeVehicleCompatibility(id, vehicleMasterId) {
    const response = await api.delete(`/inventory/${id}/vehicle-compatibility/${vehicleMasterId}`)
    return response.data
  },
}
