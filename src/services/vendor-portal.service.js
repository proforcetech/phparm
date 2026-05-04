import axios from 'axios'
import env from '@/config/env'

/**
 * Vendor self-service portal client (Phase 18 / C1).
 *
 * The portal is unauthenticated to the staff JWT stack — the bearer token
 * IS the credential. We deliberately do NOT use the shared `api` axios
 * instance because:
 *   - the shared instance attaches the staff JWT cookie / CSRF, which
 *     would mix two auth domains in one request;
 *   - the shared instance has interceptors that bounce the user to /login
 *     on 401, which would break a vendor whose token actually IS expired.
 *
 * Instead this exports a tiny axios instance that only knows about the
 * vendor-portal token. The token is held in module scope after `setToken`
 * and re-attached to every call.
 */

let bearerToken = ''

export function setToken(token) {
  bearerToken = (token || '').trim()
}

export function getToken() {
  return bearerToken
}

const client = axios.create({
  baseURL: env.API_BASE_URL,
  headers: { 'Content-Type': 'application/json' },
  withCredentials: false,
})

client.interceptors.request.use((config) => {
  if (bearerToken) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${bearerToken}`
  }
  return config
})

function unwrap(p) {
  return p.then((res) => res.data)
}

const vendorPortalService = {
  setToken,
  getToken,

  me() {
    return unwrap(client.get('/vendor-portal/me'))
  },

  listPos(status) {
    const params = {}
    if (status) params.status = status
    return unwrap(client.get('/vendor-portal/purchase-orders', { params }))
  },

  getPo(id) {
    return unwrap(client.get(`/vendor-portal/purchase-orders/${id}`))
  },

  acknowledge(id) {
    return unwrap(client.post(`/vendor-portal/purchase-orders/${id}/acknowledge`, {}))
  },

  markLineShipped(lineId, payload) {
    return unwrap(client.post(`/vendor-portal/purchase-order-lines/${lineId}/ship`, payload))
  },

  listDocuments(poId) {
    return unwrap(client.get(`/vendor-portal/purchase-orders/${poId}/documents`))
  },

  uploadDocument(poId, file, meta = {}) {
    const fd = new FormData()
    fd.append('file', file)
    if (meta.kind) fd.append('kind', meta.kind)
    if (meta.tracking_number) fd.append('tracking_number', meta.tracking_number)
    if (meta.carrier) fd.append('carrier', meta.carrier)
    if (meta.purchase_order_line_id) fd.append('purchase_order_line_id', meta.purchase_order_line_id)
    if (meta.notes) fd.append('notes', meta.notes)
    return unwrap(client.post(`/vendor-portal/purchase-orders/${poId}/documents`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }))
  },

  deleteDocument(documentId) {
    return unwrap(client.delete(`/vendor-portal/documents/${documentId}`))
  },
}

export default vendorPortalService
