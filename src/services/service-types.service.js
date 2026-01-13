import api from './api'

export function fetchServiceTypes(params = {}) {
  return api.get('/service-types', { params }).then((r) => r.data)
}

export function createServiceType(payload) {
  return api.post('/service-types', payload).then((r) => r.data)
}

export function updateServiceType(id, payload) {
  return api.put(`/service-types/${id}`, payload).then((r) => r.data)
}

export function deleteServiceType(id) {
  return api.delete(`/service-types/${id}`).then((r) => r.data)
}
