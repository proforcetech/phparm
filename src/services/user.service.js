import api from './api'

export function listUsers(filters = {}) {
  return api.get('/users', { params: filters }).then((r) => r.data)
}

export function getUser(id) {
  return api.get(`/users/${id}`).then((r) => r.data)
}

export function createUser(data) {
  return api.post('/users', data).then((r) => r.data)
}

export function inviteUser(data) {
  return api.post('/users/invite', data).then((r) => r.data)
}

export function updateUser(id, data) {
  return api.put(`/users/${id}`, data).then((r) => r.data)
}

export function updateProfile(data) {
  return api.put('/users/me', data).then((r) => r.data)
}

export function deleteUser(id) {
  return api.delete(`/users/${id}`)
}

export function reset2FA(id) {
  return api.post(`/users/${id}/reset-2fa`).then((r) => r.data)
}

export function require2FA(id, required) {
  return api.post(`/users/${id}/require-2fa`, { required }).then((r) => r.data)
}

export function bulkDeactivateUsers(userIds) {
  return api.post('/users/bulk-deactivate', { user_ids: userIds }).then((r) => r.data)
}

export function bulkUpdateRole(userIds, role) {
  return api.post('/users/bulk-role', { user_ids: userIds, role }).then((r) => r.data)
}

const userService = {
  listUsers,
  getUser,
  createUser,
  inviteUser,
  updateUser,
  updateProfile,
  deleteUser,
  reset2FA,
  require2FA,
  bulkDeactivateUsers,
  bulkUpdateRole
}

export default userService
