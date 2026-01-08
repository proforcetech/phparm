import api from './api'

export default {
  /**
   * List core returns with filters
   * @param {Object} params - Filter parameters
   * @returns {Promise}
   */
  async list(params = {}) {
    const response = await api.get('/core-returns', { params })
    return response.data
  },

  /**
   * Get core returns summary (for dashboard)
   * @returns {Promise}
   */
  async getSummary() {
    const response = await api.get('/core-returns/summary')
    return response.data
  },

  /**
   * Get core tracking status (enabled/disabled)
   * @returns {Promise}
   */
  async getStatus() {
    const response = await api.get('/core-returns/status')
    return response.data
  },

  /**
   * Get single core return by ID
   * @param {number} id - Core return ID
   * @returns {Promise}
   */
  async get(id) {
    const response = await api.get(`/core-returns/${id}`)
    return response.data
  },

  /**
   * Create a new core return record
   * @param {Object} data - Core return data
   * @returns {Promise}
   */
  async create(data) {
    const response = await api.post('/core-returns', data)
    return response.data
  },

  /**
   * Mark core as received from customer
   * @param {number} id - Core return ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async receiveFromCustomer(id, notes = null) {
    const response = await api.post(`/core-returns/${id}/receive-from-customer`, { notes })
    return response.data
  },

  /**
   * Credit customer for returned core
   * @param {number} id - Core return ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async creditCustomer(id, notes = null) {
    const response = await api.post(`/core-returns/${id}/credit-customer`, { notes })
    return response.data
  },

  /**
   * Mark core as returned to vendor
   * @param {number} id - Core return ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async returnToVendor(id, notes = null) {
    const response = await api.post(`/core-returns/${id}/return-to-vendor`, { notes })
    return response.data
  },

  /**
   * Record vendor credit received
   * @param {number} id - Core return ID
   * @param {number} creditAmount - Credit amount received
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async recordVendorCredit(id, creditAmount, notes = null) {
    const response = await api.post(`/core-returns/${id}/vendor-credit`, {
      credit_amount: creditAmount,
      notes
    })
    return response.data
  },

  /**
   * Waive core (customer keeps old part)
   * @param {number} id - Core return ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async waive(id, notes = null) {
    const response = await api.post(`/core-returns/${id}/waive`, { notes })
    return response.data
  },

  /**
   * Mark core as expired
   * @param {number} id - Core return ID
   * @param {string|null} notes - Optional notes
   * @returns {Promise}
   */
  async expire(id, notes = null) {
    const response = await api.post(`/core-returns/${id}/expire`, { notes })
    return response.data
  }
}
