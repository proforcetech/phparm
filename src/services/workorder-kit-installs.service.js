import api from './api'

/**
 * Kit installs — pre-built parts/labor bundles applied to a workorder job line.
 */
export default {
  listForWorkorder(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/kit-installs`, { params })
      .then((res) => res.data)
  },
  listForJob(jobId, params = {}) {
    return api.get(`/workorder-jobs/${jobId}/kit-installs`, { params }).then((res) => res.data)
  },
  planned(params = {}) {
    return api.get('/workorder-kit-installs/planned', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/workorder-kit-installs/${id}`).then((res) => res.data)
  },
  createForWorkorder(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/kit-installs`, payload)
      .then((res) => res.data)
  },
  install(id, payload = {}) {
    return api.post(`/workorder-kit-installs/${id}/install`, payload).then((res) => res.data)
  },
  cancel(id, payload = {}) {
    return api.post(`/workorder-kit-installs/${id}/cancel`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/workorder-kit-installs/${id}`).then((res) => res.data)
  },
}
