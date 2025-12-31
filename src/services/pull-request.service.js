import api from './api'

export default {
  /**
   * Get list of pull requests with filters
   * @param {Object} params - Filter parameters
   * @returns {Promise}
   */
  list(params = {}) {
    return api.get('/inventory/pull-requests', { params })
  },

  /**
   * Get single pull request by ID
   * @param {number} id - Pull request ID
   * @returns {Promise}
   */
  get(id) {
    return api.get(`/inventory/pull-requests/${id}`)
  },

  /**
   * Get pull requests for a specific workorder
   * @param {number} workorderId - Workorder ID
   * @returns {Promise}
   */
  getByWorkorder(workorderId) {
    return api.get(`/workorders/${workorderId}/pull-requests`)
  },

  /**
   * Get pull requests summary (for dashboard)
   * @returns {Promise}
   */
  getSummary() {
    return api.get('/inventory/pull-requests/summary')
  },

  /**
   * Create a new pull request
   * @param {Object} data - Pull request data
   * @returns {Promise}
   */
  create(data) {
    return api.post('/inventory/pull-requests', data)
  },

  /**
   * Update a pull request
   * @param {number} id - Pull request ID
   * @param {Object} data - Updated data
   * @returns {Promise}
   */
  update(id, data) {
    return api.put(`/inventory/pull-requests/${id}`, data)
  },

  /**
   * Mark request as pulled from inventory
   * @param {number} id - Pull request ID
   * @param {number} quantity - Quantity pulled
   * @returns {Promise}
   */
  markAsPulled(id, quantity = 1) {
    return api.post(`/inventory/pull-requests/${id}/pull`, { quantity })
  },

  /**
   * Mark request as ordered
   * @param {number} id - Pull request ID
   * @param {string} orderReference - Optional PO number or order reference
   * @returns {Promise}
   */
  markAsOrdered(id, orderReference = null) {
    return api.post(`/inventory/pull-requests/${id}/order`, { order_reference: orderReference })
  },

  /**
   * Mark ordered request as received
   * @param {number} id - Pull request ID
   * @param {number} quantity - Quantity received
   * @returns {Promise}
   */
  markAsReceived(id, quantity = 1) {
    return api.post(`/inventory/pull-requests/${id}/receive`, { quantity })
  },

  /**
   * Cancel a pull request
   * @param {number} id - Pull request ID
   * @returns {Promise}
   */
  cancel(id) {
    return api.post(`/inventory/pull-requests/${id}/cancel`)
  },

  /**
   * Delete a pull request
   * @param {number} id - Pull request ID
   * @returns {Promise}
   */
  remove(id) {
    return api.delete(`/inventory/pull-requests/${id}`)
  },
}
