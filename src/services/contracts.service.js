import api from './api'

/**
 * Service contracts — master agreements, sites, entitlements, amendments,
 * billing schedules, utilization, renewals, and customer signing.
 */
export default {
  // CRUD
  list(params = {}) {
    return api.get('/contracts', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/contracts/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/contracts', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/contracts/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/contracts/${id}`).then((res) => res.data)
  },

  // Site bindings
  listSites(contractId) {
    return api.get(`/contracts/${contractId}/sites`).then((res) => res.data)
  },
  addSite(contractId, payload) {
    return api.post(`/contracts/${contractId}/sites`, payload).then((res) => res.data)
  },
  removeSite(contractId, siteId) {
    return api.delete(`/contracts/${contractId}/sites/${siteId}`).then((res) => res.data)
  },

  // Entitlements
  listEntitlements(contractId) {
    return api.get(`/contracts/${contractId}/entitlements`).then((res) => res.data)
  },
  addEntitlement(contractId, payload) {
    return api.post(`/contracts/${contractId}/entitlements`, payload).then((res) => res.data)
  },

  // Amendments
  listAmendments(contractId) {
    return api.get(`/contracts/${contractId}/amendments`).then((res) => res.data)
  },
  createAmendment(contractId, payload) {
    return api.post(`/contracts/${contractId}/amendments`, payload).then((res) => res.data)
  },

  // Public signing flow (no auth required)
  publicView(token) {
    return api.get('/public/contract/view', { params: { token } }).then((res) => res.data)
  },
  publicByCode(shortCode) {
    return api.get(`/public/contract/by-code/${shortCode}`).then((res) => res.data)
  },
  publicSign(payload) {
    return api.post('/public/contract/sign', payload).then((res) => res.data)
  },

  // Signing-link administration (auth required)
  listLinks(contractId) {
    return api.get(`/contracts/${contractId}/links`).then((res) => res.data)
  },
  issueLink(contractId, payload = {}) {
    return api.post(`/contracts/${contractId}/links`, payload).then((res) => res.data)
  },
  revokeLink(contractId, linkId) {
    return api.delete(`/contracts/${contractId}/links/${linkId}`).then((res) => res.data)
  },

  // Signature audit + internal signature capture
  listSignatures(contractId) {
    return api.get(`/contracts/${contractId}/signatures`).then((res) => res.data)
  },
  captureInternalSignature(contractId, payload) {
    return api.post(`/contracts/${contractId}/signatures`, payload).then((res) => res.data)
  },

  // R-02c: multi-party signer roster
  listSigners(contractId, includeRevoked = true) {
    return api
      .get(`/contracts/${contractId}/signers`, { params: { include_revoked: includeRevoked ? 1 : 0 } })
      .then((res) => res.data)
  },
  inviteSigner(contractId, payload) {
    return api.post(`/contracts/${contractId}/signers`, payload).then((res) => res.data)
  },
  revokeSigner(contractId, signerId) {
    return api.delete(`/contracts/${contractId}/signers/${signerId}`).then((res) => res.data)
  },
}
