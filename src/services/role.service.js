import api from './api'

export function listRoles(filters = {}) {
  return api.get('/roles', { params: filters }).then((r) => r.data)
}

export function getRole(id) {
  return api.get(`/roles/${id}`).then((r) => r.data)
}

export function createRole(data) {
  return api.post('/roles', data).then((r) => r.data)
}

export function updateRole(id, data) {
  return api.put(`/roles/${id}`, data).then((r) => r.data)
}

export function deleteRole(id) {
  return api.delete(`/roles/${id}`)
}

export function getAvailablePermissions() {
  return api.get('/permissions').then((r) => r.data)
}

const roleService = {
  listRoles,
  getRole,
  createRole,
  updateRole,
  deleteRole,
  getAvailablePermissions
}

export default roleService
