import { api } from './api'
import { registerSyncHandler } from './offlineSync'
import { enqueueItem } from '../utils/offlineQueue'
import { isOnline } from '../utils/network'

const WORKORDER_JOB_STATUS = 'workorder_job_status'

registerSyncHandler(WORKORDER_JOB_STATUS, (payload) => {
  return api.patch(`/workorders/${payload.workorderId}/jobs/${payload.jobId}/status`, {
    status: payload.status,
  })
})

export type WorkorderFilters = {
  status?: string
  technician_id?: string | number
  priority?: string
  term?: string
  limit?: number
  offset?: number
}

const workorderService = {
  /**
   * Fetch list of workorders with optional filters
   */
  getWorkorders(params: WorkorderFilters = {}) {
    return api.get('/workorders', { params })
  },

  /**
   * Fetch single workorder details by ID
   */
  getWorkorder(id: number | string) {
    return api.get(`/workorders/${id}`)
  },

  /**
   * Fetch jobs assigned to the current technician
   */
  getTechnicianJobs() {
    return api.get('/time-tracking/technician/jobs')
  },

  /**
   * Fetch technician portal summary (jobs, active timer, history, totals)
   */
  getTechnicianPortal() {
    return api.get('/time-tracking/technician/portal')
  },

  /**
   * Update job status with offline support
   */
  async updateJobStatus(workorderId: number | string, jobId: number | string, status: string) {
    const online = await isOnline()
    if (!online) {
      await enqueueItem(WORKORDER_JOB_STATUS, { workorderId, jobId, status })
      return { queued: true }
    }

    return api.patch(`/workorders/${workorderId}/jobs/${jobId}/status`, { status })
  },
}

export default workorderService
