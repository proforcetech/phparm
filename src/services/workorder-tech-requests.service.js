import api from './api'

/**
 * Additional technician requests — primary tech requests help on an in-flight
 * WO; once approved, an "additional tech" row is created on the workorder.
 */
export default {
  // Tech-request workflow
  list(params = {}) {
    return api.get('/workorder-tech-requests', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/workorder-tech-requests/${id}`).then((res) => res.data)
  },
  listForWorkorder(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/tech-requests`, { params })
      .then((res) => res.data)
  },
  request(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/tech-requests`, payload)
      .then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/workorder-tech-requests/${id}`, payload).then((res) => res.data)
  },
  approve(id, payload = {}) {
    return api.post(`/workorder-tech-requests/${id}/approve`, payload).then((res) => res.data)
  },
  decline(id, payload = {}) {
    return api.post(`/workorder-tech-requests/${id}/decline`, payload).then((res) => res.data)
  },
  cancel(id, payload = {}) {
    return api.post(`/workorder-tech-requests/${id}/cancel`, payload).then((res) => res.data)
  },
  fulfil(id, payload = {}) {
    return api.post(`/workorder-tech-requests/${id}/fulfil`, payload).then((res) => res.data)
  },

  // Additional-tech rows on the WO
  listAdditionalTechs(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/additional-techs`, { params })
      .then((res) => res.data)
  },
  addAdditionalTech(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/additional-techs`, payload)
      .then((res) => res.data)
  },
  updateAdditionalTech(id, payload) {
    return api.put(`/workorder-additional-techs/${id}`, payload).then((res) => res.data)
  },
  removeAdditionalTech(id, payload = {}) {
    return api
      .post(`/workorder-additional-techs/${id}/remove`, payload)
      .then((res) => res.data)
  },
}
