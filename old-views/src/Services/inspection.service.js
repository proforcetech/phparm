import api from './api'

export default {
  listTemplates() {
    return api.get('/inspections/templates').then((r) => r.data)
  },
  getTemplate(id) {
    return api.get(`/inspections/templates/${id}`).then((r) => r.data)
  },
  createTemplate(payload) {
    return api.post('/inspections/templates', payload).then((r) => r.data)
  },
  updateTemplate(id, payload) {
    return api.put(`/inspections/templates/${id}`, payload).then((r) => r.data)
  },
  deleteTemplate(id) {
    return api.delete(`/inspections/templates/${id}`)
  },
  startInspection(payload) {
    return api.post('/inspections/start', payload).then((r) => r.data)
  },
  completeInspection(reportId, payload) {
    return api.post(`/inspections/${reportId}/complete`, payload).then((r) => r.data)
  },
  getInspection(reportId) {
    return api.get(`/inspections/${reportId}`).then((r) => r.data)
  },
  uploadMedia(reportId, file) {
    const form = new FormData()
    form.append('media', file)
    return api.post(`/inspections/${reportId}/media`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then((r) => r.data)
  },
  customerList() {
    return api.get('/inspections/customer').then((r) => r.data)
  },
  customerShow(id) {
    return api.get(`/inspections/customer/${id}`).then((r) => r.data)
  },
}
