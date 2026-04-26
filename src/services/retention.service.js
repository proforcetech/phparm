import api from './api'

/**
 * Data retention policies — pruning rules and execution history.
 * Compliance-adjacent: every run is logged.
 */
export default {
  // Policies
  listPolicies(params = {}) {
    return api.get('/retention/policies', { params }).then((res) => res.data)
  },
  getPolicy(id) {
    return api.get(`/retention/policies/${id}`).then((res) => res.data)
  },
  createPolicy(payload) {
    return api.post('/retention/policies', payload).then((res) => res.data)
  },
  updatePolicy(id, payload) {
    return api.put(`/retention/policies/${id}`, payload).then((res) => res.data)
  },
  deletePolicy(id) {
    return api.delete(`/retention/policies/${id}`).then((res) => res.data)
  },

  // Execution
  runPolicy(id, payload = {}) {
    return api.post(`/retention/policies/${id}/run`, payload).then((res) => res.data)
  },
  runAll(payload = {}) {
    return api.post('/retention/run-all', payload).then((res) => res.data)
  },
  listRuns(params = {}) {
    return api.get('/retention/runs', { params }).then((res) => res.data)
  },
}
