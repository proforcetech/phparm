import api from './api'

/**
 * Custom field definitions and per-record values.
 * Definitions are scoped to entity_type; values are keyed by entity_type+id.
 */
export default {
  // Definitions
  listDefinitions(params = {}) {
    return api.get('/custom-fields/definitions', { params }).then((res) => res.data)
  },
  createDefinition(payload) {
    return api.post('/custom-fields/definitions', payload).then((res) => res.data)
  },
  updateDefinition(id, payload) {
    return api.put(`/custom-fields/definitions/${id}`, payload).then((res) => res.data)
  },
  deleteDefinition(id) {
    return api.delete(`/custom-fields/definitions/${id}`).then((res) => res.data)
  },

  // Values
  listValues(params = {}) {
    return api.get('/custom-fields/values', { params }).then((res) => res.data)
  },
  upsertValues(payload) {
    return api.put('/custom-fields/values', payload).then((res) => res.data)
  },
}
