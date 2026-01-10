import api from './api'

export default {
  listTemplates(params = {}) {
    return api.get('/truck-checklists/templates', { params }).then((r) => r.data)
  },
  getTemplate(id) {
    return api.get(`/truck-checklists/templates/${id}`).then((r) => r.data)
  },
  getDefaultTemplate(checklistType) {
    return api.get('/truck-checklists/templates/default', { params: { checklist_type: checklistType } }).then((r) => r.data)
  },
  createTemplate(payload) {
    return api.post('/truck-checklists/templates', payload).then((r) => r.data)
  },
  updateTemplate(id, payload) {
    return api.put(`/truck-checklists/templates/${id}`, payload).then((r) => r.data)
  },
  deleteTemplate(id) {
    return api.delete(`/truck-checklists/templates/${id}`).then((r) => r.data)
  },
  listEntries(params = {}) {
    return api.get('/truck-checklists/entries', { params }).then((r) => r.data)
  },
  getEntry(id) {
    return api.get(`/truck-checklists/entries/${id}`).then((r) => r.data)
  },
  createEntry(payload) {
    return api.post('/truck-checklists/entries', payload).then((r) => r.data)
  },
  getActiveShift() {
    return api.get('/driver/shifts/active').then((r) => r.data)
  },
  startShift(payload) {
    return api.post('/driver/shifts/start', payload).then((r) => r.data)
  },
  endShift(id, payload) {
    return api.post(`/driver/shifts/${id}/end`, payload).then((r) => r.data)
  },
}
