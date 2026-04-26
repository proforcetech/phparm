import api from './api'

/**
 * Subcontractor catalog and assignment lookup.
 */
export default {
  list(params = {}) {
    return api.get('/subcontractors', { params }).then((res) => res.data)
  },
  // Filtered list of subs available for a given assignment context
  assignable(params = {}) {
    return api.get('/subcontractors/assignable', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/subcontractors/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/subcontractors', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/subcontractors/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/subcontractors/${id}`).then((res) => res.data)
  },
  listAssignments(params = {}) {
    return api.get('/subcontractor-assignments', { params }).then((res) => res.data)
  },
}
