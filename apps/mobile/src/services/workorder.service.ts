import { api } from './api'

const workorderService = {
  updateJobStatus(workorderId: number | string, jobId: number | string, status: string) {
    return api.patch(`/workorders/${workorderId}/jobs/${jobId}/status`, { status })
  },
}

export default workorderService
