import api from './api'

export default {
  /**
   * Get list of estimates with filters
   * @param {Object} filters - Filter parameters (status, customer_id, vehicle_id, etc.)
   * @returns {Promise}
   */
  getEstimates(filters = {}) {
    return api.get('/estimates', { params: filters })
  },

  /**
   * Get single estimate by ID
   * @param {number} id - Estimate ID
   * @returns {Promise}
   */
  getEstimate(id) {
    return api.get(`/estimates/${id}`)
  },

  /**
   * Create new estimate
   * @param {Object} data - Estimate data
   * @returns {Promise}
   */
  createEstimate(data) {
    return api.post('/estimates', data)
  },

  /**
   * Update estimate
   * @param {number} id - Estimate ID
   * @param {Object} data - Updated data
   * @returns {Promise}
   */
  updateEstimate(id, data) {
    return api.put(`/estimates/${id}`, data)
  },

  /**
   * Approve estimate
   * @param {number} id - Estimate ID
   * @param {string} reason - Optional reason
   * @returns {Promise}
   */
  approveEstimate(id, reason = null) {
    return api.post(`/estimates/${id}/approve`, { reason })
  },

  /**
   * Decline/reject estimate
   * @param {number} id - Estimate ID
   * @param {string} reason - Optional reason
   * @returns {Promise}
   */
  declineEstimate(id, reason = null) {
    return api.post(`/estimates/${id}/decline`, { reason })
  },

  /**
   * Request reapproval for estimate
   * @param {number} id - Estimate ID
   * @param {string} reason - Optional reason
   * @returns {Promise}
   */
  requestReapproval(id, reason = null) {
    return api.post(`/estimates/${id}/reapproval`, { reason })
  },

  /**
   * Expire estimate
   * @param {number} id - Estimate ID
   * @param {string} reason - Optional reason
   * @returns {Promise}
   */
  expireEstimate(id, reason = null) {
    return api.post(`/estimates/${id}/expire`, { reason })
  },

  /**
   * Convert estimate to invoice
   * @param {number} id - Estimate ID
   * @param {Object} data - Invoice data (issue_date, due_date, job_ids)
   * @returns {Promise}
   */
  convertToInvoice(id, data) {
    return api.post('/invoices/from-estimate', {
      estimate_id: id,
      ...data
    })
  },

  /**
   * Get bundle items for estimate
   * @param {number} bundleId - Bundle ID
   * @returns {Promise}
   */
  getBundleItems(bundleId) {
    return api.get(`/estimates/bundles/${bundleId}/items`)
  },

  /**
   * Delete estimate
   * @param {number} id - Estimate ID
   * @returns {Promise}
   */
  deleteEstimate(id) {
    return api.delete(`/estimates/${id}`)
  }
}
