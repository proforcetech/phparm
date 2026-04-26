import api from './api'

/**
 * Voice notes — audio attachments on tickets/workorders with transcription,
 * tagging, and pinning.
 */
export default {
  // Personal & inbox views
  mine(params = {}) {
    return api.get('/voice-notes/my', { params }).then((res) => res.data)
  },
  pending(params = {}) {
    return api.get('/voice-notes/pending', { params }).then((res) => res.data)
  },
  tags() {
    return api.get('/voice-notes/tags').then((res) => res.data)
  },

  // Per-entity feeds
  forTicket(ticketId) {
    return api.get(`/tickets/${ticketId}/voice-notes`).then((res) => res.data)
  },
  forWorkorder(workorderId) {
    return api.get(`/workorders/${workorderId}/voice-notes`).then((res) => res.data)
  },

  // CRUD
  get(id) {
    return api.get(`/voice-notes/${id}`).then((res) => res.data)
  },
  create(formData) {
    return api
      .post('/voice-notes', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/voice-notes/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/voice-notes/${id}`).then((res) => res.data)
  },

  // Actions
  transcribe(id, payload = {}) {
    return api.post(`/voice-notes/${id}/transcribe`, payload).then((res) => res.data)
  },
  pin(id) {
    return api.post(`/voice-notes/${id}/pin`).then((res) => res.data)
  },
  unpin(id) {
    return api.post(`/voice-notes/${id}/unpin`).then((res) => res.data)
  },
  setTags(id, tags) {
    return api.put(`/voice-notes/${id}/tags`, { tags }).then((res) => res.data)
  },
}
