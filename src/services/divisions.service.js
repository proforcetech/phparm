import api from './api'

/**
 * Divisions — top-level org partitioning above branches.
 */
export default {
  list(params = {}) {
    return api.get('/divisions', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/divisions/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/divisions', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/divisions/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/divisions/${id}`).then((res) => res.data)
  },
}
