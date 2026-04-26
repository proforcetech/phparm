import api from './api'

/**
 * Security event log — audit-adjacent stream for auth failures, MFA challenges,
 * suspicious activity, etc.
 */
export default {
  list(params = {}) {
    return api.get('/security-events', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/security-events/${id}`).then((res) => res.data)
  },
  summary(params = {}) {
    return api.get('/security-events/summary', { params }).then((res) => res.data)
  },
  record(payload) {
    return api.post('/security-events', payload).then((res) => res.data)
  },
}
