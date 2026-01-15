import api from './api'

export default {
  // Driver Suggestions
  getSuggestions(params = {}) {
    return api.get('/dispatch/suggestions', { params })
  },

  // Waterfall Dispatch
  initiateWaterfall(payload) {
    return api.post('/dispatch/waterfall', payload)
  },
  getActiveWaterfalls() {
    return api.get('/dispatch/waterfall')
  },
  getWaterfallStatus(reference) {
    return api.get(`/dispatch/waterfall/${reference}`)
  },
  cancelWaterfall(id, reason = null) {
    return api.post(`/dispatch/waterfall/${id}/cancel`, { reason })
  },

  // Job Offers
  getJobOffers(params = {}) {
    return api.get('/dispatch/job-offers', { params })
  },
  createJobOffer(payload) {
    return api.post('/dispatch/job-offers', payload)
  },
  acceptOffer(offerId) {
    return api.post(`/dispatch/job-offers/${offerId}/accept`)
  },
  declineOffer(offerId, rejectionReason = null, rejectionNotes = null) {
    return api.post(`/dispatch/job-offers/${offerId}/decline`, {
      rejection_reason: rejectionReason,
      rejection_notes: rejectionNotes,
    })
  },
  getOffersForJob(jobReference) {
    return api.get(`/dispatch/jobs/${jobReference}/offers`)
  },

  // Rejection Reasons
  getRejectionReasons() {
    return api.get('/dispatch/rejection-reasons')
  },

  // Geofencing
  createGeofence(payload) {
    return api.post('/dispatch/geofences', payload)
  },
  checkGeofence(geofenceId, driverProfileId = null) {
    return api.get(`/dispatch/geofences/${geofenceId}/check`, {
      params: { driver_profile_id: driverProfileId },
    })
  },
  deleteGeofence(geofenceId) {
    return api.delete(`/dispatch/geofences/${geofenceId}`)
  },

  // Idle Alerts
  getIdleAlerts() {
    return api.get('/dispatch/idle-alerts')
  },
  acknowledgeIdleAlert(alertId, notes = null) {
    return api.post(`/dispatch/idle-alerts/${alertId}/acknowledge`, { notes })
  },

  // Analytics & Audit
  getRejectionAnalytics(days = 30) {
    return api.get('/dispatch/analytics/rejections', { params: { days } })
  },
  getJobAuditLog(jobReference, eventType = null) {
    return api.get(`/dispatch/audit/${jobReference}`, { params: { event_type: eventType } })
  },

  // Heatmap Data
  getHeatmapData(date = null, hour = null) {
    return api.get('/dispatch/heatmap', { params: { date, hour } })
  },

  // ETA Calculation
  calculateEta(fromLat, fromLng, toLat, toLng, driverProfileId = null) {
    return api.post('/dispatch/calculate-eta', {
      from_latitude: fromLat,
      from_longitude: fromLng,
      to_latitude: toLat,
      to_longitude: toLng,
      driver_profile_id: driverProfileId,
    })
  },

  // Driver Location
  updateDriverLocation(locationData) {
    return api.post('/driver/location', locationData)
  },
  getCurrentLocation() {
    return api.get('/driver/location/current')
  },

  // Certifications
  getDriverCertifications() {
    return api.get('/driver/certifications')
  },
  addCertification(payload) {
    return api.post('/driver/certifications', payload)
  },
  verifyCertification(certId, verified) {
    return api.post(`/dispatch/certifications/${certId}/verify`, { verified })
  },
}
