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

const workorderService = {
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
