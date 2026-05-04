import api from './api'

/**
 * Technician skill matrix — Phase 17 / S11 of docs/woms-expansion-plan.md.
 *
 * Two surfaces:
 *   - skills        (CRUD on the catalog of competencies)
 *   - assignments   (grant/revoke per technician, plus the combined matrix
 *                    grid endpoint)
 *
 * Proficiency levels live in the backend constants but are mirrored here so
 * the React form can render the dropdown without a round-trip:
 */
export const PROFICIENCY_LEVELS = [
  { value: 'learner', label: 'Learner' },
  { value: 'competent', label: 'Competent' },
  { value: 'expert', label: 'Expert' },
]

export default {
  // ---- catalog -------------------------------------------------------
  list(params = {}) {
    return api.get('/skills', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/skills/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/skills', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/skills/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/skills/${id}`).then((res) => res.data)
  },

  // ---- combined matrix -----------------------------------------------
  matrix(params = {}) {
    return api.get('/skills/matrix', { params }).then((res) => res.data)
  },

  // ---- per-user assignments ------------------------------------------
  listForUser(userId) {
    return api.get(`/users/${userId}/skills`).then((res) => res.data)
  },
  grant(userId, skillId, payload = {}) {
    return api.put(`/users/${userId}/skills/${skillId}`, payload).then((res) => res.data)
  },
  revoke(userId, skillId) {
    return api.delete(`/users/${userId}/skills/${skillId}`).then((res) => res.data)
  },
}
