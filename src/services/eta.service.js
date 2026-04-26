import api from './api'

/**
 * ETA promises — customer-facing time-of-arrival commitments for tickets and WOs.
 * Each entity has at most one "current" promise; new promises supersede it.
 */
export default {
  // Tickets
  listTicketPromises(ticketId) {
    return api.get(`/eta/tickets/${ticketId}/promises`).then((res) => res.data)
  },
  createTicketPromise(ticketId, payload) {
    return api.post(`/eta/tickets/${ticketId}/promises`, payload).then((res) => res.data)
  },
  clearCurrentTicketPromise(ticketId) {
    return api.delete(`/eta/tickets/${ticketId}/promises/current`).then((res) => res.data)
  },

  // Workorders
  listWorkorderPromises(workorderId) {
    return api.get(`/eta/workorders/${workorderId}/promises`).then((res) => res.data)
  },
  createWorkorderPromise(workorderId, payload) {
    return api.post(`/eta/workorders/${workorderId}/promises`, payload).then((res) => res.data)
  },
  clearCurrentWorkorderPromise(workorderId) {
    return api.delete(`/eta/workorders/${workorderId}/promises/current`).then((res) => res.data)
  },
}
