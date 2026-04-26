import api from './api'

/**
 * Workorder reassignment — request/approval flow for moving an in-flight WO
 * to a different technician, plus an immediate "reassign-now" override.
 */
export default {
  list(params = {}) {
    return api.get('/workorder-reassignment-requests', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/workorder-reassignment-requests/${id}`).then((res) => res.data)
  },
  listForWorkorder(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/reassignment-requests`, { params })
      .then((res) => res.data)
  },
  history(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/reassignment-history`, { params })
      .then((res) => res.data)
  },
  request(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/reassignment-requests`, payload)
      .then((res) => res.data)
  },
  reassignNow(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/reassign-now`, payload)
      .then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/workorder-reassignment-requests/${id}`, payload).then((res) => res.data)
  },
  approve(id, payload = {}) {
    return api
      .post(`/workorder-reassignment-requests/${id}/approve`, payload)
      .then((res) => res.data)
  },
  decline(id, payload = {}) {
    return api
      .post(`/workorder-reassignment-requests/${id}/decline`, payload)
      .then((res) => res.data)
  },
  cancel(id, payload = {}) {
    return api
      .post(`/workorder-reassignment-requests/${id}/cancel`, payload)
      .then((res) => res.data)
  },
  fulfil(id, payload = {}) {
    return api
      .post(`/workorder-reassignment-requests/${id}/fulfil`, payload)
      .then((res) => res.data)
  },
}
