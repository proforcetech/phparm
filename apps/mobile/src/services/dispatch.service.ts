import { api } from './api'

const dispatchService = {
  getJobOffers(params: Record<string, unknown> = {}) {
    return api.get('/dispatch/job-offers', { params })
  },
  getJobs(params: Record<string, unknown> = {}) {
    return api.get('/dispatch/jobs', { params })
  },
  acceptOffer(offerId: number | string) {
    return api.post(`/dispatch/job-offers/${offerId}/accept`)
  },
  declineOffer(offerId: number | string, rejectionReason?: string, rejectionNotes?: string) {
    return api.post(`/dispatch/job-offers/${offerId}/decline`, {
      rejection_reason: rejectionReason ?? null,
      rejection_notes: rejectionNotes ?? null,
    })
  },
}

export default dispatchService
