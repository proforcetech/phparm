import api from './api'

/**
 * Security credential register — Phase 16 / S1 of docs/woms-expansion-plan.md.
 *
 * Three top-level resources:
 *   - credentials      (cards, fobs, PINs, mobile tokens, biometric ids, plates)
 *   - credential-doors (m:n credential ↔ door (site_asset))
 *   - access-schedules (reusable time-window templates)
 *
 * Plus a polymorphic programming-log audit feed.
 *
 * Status flow: active ↔ suspended ↔ active, active → revoked|lost (terminal —
 * the server cascades and revokes every active door grant on the credential).
 */
export default {
  // ---- credentials ----------------------------------------------------
  list(params = {}) {
    return api.get('/security/credentials', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/security/credentials/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/security/credentials', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/security/credentials/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/security/credentials/${id}`).then((res) => res.data)
  },
  changeStatus(id, status, reason = null) {
    return api
      .post(`/security/credentials/${id}/status`, { status, reason })
      .then((res) => res.data)
  },

  // ---- doors ----------------------------------------------------------
  listDoors(credentialId) {
    return api
      .get(`/security/credentials/${credentialId}/doors`)
      .then((res) => res.data)
  },
  grantDoor(credentialId, payload) {
    return api
      .post(`/security/credentials/${credentialId}/doors`, payload)
      .then((res) => res.data)
  },
  updateDoor(doorId, payload) {
    return api
      .put(`/security/credential-doors/${doorId}`, payload)
      .then((res) => res.data)
  },
  revokeDoor(doorId, reason = null) {
    return api
      .post(`/security/credential-doors/${doorId}/revoke`, { reason })
      .then((res) => res.data)
  },

  // ---- access schedules -----------------------------------------------
  listSchedules(params = {}) {
    return api.get('/security/access-schedules', { params }).then((res) => res.data)
  },
  getSchedule(id) {
    return api.get(`/security/access-schedules/${id}`).then((res) => res.data)
  },
  createSchedule(payload) {
    return api.post('/security/access-schedules', payload).then((res) => res.data)
  },
  updateSchedule(id, payload) {
    return api.put(`/security/access-schedules/${id}`, payload).then((res) => res.data)
  },
  deleteSchedule(id) {
    return api.delete(`/security/access-schedules/${id}`).then((res) => res.data)
  },

  // ---- programming-log audit feed -------------------------------------
  listLogs(params = {}) {
    return api.get('/security/programming-logs', { params }).then((res) => res.data)
  },
}
