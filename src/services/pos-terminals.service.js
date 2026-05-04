import api from './api'

/**
 * POS terminal management — Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Two surfaces:
 *   - terminals     (CRUD + rotate-secret + heartbeat history)
 *   - heartbeats    (read-only — write side is the public webhook receiver)
 *
 * shared_secret is only returned by create() / rotateSecret() — show the
 * value to the user exactly once, then it's permanently redacted.
 */
export default {
  // ---- terminals -----------------------------------------------------
  list(params = {}) {
    return api.get('/pos/terminals', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/pos/terminals/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/pos/terminals', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/pos/terminals/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/pos/terminals/${id}`).then((res) => res.data)
  },
  rotateSecret(id) {
    return api.post(`/pos/terminals/${id}/rotate-secret`).then((res) => res.data)
  },

  // ---- heartbeats ----------------------------------------------------
  listHeartbeats(id, params = {}) {
    return api.get(`/pos/terminals/${id}/heartbeats`, { params }).then((res) => res.data)
  },
}
