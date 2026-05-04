import api from './api'

/**
 * Staff-side management of vendor portal tokens (Phase 18 / C1).
 *
 * Issuing returns the plaintext exactly once — caller must surface it
 * immediately to the staff user (modal, copy-to-clipboard) because the
 * server only stores the hash.
 */
export default {
  list(vendorId, includeRevoked = false) {
    return api
      .get(`/vendors/${vendorId}/portal-tokens`, {
        params: includeRevoked ? { include_revoked: '1' } : {},
      })
      .then((res) => res.data)
  },

  issue(vendorId, payload = {}) {
    return api
      .post(`/vendors/${vendorId}/portal-tokens`, payload)
      .then((res) => res.data)
  },

  revoke(tokenId, reason = '') {
    return api
      .delete(`/vendor-portal-tokens/${tokenId}`, {
        data: reason ? { reason } : {},
      })
      .then((res) => res.data)
  },

  listDocumentsForPo(purchaseOrderId) {
    return api
      .get(`/purchase-orders/${purchaseOrderId}/documents`)
      .then((res) => res.data)
  },
}
