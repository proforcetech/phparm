import api from './api'
import { enqueueItem } from '../react/utils/offlineQueue'
import offlineSync from '../react/services/offlineSync'

const getCurrentLocation = () => new Promise((resolve) => {
  if (typeof navigator === 'undefined' || !navigator.geolocation) {
    resolve(null)
    return
  }

  navigator.geolocation.getCurrentPosition(
    (position) => {
      resolve({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : null,
      })
    },
    () => resolve(null),
    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0,
    }
  )
})

const buildLocationPayload = async (locationOverride, captureIfMissing = true) => {
  const location = locationOverride !== undefined
    ? locationOverride
    : (captureIfMissing ? await getCurrentLocation() : null)
  if (!location || location.latitude == null || location.longitude == null) {
    return null
  }

  return {
    latitude: location.latitude,
    longitude: location.longitude,
    accuracy: location.accuracy ?? null,
  }
}

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
   * Create a direct workorder for a B2B customer with an active contract.
   * Bypasses the estimate stage. Backend enforces:
   *   - workorders.create_direct permission
   *   - customer.company_id is set
   *   - active contract covering the chosen service_line_id
   *   - subject FK matches the service_line's subject_column rule
   *
   * Payload mirrors WorkorderService::createDirect — minimum:
   *   { customer_id, service_line_id, jobs: [{ title, items: [...] }],
   *     vehicle_id?|site_asset_id?, branch_id?, type?, priority?, ... }
   *
   * @param {Object} payload
   * @returns {Promise}
   */
  createDirect(payload) {
    return api.post('/workorders/direct', payload)
  },

  /**
   * Update workorder status
   * @param {number} id - Workorder ID
   * @param {string} status - New status
   * @param {string} notes - Optional notes
   * @returns {Promise}
   */
  async updateStatus(id, status, notes = null, options = {}) {
    const {
      allowQueue = true,
      clientEventId = null,
      location,
      payload = {},
    } = options
    const hasLocationOverride = Object.prototype.hasOwnProperty.call(options, 'location')
    const locationPayload = await buildLocationPayload(location, !hasLocationOverride)
    const requestPayload = {
      status,
      notes,
      client_event_id: clientEventId,
      ...payload,
      ...(locationPayload ? { location: locationPayload } : {}),
    }
    if (!allowQueue) {
      return api.patch(`/workorders/${id}/status`, requestPayload)
    }

    const eventId = clientEventId || crypto.randomUUID()
    enqueueItem('workorder_status', {
      id,
      status,
      notes,
      clientEventId: eventId,
      location: locationPayload,
      payload,
    })

    if (typeof navigator !== 'undefined' && navigator.onLine) {
      return offlineSync.manualSync().then(() => ({ queued: true, client_event_id: eventId }))
    }

    return Promise.resolve({ queued: true, client_event_id: eventId })
  },

  /**
   * Assign technician to workorder
   * @param {number} id - Workorder ID
   * @param {number} technicianId - Technician user ID
   * @returns {Promise}
   */
  assignTechnician(id, technicianId, recommendedDriver = null) {
    return api.patch(`/workorders/${id}/assign`, {
      technician_id: technicianId,
      ...(recommendedDriver ? { recommended_driver: recommendedDriver } : {}),
    })
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
   * Update workorder type (corrective, preventive, inspection, install,
   * project, recurring_visit, change_request).
   * @param {number} id - Workorder ID
   * @param {string} type - Workorder type
   * @returns {Promise}
   */
  updateType(id, type) {
    return api.patch(`/workorders/${id}/type`, { type })
  },

  /**
   * Pin a property-management workorder to a unit and snapshot the billing
   * routing decision from the unit's active lease (Phase 12 of
   * docs/woms-expansion-plan.md). Pass unitId=null to clear and detach.
   * `category` is consulted only when the lease's billing_responsibility is
   * 'split'; ignored otherwise.
   * @param {number} id - Workorder ID
   * @param {number|null} unitId - Unit ID, or null to clear
   * @param {string|null} category - Optional maintenance category for split leases
   * @returns {Promise}
   */
  updateUnit(id, unitId, category = null) {
    return api.patch(`/workorders/${id}/unit`, { unit_id: unitId, category })
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
  async updateJobStatus(workorderId, jobId, status, options = {}) {
    const { allowQueue = true, clientEventId = null, location } = options
    const hasLocationOverride = Object.prototype.hasOwnProperty.call(options, 'location')
    const locationPayload = await buildLocationPayload(location, !hasLocationOverride)
    if (!allowQueue) {
      return api.patch(`/workorders/${workorderId}/jobs/${jobId}/status`, {
        status,
        client_event_id: clientEventId,
        ...(locationPayload ? { location: locationPayload } : {}),
      })
    }

    const eventId = clientEventId || crypto.randomUUID()
    enqueueItem('workorder_job_status', {
      workorderId,
      jobId,
      status,
      clientEventId: eventId,
      location: locationPayload,
    })

    if (typeof navigator !== 'undefined' && navigator.onLine) {
      return offlineSync.manualSync().then(() => ({ queued: true, client_event_id: eventId }))
    }

    return Promise.resolve({ queued: true, client_event_id: eventId })
  },

  /**
   * Assign technician to specific job
   * @param {number} workorderId - Workorder ID
   * @param {number} jobId - Job ID
   * @param {number} technicianId - Technician user ID
   * @returns {Promise}
   */
  assignJobTechnician(workorderId, jobId, technicianId, recommendedDriver = null) {
    return api.patch(`/workorders/${workorderId}/jobs/${jobId}/assign`, {
      technician_id: technicianId,
      ...(recommendedDriver ? { recommended_driver: recommendedDriver } : {}),
    })
  },

  /**
   * Upload checkpoint photo for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @param {string} checkpointType
   * @param {File} file
   * @returns {Promise}
   */
  uploadJobCheckpoint(workorderId, jobId, checkpointType, file) {
    const formData = new FormData()
    formData.append('file', file)
    return api.post(`/workorders/${workorderId}/jobs/${jobId}/checkpoints/${checkpointType}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },

  /**
   * Get checkpoint summary for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @returns {Promise}
   */
  getJobCheckpointStatus(workorderId, jobId) {
    return api.get(`/workorders/${workorderId}/jobs/${jobId}/checkpoints`)
  },

  /**
   * Upload a damage photo for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @param {File} file
   * @returns {Promise}
   */
  uploadJobDamagePhoto(workorderId, jobId, file) {
    const formData = new FormData()
    formData.append('file', file)
    return api.post(`/workorders/${workorderId}/jobs/${jobId}/damage-photos`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },

  /**
   * Get damage photo summary for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @returns {Promise}
   */
  getJobDamagePhotoStatus(workorderId, jobId) {
    return api.get(`/workorders/${workorderId}/jobs/${jobId}/damage-photos`)
  },

  /**
   * Create a damage report for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @param {Object} payload
   * @returns {Promise}
   */
  createDamageReport(workorderId, jobId, payload) {
    return api.post(`/workorders/${workorderId}/jobs/${jobId}/damage-reports`, payload)
  },

  /**
   * List damage reports for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @returns {Promise}
   */
  getDamageReports(workorderId, jobId) {
    return api.get(`/workorders/${workorderId}/jobs/${jobId}/damage-reports`)
  },

  /**
   * Save vehicle intake details for a job
   * @param {number} workorderId
   * @param {number} jobId
   * @param {Object} payload
   * @returns {Promise}
   */
  saveJobVehicleIntake(workorderId, jobId, payload) {
    return api.post(`/workorders/${workorderId}/jobs/${jobId}/vehicle-intake`, payload)
  },

  /**
   * Capture a job signature
   * @param {number} workorderId
   * @param {number} jobId
   * @param {Object} payload
   * @returns {Promise}
   */
  captureJobSignature(workorderId, jobId, payload) {
    return api.post(`/workorders/${workorderId}/jobs/${jobId}/signature`, payload)
  }
}
