import api from './api'

/**
 * Staff-side management of subcontractor portal tokens (Phase 18 / C2).
 *
 * Issuing returns the plaintext exactly once — caller must surface it
 * immediately to the staff user (modal, copy-to-clipboard) because the
 * server only stores the hash.
 */
export default {
  list(subcontractorId, includeRevoked = false) {
    return api
      .get(`/subcontractors/${subcontractorId}/portal-tokens`, {
        params: includeRevoked ? { include_revoked: '1' } : {},
      })
      .then((res) => res.data)
  },

  issue(subcontractorId, payload = {}) {
    return api
      .post(`/subcontractors/${subcontractorId}/portal-tokens`, payload)
      .then((res) => res.data)
  },

  revoke(tokenId, reason = '') {
    return api
      .delete(`/subcontractor-portal-tokens/${tokenId}`, {
        data: reason ? { reason } : {},
      })
      .then((res) => res.data)
  },

  listPodsForAssignment(assignmentId) {
    return api
      .get(`/subcontractor-assignments/${assignmentId}/pods`)
      .then((res) => res.data)
  },
}
