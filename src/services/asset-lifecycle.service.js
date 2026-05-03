import api from './api'

/**
 * Phase 13 admin views — leases, acquisitions, decommissions.
 *
 * The portal-facing surface lives in portal.service.js; this is the
 * staff/admin REST client. Response envelopes mirror the rest of Phase
 * 12/13: { data: <payload> }.
 */
export const assetLifecycleService = {
  // ── leases ─────────────────────────────────────────────────────────────
  listLeases(params = {}) {
    return api.get('/asset-leases', { params }).then((res) => res.data)
  },
  getLease(id) {
    return api.get(`/asset-leases/${id}`).then((res) => res.data)
  },
  createLease(payload) {
    return api.post('/asset-leases', payload).then((res) => res.data)
  },
  updateLease(id, payload) {
    return api.put(`/asset-leases/${id}`, payload).then((res) => res.data)
  },
  recordLeaseDecision(id, payload) {
    return api.post(`/asset-leases/${id}/decision`, payload).then((res) => res.data)
  },
  terminateLease(id) {
    return api.post(`/asset-leases/${id}/terminate`).then((res) => res.data)
  },

  // ── acquisitions ───────────────────────────────────────────────────────
  listAcquisitions(params = {}) {
    return api.get('/asset-acquisitions', { params }).then((res) => res.data)
  },
  getAcquisition(id) {
    return api.get(`/asset-acquisitions/${id}`).then((res) => res.data)
  },
  createAcquisition(payload) {
    return api.post('/asset-acquisitions', payload).then((res) => res.data)
  },
  updateAcquisition(id, payload) {
    return api.put(`/asset-acquisitions/${id}`, payload).then((res) => res.data)
  },
  attachAcquisitionQuote(id, payload) {
    return api.post(`/asset-acquisitions/${id}/quote`, payload).then((res) => res.data)
  },
  approveAcquisition(id, payload = {}) {
    return api.post(`/asset-acquisitions/${id}/approve`, payload).then((res) => res.data)
  },
  rejectAcquisition(id, payload) {
    return api.post(`/asset-acquisitions/${id}/reject`, payload).then((res) => res.data)
  },
  issueAcquisitionPo(id, payload) {
    return api.post(`/asset-acquisitions/${id}/po`, payload).then((res) => res.data)
  },
  receiveAcquisition(id, payload = {}) {
    return api.post(`/asset-acquisitions/${id}/receive`, payload).then((res) => res.data)
  },
  scheduleAcquisitionInstall(id, payload) {
    return api.post(`/asset-acquisitions/${id}/schedule-install`, payload).then((res) => res.data)
  },
  installAcquisition(id, payload = {}) {
    return api.post(`/asset-acquisitions/${id}/install`, payload).then((res) => res.data)
  },
  activateAcquisition(id, payload = {}) {
    return api.post(`/asset-acquisitions/${id}/activate`, payload).then((res) => res.data)
  },
  cancelAcquisition(id, payload) {
    return api.post(`/asset-acquisitions/${id}/cancel`, payload).then((res) => res.data)
  },

  // ── decommissions ──────────────────────────────────────────────────────
  listDecommissions(params = {}) {
    return api.get('/asset-decommissions', { params }).then((res) => res.data)
  },
  getDecommission(id) {
    return api.get(`/asset-decommissions/${id}`).then((res) => res.data)
  },
  createDecommission(payload) {
    return api.post('/asset-decommissions', payload).then((res) => res.data)
  },
  updateDecommission(id, payload) {
    return api.put(`/asset-decommissions/${id}`, payload).then((res) => res.data)
  },
  startDecommissionWipe(id, payload = {}) {
    return api.post(`/asset-decommissions/${id}/wipe/start`, payload).then((res) => res.data)
  },
  completeDecommissionWipe(id, payload) {
    return api.post(`/asset-decommissions/${id}/wipe/complete`, payload).then((res) => res.data)
  },
  startDecommissionRecovery(id, payload = {}) {
    return api.post(`/asset-decommissions/${id}/recovery/start`, payload).then((res) => res.data)
  },
  completeDecommissionRecovery(id, payload) {
    return api.post(`/asset-decommissions/${id}/recovery/complete`, payload).then((res) => res.data)
  },
  updateDecommissionEntitlements(id, payload = {}) {
    return api.post(`/asset-decommissions/${id}/entitlements`, payload).then((res) => res.data)
  },
  markDecommissionAudited(id, payload = {}) {
    return api.post(`/asset-decommissions/${id}/audit`, payload).then((res) => res.data)
  },
  retireDecommission(id, payload = {}) {
    return api.post(`/asset-decommissions/${id}/retire`, payload).then((res) => res.data)
  },
  cancelDecommission(id, payload) {
    return api.post(`/asset-decommissions/${id}/cancel`, payload).then((res) => res.data)
  },

  // ── audit timeline (filtered to one entity) ────────────────────────────
  // entityType ∈ {'asset_lease', 'asset_acquisition', 'asset_decommission'}
  timelineFor(entityType, entityId) {
    return api
      .get('/audit', {
        params: { entity_type: entityType, entity_id: entityId, limit: 200 },
      })
      .then((res) => res.data)
  },
}

export default assetLifecycleService
