import api from './api'

/**
 * Single sign-on — admin-managed identity providers and per-user provider links.
 * Authentication endpoints under /api/auth/sso/* are public (no auth header).
 */
export default {
  // Auth flow (public)
  listAvailableProviders() {
    return api.get('/auth/sso/providers').then((res) => res.data)
  },
  startLogin(slug, payload = {}) {
    return api.post(`/auth/sso/${slug}/start`, payload).then((res) => res.data)
  },
  // Backend handles the callback URL; exposed here so tests / admin flows can poke it
  callback(params = {}) {
    return api.get('/auth/sso/callback', { params }).then((res) => res.data)
  },

  // Admin: identity providers
  listAdminProviders(params = {}) {
    return api.get('/admin/sso/providers', { params }).then((res) => res.data)
  },
  getAdminProvider(id) {
    return api.get(`/admin/sso/providers/${id}`).then((res) => res.data)
  },
  createAdminProvider(payload) {
    return api.post('/admin/sso/providers', payload).then((res) => res.data)
  },
  updateAdminProvider(id, payload) {
    return api.put(`/admin/sso/providers/${id}`, payload).then((res) => res.data)
  },
  deleteAdminProvider(id) {
    return api.delete(`/admin/sso/providers/${id}`).then((res) => res.data)
  },

  // Per-user links
  listMyLinks() {
    return api.get('/users/me/sso-links').then((res) => res.data)
  },
  unlinkMine(id) {
    return api.delete(`/users/me/sso-links/${id}`).then((res) => res.data)
  },
}
