import axios from 'axios'
import env from '@/config/env'

/**
 * Subcontractor self-service portal client (Phase 18 / C2).
 *
 * The portal is unauthenticated to the staff JWT stack — the bearer token
 * IS the credential. We deliberately do NOT use the shared `api` axios
 * instance because:
 *   - the shared instance attaches the staff JWT cookie / CSRF, which
 *     would mix two auth domains in one request;
 *   - the shared instance has interceptors that bounce the user to /login
 *     on 401, which would break a sub whose token actually IS expired.
 *
 * Instead this exports a tiny axios instance that only knows about the
 * sub-portal token. The token is held in module scope after `setToken`
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

const subPortalService = {
  setToken,
  getToken,

  me() {
    return unwrap(client.get('/sub-portal/me'))
  },

  listAssignments(status) {
    const params = {}
    if (status) params.status = status
    return unwrap(client.get('/sub-portal/assignments', { params }))
  },

  getAssignment(id) {
    return unwrap(client.get(`/sub-portal/assignments/${id}`))
  },

  accept(id) {
    return unwrap(client.post(`/sub-portal/assignments/${id}/accept`, {}))
  },

  decline(id) {
    return unwrap(client.post(`/sub-portal/assignments/${id}/decline`, {}))
  },

  start(id) {
    return unwrap(client.post(`/sub-portal/assignments/${id}/start`, {}))
  },

  complete(id, payload = {}) {
    return unwrap(client.post(`/sub-portal/assignments/${id}/complete`, payload))
  },

  updateAssignment(id, payload) {
    return unwrap(client.patch(`/sub-portal/assignments/${id}`, payload))
  },

  listPods(id) {
    return unwrap(client.get(`/sub-portal/assignments/${id}/pods`))
  },

  uploadPod(id, file, kind = 'pod', note = '') {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('kind', kind)
    if (note) fd.append('note', note)
    return unwrap(client.post(`/sub-portal/assignments/${id}/pods`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }))
  },

  addNote(id, note) {
    return unwrap(client.post(`/sub-portal/assignments/${id}/notes`, { note }))
  },

  deletePod(podId) {
    return unwrap(client.delete(`/sub-portal/pods/${podId}`))
  },
}

export default subPortalService
