import api from './api'

/**
 * Trusted-device registry for 2FA bypass. Self-management for /me, admin
 * management for arbitrary users.
 */
export default {
  listMine() {
    return api.get('/users/me/trusted-devices').then((res) => res.data)
  },
  revokeMine(id) {
    return api.delete(`/users/me/trusted-devices/${id}`).then((res) => res.data)
  },
  revokeAllMine() {
    return api.delete('/users/me/trusted-devices').then((res) => res.data)
  },
  listForUser(userId) {
    return api.get(`/users/${userId}/trusted-devices`).then((res) => res.data)
  },
  revokeAllForUser(userId) {
    return api.delete(`/users/${userId}/trusted-devices`).then((res) => res.data)
  },
}
