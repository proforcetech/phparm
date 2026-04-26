import api from './api'

/**
 * Ticket triage suggestions — AI/rule-driven recommendations for category,
 * priority, queue, etc. Each suggestion is acceptable/rejectable.
 */
export default {
  list(params = {}) {
    return api.get('/ticket-triage-suggestions', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/ticket-triage-suggestions/${id}`).then((res) => res.data)
  },
  forTicket(ticketId) {
    return api.get(`/tickets/${ticketId}/triage-suggestions`).then((res) => res.data)
  },
  generateForTicket(ticketId, payload = {}) {
    return api
      .post(`/tickets/${ticketId}/triage-suggestions/generate`, payload)
      .then((res) => res.data)
  },
  accept(id, payload = {}) {
    return api.post(`/ticket-triage-suggestions/${id}/accept`, payload).then((res) => res.data)
  },
  reject(id, payload = {}) {
    return api.post(`/ticket-triage-suggestions/${id}/reject`, payload).then((res) => res.data)
  },
}
