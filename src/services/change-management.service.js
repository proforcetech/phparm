import api from './api'

/**
 * Change management — RFC + CAB workflow. Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * State machine: draft → submitted → cab_review → approved/rejected
 *               → scheduled → in_progress → completed/rolled_back
 * Cancelled is a wildcard exit from any non-terminal state.
 */
export default {
  list(params = {}) {
    return api.get('/change-requests', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/change-requests/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/change-requests', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/change-requests/${id}`, payload).then((res) => res.data)
  },
  transition(id, payload) {
    return api.post(`/change-requests/${id}/transition`, payload).then((res) => res.data)
  },

  // CAB voting
  listApprovals(id) {
    return api.get(`/change-requests/${id}/approvals`).then((res) => res.data)
  },
  recordVote(id, payload) {
    return api.post(`/change-requests/${id}/votes`, payload).then((res) => res.data)
  },
  decide(id, payload = {}) {
    return api.post(`/change-requests/${id}/decide`, payload).then((res) => res.data)
  },

  // Calendar
  window(params = {}) {
    return api.get('/change-window', { params }).then((res) => res.data)
  },
}
