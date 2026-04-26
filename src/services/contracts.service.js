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
}
