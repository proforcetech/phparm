import api from './api'

export default {
  /**
   * Get list of workorders with filters
   * @param {Object} filters - Filter parameters (status, customer_id, vehicle_id, technician_id, etc.)
   * @returns {Promise}
   */
  getWorkorders(filters = {}) {
    return api.get('/workorders', { params: filters })
  },

  /**
   * Get single workorder by ID
   * @param {number} id - Workorder ID
   * @returns {Promise}
   */
  getWorkorder(id) {
    return api.get(`/workorders/${id}`)
  },

  /**
   * Get workorder statistics
   * @param {Object} filters - Filter parameters (technician_id)
   * @returns {Promise}
   */
  getStats(filters = {}) {
    return api.get('/workorders/stats', { params: filters })
  },

  /**
   * Create workorder from approved estimate
   * @param {number} estimateId - Estimate ID
   * @param {number} technicianId - Optional technician ID
   * @returns {Promise}
   */
  createFromEstimate(estimateId, technicianId = null) {
    return api.post('/workorders/from-estimate', {
      estimate_id: estimateId,
      technician_id: technicianId
    })
  },

  /**
   * Update workorder status
   * @param {number} id - Workorder ID
   * @param {string} status - New status
   * @param {string} notes - Optional notes
   * @returns {Promise}
   */
  updateStatus(id, status, notes = null) {
    return api.patch(`/workorders/${id}/status`, { status, notes })
  },

  /**
   * Assign technician to workorder
   * @param {number} id - Workorder ID
   * @param {number} technicianId - Technician user ID
   * @returns {Promise}
   */
  assignTechnician(id, technicianId) {
    return api.patch(`/workorders/${id}/assign`, { technician_id: technicianId })
  },

  /**
   * Update workorder priority
   * @param {number} id - Workorder ID
   * @param {string} priority - Priority level (low, normal, high, urgent)
   * @returns {Promise}
   */
  updatePriority(id, priority) {
    return api.patch(`/workorders/${id}/priority`, { priority })
  },

  /**
   * Convert completed workorder to invoice
   * @param {number} id - Workorder ID
   * @param {string} dueDate - Optional due date
   * @returns {Promise}
   */
  convertToInvoice(id, dueDate = null) {
    return api.post(`/workorders/${id}/to-invoice`, { due_date: dueDate })
  },

  /**
   * Create sub-estimate for additional work
   * @param {number} id - Workorder ID
   * @param {Object} data - Sub-estimate data with jobs
   * @returns {Promise}
   */
  createSubEstimate(id, data) {
    return api.post(`/workorders/${id}/sub-estimate`, data)
  },

  /**
   * Add approved sub-estimate jobs to workorder
   * @param {number} id - Workorder ID
   * @param {number} subEstimateId - Sub-estimate ID
   * @returns {Promise}
   */
  addSubEstimateJobs(id, subEstimateId) {
    return api.post(`/workorders/${id}/add-sub-estimate`, { sub_estimate_id: subEstimateId })
  },

  /**
   * Get workorder timeline
   * @param {number} id - Workorder ID
   * @returns {Promise}
   */
  getTimeline(id) {
    return api.get(`/workorders/${id}/timeline`)
  },

  /**
   * Update job status within workorder
   * @param {number} workorderId - Workorder ID
   * @param {number} jobId - Job ID
   * @param {string} status - New status
   * @returns {Promise}
   */
  updateJobStatus(workorderId, jobId, status) {
    return api.patch(`/workorders/${workorderId}/jobs/${jobId}/status`, { status })
  },

  /**
   * Assign technician to specific job
   * @param {number} workorderId - Workorder ID
   * @param {number} jobId - Job ID
   * @param {number} technicianId - Technician user ID
   * @returns {Promise}
   */
  assignJobTechnician(workorderId, jobId, technicianId) {
    return api.patch(`/workorders/${workorderId}/jobs/${jobId}/assign`, { technician_id: technicianId })
  }
}
