import api from './api'

/**
 * Tickets — service request intake before they become workorders. Includes
 * categories, SLA policies, routing rules, queues, escalation rules, and
 * close/resolution/failure code catalogs.
 */
export default {
  // Tickets
  list(params = {}) {
    return api.get('/tickets', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/tickets/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/tickets', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/tickets/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/tickets/${id}`).then((res) => res.data)
  },
  comment(id, payload) {
    return api.post(`/tickets/${id}/comments`, payload).then((res) => res.data)
  },
  sla(id) {
    return api.get(`/tickets/${id}/sla`).then((res) => res.data)
  },
  listLinkedWorkorders(id) {
    return api.get(`/tickets/${id}/workorders`).then((res) => res.data)
  },

  // Categories
  listCategories(params = {}) {
    return api.get('/ticket-categories', { params }).then((res) => res.data)
  },
  createCategory(payload) {
    return api.post('/ticket-categories', payload).then((res) => res.data)
  },
  updateCategory(id, payload) {
    return api.put(`/ticket-categories/${id}`, payload).then((res) => res.data)
  },
  deleteCategory(id) {
    return api.delete(`/ticket-categories/${id}`).then((res) => res.data)
  },

  // SLA policies
  listSlaPolicies(params = {}) {
    return api.get('/ticket-sla-policies', { params }).then((res) => res.data)
  },
  createSlaPolicy(payload) {
    return api.post('/ticket-sla-policies', payload).then((res) => res.data)
  },
  updateSlaPolicy(id, payload) {
    return api.put(`/ticket-sla-policies/${id}`, payload).then((res) => res.data)
  },
  deleteSlaPolicy(id) {
    return api.delete(`/ticket-sla-policies/${id}`).then((res) => res.data)
  },

  // Routing rules
  listRoutingRules(params = {}) {
    return api.get('/ticket-routing-rules', { params }).then((res) => res.data)
  },
  createRoutingRule(payload) {
    return api.post('/ticket-routing-rules', payload).then((res) => res.data)
  },
  updateRoutingRule(id, payload) {
    return api.put(`/ticket-routing-rules/${id}`, payload).then((res) => res.data)
  },
  deleteRoutingRule(id) {
    return api.delete(`/ticket-routing-rules/${id}`).then((res) => res.data)
  },

  // Queues
  listQueues(params = {}) {
    return api.get('/ticket-queues', { params }).then((res) => res.data)
  },
  createQueue(payload) {
    return api.post('/ticket-queues', payload).then((res) => res.data)
  },
  updateQueue(id, payload) {
    return api.put(`/ticket-queues/${id}`, payload).then((res) => res.data)
  },
  deleteQueue(id) {
    return api.delete(`/ticket-queues/${id}`).then((res) => res.data)
  },

  // Escalation rules
  listEscalationRules(params = {}) {
    return api.get('/ticket-escalation-rules', { params }).then((res) => res.data)
  },
  createEscalationRule(payload) {
    return api.post('/ticket-escalation-rules', payload).then((res) => res.data)
  },
  updateEscalationRule(id, payload) {
    return api.put(`/ticket-escalation-rules/${id}`, payload).then((res) => res.data)
  },
  deleteEscalationRule(id) {
    return api.delete(`/ticket-escalation-rules/${id}`).then((res) => res.data)
  },

  // Close / resolution / failure code catalogs
  listCloseReasons(params = {}) {
    return api.get('/ticket-close-reasons', { params }).then((res) => res.data)
  },
  createCloseReason(payload) {
    return api.post('/ticket-close-reasons', payload).then((res) => res.data)
  },
  updateCloseReason(id, payload) {
    return api.put(`/ticket-close-reasons/${id}`, payload).then((res) => res.data)
  },
  deleteCloseReason(id) {
    return api.delete(`/ticket-close-reasons/${id}`).then((res) => res.data)
  },

  listResolutionCodes(params = {}) {
    return api.get('/ticket-resolution-codes', { params }).then((res) => res.data)
  },
  createResolutionCode(payload) {
    return api.post('/ticket-resolution-codes', payload).then((res) => res.data)
  },
  updateResolutionCode(id, payload) {
    return api.put(`/ticket-resolution-codes/${id}`, payload).then((res) => res.data)
  },
  deleteResolutionCode(id) {
    return api.delete(`/ticket-resolution-codes/${id}`).then((res) => res.data)
  },

  listFailureCodes(params = {}) {
    return api.get('/ticket-failure-codes', { params }).then((res) => res.data)
  },
  createFailureCode(payload) {
    return api.post('/ticket-failure-codes', payload).then((res) => res.data)
  },
  updateFailureCode(id, payload) {
    return api.put(`/ticket-failure-codes/${id}`, payload).then((res) => res.data)
  },
  deleteFailureCode(id) {
    return api.delete(`/ticket-failure-codes/${id}`).then((res) => res.data)
  },
}
