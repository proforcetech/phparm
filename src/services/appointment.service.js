import api from './api'

export default {
  /**
   * Get list of appointments with filters
   * @param {Object} params - Filter parameters
   * @returns {Promise}
   */
  getAppointments(params = {}) {
    return api.get('/appointments', { params })
  },

  /**
   * Get single appointment by ID
   * @param {number} id - Appointment ID
   * @returns {Promise}
   */
  getAppointment(id) {
    return api.get(`/appointments/${id}`)
  },

  /**
   * Create new appointment
   * @param {Object} payload - Appointment data
   * @returns {Promise}
   */
  createAppointment(payload) {
    return api.post('/appointments', payload)
  },

  /**
   * Update appointment
   * @param {number} id - Appointment ID
   * @param {Object} payload - Updated data
   * @returns {Promise}
   */
  updateAppointment(id, payload) {
    return api.put(`/appointments/${id}`, payload)
  },

  /**
   * Delete appointment
   * @param {number} id - Appointment ID
   * @returns {Promise}
   */
  deleteAppointment(id) {
    return api.delete(`/appointments/${id}`)
  },

  /**
   * Update appointment status
   * @param {number} id - Appointment ID
   * @param {string} status - New status
   * @returns {Promise}
   */
  updateAppointmentStatus(id, status) {
    return api.patch(`/appointments/${id}/status`, { status })
  },

  /**
   * Fetch availability slots
   * @param {Object} params - Filter parameters
   * @returns {Promise}
   */
  fetchAvailability(params = {}) {
    return api.get('/appointments/availability', { params })
  },

  /**
   * Fetch public availability slots
   * @param {Object} params - Filter parameters
   * @returns {Promise}
   */
  fetchPublicAvailability(params = {}) {
    return api.get('/public/appointments/availability', { params })
  },

  /**
   * Get availability configuration
   * @returns {Promise}
   */
  fetchAvailabilityConfig() {
    return api.get('/appointments/availability/config')
  },

  /**
   * Save availability configuration
   * @param {Object} payload - Configuration data
   * @returns {Promise}
   */
  saveAvailabilityConfig(payload) {
    return api.put('/appointments/availability/config', payload)
  }
}
