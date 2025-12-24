import api from './api'

export default {
  list(params = {}) {
    return api.get('/financial/entries', { params }).then((res) => res.data)
  },
  create(payload) {
    return api.post('/financial/entries', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/financial/entries/${id}`, payload).then((res) => res.data)
  },
  destroy(id) {
    return api.delete(`/financial/entries/${id}`).then((res) => res.data)
  },
  exportEntries(params = {}) {
    return api
      .get('/financial/entries/export', { params })
      .then((res) => res.data)
  },
  uploadAttachment(id, file) {
    const formData = new FormData()
    formData.append('file', file)
    return api
      .post(`/financial/entries/${id}/attachment`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((res) => res.data)
  },
  removeAttachment(id) {
    return api.delete(`/financial/entries/${id}/attachment`).then((res) => res.data)
  },
  report(params = {}) {
    return api.get('/financial/reports', { params }).then((res) => res.data)
  },
  exportReport(params = {}) {
    return api.get('/financial/reports/export', { params }).then((res) => res.data)
  },
}
