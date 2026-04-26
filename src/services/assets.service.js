import api from './api'

/**
 * Installed-asset endpoints (Phase 2.x of docs/expansion-plan.md).
 * Covers asset types, site assets, polymorphic links, documents, and QR.
 */
export default {
  // Asset types catalog
  listTypes(params = {}) {
    return api.get('/asset-types', { params }).then((res) => res.data)
  },
  createType(payload) {
    return api.post('/asset-types', payload).then((res) => res.data)
  },
  updateType(id, payload) {
    return api.put(`/asset-types/${id}`, payload).then((res) => res.data)
  },
  deleteType(id) {
    return api.delete(`/asset-types/${id}`).then((res) => res.data)
  },

  // Site assets
  listForSite(siteId, params = {}) {
    return api.get(`/sites/${siteId}/assets`, { params }).then((res) => res.data)
  },
  lifecycleForSite(siteId) {
    return api.get(`/sites/${siteId}/assets/lifecycle`).then((res) => res.data)
  },
  get(id) {
    return api.get(`/assets/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/assets', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/assets/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/assets/${id}`).then((res) => res.data)
  },

  // Polymorphic links (WO/inspection/contract/etc)
  listLinks(assetId) {
    return api.get(`/assets/${assetId}/links`).then((res) => res.data)
  },
  createLink(assetId, payload) {
    return api.post(`/assets/${assetId}/links`, payload).then((res) => res.data)
  },
  deleteLink(linkId) {
    return api.delete(`/asset-links/${linkId}`).then((res) => res.data)
  },

  // Documents
  listDocuments(assetId, params = {}) {
    return api.get(`/assets/${assetId}/documents`, { params }).then((res) => res.data)
  },
  uploadDocument(assetId, formData) {
    return api
      .post(`/assets/${assetId}/documents`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((res) => res.data)
  },
  deleteDocument(documentId) {
    return api.delete(`/asset-documents/${documentId}`).then((res) => res.data)
  },

  // QR — returns binary; caller decides what to do with the response
  qrPng(assetId, scale = 8) {
    return api.get(`/assets/${assetId}/qr.png`, {
      params: { scale },
      responseType: 'blob',
    })
  },
}
