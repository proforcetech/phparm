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
   * Search inventory parts with compatibility highlighting
   * Returns all matching parts with is_compatible flag for the specified vehicle
   * @param {string} query - Search query
   * @param {number|null} vehicleMasterId - Vehicle master ID to check compatibility against
   * @param {number} limit - Maximum results
   * @returns {Promise}
   */
  async searchWithCompatibility(query, vehicleMasterId = null, limit = 20) {
    const params = { query, limit }
    if (vehicleMasterId) {
      params.vehicle_master_id = vehicleMasterId
    }
    const response = await api.get('/inventory/search-with-compatibility', { params })
    return response.data
  },

  /**
   * Get all inventory items compatible with a specific vehicle
   * @param {number} vehicleMasterId - Vehicle master ID
   * @param {number} limit - Maximum results
   * @returns {Promise}
   */
  async getCompatibleParts(vehicleMasterId, limit = 100) {
    const response = await api.get(`/inventory/compatible-parts/${vehicleMasterId}`, {
      params: { limit }
    })
    return response.data
  },

  /**
   * Find inventory item by SKU
   * @param {string} sku - SKU to search for
   * @returns {Promise}
   */
  async findBySku(sku) {
    const response = await api.get('/inventory/by-sku', {
      params: { sku }
    })
    return response.data
  },

  /**
   * Find inventory item by barcode or UPC
   * @param {string} code - Barcode or UPC value
   * @param {string} scanType - Type of scan (inventory_lookup, workorder_add, etc.)
   * @param {number|null} workorderId - Related workorder ID
   * @param {number|null} invoiceId - Related invoice ID
   * @returns {Promise}
   */
  async findByBarcode(code, scanType = 'inventory_lookup', workorderId = null, invoiceId = null) {
    const params = { code, scan_type: scanType }
    if (workorderId) params.workorder_id = workorderId
    if (invoiceId) params.invoice_id = invoiceId

    const response = await api.get('/inventory/by-barcode', { params })
    return response.data
  },

  /**
   * Scan barcode (POST version for scanner input)
   * @param {string} code - Barcode or UPC value
   * @param {string} scanType - Type of scan
   * @param {number|null} workorderId - Related workorder ID
   * @param {number|null} invoiceId - Related invoice ID
   * @returns {Promise}
   */
  async scanBarcode(code, scanType = 'inventory_lookup', workorderId = null, invoiceId = null) {
    const response = await api.post('/inventory/scan-barcode', {
      code,
      scan_type: scanType,
      workorder_id: workorderId,
      invoice_id: invoiceId
    })
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

  /**
   * Export inventory to CSV
   * @param {Object} filters - Optional filters (category, location, query, low_stock)
   * @returns {Promise<Blob>}
   */
  async exportCsv(filters = {}) {
    const params = {}
    if (filters.category) params.category = filters.category
    if (filters.location) params.location = filters.location
    if (filters.query) params.query = filters.query
    if (filters.low_stock_only) params.low_stock = 'true'

    const response = await api.get('/inventory/export', {
      params,
      responseType: 'blob'
    })
    return response.data
  },

  /**
   * Import inventory from CSV
   * @param {string} csvContent - CSV file content as string
   * @param {boolean} updateExisting - Whether to update existing items (by SKU)
   * @returns {Promise}
   */
  async importCsv(csvContent, updateExisting = false) {
    const response = await api.post('/inventory/import', {
      csv: csvContent,
      update_existing: updateExisting
    })
    return response.data
  },

  /**
   * Generate a CSV template for importing inventory
   * @returns {string} - CSV template with headers and sample data
   */
  getTemplateContent() {
    const headers = [
      'name',
      'description',
      'sku',
      'category',
      'stock_quantity',
      'low_stock_threshold',
      'reorder_quantity',
      'cost',
      'core_cost',
      'core_price',
      'core_eligible',
      'sale_price',
      'markup',
      'location',
      'vendor',
      'notes'
    ]

    const sampleRow = [
      'Sample Part',
      'This is a sample description',
      'SAMPLE-001',
      'Brakes',
      '100',
      '10',
      '50',
      '25.00',
      '10.00',
      '15.00',
      'true',
      '49.99',
      '100',
      'Aisle 3',
      'ABC Parts Co',
      'Sample notes here'
    ]

    // Convert to CSV format
    const headerLine = headers.join(',')
    const sampleLine = sampleRow.map(field => {
      // Escape fields with commas or quotes
      if (field.includes(',') || field.includes('"') || field.includes('\n')) {
        return `"${field.replace(/"/g, '""')}"`
      }
      return field
    }).join(',')

    return `${headerLine}\n${sampleLine}\n`
  },

  /**
   * Download CSV template file
   */
  downloadTemplate() {
    const content = this.getTemplateContent()
    const blob = new Blob([content], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'inventory_template.csv'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  }
}
