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

export function updateUser(id, data) {
  return api.put(`/users/${id}`, data).then((r) => r.data)
}

export function updateProfile(data) {
  return api.put('/auth/profile', data).then((r) => r.data)
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

const userService = {
  listUsers,
  getUser,
  createUser,
  updateUser,
  updateProfile,
  deleteUser,
  reset2FA,
  require2FA
}

export default userService
