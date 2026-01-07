import api from './api'
import { enqueueItem } from '../react/utils/offlineQueue'

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
  completeInspection(reportId, payload, options = {}) {
    const { allowQueue = true } = options
    if (allowQueue && typeof navigator !== 'undefined' && !navigator.onLine) {
      enqueueItem('inspection_complete', {
        reportId,
        payload,
      })
      return Promise.resolve({ queued: true })
    }
    return api.post(`/inspections/${reportId}/complete`, payload).then((r) => r.data)
  },
  getInspection(reportId) {
    return api.get(`/inspections/${reportId}`).then((r) => r.data)
  },
  uploadMedia(reportId, file, options = {}) {
    const { allowQueue = true, clientToken = null } = options
    const token = clientToken || crypto.randomUUID()
    if (allowQueue && typeof navigator !== 'undefined' && !navigator.onLine) {
      enqueueItem('inspection_media', {
        reportId,
        file,
        clientToken: token,
      })
      return Promise.resolve({ queued: true, client_token: token })
    }

    const form = new FormData()
    form.append('media', file)
    form.append('client_token', token)
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

  // Inspection-to-Estimate Bridge methods
  getFailedItems(reportId) {
    return api.get(`/inspections/${reportId}/failed-items`).then((r) => r.data)
  },
  getRecommendations(reportId) {
    return api.get(`/inspections/${reportId}/recommendations`).then((r) => r.data)
  },
  addToEstimate(reportId, payload) {
    return api.post(`/inspections/${reportId}/add-to-estimate`, payload).then((r) => r.data)
  },
  createEstimateFromInspection(reportId, payload) {
    return api.post(`/inspections/${reportId}/create-estimate`, payload).then((r) => r.data)
  },
  addToWorkorder(reportId, payload) {
    return api.post(`/inspections/${reportId}/add-to-workorder`, payload).then((r) => r.data)
  },
  declineRecommendation(reportId, itemId) {
    return api.post(`/inspections/${reportId}/recommendations/${itemId}/decline`).then((r) => r.data)
  },
  deferRecommendation(reportId, itemId) {
    return api.post(`/inspections/${reportId}/recommendations/${itemId}/defer`).then((r) => r.data)
  },
}
